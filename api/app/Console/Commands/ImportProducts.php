<?php

namespace App\Console\Commands;

use App\Services\ImportFatal;
use App\Services\ImportReport;
use App\Services\ProductImporter;
use App\Support\CatalogCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Operator CLI only: reads a CSV, never executes or serves it. Spec: docs/phase-2-design.md section 6; the logic lives in ProductImporter
 * (shared with the two-step HTTP import, which has a smaller row limit).
 * Exit codes: 0 all rows valid, 1 bad rows skipped (valid rows imported), 2 fatal (nothing imported).
 */
class ImportProducts extends Command
{
    protected $signature = 'products:import {file : CSV file to import} {--dry-run : Validate and classify, write nothing}';

    protected $description = 'Create or update products from a CSV file (upsert by normalised part number)';

    private const MAX_ROWS = 100000;

    public function handle(ProductImporter $importer): int
    {
        $path = (string) $this->argument('file');
        $handle = is_file($path) && is_readable($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            return $this->fatal('cannot read file '.$this->show($path));
        }

        try {
            $dry = (bool) $this->option('dry-run');
            $report = $dry
                ? $importer->run($handle, true, self::MAX_ROWS)
                : DB::transaction(fn () => $importer->run($handle, false, self::MAX_ROWS));

            // After commit, so a concurrent request cannot re-cache pre-import data.
            $dry || CatalogCache::flush();
            $this->print($report, $dry);

            return $report->bad > 0 ? 1 : 0;
        } catch (ImportFatal $e) {
            $this->warnings($e->warnings);

            return $this->fatal($e->getMessage());
        } finally {
            fclose($handle);
        }
    }

    private function print(ImportReport $report, bool $dry): void
    {
        $this->warnings($report->warnings);

        foreach ($report->errors as $error) {
            $this->line(OutputFormatter::escape("line {$error['line']}: ".implode('; ', $error['messages'])));
        }

        $listed = $report->bad > ProductImporter::MAX_LISTED ? ' (first '.ProductImporter::MAX_LISTED.' listed)' : '';
        $this->line($dry
            ? "DRY RUN: Rows read: {$report->read}, would create: {$report->created}, would update: {$report->updated}, bad: {$report->bad}{$listed}"
            : "Rows read: {$report->read}, created: {$report->created}, updated: {$report->updated}, unchanged-or-skipped: 0, bad: {$report->bad}{$listed}");
    }

    /** @param  list<string>  $warnings */
    private function warnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->line('warning: '.OutputFormatter::escape($warning));
        }
    }

    /** Operator data in messages: printable ASCII only, truncated. */
    private function show(string $value): string
    {
        return preg_replace('/[^\x20-\x7E]/', '?', mb_strcut($value, 0, 64));
    }

    private function fatal(string $message): int
    {
        $this->error(OutputFormatter::escape('Import aborted, nothing imported: '.$message));

        return 2;
    }
}
