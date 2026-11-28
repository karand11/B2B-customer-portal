<?php

use App\Models\Product;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const SEC_IMPORT_HEADER = 'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,stock_status,price_cents,price_on_request,active,description';

beforeEach(function () {
    Storage::fake('imports');
    $this->staff = makeUser('staff');
    $this->make = fn (string $pn, array $attributes = []) => makeProduct($pn, $attributes + [
        'brand' => 'SKF', 'material' => 'Chrome steel', 'seal_type' => '2rs', 'inner_diameter_mm' => 10, 'outer_diameter_mm' => 30, 'width_mm' => 9,
        'stock_status' => 'in_stock', 'price_cents' => 100, 'price_on_request' => false, 'active' => true, 'description' => null,
    ]);
});

/** One CSV row as a string; every column can be overridden. */
function secImportRow(string $pn, array $o = []): string
{
    $v = $o + [
        'name' => "Bearing {$pn}", 'brand' => 'SKF', 'category' => 'deep-groove-ball', 'i' => '10', 'o' => '30', 'w' => '9', 'material' => 'Chrome steel',
        'seal' => '2rs', 'stock' => 'in_stock', 'price' => '100', 'por' => 'false', 'active' => 'true', 'description' => '',
    ];

    return implode(',', [$pn, $v['name'], $v['brand'], $v['category'], $v['i'], $v['o'], $v['w'], $v['material'], $v['seal'], $v['stock'], $v['price'], $v['por'], $v['active'], $v['description']]);
}

function secImportApply(User $staff, array $rows, string $name = 'catalog.csv')
{
    $csv = SEC_IMPORT_HEADER."\n".implode("\n", $rows)."\n";
    $service = app(ProductImportService::class);
    $import = $service->preview($staff, UploadedFile::fake()->createWithContent($name, $csv));

    return $service->apply($staff, $import->id, true);
}

it('writes one product.import_updated row per UPDATED product with a real difference, with exact before and after, and none for unchanged or created ones', function () {
    $changedPrice = ($this->make)('IA-1');
    $changedMany = ($this->make)('IA-2');
    $toRequest = ($this->make)('IA-3');
    $unchanged1 = ($this->make)('IA-4');
    $unchanged2 = ($this->make)('IA-5');

    $import = secImportApply($this->staff, [
        secImportRow('IA-1', ['price' => '250']),
        secImportRow('IA-2', ['name' => 'Renamed bearing', 'stock' => 'low_stock', 'brand' => 'FAG']),
        secImportRow('IA-3', ['por' => 'true', 'price' => '']),
        secImportRow('IA-4'),
        secImportRow('IA-5'),
        secImportRow('IA-NEW'),
    ]);

    $rows = auditLogRows('product.import_updated');
    expect($rows)->toHaveCount(3);

    $byPart = collect($rows)->keyBy('subject_label');
    $expect = [
        'IA-1' => [$changedPrice, ['price_cents' => 100], ['price_cents' => 250]],
        'IA-2' => [$changedMany, ['name' => 'Bearing IA-2', 'brand' => 'SKF', 'stock_status' => 'in_stock'], ['name' => 'Renamed bearing', 'brand' => 'FAG', 'stock_status' => 'low_stock']],
        'IA-3' => [$toRequest, ['price_cents' => 100, 'price_on_request' => false], ['price_cents' => null, 'price_on_request' => true]],
    ];
    foreach ($expect as $part => [$product, $before, $after]) {
        auditLogExpect($byPart[$part], $this->staff, 'product', (string) $product->id, null, ['before' => $before, 'after' => $after], ['import_id' => $import->id, 'file_name' => 'catalog.csv']);
    }

    $aggregate = auditLogOne('product.imported');
    expect($aggregate['metadata'])->toMatchArray(['created' => 1, 'updated' => 5, 'rows_read' => 6])
        ->and(auditLogRows())->toHaveCount(4)
        ->and(Product::where('part_number', 'IA-NEW')->exists())->toBeTrue();
});

it('audits a deactivation by the file and ignores `active` when the file has no such column', function () {
    $a = ($this->make)('IB-1');
    ($this->make)('IB-2', ['active' => false]);
    $header = 'part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,stock_status,price_cents';
    $service = app(ProductImportService::class);

    secImportApply($this->staff, [secImportRow('IB-1', ['active' => 'false'])]);
    expect(auditLogOne('product.import_updated')['changes'])->toEqual(['before' => ['active' => true], 'after' => ['active' => false]]);

    // A file without an `active` column leaves deactivated products deactivated: that is not a change either.
    $csv = $header."\nIB-2,Bearing IB-2,SKF,deep-groove-ball,10,30,9,Chrome steel,2rs,in_stock,100\n";
    $import = $service->preview($this->staff, UploadedFile::fake()->createWithContent('b.csv', $csv));
    $service->apply($this->staff, $import->id, false);

    expect(auditLogRows('product.import_updated'))->toHaveCount(1)->and(Product::where('part_number', 'IB-2')->value('active'))->toBeFalse();
});

it('does not audit a description-only change (the audited fields are the same) but still applies it', function () {
    ($this->make)('IC-1');

    secImportApply($this->staff, [secImportRow('IC-1', ['description' => 'New text'])]);

    expect(auditLogRows('product.import_updated'))->toBe([])->and(Product::where('part_number', 'IC-1')->value('description'))->toBe('New text');
});

it('inserts the rows in chunks of 500 inside the apply transaction', function () {
    DB::statement("INSERT INTO products (category_id, part_number, name, brand, inner_diameter_mm, outer_diameter_mm, width_mm, material, seal_type, price_cents, price_on_request, stock_status, active, created_at, updated_at)
        SELECT ?, 'BULK-' || g, 'Bulk ' || g, 'SKF', 10, 30, 9, 'Chrome steel', '2rs', 100, false, 'in_stock', true, now(), now() FROM generate_series(1, 1200) g", [category()->id]);
    $rows = array_map(fn (int $g) => secImportRow("BULK-{$g}", ['price' => '300']), range(1, 1200));
    $inserts = 0;
    DB::listen(function ($q) use (&$inserts) {
        str_starts_with($q->sql, 'insert into "audit_log"') && $inserts++;
    });

    secImportApply($this->staff, $rows);

    expect(DB::table('audit_log')->where('action', 'product.import_updated')->count())->toBe(1200)
        // 1200 rows = 500 + 500 + 200, plus the aggregate product.imported row.
        ->and($inserts)->toBe(4);
});

it('rolls the whole import back when the audit write fails: no product changes, the preview stays applicable', function () {
    $p = ($this->make)('ID-1');
    DB::statement("ALTER TABLE audit_log ADD CONSTRAINT sec_block_import_rows CHECK (action <> 'product.import_updated')");
    $service = app(ProductImportService::class);
    $csv = SEC_IMPORT_HEADER."\n".secImportRow('ID-1', ['price' => '999'])."\n".secImportRow('ID-NEW')."\n";
    $import = $service->preview($this->staff, UploadedFile::fake()->createWithContent('c.csv', $csv));

    expect(fn () => $service->apply($this->staff, $import->id, true))->toThrow(QueryException::class);

    expect($p->fresh()->price_cents)->toBe(100)->and(Product::where('part_number', 'ID-NEW')->exists())->toBeFalse()
        ->and($import->fresh()->status)->toBe('previewed')
        ->and(Storage::disk('imports')->exists($import->storage_path))->toBeTrue()
        ->and(DB::table('audit_log')->count())->toBe(0);
});

it('writes no per-product row for a dry-run preview or for the CLI import', function () {
    ($this->make)('IE-1');
    $csv = SEC_IMPORT_HEADER."\n".secImportRow('IE-1', ['price' => '777'])."\n";
    app(ProductImportService::class)->preview($this->staff, UploadedFile::fake()->createWithContent('d.csv', $csv));
    expect(auditLogRows())->toBe([]);

    $path = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, $csv);
    Artisan::call('products:import', ['file' => $path]);
    unlink($path);

    expect(Product::where('part_number', 'IE-1')->value('price_cents'))->toBe(777)->and(auditLogRows())->toBe([]);
});

it('shows the per-product rows in the audit viewer with the product as subject', function () {
    $p = ($this->make)('IF-1');
    secImportApply($this->staff, [secImportRow('IF-1', ['price' => '400'])]);

    $response = $this->actingAs(makeUser('admin'))->getJson('/api/v1/admin/audit-log?action=product.import_updated')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.subject'))->toBe(['type' => 'product', 'id' => (string) $p->id, 'label' => 'IF-1'])
        ->and($response->json('data.0.changes'))->toBe(['before' => ['price_cents' => 100], 'after' => ['price_cents' => 400]])
        ->and(array_keys($response->json('data.0.metadata')))->toBe(['import_id', 'file_name']);
});
