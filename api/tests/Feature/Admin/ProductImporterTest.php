<?php

use App\Models\Product;
use App\Services\ImportFatal;
use App\Services\ImportReport;
use App\Services\ProductImporter;
use Database\Seeders\CategorySeeder;

beforeEach(fn () => $this->seed(CategorySeeder::class));

/** A readable stream over a CSV string (the importer takes a stream, never a path). */
function productImporterStream(string $csv)
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);

    return $stream;
}

function productImporterCsv(array $rows, string $extraHeader = ''): string
{
    $header = 'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,price_cents,stock_status'.$extraHeader;

    return $header."\n".implode("\n", $rows)."\n";
}

function productImporterRow(string $part, string $overrides = ''): string
{
    return "{$part},Name {$part},SKF,deep-groove-ball,25,52,15,Steel,open,100,in_stock{$overrides}";
}

it('classifies without writing in a dry run', function () {
    makeProduct('PI-1');
    $csv = productImporterCsv([productImporterRow('PI-1'), productImporterRow('PI-2'), productImporterRow('PI-3').'x,y']);

    $report = app(ProductImporter::class)->run(productImporterStream($csv), true, 100);

    expect($report)->toBeInstanceOf(ImportReport::class)->and([$report->read, $report->created, $report->updated, $report->bad])->toBe([3, 1, 1, 1])
        ->and($report->errors)->toHaveCount(1)->and($report->errors[0]['line'])->toBe(4)->and($report->errors[0]['messages'][0])->toStartWith('row: expected 11 columns')
        ->and(Product::count())->toBe(1);
});

it('upserts by normalised part number when not a dry run', function () {
    $existing = makeProduct('PI-1', ['name' => 'Old']);
    $csv = productImporterCsv([productImporterRow('pi 1'), productImporterRow('PI-2')]);

    $report = app(ProductImporter::class)->run(productImporterStream($csv), false, 100);

    expect([$report->created, $report->updated])->toBe([1, 1])->and(Product::count())->toBe(2)->and($existing->fresh()->name)->toBe('Name pi 1')->and($existing->fresh()->version)->toBe(2);
});

it('returns unknown column warnings in the report', function () {
    $report = app(ProductImporter::class)->run(productImporterStream(productImporterCsv([productImporterRow('PI-1', ',red')], ',colour')), true, 100);

    expect($report->warnings)->toBe(['unknown column "colour" ignored']);
});

it('stops at the row limit with a fatal that still carries the warnings found before it', function () {
    $csv = productImporterCsv([productImporterRow('PI-1', ',red'), productImporterRow('PI-2', ',red'), productImporterRow('PI-3', ',red')], ',colour');

    try {
        app(ProductImporter::class)->run(productImporterStream($csv), false, 2);
        $this->fail('expected ImportFatal');
    } catch (ImportFatal $e) {
        expect($e->getMessage())->toBe('more than 2 data rows; nothing imported')->and($e->warnings)->toBe(['unknown column "colour" ignored']);
    }
});

it('refuses an empty file and a header with missing columns', function (string $csv, string $message) {
    expect(fn () => app(ProductImporter::class)->run(productImporterStream($csv), true, 100))->toThrow(ImportFatal::class, $message);
})->with([['', 'file is empty'], ["part_number,name\n", 'missing required column(s)'], ["part_number,part_number\n", 'duplicate']]);

it('lists at most 100 bad rows but counts them all', function () {
    $rows = array_map(fn ($i) => productImporterRow("BAD-{$i}").',extra', range(1, 130));

    $report = app(ProductImporter::class)->run(productImporterStream(productImporterCsv($rows)), true, 1000);

    expect($report->bad)->toBe(130)->and($report->errors)->toHaveCount(ProductImporter::MAX_LISTED);
});

it('is the same code the command uses: identical validation messages', function () {
    $bad = str_replace(',Steel,open,100', ',Steel,wooden,100', productImporterRow('X-2'));

    $report = app(ProductImporter::class)->run(productImporterStream(productImporterCsv([productImporterRow('X-1'), $bad])), true, 10);

    expect($report->errors[0]['messages'])->toBe(['seal_type: must be one of open, 2rs, zz']);
});

it('keeps a deactivated product deactivated when the CSV has no active column, and still changes it when it has one', function () {
    $off = makeProduct('PI-OFF', ['active' => false]);
    $row = productImporterRow('PI-OFF');

    $report = app(ProductImporter::class)->run(productImporterStream(productImporterCsv([$row, productImporterRow('PI-NEW')])), false, 100);

    // No `active` header: an update leaves it alone, an insert keeps the default (true).
    expect([$report->created, $report->updated])->toBe([1, 1])->and($off->fresh()->active)->toBeFalse()->and($off->fresh()->name)->toBe('Name PI-OFF')
        ->and(Product::where('part_number', 'PI-NEW')->value('active'))->toBeTrue();

    app(ProductImporter::class)->run(productImporterStream(productImporterCsv([productImporterRow('PI-OFF', ',true')], ',active')), false, 100);
    expect($off->fresh()->active)->toBeTrue();

    app(ProductImporter::class)->run(productImporterStream(productImporterCsv([productImporterRow('PI-OFF', ',false')], ',active')), false, 100);
    expect($off->fresh()->active)->toBeFalse();
});
