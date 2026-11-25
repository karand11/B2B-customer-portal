<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\PartNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Operator CLI only: reads a CSV, never executes or serves it. Spec: docs/phase-2-design.md section 6.
 * Exit codes: 0 all rows valid, 1 bad rows skipped (valid rows imported), 2 fatal (nothing imported).
 */
class ImportProducts extends Command
{
    protected $signature = 'products:import {file : CSV file to import} {--dry-run : Validate and classify, write nothing}';

    protected $description = 'Create or update products from a CSV file (upsert by normalised part number)';

    private const REQUIRED = ['part_number', 'name', 'brand', 'category', 'inner_diameter_mm', 'outer_diameter_mm', 'width_mm', 'material', 'seal_type', 'stock_status'];

    private const OPTIONAL = ['price_cents', 'price_on_request', 'active', 'description'];

    private const MAX_ROWS = 100000;

    private const MAX_LISTED = 100;

    private const CHUNK = 500;

    private const MAX_RECORD_BYTES = 1000000;

    /** Starts with a letter or digit (no spreadsheet formula prefix), same character set as the API brand filter. */
    private const TEXT_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} .&\/+-]*$/uD';

    /** Values starting with these are executed as formulas by spreadsheet software (CSV injection). */
    private const FORMULA_PREFIX = '/^[=+\-@	]/';

    /** @var array<string, int> slug => id */
    private array $categories = [];

    /** @var list<string> */
    private array $header = [];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $handle = is_file($path) && is_readable($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            return $this->fatal('cannot read file '.$this->show($path));
        }

        try {
            $dry = (bool) $this->option('dry-run');
            $code = $dry ? $this->import($handle, true) : DB::transaction(fn () => $this->import($handle, false));

            // After commit, so a concurrent request cannot re-cache pre-import data.
            $dry || CatalogCache::flush();

            return $code;
        } catch (ImportFatal $e) {
            return $this->fatal($e->getMessage());
        } finally {
            fclose($handle);
        }
    }

    private function import($handle, bool $dry): int
    {
        $line = 0;
        $this->readHeader($handle, $line);
        $this->categories = Category::pluck('id', 'slug')->all();

        $stats = ['read' => 0, 'created' => 0, 'updated' => 0, 'bad' => 0];
        $bad = [];
        $seen = [];
        $chunk = [];

        while (($record = $this->readRecord($handle, $line)) !== null) {
            [$start, $fields] = $record;

            if (++$stats['read'] > self::MAX_ROWS) {
                throw new ImportFatal('more than '.self::MAX_ROWS.' data rows; nothing imported');
            }

            [$errors, $values, $key] = $fields === null
                ? [['row: line longer than '.self::MAX_RECORD_BYTES.' bytes'], null, null]
                : $this->validate($fields, $seen);

            if ($errors) {
                $stats['bad']++;
                if (count($bad) < self::MAX_LISTED) {
                    $bad[] = "line {$start}: ".implode('; ', $errors);
                }

                continue;
            }

            $seen[$key] = $start;
            $chunk[$key] = $values;

            if (count($chunk) >= self::CHUNK) {
                $this->flushChunk($chunk, $dry, $stats);
                $chunk = [];
            }
        }

        $this->flushChunk($chunk, $dry, $stats);

        foreach ($bad as $text) {
            $this->line(OutputFormatter::escape($text));
        }

        $listed = $stats['bad'] > self::MAX_LISTED ? ' (first '.self::MAX_LISTED.' listed)' : '';
        $this->line($dry
            ? "DRY RUN: Rows read: {$stats['read']}, would create: {$stats['created']}, would update: {$stats['updated']}, bad: {$stats['bad']}{$listed}"
            : "Rows read: {$stats['read']}, created: {$stats['created']}, updated: {$stats['updated']}, unchanged-or-skipped: 0, bad: {$stats['bad']}{$listed}");

        return $stats['bad'] > 0 ? 1 : 0;
    }

    /** @param  array<string, array<string, mixed>>  $chunk keyed by normalised part number */
    private function flushChunk(array $chunk, bool $dry, array &$stats): void
    {
        if (! $chunk) {
            return;
        }

        $existing = Product::whereIn('part_number_search', array_keys($chunk))->count();
        $stats['updated'] += $existing;
        $stats['created'] += count($chunk) - $existing;

        if (! $dry) {
            Product::upsert(array_values($chunk), ['part_number_search'], [
                'category_id', 'part_number', 'name', 'description', 'brand', 'inner_diameter_mm', 'outer_diameter_mm',
                'width_mm', 'material', 'seal_type', 'price_cents', 'price_on_request', 'stock_status', 'active',
            ]);
        }
    }

    private function readHeader($handle, int &$line): void
    {
        $record = $this->readRecord($handle, $line, true);

        if ($record === null) {
            throw new ImportFatal('file is empty (no header line)');
        }

        if ($record[1] === null) {
            throw new ImportFatal('header line longer than '.self::MAX_RECORD_BYTES.' bytes');
        }

        $names = array_map(fn ($n) => strtolower(trim((string) $n)), $record[1]);

        if (count(array_unique($names)) !== count($names)) {
            throw new ImportFatal('header has duplicate columns');
        }

        $missing = array_diff(self::REQUIRED, $names);
        if (! in_array('price_cents', $names, true) && ! in_array('price_on_request', $names, true)) {
            $missing[] = 'price_cents or price_on_request';
        }
        if ($missing) {
            throw new ImportFatal('missing required column(s): '.implode(', ', $missing));
        }

        foreach (array_diff($names, self::REQUIRED, self::OPTIONAL) as $unknown) {
            $this->line('warning: unknown column "'.OutputFormatter::escape($this->show($unknown)).'" ignored');
        }

        $this->header = $names;
    }

    /**
     * Next non-blank CSV record as [1-based start line, fields]; null at end of file.
     * fields is null when the record exceeds MAX_RECORD_BYTES (a bad row, or fatal for the header).
     * Physical lines are counted by hand so quoted multi-line fields keep line numbers right.
     *
     * @return array{int, list<string|null>|null}|null
     */
    private function readRecord($handle, int &$line, bool $isHeader = false): ?array
    {
        do {
            $raw = $this->readLine($handle, $tooLong);
            if ($raw === false) {
                return null;
            }
            $start = ++$line;
        } while (trim($raw, "\r\n\t ") === '' || ($isHeader && trim(str_replace("\xEF\xBB\xBF", '', $raw)) === ''));

        $record = $raw;
        while (! $tooLong && substr_count($record, '"') % 2 === 1 && ($next = $this->readLine($handle, $tooLong)) !== false) {
            $record .= $next;
            $line++;
            $tooLong = $tooLong || strlen($record) >= self::MAX_RECORD_BYTES;
        }

        if ($tooLong) {
            return [$start, null];
        }

        if ($start === 1) {
            $record = preg_replace('/^\xEF\xBB\xBF/', '', $record);
        }

        $record = rtrim($record, "\r\n");

        if (! mb_check_encoding($record, 'UTF-8') && $isHeader) {
            throw new ImportFatal('header is not valid UTF-8');
        }

        return [$start, str_getcsv($record, ',', '"', '')];
    }

    /** One physical line, at most MAX_RECORD_BYTES; the rest of an over-long line is skipped and $tooLong set. */
    private function readLine($handle, ?bool &$tooLong): string|false
    {
        $tooLong = false;
        $line = fgets($handle, self::MAX_RECORD_BYTES + 1);

        if ($line !== false && strlen($line) >= self::MAX_RECORD_BYTES && ! str_ends_with($line, "\n")) {
            $tooLong = true;
            while (($rest = fgets($handle, self::MAX_RECORD_BYTES + 1)) !== false && ! str_ends_with($rest, "\n")) {
                // drain to the end of the physical line
            }
        }

        return $line;
    }

    /**
     * @param  list<string|null>  $fields
     * @param  array<string, int>  $seen
     * @return array{list<string>, array<string, mixed>|null, string|null} errors, row values, normalised key
     */
    private function validate(array $fields, array $seen): array
    {
        if (count($fields) !== count($this->header)) {
            return [['row: expected '.count($this->header).' columns, got '.count($fields)], null, null];
        }

        $data = array_combine($this->header, array_map(fn ($f) => trim((string) $f, " \t\r\n"), $fields));

        foreach ($data as $value) {
            if (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
                return [['row: must be valid UTF-8 without NUL bytes'], null, null];
            }
        }

        $errors = [];
        $fail = function (string $column, string $message) use (&$errors) {
            $errors[] = "{$column}: {$message}";
        };
        $get = fn (string $c) => $data[$c] ?? '';

        $partNumber = strtoupper($get('part_number'));
        $key = null;
        if ($partNumber === '' || strlen($partNumber) > 64 || preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]*$/D', $partNumber) !== 1) {
            $fail('part_number', 'required, 1-64 characters, letters, digits, space . _ / - only, starting with a letter or digit');
        } else {
            $key = PartNumber::normalise($partNumber);
            if (isset($seen[$key])) {
                $fail('part_number', "duplicate of line {$seen[$key]} ({$this->show($partNumber)})");
            }
        }

        $length = fn (string $c, int $max) => $get($c) !== '' && mb_strlen($get($c)) <= $max || $fail($c, "required, 1-{$max} characters");
        $length('name', 255);

        // Checked on the untrimmed value: a leading tab or CR is itself a formula prefix.
        foreach (array_intersect_key(array_combine($this->header, $fields), ['name' => 1, 'description' => 1]) as $column => $rawValue) {
            if (preg_match(self::FORMULA_PREFIX, ltrim((string) $rawValue, ' ')) === 1) {
                $fail($column, 'must not start with = + - @ or a tab/carriage return (spreadsheet formula)');
            }
        }

        foreach (['brand', 'material'] as $column) {
            if ($get($column) === '' || mb_strlen($get($column)) > 64 || preg_match(self::TEXT_PATTERN, $get($column)) !== 1) {
                $fail($column, 'required, 1-64 characters, letters, digits, space . & / + - only');
            }
        }

        $categoryId = $this->categories[$get('category')] ?? null;
        $categoryId !== null || $fail('category', "unknown category \"{$this->show($get('category'))}\"");

        $mm = [];
        foreach (['inner_diameter_mm', 'outer_diameter_mm', 'width_mm'] as $column) {
            $v = $get($column);
            if (preg_match('/^\d{1,4}(\.\d{1,2})?$/D', $v) !== 1 || (float) $v <= 0 || (float) $v > 5000) {
                $fail($column, "must be a number above 0 and up to 5000 with at most 2 decimals; got \"{$this->show($v)}\"");
            } else {
                $mm[$column] = $v;
            }
        }
        if (isset($mm['inner_diameter_mm'], $mm['outer_diameter_mm']) && (float) $mm['outer_diameter_mm'] <= (float) $mm['inner_diameter_mm']) {
            $fail('outer_diameter_mm', 'must be greater than inner_diameter_mm');
        }

        $seal = strtolower($get('seal_type'));
        in_array($seal, Product::SEAL_TYPES, true) || $fail('seal_type', 'must be one of '.implode(', ', Product::SEAL_TYPES));

        $stock = strtolower($get('stock_status'));
        in_array($stock, Product::STOCK_STATUSES, true) || $fail('stock_status', 'must be one of '.implode(', ', Product::STOCK_STATUSES));

        $onRequest = $this->bool($get('price_on_request'), false);
        $onRequest !== null || $fail('price_on_request', 'must be true/false/1/0/yes/no or empty');
        $active = $this->bool($get('active'), true);
        $active !== null || $fail('active', 'must be true/false/1/0/yes/no or empty');

        $cents = $get('price_cents');
        if ($cents !== '' && preg_match('/^\d{1,18}$/D', $cents) !== 1) {
            $fail('price_cents', "must be a non-negative integer; got \"{$this->show($cents)}\"");
        } elseif ($cents !== '' && (int) $cents > Product::MAX_PRICE_CENTS) {
            $fail('price_cents', 'must be at most '.Product::MAX_PRICE_CENTS." (cents); got \"{$cents}\"");
        } elseif ($onRequest !== null && $onRequest && $cents !== '') {
            $fail('price_cents', 'must be empty when price_on_request is true');
        } elseif ($onRequest === false && $cents === '') {
            $fail('price_cents', 'required unless price_on_request is true');
        }

        if (mb_strlen($get('description')) > 5000) {
            $fail('description', 'must be at most 5000 characters');
        }

        if ($errors) {
            return [$errors, null, null];
        }

        return [[], [
            'category_id' => $categoryId,
            'part_number' => $partNumber,
            'name' => $get('name'),
            'description' => $get('description') === '' ? null : $get('description'),
            'brand' => $get('brand'),
            'inner_diameter_mm' => $mm['inner_diameter_mm'],
            'outer_diameter_mm' => $mm['outer_diameter_mm'],
            'width_mm' => $mm['width_mm'],
            'material' => $get('material'),
            'seal_type' => $seal,
            'price_cents' => $onRequest ? null : (int) $cents,
            'price_on_request' => $onRequest,
            'stock_status' => $stock,
            'active' => $active,
        ], $key];
    }

    private function bool(string $value, bool $default): ?bool
    {
        return match (strtolower($value)) {
            '' => $default,
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => null,
        };
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

/** Fatal import problem (exit code 2, nothing imported). */
class ImportFatal extends RuntimeException {}
