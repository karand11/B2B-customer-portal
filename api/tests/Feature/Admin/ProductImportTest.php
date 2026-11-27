<?php

use App\Models\Product;
use App\Models\ProductImport;
use App\Models\User;
use App\Support\CatalogCache;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const ADMIN_IMPORT_HEADER = 'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,price_cents,price_on_request,stock_status,active';

beforeEach(function () {
    Storage::fake('imports');
    $this->travelTo(Carbon::parse('2026-11-27 10:00:00', 'UTC'));
    $this->seed(CategorySeeder::class);
    $this->staff = makeUser('staff');
    $this->admin = makeUser('admin');
});

afterEach(fn () => $this->travelBack());

/** One quoted CSV data row with a valid default for every column. */
function productImportRow(array $overrides = []): string
{
    $row = array_replace([
        'part_number' => 'IMP-1', 'name' => 'Imported bearing', 'brand' => 'SKF', 'category' => 'deep-groove-ball', 'inner_diameter_mm' => '25',
        'outer_diameter_mm' => '52', 'width_mm' => '15', 'material' => 'Chrome steel', 'seal_type' => '2rs', 'price_cents' => '1250',
        'price_on_request' => '', 'stock_status' => 'in_stock', 'active' => 'true',
    ], $overrides);

    return implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $row));
}

function productImportCsv(array $rows, string $header = ADMIN_IMPORT_HEADER): string
{
    return $header."\n".implode("\n", $rows)."\n";
}

function productImportFile(string $content, string $name = 'catalog.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function productImportPreview(string $content, string $name = 'catalog.csv', ?User $as = null)
{
    return test()->actingAs($as ?? test()->staff)->post('/api/v1/admin/products/imports', ['file' => productImportFile($content, $name)]);
}

function productImportApply(string $id, array $body = [], ?User $as = null)
{
    return test()->actingAs($as ?? test()->staff)->postJson("/api/v1/admin/products/imports/{$id}/apply", $body);
}

/** @return list<string> the files on the fake imports disk */
function productImportFiles(): array
{
    return Storage::disk('imports')->allFiles();
}

it('previews a file: classifies every row, reports bad rows by line, writes no product and keeps the file privately', function () {
    makeProduct('IMP-2', ['price_cents' => 100]);
    CatalogCache::filters();
    $content = productImportCsv([
        productImportRow(['part_number' => 'IMP-1']),
        productImportRow(['part_number' => 'IMP-2', 'price_cents' => '200']),
        productImportRow(['part_number' => 'IMP-3', 'outer_diameter_mm' => '10']),
    ]);

    $response = productImportPreview($content)->assertCreated();
    $data = $response->json('data');

    expect(array_keys($data))->toBe(['id', 'file_name', 'size_bytes', 'status', 'expires_at', 'rows_read', 'would_create', 'would_update', 'bad_rows', 'errors', 'errors_truncated', 'warnings'])
        ->and($data)->toMatchArray([
            'file_name' => 'catalog.csv', 'size_bytes' => strlen($content), 'status' => 'previewed', 'expires_at' => '2026-11-27T11:00:00Z',
            'rows_read' => 3, 'would_create' => 1, 'would_update' => 1, 'bad_rows' => 1, 'errors_truncated' => false, 'warnings' => [],
        ])
        ->and($data['id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($data['errors'])->toBe([['line' => 4, 'messages' => ['outer_diameter_mm: must be greater than inner_diameter_mm']]]);

    // Nothing was written to the catalog and the cache was not touched.
    expect(Product::count())->toBe(1)->and(Product::first()->price_cents)->toBe(100)->and(Cache::has(CatalogCache::FILTERS_KEY))->toBeTrue()
        ->and(auditLogRows())->toBe([]);

    $import = ProductImport::findOrFail($data['id']);
    expect($import->user_id)->toBe($this->staff->id)->and($import->sha256)->toBe(hash('sha256', $content))->and($import->status)->toBe('previewed');
    expect(productImportFiles())->toBe([$import->storage_path])->and($import->storage_path)->toMatch('/^[0-9a-f]{32}$/');
});

it('keeps physical line numbers across a quoted multi-line field', function () {
    $content = ADMIN_IMPORT_HEADER."\n"
        .productImportRow(['part_number' => 'ML-1', 'name' => "Line one\nline two\nline three"])."\n"
        .productImportRow(['part_number' => 'ML-2', 'width_mm' => '0'])."\n";

    $errors = productImportPreview($content)->assertCreated()->json('data.errors');

    expect($errors)->toHaveCount(1)->and($errors[0]['line'])->toBe(5)->and($errors[0]['messages'][0])->toStartWith('width_mm:');
});

it('lists at most 100 bad rows and says so', function () {
    $rows = array_map(fn ($i) => productImportRow(['part_number' => "BAD-{$i}", 'width_mm' => '0']), range(1, 101));

    $data = productImportPreview(productImportCsv($rows))->assertCreated()->json('data');

    expect($data['bad_rows'])->toBe(101)->and($data['errors'])->toHaveCount(100)->and($data['errors_truncated'])->toBeTrue()
        ->and($data['errors'][0]['line'])->toBe(2)->and($data['errors'][99]['line'])->toBe(101);
});

it('warns about unknown columns and rejects spreadsheet formula prefixes as bad rows', function () {
    $header = ADMIN_IMPORT_HEADER.',colour';
    $content = productImportCsv([
        productImportRow(['part_number' => 'F-1', 'name' => '=SUM(A1)']).',red',
        productImportRow(['part_number' => 'F-2']).',blue',
    ], $header);

    $data = productImportPreview($content)->assertCreated()->json('data');

    expect($data['warnings'])->toBe(['unknown column "colour" ignored'])->and($data['bad_rows'])->toBe(1)
        ->and($data['errors'][0]['messages'][0])->toStartWith('name: must not start with');
});

it('rejects a bad upload with 422 on file and stores nothing', function (string $name, string $content, string $expected) {
    productImportPreview($content, $name)->assertStatus(422)->assertJsonValidationErrors('file')
        ->assertJsonPath('errors.file.0', fn ($m) => collect(explode('|', $expected))->contains(fn ($e) => str_contains($m, $e)));

    expect(ProductImport::count())->toBe(0)->and(productImportFiles())->toBe([]);
})->with([
    'wrong extension' => ['catalog.txt', "a,b\n1,2\n", 'Only .csv'],
    'no extension' => ['catalog', "a,b\n1,2\n", 'Only .csv'],
    'double extension' => ['catalog.csv.exe', "a,b\n1,2\n", 'Only .csv'],
    'html renamed' => ['catalog.csv', '<html><script>alert(1)</script></html>'.str_repeat(' ', 50), 'not CSV text'],
    'exe renamed' => ['catalog.csv', "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 200), 'not CSV text'],
    'zip renamed' => ['catalog.csv', "PK\x03\x04\x14\x00\x00\x00\x08\x00".str_repeat('z', 100), 'not CSV text'],
    'NUL byte' => ['catalog.csv', ADMIN_IMPORT_HEADER."\nvalue\0with nul\n", 'NUL|not CSV text'],
    'invalid UTF-8' => ['catalog.csv', ADMIN_IMPORT_HEADER."\nname \xB1\xB2 bad\n", 'UTF-8|not CSV text'],
    'empty' => ['catalog.csv', '', 'empty'],
    'header only missing columns' => ['catalog.csv', "part_number,name\nA-1,Name\n", 'missing required column'],
    'duplicate header' => ['catalog.csv', "part_number,part_number\nA-1,A-1\n", 'duplicate'],
]);

it('rejects UTF-16 text', function () {
    productImportPreview(mb_convert_encoding(ADMIN_IMPORT_HEADER."\n", 'UTF-16LE', 'UTF-8'))->assertStatus(422)->assertJsonValidationErrors('file');
});

it('accepts a UTF-8 file with a byte order mark', function () {
    productImportPreview("\xEF\xBB\xBF".productImportCsv([productImportRow()]))->assertCreated()->assertJsonPath('data.would_create', 1);
});

it('enforces the size and row limits from the config, with the contract defaults', function () {
    expect(config('admin.imports.max_rows'))->toBe(5000)->and(config('admin.imports.max_bytes'))->toBe(5 * 1024 * 1024);

    config(['admin.imports.max_rows' => 2]);
    $three = productImportCsv([productImportRow(['part_number' => 'R-1']), productImportRow(['part_number' => 'R-2']), productImportRow(['part_number' => 'R-3'])]);
    productImportPreview($three)->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'more than 2 data rows'));
    productImportPreview(productImportCsv([productImportRow(['part_number' => 'R-1']), productImportRow(['part_number' => 'R-2'])]))->assertCreated();

    config(['admin.imports.max_rows' => 5000, 'admin.imports.max_bytes' => 100]);
    productImportPreview(productImportCsv([productImportRow(), productImportRow(['part_number' => 'IMP-2'])]))->assertStatus(422)
        ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'must not be greater than'));
    expect(ProductImport::count())->toBe(1);
});

it('refuses a missing or non-file upload', function () {
    $this->actingAs($this->staff)->postJson('/api/v1/admin/products/imports', [])->assertStatus(422)->assertJsonValidationErrors('file');
    $this->postJson('/api/v1/admin/products/imports', ['file' => 'not a file'])->assertStatus(422)->assertJsonValidationErrors('file');
    $this->postJson('/api/v1/admin/products/imports', ['file' => ['a']])->assertStatus(422)->assertJsonValidationErrors('file');
});

it('applies only the previewed valid rows, once, and reports the real counts', function () {
    $existing = makeProduct('IMP-2', ['price_cents' => 100]);
    CatalogCache::filters();
    CatalogCache::categories();
    $content = productImportCsv([
        productImportRow(['part_number' => 'IMP-1']),
        productImportRow(['part_number' => 'IMP-2', 'price_cents' => '200', 'name' => 'Updated name']),
        productImportRow(['part_number' => 'IMP-3', 'outer_diameter_mm' => '10']),
    ]);
    $id = productImportPreview($content)->assertCreated()->json('data.id');
    $path = ProductImport::find($id)->storage_path;

    $response = productImportApply($id, ['skip_invalid' => true])->assertOk();

    expect($response->json('data'))->toBe(['id' => $id, 'status' => 'applied', 'rows_read' => 3, 'created' => 1, 'updated' => 1, 'bad_rows' => 1])
        ->and(Product::orderBy('id')->pluck('part_number')->all())->toBe(['IMP-2', 'IMP-1'])
        ->and($existing->fresh()->price_cents)->toBe(200)->and($existing->fresh()->name)->toBe('Updated name')->and($existing->fresh()->version)->toBe(2)
        ->and(Cache::has(CatalogCache::FILTERS_KEY))->toBeFalse()->and(Cache::has(CatalogCache::CATEGORIES_KEY))->toBeFalse();

    $import = ProductImport::find($id);
    expect($import->status)->toBe('applied')->and($import->storage_path)->toBeNull()->and($import->applied_at->toDateTimeString())->toBe('2026-11-27 10:00:00')
        ->and($import->result)->toBe(['created' => 1, 'updated' => 1, 'bad_rows' => 1]);
    Storage::disk('imports')->assertMissing($path);

    $row = auditLogOne('product.imported');
    // The file's display name and SHA-256 identify what was applied; its contents are never stored.
    auditLogExpect($row, $this->staff, 'import', $id, null, [], [
        'file_name' => 'catalog.csv', 'sha256' => $import->sha256, 'rows_read' => 3, 'created' => 1, 'updated' => 1, 'bad_rows' => 1, 'applied_rows' => 2, 'skipped_rows' => 1,
    ]);
    expect($row['subject_label'])->toBeNull()->and($import->sha256)->toMatch('/^[a-f0-9]{64}$/')->and(json_encode($row))->not->toContain('IMP-1')->not->toContain('Imported bearing');
});

it('lets an admin apply an import of an admin', function () {
    $id = productImportPreview(productImportCsv([productImportRow()]), 'catalog.csv', $this->admin)->assertCreated()->json('data.id');

    productImportApply($id, [], $this->admin)->assertOk()->assertJsonPath('data.created', 1);

    expect(auditLogOne('product.imported')['actor_role'])->toBe('admin');
});

it('requires skip_invalid when the preview had bad rows and nothing else', function () {
    $id = productImportPreview(productImportCsv([productImportRow(), productImportRow(['part_number' => 'IMP-3', 'width_mm' => '0'])]))->assertCreated()->json('data.id');

    productImportApply($id)->assertStatus(422)->assertJsonValidationErrors('skip_invalid');
    productImportApply($id, ['skip_invalid' => false])->assertStatus(422)->assertJsonValidationErrors('skip_invalid');
    productImportApply($id, ['skip_invalid' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('skip_invalid');
    expect(Product::count())->toBe(0)->and(ProductImport::find($id)->status)->toBe('previewed');

    productImportApply($id, ['skip_invalid' => true])->assertOk();
    expect(Product::count())->toBe(1);
});

it('applies a clean file without skip_invalid', function () {
    $id = productImportPreview(productImportCsv([productImportRow()]))->assertCreated()->json('data.id');

    productImportApply($id)->assertOk()->assertJsonPath('data.bad_rows', 0);
});

it('refuses a second apply of the same preview with 422 import', function () {
    $id = productImportPreview(productImportCsv([productImportRow()]))->assertCreated()->json('data.id');
    productImportApply($id)->assertOk();

    productImportApply($id)->assertStatus(422)->assertJsonPath('errors.import.0', 'This import was already applied.');

    expect(Product::count())->toBe(1)->and(auditLogRows('product.imported'))->toHaveCount(1);
});

it('is idempotent: applying two previews of the same data creates each product once', function () {
    $content = productImportCsv([productImportRow(['part_number' => 'ID-1']), productImportRow(['part_number' => 'ID-2'])]);
    $first = productImportPreview($content)->assertCreated()->json('data.id');
    $second = productImportPreview($content)->assertCreated()->json('data.id');

    productImportApply($first)->assertOk()->assertJsonPath('data.created', 2);
    productImportApply($second)->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.updated', 2);

    expect(Product::count())->toBe(2);
});

it('answers 404 for another users token, an unknown token and a malformed one', function () {
    $id = productImportPreview(productImportCsv([productImportRow()]))->assertCreated()->json('data.id');

    productImportApply($id, [], $this->admin)->assertNotFound()->assertExactJson(NOT_FOUND);
    productImportApply('3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90')->assertNotFound()->assertExactJson(NOT_FOUND);
    productImportApply('not-a-uuid')->assertNotFound()->assertExactJson(NOT_FOUND);
    productImportApply(strtoupper($id))->assertNotFound();

    expect(Product::count())->toBe(0)->and(ProductImport::find($id)->status)->toBe('previewed');
});

it('refuses to apply an expired preview', function () {
    $id = productImportPreview(productImportCsv([productImportRow()]))->assertCreated()->json('data.id');

    $this->travelTo(Carbon::parse('2026-11-27 10:59:59', 'UTC'));
    productImportApply($id)->assertOk();

    $other = productImportPreview(productImportCsv([productImportRow(['part_number' => 'EX-1'])]))->assertCreated()->json('data.id');
    $this->travelTo(Carbon::parse('2026-11-27 12:00:01', 'UTC'));
    productImportApply($other)->assertStatus(422)->assertJsonPath('errors.import.0', 'This preview has expired. Upload the file again.');
    expect(Product::pluck('part_number')->all())->toBe(['IMP-1']);
});

it('refuses to apply a file that changed on disk after the preview', function () {
    $id = productImportPreview(productImportCsv([productImportRow()]))->assertCreated()->json('data.id');
    $path = ProductImport::find($id)->storage_path;
    Storage::disk('imports')->put($path, productImportCsv([productImportRow(['part_number' => 'TAMPERED', 'price_cents' => '1'])]));

    productImportApply($id)->assertStatus(422)->assertJsonValidationErrors('import');

    expect(Product::count())->toBe(0)->and(ProductImport::find($id)->status)->toBe('previewed');
});

it('refuses to apply when the stored file is gone', function () {
    $id = productImportPreview(productImportCsv([productImportRow()]))->assertCreated()->json('data.id');
    Storage::disk('imports')->delete(ProductImport::find($id)->storage_path);

    productImportApply($id)->assertStatus(422)->assertJsonValidationErrors('import');
});

it('leaves the import previewed, the catalog and the file untouched when the apply fails', function () {
    $id = productImportPreview(productImportCsv([productImportRow(['name' => 'Boom'])]))->assertCreated()->json('data.id');
    DB::statement("ALTER TABLE products ADD CONSTRAINT products_tmp_refuse CHECK (name <> 'Boom')");
    CatalogCache::filters();

    productImportApply($id)->assertStatus(500)->assertExactJson(['message' => 'Server Error']);

    $import = ProductImport::find($id);
    expect($import->status)->toBe('previewed')->and($import->applied_at)->toBeNull()->and(Product::count())->toBe(0)->and(auditLogRows())->toBe([])
        ->and(Cache::has(CatalogCache::FILTERS_KEY))->toBeTrue();
    Storage::disk('imports')->assertExists($import->storage_path);
});

it('expires old previews and deletes their files with imports:prune, and leaves applied and fresh ones alone', function () {
    $old = productImportPreview(productImportCsv([productImportRow(['part_number' => 'PR-1'])]))->assertCreated()->json('data.id');
    $applied = productImportPreview(productImportCsv([productImportRow(['part_number' => 'PR-2'])]))->assertCreated()->json('data.id');
    productImportApply($applied)->assertOk();
    $oldPath = ProductImport::find($old)->storage_path;

    $this->travelTo(Carbon::parse('2026-11-27 11:30:00', 'UTC'));
    $fresh = productImportPreview(productImportCsv([productImportRow(['part_number' => 'PR-3'])]))->assertCreated()->json('data.id');
    $freshPath = ProductImport::find($fresh)->storage_path;

    $this->artisan('imports:prune')->assertSuccessful();

    expect(ProductImport::find($old)->status)->toBe('expired')->and(ProductImport::find($old)->storage_path)->toBeNull()
        ->and(ProductImport::find($applied)->status)->toBe('applied')->and(ProductImport::find($fresh)->status)->toBe('previewed');
    Storage::disk('imports')->assertMissing($oldPath);
    Storage::disk('imports')->assertExists($freshPath);
    productImportApply($old)->assertStatus(422)->assertJsonPath('errors.import.0', 'This preview has expired. Upload the file again.');
});

it('counts every upload attempt, 422s included, against 5 per minute and 20 per hour', function () {
    $attempt = fn () => $this->actingAs($this->staff)->postJson('/api/v1/admin/products/imports', []);

    foreach (range(1, 5) as $i) {
        $attempt()->assertStatus(422);
    }
    $attempt()->assertStatus(429)->assertHeader('Retry-After');

    foreach (range(1, 3) as $batch) {
        $this->travelTo(now()->addSeconds(61));
        foreach (range(1, 5) as $i) {
            $attempt()->assertStatus(422);
        }
    }
    // 20 attempts so far in this hour: the 21st is refused even though the minute window is fresh.
    $this->travelTo(now()->addSeconds(61));
    $attempt()->assertStatus(429);
});

it('puts the role check before the import limiter', function () {
    $customer = makeUser('customer');

    foreach (range(1, 8) as $i) {
        $this->actingAs($customer)->postJson('/api/v1/admin/products/imports', [])->assertForbidden();
    }
});

it('keeps a deactivated product deactivated when the uploaded CSV has no active column (staff, over HTTP)', function () {
    $off = makeProduct('IMP-OFF', ['active' => false]);
    $header = str_replace(',active', '', ADMIN_IMPORT_HEADER);
    $row = preg_replace('/,"true"$/', '', productImportRow(['part_number' => 'IMP-OFF', 'name' => 'Changed by import']));

    $id = productImportPreview(productImportCsv([$row], $header))->assertCreated()->json('data.id');
    productImportApply($id)->assertOk()->assertJsonPath('data.updated', 1);

    expect($off->fresh()->active)->toBeFalse()->and($off->fresh()->name)->toBe('Changed by import');

    // With the column the file decides.
    $id = productImportPreview(productImportCsv([productImportRow(['part_number' => 'IMP-OFF', 'active' => 'true'])]))->assertCreated()->json('data.id');
    productImportApply($id)->assertOk();
    expect($off->fresh()->active)->toBeTrue();
});
