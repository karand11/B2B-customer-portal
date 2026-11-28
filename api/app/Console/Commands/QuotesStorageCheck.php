<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only storage report for quote attachments (scheduled daily): bytes and files per company, the import disk, free space,
 * files on disk without a row (older than 24 h: a younger one may be an upload in flight) and rows without a file.
 * `--prune-orphans --force` deletes orphan FILES only, never rows. Without `--force` it is a dry run. Warnings go to the log.
 */
class QuotesStorageCheck extends Command
{
    protected $signature = 'quotes:storage-check {--json : Machine readable output} {--prune-orphans : Delete files that have no database row} {--force : Really delete (without it --prune-orphans is a dry run)}';

    protected $description = 'Report quote attachment storage, orphan files and rows without a file';

    private const ORPHAN_MIN_AGE_HOURS = 24;

    private const COMPANY_WARN_PERCENT = 80;

    public function handle(): int
    {
        $disk = Storage::disk(config('quotes.attachments.disk'));
        $directory = (string) config('quotes.attachments.directory');
        $cap = (int) config('quotes.attachments.max_company_bytes');

        $companies = DB::table('quote_attachments as a')->join('quote_requests as q', 'q.id', '=', 'a.quote_request_id')
            ->groupBy('q.company_id')->orderBy('q.company_id')
            ->selectRaw('q.company_id, count(*) as files, sum(a.size_bytes) as bytes')->get()
            ->map(fn ($r) => [
                'company_id' => (int) $r->company_id, 'files' => (int) $r->files, 'bytes' => (int) $r->bytes,
                'percent_of_cap' => $cap > 0 ? round($r->bytes / $cap * 100, 1) : 0.0,
            ])->all();

        $onDisk = array_flip($disk->files($directory));
        $rows = DB::table('quote_attachments')->orderBy('id')->get(['public_id', 'storage_path']);
        $known = array_flip($rows->pluck('storage_path')->all());
        $cutoff = now()->subHours(self::ORPHAN_MIN_AGE_HOURS)->getTimestamp();

        $orphans = array_values(array_filter(array_keys($onDisk), fn (string $path) => ! isset($known[$path]) && $disk->lastModified($path) < $cutoff));
        $missing = $rows->filter(fn ($r) => ! isset($onDisk[$r->storage_path]))->pluck('public_id')->values()->all();

        $pruned = [];
        if ($this->option('prune-orphans') && $this->option('force')) {
            foreach ($orphans as $path) {
                $disk->delete($path) && $pruned[] = $path;
            }
        }

        $free = @disk_free_space(storage_path());
        $report = [
            'quotes' => ['files' => array_sum(array_column($companies, 'files')), 'bytes' => array_sum(array_column($companies, 'bytes')), 'companies' => $companies],
            'imports_bytes' => $this->bytesOf(config('admin.imports.disk'), (string) config('admin.imports.directory')),
            'free_bytes' => $free === false ? null : (int) $free,
            'orphan_files' => $orphans,
            'rows_without_file' => $missing,
            'pruned' => $pruned,
        ];

        $this->logWarnings($report);
        $this->option('json') ? $this->line(json_encode($report, JSON_THROW_ON_ERROR)) : $this->print($report);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $report */
    private function logWarnings(array $report): void
    {
        foreach ($report['quotes']['companies'] as $c) {
            $c['percent_of_cap'] >= self::COMPANY_WARN_PERCENT
                && Log::warning('Quote storage: a company is close to its attachment cap', ['company_id' => $c['company_id'], 'percent' => $c['percent_of_cap']]);
        }
        $report['free_bytes'] !== null && $report['free_bytes'] < (int) config('portal.storage_min_free_bytes')
            && Log::warning('Quote storage: free disk space is low', ['free_bytes' => $report['free_bytes']]);
        ($report['orphan_files'] || $report['rows_without_file'])
            && Log::warning('Quote storage: orphans found', ['orphan_files' => count($report['orphan_files']), 'rows_without_file' => count($report['rows_without_file'])]);
    }

    private function bytesOf(string $diskName, string $directory): int
    {
        $disk = Storage::disk($diskName);

        return array_sum(array_map(fn (string $path) => $disk->size($path), $disk->allFiles($directory)));
    }

    /** @param  array<string, mixed>  $report */
    private function print(array $report): void
    {
        $this->line(sprintf('Quote attachments: %d files, %s bytes', $report['quotes']['files'], number_format($report['quotes']['bytes'])));
        foreach ($report['quotes']['companies'] as $c) {
            $this->line(sprintf('  company %d: %d files, %s bytes (%s%% of the cap)', $c['company_id'], $c['files'], number_format($c['bytes']), $c['percent_of_cap']));
        }
        $this->line('Import files: '.number_format($report['imports_bytes']).' bytes');
        $this->line('Free disk space: '.($report['free_bytes'] === null ? 'unknown' : number_format($report['free_bytes']).' bytes'));
        $this->line('Files without a row (older than 24 h): '.count($report['orphan_files']));
        foreach ($report['orphan_files'] as $path) {
            $this->line("  {$path}");
        }
        $this->line('Rows without a file: '.count($report['rows_without_file']));
        foreach ($report['rows_without_file'] as $id) {
            $this->line("  {$id}");
        }
        if ($this->option('prune-orphans')) {
            $this->line($this->option('force') ? 'Deleted '.count($report['pruned']).' orphan file(s).' : 'Dry run: nothing deleted. Add --force to delete the orphan files.');
        }
    }
}
