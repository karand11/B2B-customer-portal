<?php

use App\Models\Product;
use Database\Seeders\CategorySeeder;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

const IMPORT_HEADER = 'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,price_cents,price_on_request,stock_status,active';

beforeEach(function () {
    $this->seed(CategorySeeder::class);
    $GLOBALS['import_files'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['import_files'] as $file) {
        @unlink($file);
    }
});

/** One quoted CSV data row; every column has a valid default so tests override only what they test. */
function importRow(array $overrides = []): string
{
    $row = array_replace([
        'part_number' => '6205-2RS', 'name' => 'Deep groove ball bearing 6205-2RS', 'brand' => 'SKF',
        'category' => 'deep-groove-ball', 'inner_diameter_mm' => '25', 'outer_diameter_mm' => '52', 'width_mm' => '15',
        'material' => 'Chrome steel', 'seal_type' => '2rs', 'price_cents' => '1250', 'price_on_request' => '',
        'stock_status' => 'in_stock', 'active' => 'true',
    ], $overrides);

    return implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $row));
}

/** Write a temp CSV (never real data) and return its path. */
function csvFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, $content);
    $GLOBALS['import_files'][] = $path;

    return $path;
}

/** @return array{int, string} exit code and output */
function runImport(string $path, bool $dry = false): array
{
    $code = Artisan::call('products:import', ['file' => $path] + ($dry ? ['--dry-run' => true] : []));

    return [$code, Artisan::output()];
}

function importFile(array $rows, string $header = IMPORT_HEADER): string
{
    return csvFile($header."\n".implode("\n", $rows)."\n");
}

function snapshot(): array
{
    return Product::orderBy('part_number_search')->get()->map->only([
        'id', 'part_number', 'name', 'brand', 'category_id', 'inner_diameter_mm', 'outer_diameter_mm', 'width_mm', 'material',
        'seal_type', 'price_cents', 'price_on_request', 'stock_status', 'active', 'description',
    ])->all();
}

it('creates the example rows and is idempotent on a second run', function () {
    $path = csvFile(IMPORT_HEADER."\n"
        .'6205-2RS,Deep groove ball bearing 6205-2RS,SKF,deep-groove-ball,25,52,15,Chrome steel,2rs,1250,,in_stock,true'."\n"
        .'NU206,Cylindrical roller bearing NU206,FAG,cylindrical-roller,30,62,16,Chrome steel,open,,true,on_order,true'."\n");

    [$code, $out] = runImport($path);
    $first = snapshot();

    expect($code)->toBe(0)->and($out)->toContain('Rows read: 2, created: 2, updated: 0')->and($out)->toContain('bad: 0')
        ->and($first)->toHaveCount(2)
        ->and($first[0]['part_number'])->toBe('6205-2RS')
        ->and($first[0]['price_cents'])->toBe(1250)
        ->and($first[1]['price_cents'])->toBeNull()->and($first[1]['price_on_request'])->toBeTrue();

    [$code, $out] = runImport($path);

    expect($code)->toBe(0)->and($out)->toContain('created: 0, updated: 2')
        ->and(snapshot())->toBe($first)->and(Product::count())->toBe(2);
});

it('reports every kind of bad row with its line, imports the valid rows and exits 1', function () {
    $path = importFile([
        importRow(['part_number' => 'OK-1']),                                              // line 2 good
        importRow(['part_number' => 'BAD-DIM', 'inner_diameter_mm' => '50', 'outer_diameter_mm' => '40']), // 3
        importRow(['part_number' => 'BAD-CAT', 'category' => 'tapered-rolller']),          // 4
        importRow(['part_number' => 'BAD-PRICE', 'price_cents' => '12.50']),               // 5
        importRow(['part_number' => 'BAD-BOTH', 'price_on_request' => 'true']),            // 6 price conflict
        importRow(['part_number' => 'BAD-NONE', 'price_cents' => '']),                     // 7 neither price
        importRow(['part_number' => 'BAD-SEAL', 'seal_type' => 'rubber']),                 // 8
        importRow(['part_number' => 'BAD-STOCK', 'stock_status' => 'gone']),               // 9
        importRow(['part_number' => '', 'name' => '']),                                    // 10 missing required
        importRow(['part_number' => 'ok-1']),                                              // 11 duplicate of line 2
        importRow(['part_number' => 'OK-2']),                                              // 12 good
    ]);

    [$code, $out] = runImport($path);

    expect($code)->toBe(1)
        ->and($out)->toContain('line 3: outer_diameter_mm: must be greater than inner_diameter_mm')
        ->and($out)->toContain('line 4: category: unknown category "tapered-rolller"')
        ->and($out)->toContain('line 5: price_cents: must be a non-negative integer; got "12.50"')
        ->and($out)->toContain('line 6: price_cents: must be empty when price_on_request is true')
        ->and($out)->toContain('line 7: price_cents: required unless price_on_request is true')
        ->and($out)->toContain('line 8: seal_type: must be one of open, 2rs, zz')
        ->and($out)->toContain('line 9: stock_status: must be one of')
        ->and($out)->toContain('line 10: part_number: required')
        ->and($out)->toContain('name: required')
        ->and($out)->toContain('line 11: part_number: duplicate of line 2 (OK-1)')
        ->and($out)->toContain('Rows read: 11, created: 2, updated: 0, unchanged-or-skipped: 0, bad: 9')
        ->and(Product::orderBy('part_number')->pluck('part_number')->all())->toBe(['OK-1', 'OK-2']);
});

it('updates the same row on a later file', function () {
    runImport(importFile([importRow(['price_cents' => '1000', 'stock_status' => 'in_stock'])]));
    $id = Product::value('id');

    [$code, $out] = runImport(importFile([importRow(['price_cents' => '1500', 'stock_status' => 'low_stock', 'name' => 'Renamed'])]));

    expect($code)->toBe(0)->and($out)->toContain('created: 0, updated: 1')
        ->and(Product::count())->toBe(1)
        ->and(Product::first()->only(['id', 'price_cents', 'stock_status', 'name']))->toBe(
            ['id' => $id, 'price_cents' => 1500, 'stock_status' => 'low_stock', 'name' => 'Renamed']);
});

it('treats a differently formatted duplicate (6205-2rs after 6205-2RS) as a bad row', function () {
    [$code, $out] = runImport(importFile([importRow(['part_number' => '6205-2RS']), importRow(['part_number' => '6205 2rs'])]));

    expect($code)->toBe(1)->and($out)->toContain('line 3: part_number: duplicate of line 2')->and(Product::count())->toBe(1);
});

it('writes nothing in a dry run but reports the same bad rows', function () {
    $path = importFile([importRow(['part_number' => 'D-1']), importRow(['part_number' => 'D-BAD', 'category' => 'nope']), importRow(['part_number' => 'D-2'])]);

    [$code, $out] = runImport($path, dry: true);

    expect($code)->toBe(1)->and(Product::count())->toBe(0)
        ->and($out)->toContain('line 3: category: unknown category "nope"')
        ->and($out)->toContain('DRY RUN: Rows read: 3, would create: 2, would update: 0, bad: 1');

    runImport($path);
    [, $out] = runImport($path, dry: true);
    expect($out)->toContain('would create: 0, would update: 2');
});

it('does not flush caches in a dry run', function () {
    cache()->put('catalog:filters', ['x'], 600);

    runImport(importFile([importRow()]), dry: true);

    expect(cache()->has('catalog:filters'))->toBeTrue();
});

it('exits 2 and imports nothing on fatal problems', function (string $content, string $message) {
    [$code, $out] = runImport(csvFile($content));

    expect($code)->toBe(2)->and($out)->toContain($message)->and(Product::count())->toBe(0);
})->with([
    'empty file' => ['', 'file is empty'],
    'only blank lines' => ["\n\n", 'file is empty'],
    'missing required column' => [str_replace('brand,', '', IMPORT_HEADER)."\n6205,x,deep-groove-ball,25,52,15,m,open,1,,in_stock,true\n", 'missing required column(s): brand'],
    'no price column at all' => [str_replace(['price_cents,', 'price_on_request,'], '', IMPORT_HEADER)."\n", 'price_cents or price_on_request'],
    'duplicate header column' => [IMPORT_HEADER.",brand\n", 'duplicate columns'],
    'invalid utf-8 header' => ["part_number,\xFF,brand\n", 'not valid UTF-8'],
]);

it('exits 2 for a missing file or a directory', function () {
    expect(runImport('/definitely/not/here.csv')[0])->toBe(2)
        ->and(runImport(sys_get_temp_dir())[0])->toBe(2);
});

it('exits 2 and rolls everything back above 100,000 data rows', function () {
    $content = IMPORT_HEADER."\n".importRow(['part_number' => 'FIRST'])."\n".str_repeat("x\n", 100000);

    [$code, $out] = runImport(csvFile($content));

    expect($code)->toBe(2)->and($out)->toContain('100000 data rows')->and(Product::count())->toBe(0);
});

it('accepts a BOM, CRLF line endings, any column order, blank lines and quoted commas', function () {
    $content = "\xEF\xBB\xBF"."Part_Number,NAME,brand,category,width_mm,outer_diameter_mm,inner_diameter_mm,material,seal_type,price_cents,stock_status\r\n"
        ."\r\n"
        .'6205-2RS,"Bearing, deep groove ""special""",SKF,deep-groove-ball,15,52,25,Chrome steel,2RS,1250,IN_STOCK'."\r\n"
        .'6206,Plain,NSK,deep-groove-ball,16,62,30,Chrome steel,open,2000,low_stock';

    [$code, $out] = runImport(csvFile($content));
    $p = Product::where('part_number', '6205-2RS')->first();

    expect($code)->toBe(0)->and($out)->toContain('Rows read: 2, created: 2')
        ->and($p->name)->toBe('Bearing, deep groove "special"')
        ->and($p->seal_type)->toBe('2rs')->and($p->stock_status)->toBe('in_stock')->and($p->active)->toBeTrue()
        ->and((float) $p->width_mm)->toBe(15.0);
});

it('counts physical lines correctly across quoted multi-line fields', function () {
    $path = csvFile(IMPORT_HEADER."\n"
        .'6205,"Line one'."\n".'line two",SKF,deep-groove-ball,25,52,15,Chrome steel,open,100,,in_stock,true'."\n"
        .'6206,Bad,SKF,nope,25,52,15,Chrome steel,open,100,,in_stock,true'."\n");

    [$code, $out] = runImport($path);

    expect($code)->toBe(1)->and($out)->toContain('line 4: category')->and(Product::count())->toBe(1);
});

it('rejects bad numbers, booleans, columns counts and control characters per row', function (array $row, string $message) {
    [$code, $out] = runImport(importFile([importRow($row)]));

    expect($code)->toBe(1)->and($out)->toContain($message)->and(Product::count())->toBe(0);
})->with([
    'negative price' => [['price_cents' => '-1'], 'price_cents: must be a non-negative integer; got "-1"'],
    'price above the cap' => [['price_cents' => '1000000001'], 'price_cents: must be at most 1000000000'],
    'price 18 digits' => [['price_cents' => '999999999999999999'], 'price_cents: must be at most 1000000000'],
    'text price' => [['price_cents' => 'abc'], 'price_cents: must be a non-negative integer; got "abc"'],
    'currency price' => [['price_cents' => '$12'], 'price_cents: must be a non-negative integer'],
    'thousands price' => [['price_cents' => '1,250'], 'price_cents: must be a non-negative integer'],
    'bad boolean' => [['price_on_request' => 'maybe', 'price_cents' => ''], 'price_on_request: must be true/false/1/0/yes/no or empty'],
    'bad active' => [['active' => 'maybe'], 'active: must be true/false/1/0/yes/no or empty'],
    'three decimals' => [['width_mm' => '1.234'], 'width_mm: must be a number above 0'],
    'zero width' => [['width_mm' => '0'], 'width_mm: must be a number above 0'],
    'huge width' => [['width_mm' => '5001'], 'width_mm: must be a number above 0'],
    'comma decimal' => [['width_mm' => '1,5'], 'width_mm: must be a number above 0'],
    'bad part number' => [['part_number' => '-LEADING'], 'part_number: required'],
    'part number with symbol' => [['part_number' => 'A$B'], 'part_number: required'],
    'brand with comma' => [['brand' => 'SKF,NSK'], 'brand: required'],
    'brand too long' => [['brand' => str_repeat('a', 65)], 'brand: required'],
    'nul byte' => [['name' => "a\0b"], 'row: must be valid UTF-8 without NUL bytes'],
    'invalid utf-8' => [['name' => "a\xFFb"], 'row: must be valid UTF-8 without NUL bytes'],
]);

it('lists several problems of one row on one line joined by semicolons', function () {
    [, $out] = runImport(importFile([importRow(['category' => 'nope', 'seal_type' => 'x'])]));

    expect($out)->toContain('line 2: category: unknown category "nope"; seal_type: must be one of');
});

it('reports a wrong number of columns', function () {
    [$code, $out] = runImport(csvFile(IMPORT_HEADER."\n6205,only,three\n"));

    expect($code)->toBe(1)->and($out)->toContain('line 2: row: expected 13 columns, got 3');
});

it('warns about unknown columns and ignores them', function () {
    $path = csvFile(IMPORT_HEADER.",colour\n".importRow().",red\n");

    [$code, $out] = runImport($path);

    expect($code)->toBe(0)->and($out)->toContain('warning: unknown column "colour" ignored')->and(Product::count())->toBe(1);
});

it('never deletes products missing from the file; active=false deactivates and active=true reactivates', function () {
    makeProduct('KEEP-ME', ['active' => true]);
    runImport(importFile([importRow(['part_number' => 'A-1'])]));

    runImport(importFile([importRow(['part_number' => 'A-1', 'active' => 'false'])]));
    expect(Product::where('part_number', 'A-1')->value('active'))->toBeFalse()
        ->and(Product::where('part_number', 'KEEP-ME')->exists())->toBeTrue()
        ->and(Product::where('part_number', 'KEEP-ME')->value('active'))->toBeTrue();

    runImport(importFile([importRow(['part_number' => 'A-1', 'active' => 'yes'])]));
    expect(Product::where('part_number', 'A-1')->value('active'))->toBeTrue();
});

it('flushes the filters cache so a new brand shows up', function () {
    makeProduct('SEED-1', ['brand' => 'SKF']);
    loginAs(makeUser());
    $this->getJson('/api/v1/products/filters')->assertJsonPath('data.brands', ['SKF']);

    runImport(importFile([importRow(['part_number' => 'NEW-1', 'brand' => 'Timken'])]));

    $this->getJson('/api/v1/products/filters')->assertJsonPath('data.brands', ['SKF', 'Timken']);
    $this->getJson('/api/v1/categories')->assertJsonPath('data.0.product_count', 2);
});

it('lists at most 100 bad rows but reports the total', function () {
    $rows = array_map(fn ($i) => importRow(['part_number' => "BAD-{$i}", 'category' => 'nope']), range(1, 105));

    [$code, $out] = runImport(importFile($rows));

    expect($code)->toBe(1)->and(substr_count($out, 'category: unknown category'))->toBe(100)
        ->and($out)->toContain('bad: 105 (first 100 listed)');
});

it('does not interpret console markup or control characters from the file in its output', function () {
    [, $out] = runImport(importFile([importRow(['category' => "<error>x</error>\x1b[31m"])]));

    expect($out)->toContain('unknown category "<error>x</error>?[31m"');
});

it('imports a product that the API then serves with the computed price', function () {
    runImport(importFile([importRow(['part_number' => 'API-1', 'price_cents' => '9900'])]));
    loginAs(makeUser());

    $this->getJson('/api/v1/products?q=api1')->assertOk()->assertJsonPath('data.0.price_cents', 9900);
});

it('rejects spreadsheet formula prefixes in name and description', function (string $value) {
    $path = csvFile(IMPORT_HEADER.",description\n".importRow(['part_number' => 'F-1', 'name' => $value]).",fine\n"
        .importRow(['part_number' => 'F-2']).','.'"'.str_replace('"', '""', $value).'"'."\n"
        .importRow(['part_number' => 'F-3']).",ok\n");

    [$code, $out] = runImport($path);

    expect($code)->toBe(1)
        ->and($out)->toContain('line 2: name: must not start with')
        ->and($out)->toContain('line 3: description: must not start with')
        ->and($out)->toContain('bad: 2')
        ->and(Product::pluck('part_number')->all())->toBe(['F-3']);
})->with(['=1+1', '+1', '-1', '@SUM(A1)', "\t=1", "\r=1", ' =1']);

it('still accepts ordinary names and descriptions with inner symbols', function () {
    $path = csvFile(IMPORT_HEADER.",description\n".importRow(['name' => 'Bearing 6205 - 2RS = good']).",\"Use + care @ home\"\n");

    expect(runImport($path)[0])->toBe(0);
});

it('requires brand and material to start with a letter or digit', function (string $column, string $value) {
    [$code, $out] = runImport(importFile([importRow([$column => $value])]));

    expect($code)->toBe(1)->and($out)->toContain("{$column}: required")->and(Product::count())->toBe(0);
})->with([['brand', '=SKF'], ['brand', '+SKF'], ['brand', '-SKF'], ['brand', '@SKF'], ['brand', '.SKF'], ['material', '=Steel'], ['material', '-Steel'], ['material', '@x']]);

it('flushes the catalog caches only after the import transaction has committed', function () {
    $levels = [];
    $base = DB::transactionLevel();
    Event::listen(ForgettingKey::class, function ($e) use (&$levels) {
        if (str_starts_with($e->key, 'catalog:')) {
            $levels[$e->key] = DB::transactionLevel();
        }
    });

    runImport(importFile([importRow()]));

    expect($levels)->toBe(['catalog:filters' => $base, 'catalog:categories' => $base]);
});

it('does not flush caches when the import is fatal', function () {
    cache()->put('catalog:filters', ['x'], 600);

    runImport(csvFile(''));

    expect(cache()->has('catalog:filters'))->toBeTrue();
});

it('treats a single over-long line as a bad row and keeps importing', function () {
    $huge = str_repeat('a', 1_200_000);
    $path = csvFile(IMPORT_HEADER."\n"
        .importRow(['part_number' => 'L-1'])."\n"
        .importRow(['part_number' => 'L-2', 'name' => $huge])."\n"
        .importRow(['part_number' => 'L-3'])."\n");

    [$code, $out] = runImport($path);

    expect($code)->toBe(1)
        ->and($out)->toContain('line 3: row: line longer than 1000000 bytes')
        ->and($out)->toContain('Rows read: 3, created: 2, updated: 0, unchanged-or-skipped: 0, bad: 1')
        ->and(Product::orderBy('part_number')->pluck('part_number')->all())->toBe(['L-1', 'L-3']);
});

it('treats an over-long header line as fatal', function () {
    [$code, $out] = runImport(csvFile(IMPORT_HEADER.','.str_repeat('x', 1_100_000)."\n".importRow()."\n"));

    expect($code)->toBe(2)->and($out)->toContain('header line longer than 1000000 bytes')->and(Product::count())->toBe(0);
});

it('caps a runaway quoted field instead of reading the whole file', function () {
    $path = csvFile(IMPORT_HEADER."\n".'"unterminated,'.str_repeat("filler line\n", 120000));

    [$code, $out] = runImport($path);

    expect($code)->toBe(1)->and($out)->toContain('line 2: row: line longer than 1000000 bytes');
});

it('accepts a price of exactly the cap and reports the line of a price above it', function () {
    [$code] = runImport(importFile([importRow(['part_number' => 'AT-CAP', 'price_cents' => '1000000000'])]));
    expect($code)->toBe(0)->and(Product::where('part_number', 'AT-CAP')->value('price_cents'))->toBe(Product::MAX_PRICE_CENTS);

    [$code, $out] = runImport(importFile([importRow(['part_number' => 'OK-1']), importRow(['part_number' => 'OVER-1', 'price_cents' => '1000000001'])]));
    expect($code)->toBe(1)->and($out)->toContain('line 3: price_cents: must be at most 1000000000')->and(Product::where('part_number', 'OVER-1')->exists())->toBeFalse();
});
