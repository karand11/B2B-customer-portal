<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Operator health report (CLI only, so it may name components and counts: the public readiness route may not).
 * Exit 0 = healthy, 1 = a check failed. `--strict` also fails on a stale (older than 3 minutes) or missing scheduler heartbeat and on failed jobs.
 * Used by the restore drill and the deploy step.
 */
class PortalHealth extends Command
{
    protected $signature = 'portal:health {--strict : A stale scheduler heartbeat or any failed job fails the check} {--json : Machine readable output}';

    protected $description = 'Check database, migrations, cache, storage, queue and scheduler';

    private const HEARTBEAT_MAX_AGE_SECONDS = 180;

    public function handle(Migrator $migrator): int
    {
        $checks = [];
        $strict = (bool) $this->option('strict');

        $checks['database'] = $this->probe(fn () => DB::select('select 1') !== [] ? 'ok' : throw new \RuntimeException);
        $database = $checks['database']['ok'];

        $checks['migrations'] = $database
            ? $this->probe(function () use ($migrator) {
                $pending = count(array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan()));

                return $pending === 0 ? 'none pending' : throw new \RuntimeException("{$pending} pending");
            })
            : ['ok' => false, 'detail' => 'skipped: no database'];

        $checks['cache'] = $this->probe(function () {
            $token = bin2hex(random_bytes(8));
            Cache::put('health:cli', $token, 60);

            return Cache::get('health:cli') === $token ? 'ok' : throw new \RuntimeException;
        });

        $checks['storage'] = $this->probe(function () {
            $file = tempnam(storage_path('framework'), 'health');

            return $file !== false && file_put_contents($file, 'x') === 1 && unlink($file) ? 'writable' : throw new \RuntimeException;
        });

        $failed = $database && Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $checks['failed_jobs'] = ['ok' => ! $strict || $failed === 0, 'detail' => $failed === null ? 'unknown' : (string) $failed];

        $oldest = $database && Schema::hasTable('jobs') ? DB::table('jobs')->min('created_at') : null;
        $checks['oldest_pending_job'] = ['ok' => true, 'detail' => $oldest === null ? 'queue empty' : (time() - (int) $oldest).' seconds'];

        $beat = null;
        try {
            $beat = Cache::get('scheduler:heartbeat');
        } catch (Throwable) {
            // reported by the cache check
        }
        $age = is_numeric($beat) ? time() - (int) $beat : null;
        $checks['scheduler_heartbeat'] = [
            'ok' => ! $strict || ($age !== null && $age <= self::HEARTBEAT_MAX_AGE_SECONDS),
            'detail' => $age === null ? 'never seen' : "{$age} seconds ago",
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        if ($this->option('json')) {
            $this->line(json_encode(['healthy' => $healthy, 'checks' => $checks], JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $check) {
                $this->line(sprintf('%-20s %-5s %s', $name, $check['ok'] ? 'ok' : 'FAIL', $check['detail']));
            }
            $healthy ? $this->info('Healthy.') : $this->error('Unhealthy.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{ok: bool, detail: string} */
    private function probe(\Closure $check): array
    {
        try {
            return ['ok' => true, 'detail' => (string) $check()];
        } catch (Throwable $e) {
            // The class and the message of an infrastructure error carry no personal data, but keep the line short.
            return ['ok' => false, 'detail' => $e::class.($e->getMessage() !== '' && ! $e instanceof QueryException ? ': '.mb_substr($e->getMessage(), 0, 120) : '')];
        }
    }
}
