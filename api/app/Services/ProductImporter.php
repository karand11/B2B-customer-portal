<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\PartNumber;
use App\Support\ProductRules;

/**
 * Reads a CSV stream and validates, classifies and (unless $dry) upserts its rows by normalised part number.
 * Shared by `products:import` (operator CLI) and the two-step HTTP import. Spec: docs/phase-2-design.md section 6.
 * It never executes or serves the file. The caller owns the transaction and the catalog cache flush.
 */
class ProductImporter
{
    public const MAX_LISTED = 100;

    private const REQUIRED = ['part_number', 'name', 'brand', 'category', 'inner_diameter_mm', 'outer_diameter_mm', 'width_mm', 'material', 'seal_type', 'stock_status'];

    private const OPTIONAL = ['price_cents', 'price_on_request', 'active', 'description'];

    private const CHUNK = 500;

    private const MAX_RECORD_BYTES = 1000000;

    /** @var array<string, int> slug => id */
    private array $categories = [];

    /** @var list<string> */
    private array $header = [];

    /** @var list<string> */
    private array $warnings = [];

    private int $maxRows = 0;

    /**
     * @param  resource  $handle
     *
     * @throws ImportFatal nothing was imported (empty file, bad header, too many rows); `warnings` holds what was found before
     */
    public function run($handle, bool $dry, int $maxRows): ImportReport
    {
        $this->maxRows = $maxRows;
        $this->warnings = [];

        try {
            return $this->import($handle, $dry);
        } catch (ImportFatal $e) {
            $e->warnings = $this->warnings;

            throw $e;
        }
    }

    private function import($handle, bool $dry): ImportReport
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

            if (++$stats['read'] > $this->maxRows) {
                throw new ImportFatal('more than '.$this->maxRows.' data rows; nothing imported');
            }

            [$errors, $values, $key] = $fields === null
                ? [['row: line longer than '.self::MAX_RECORD_BYTES.' bytes'], null, null]
                : $this->validate($fields, $seen);

            if ($errors) {
                $stats['bad']++;
                if (count($bad) < self::MAX_LISTED) {
                    $bad[] = ['line' => $start, 'messages' => $errors];
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

        return new ImportReport($stats['read'], $stats['created'], $stats['updated'], $stats['bad'], $bad, $this->warnings);
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
            // Without an `active` column in the file a conflict update must not touch it (an insert still gets the default, true):
            // otherwise a file that says nothing about it would silently reactivate deactivated products.
            Product::upsert(array_values($chunk), ['part_number_search'], [
                'category_id', 'part_number', 'name', 'description', 'brand', 'inner_diameter_mm', 'outer_diameter_mm',
                'width_mm', 'material', 'seal_type', 'price_cents', 'price_on_request', 'stock_status',
                ...(in_array('active', $this->header, true) ? ['active'] : []),
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
            $this->warnings[] = 'unknown column "'.$this->show($unknown).'" ignored';
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
        if ($partNumber === '' || strlen($partNumber) > 64 || preg_match(ProductRules::PART_NUMBER_PATTERN, $partNumber) !== 1) {
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
            if (preg_match(ProductRules::FORMULA_PREFIX, ltrim((string) $rawValue, ' ')) === 1) {
                $fail($column, 'must not start with = + - @ or a tab/carriage return (spreadsheet formula)');
            }
        }

        foreach (['brand', 'material'] as $column) {
            if ($get($column) === '' || mb_strlen($get($column)) > 64 || preg_match(ProductRules::TEXT_PATTERN, $get($column)) !== 1) {
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
        return preg_replace('/[^ -~]/', '?', mb_strcut($value, 0, 64));
    }
}
