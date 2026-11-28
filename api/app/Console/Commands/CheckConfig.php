<?php

namespace App\Console\Commands;

use App\Support\ProductionGuard;
use Illuminate\Console\Command;

/** The deploy gate: exit 1 when the configuration of any environment except local, testing and loadtest is unsafe. Prints rule names (environment variables), never values. */
class CheckConfig extends Command
{
    protected $signature = 'portal:check-config {--strict : Warnings fail as well} {--json : Machine readable output}';

    protected $description = 'Check the configuration against the production safety rules';

    public function handle(): int
    {
        $env = app()->environment();
        $result = ProductionGuard::check(ProductionGuard::settings());
        $enforced = ProductionGuard::applies();
        $failed = $enforced && ($result['errors'] !== [] || ($this->option('strict') && $result['warnings'] !== []));

        if ($this->option('json')) {
            $this->line(json_encode(['environment' => $env, 'enforced' => $enforced, 'ok' => ! $failed] + $result, JSON_THROW_ON_ERROR));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if (! $enforced) {
            $this->info("Environment [{$env}]: the production rules apply to every environment except local, testing and loadtest.");
            $all = [...$result['errors'], ...$result['warnings']];
            $all === [] || $this->line('A production environment would report: '.implode(', ', $all));

            return self::SUCCESS;
        }

        foreach ($result['errors'] as $rule) {
            $this->error("UNSAFE: {$rule}");
        }
        foreach ($result['warnings'] as $rule) {
            $this->warn("WARNING: {$rule}");
        }
        $failed ? $this->error("Configuration check failed for environment [{$env}].") : $this->info("Configuration check passed for environment [{$env}].");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
