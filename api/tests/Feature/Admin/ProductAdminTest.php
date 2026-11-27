<?php

use App\Models\Product;
use App\Models\QuoteRequest;
use App\Support\CatalogCache;
use App\Support\Mm;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->staff = makeUser('staff');
    $this->admin = makeUser('admin');
    $this->category = category();
});

function adminCreateProduct(array $overrides = [], ?string $role = 'staff')
{
    return test()->actingAs(makeUser($role))->postJson('/api/v1/admin/products', adminProductBody($overrides));
}

/** PUT as staff: the body is the product's CURRENT values plus the overrides, and its current version (unless given). */
function adminUpdateProduct(Product $product, array $overrides = [], ?int $version = null)
{
    $p = $product->fresh();
    $same = [
        'category_id' => $p->category_id, 'part_number' => $p->part_number, 'name' => $p->name, 'description' => $p->description, 'brand' => $p->brand,
        'inner_diameter_mm' => Mm::number($p->inner_diameter_mm), 'outer_diameter_mm' => Mm::number($p->outer_diameter_mm), 'width_mm' => Mm::number($p->width_mm),
        'material' => $p->material, 'seal_type' => $p->seal_type, 'stock_status' => $p->stock_status, 'price_on_request' => $p->price_on_request, 'price_cents' => $p->price_cents,
    ];

    return test()->actingAs(makeUser('staff'))->putJson("/api/v1/admin/products/{$p->id}", array_replace($same, $overrides) + ['version' => $version ?? $p->version]);
}

it('creates a product for staff and admin and returns exactly the contract keys', function (string $role) {
    $response = adminCreateProduct(['description' => "Line one\r\nLine two"], $role)->assertCreated();

    expect(array_keys($response->json('data')))->toBe(ADMIN_PRODUCT_KEYS)
        ->and($response->json('data'))->toMatchArray([
            'part_number' => '6205-2RS', 'description' => "Line one\nLine two", 'inner_diameter_mm' => 25, 'outer_diameter_mm' => 52, 'width_mm' => 15,
            'price_cents' => 1250, 'price_on_request' => false, 'active' => true, 'version' => 1,
        ])
        ->and($response->json('data.category'))->toBe(['id' => $this->category->id, 'slug' => 'deep-groove-ball', 'name' => $this->category->name])
        ->and($response->json('data.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
})->with(['staff', 'admin']);

it('uppercases and trims the part number, accepts decimals and price on request, and honours active on creation only', function () {
    $response = adminCreateProduct([
        'part_number' => '  ab-12 / x ', 'inner_diameter_mm' => '16.25', 'outer_diameter_mm' => 40.5, 'width_mm' => '12.5',
        'price_on_request' => true, 'price_cents' => null, 'active' => false,
    ])->assertCreated();

    expect($response->json('data'))->toMatchArray([
        'part_number' => 'AB-12 / X', 'inner_diameter_mm' => 16.25, 'outer_diameter_mm' => 40.5, 'width_mm' => 12.5, 'price_cents' => null, 'price_on_request' => true, 'active' => false,
    ]);
});

it('ignores forged server fields on creation', function () {
    $id = adminCreateProduct(['version' => 99, 'id' => 12345, 'part_number_search' => 'forged', 'created_at' => '2000-01-01T00:00:00Z'])->assertCreated()->json('data');

    expect($id['version'])->toBe(1)->and($id['id'])->not->toBe(12345)->and($id['created_at'])->not->toStartWith('2000');
    expect(Product::find($id['id'])->part_number_search)->toBe('62052rs');
});

it('rejects invalid fields with 422 on the field name', function (array $override, string $field) {
    adminCreateProduct($override)->assertStatus(422)->assertJsonValidationErrors($field);
    expect(Product::count())->toBe(0);
})->with([
    'category missing' => [['category_id' => null], 'category_id'],
    'category unknown' => [['category_id' => 999999], 'category_id'],
    'category text' => [['category_id' => 'abc'], 'category_id'],
    'part number empty' => [['part_number' => ''], 'part_number'],
    'part number 65 chars' => [['part_number' => str_repeat('A', 65)], 'part_number'],
    'part number leading dash' => [['part_number' => '-6205'], 'part_number'],
    'part number symbol' => [['part_number' => '62@05'], 'part_number'],
    'name empty' => [['name' => ''], 'name'],
    'name 256 chars' => [['name' => str_repeat('n', 256)], 'name'],
    'name formula =' => [['name' => '=SUM(A1)'], 'name'],
    'name formula +' => [['name' => '+1'], 'name'],
    'name formula -' => [['name' => '-1'], 'name'],
    'name formula @' => [['name' => '@x'], 'name'],
    'name control char' => [['name' => "bell\x07"], 'name'],
    'name zero width' => [['name' => "zero\u{200B}width"], 'name'],
    'name bidi mark' => [['name' => "a\u{202E}b"], 'name'],
    'name interior NUL' => [['name' => "a\0b"], 'name'],
    'name invalid utf8' => [['name' => "a\xB1b"], 'name'],
    'description 5001' => [['description' => str_repeat('d', 5001)], 'description'],
    'description formula' => [['description' => '=cmd'], 'description'],
    'description control' => [['description' => "a\x07b"], 'description'],
    'description lone CR' => [['description' => "a\rb"], 'description'],
    'brand empty' => [['brand' => ''], 'brand'],
    'brand formula' => [['brand' => '=X'], 'brand'],
    'brand 65 chars' => [['brand' => str_repeat('b', 65)], 'brand'],
    'material leading dash' => [['material' => '-Steel'], 'material'],
    'material symbol' => [['material' => 'Steel<b>'], 'material'],
    'inner zero' => [['inner_diameter_mm' => 0], 'inner_diameter_mm'],
    'inner negative' => [['inner_diameter_mm' => -1], 'inner_diameter_mm'],
    'inner 5000.01' => [['inner_diameter_mm' => 5000.01, 'outer_diameter_mm' => 5000], 'inner_diameter_mm'],
    'inner three decimals' => [['inner_diameter_mm' => 1.234], 'inner_diameter_mm'],
    'inner text' => [['inner_diameter_mm' => 'abc'], 'inner_diameter_mm'],
    'inner bool' => [['inner_diameter_mm' => true], 'inner_diameter_mm'],
    'inner exponent' => [['inner_diameter_mm' => '1e1'], 'inner_diameter_mm'],
    'outer 5000.01' => [['outer_diameter_mm' => 5000.01], 'outer_diameter_mm'],
    'width zero' => [['width_mm' => 0], 'width_mm'],
    'width null' => [['width_mm' => null], 'width_mm'],
    'outer equals inner' => [['inner_diameter_mm' => 52, 'outer_diameter_mm' => 52], 'outer_diameter_mm'],
    'outer below inner' => [['inner_diameter_mm' => 60, 'outer_diameter_mm' => 52], 'outer_diameter_mm'],
    'seal unknown' => [['seal_type' => 'rubber'], 'seal_type'],
    'seal uppercase' => [['seal_type' => '2RS'], 'seal_type'],
    'stock unknown' => [['stock_status' => 'plenty'], 'stock_status'],
    'price_on_request missing' => [['price_on_request' => null], 'price_on_request'],
    'price_on_request text' => [['price_on_request' => 'maybe'], 'price_on_request'],
    'price negative' => [['price_cents' => -1], 'price_cents'],
    'price above cap' => [['price_cents' => 1_000_000_001], 'price_cents'],
    'price float' => [['price_cents' => 12.5], 'price_cents'],
    'price text' => [['price_cents' => '1250'], 'price_cents'],
    'price missing without request' => [['price_cents' => null], 'price_cents'],
    'price given with request' => [['price_on_request' => true, 'price_cents' => 100], 'price_cents'],
    'active text' => [['active' => 'sometimes'], 'active'],
]);

it('accepts the boundary values', function () {
    adminCreateProduct(['part_number' => 'B-1', 'price_cents' => 0, 'inner_diameter_mm' => 0.01, 'outer_diameter_mm' => 5000, 'width_mm' => 5000, 'name' => str_repeat('n', 255), 'description' => str_repeat('d', 5000)])->assertCreated();
    adminCreateProduct(['part_number' => 'B-2', 'price_cents' => 1_000_000_000, 'brand' => str_repeat('b', 64), 'part_number' => str_repeat('P', 64)])->assertCreated();
    adminCreateProduct(['part_number' => 'B-3', 'price_on_request' => true, 'price_cents' => null])->assertCreated();
    adminCreateProduct(['part_number' => 'B-5', 'name' => 'Bearing 6205 - 2RS = good', 'description' => "Use + care @ home\twith a tab\nand a new line"])->assertCreated();

    expect(Product::count())->toBe(4);
});

it('refuses a duplicate part number in every spelling, also of an inactive product', function (string $spelling) {
    makeProduct('6205-2RS', ['active' => false]);

    adminCreateProduct(['part_number' => $spelling])->assertStatus(422)->assertJsonValidationErrors('part_number');
    expect(Product::count())->toBe(1);
})->with(['6205-2RS', '6205 2rs', '6205/2RS', '6205-2rs', '6205--2RS', '62052RS']);

it('allows a part number on update that is the product own or unused, and refuses another products', function () {
    $mine = makeProduct('MINE-1');
    $other = makeProduct('OTHER-1', ['active' => false]);

    adminUpdateProduct($mine, ['part_number' => 'mine 1'])->assertOk()->assertJsonPath('data.part_number', 'MINE 1');
    adminUpdateProduct($mine, ['part_number' => 'OTHER/1'])->assertStatus(422)->assertJsonValidationErrors('part_number');
    adminUpdateProduct($mine, ['part_number' => 'NEW-9'])->assertOk()->assertJsonPath('data.part_number', 'NEW-9');
});

it('turns the unique-index race into 422 part_number, never 500', function () {
    $category = $this->category;
    Product::creating(function () use ($category) {
        // A concurrent request wins between the Form Request check and the insert.
        DB::table('products')->insert([
            'category_id' => $category->id, 'part_number' => '6205 2RS', 'name' => 'Racer', 'brand' => 'SKF', 'inner_diameter_mm' => 25, 'outer_diameter_mm' => 52,
            'width_mm' => 15, 'material' => 'Steel', 'seal_type' => 'open', 'price_cents' => 1, 'price_on_request' => false, 'stock_status' => 'in_stock',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    adminCreateProduct()->assertStatus(422)->assertJsonValidationErrors('part_number');

    Product::flushEventListeners();
    expect(Product::count())->toBe(0);
});

it('shows a product including an inactive one and 404s a missing one with the exact body', function () {
    $inactive = makeProduct('IN-1', ['active' => false, 'description' => 'D']);

    $this->actingAs($this->staff)->getJson("/api/v1/admin/products/{$inactive->id}")->assertOk()
        ->assertJsonPath('data.active', false)->assertJsonPath('data.description', 'D');
    expect(array_keys($this->getJson("/api/v1/admin/products/{$inactive->id}")->json('data')))->toBe(ADMIN_PRODUCT_KEYS);
    $this->getJson('/api/v1/admin/products/999999')->assertNotFound()->assertExactJson(NOT_FOUND);
});

it('updates with the echoed version, bumps it, audits the diff and never touches active', function () {
    $product = makeProduct('UP-1', ['price_cents' => 1000, 'description' => 'old']);

    $one = adminUpdateProduct($product, ['price_cents' => 1500, 'active' => false])->assertOk();
    expect($one->json('data.price_cents'))->toBe(1500)->and($one->json('data.version'))->toBe(2)->and($one->json('data.active'))->toBeTrue();

    $two = adminUpdateProduct($product, ['price_cents' => 1700, 'description' => 'new'])->assertOk();
    expect($two->json('data.version'))->toBe(3);

    $rows = auditLogRows('product.updated');
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['changes'])->toEqual(['before' => ['price_cents' => 1000], 'after' => ['price_cents' => 1500]])
        ->and($rows[0]['metadata'])->toEqual(['description_changed' => false])
        ->and($rows[1]['changes'])->toEqual(['before' => ['price_cents' => 1500], 'after' => ['price_cents' => 1700]])
        ->and($rows[1]['metadata'])->toEqual(['description_changed' => true])
        ->and($rows[0]['actor_role'])->toBe('staff')->and($rows[0]['subject_id'])->toBe((string) $product->id)->and($rows[0]['subject_label'])->toBe('UP-1');
});

it('answers 409 with errors.version for a stale version and writes nothing', function () {
    $product = makeProduct('ST-1', ['price_cents' => 1000]);
    adminUpdateProduct($product, ['price_cents' => 2000], 1)->assertOk();

    $stale = adminUpdateProduct($product, ['price_cents' => 3000], 1);

    $stale->assertStatus(409)->assertExactJson([
        'message' => 'This record was changed by someone else. Reload it and try again.',
        'errors' => ['version' => ['This record was changed by someone else. Reload it and try again.']],
    ]);
    expect($product->fresh()->price_cents)->toBe(2000)->and($product->fresh()->version)->toBe(2)->and(auditLogRows('product.updated'))->toHaveCount(1);
    adminUpdateProduct($product, [], 99999999999)->assertStatus(409);
});

it('requires an integer version', function (mixed $version) {
    $product = makeProduct('V-1');
    $body = adminProductBody(['part_number' => 'V-1']);
    $version === 'absent' || $body['version'] = $version;

    $this->actingAs($this->staff)->putJson("/api/v1/admin/products/{$product->id}", $body)->assertStatus(422)->assertJsonValidationErrors('version');
})->with(['absent', null, 'abc', '1', 1.5, 0, -1, true, [[1]], 1e30]);

it('does not write, bump the version or audit when nothing changed', function () {
    $product = makeProduct('NO-1', ['price_cents' => 1250, 'inner_diameter_mm' => 25, 'outer_diameter_mm' => 52, 'width_mm' => 15, 'seal_type' => 'open', 'brand' => 'SKF', 'material' => 'Chrome steel']);
    $before = DB::table('products')->where('id', $product->id)->first();

    adminUpdateProduct($product)->assertOk()->assertJsonPath('data.version', 1);

    expect(DB::table('products')->where('id', $product->id)->first())->toEqual($before)->and(auditLogRows())->toBe([]);
});

it('bumps the version in the database on every update, including raw SQL and the importer upsert', function () {
    $product = makeProduct('TR-1');

    DB::table('products')->where('id', $product->id)->update(['name' => 'Raw update']);
    expect($product->fresh()->version)->toBe(2);

    $this->seed(CategorySeeder::class);
    $csv = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($csv, "part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,price_cents,stock_status\nTR-1,Imported,SKF,deep-groove-ball,25,52,15,Steel,open,100,in_stock\n");
    $this->artisan('products:import', ['file' => $csv])->assertSuccessful();
    @unlink($csv);

    expect($product->fresh()->version)->toBe(3)->and($product->fresh()->name)->toBe('Imported');
});

it('activates and deactivates idempotently with one audit row per real change', function () {
    $product = makeProduct('AC-1');
    $this->actingAs($this->staff);

    $off = $this->postJson("/api/v1/admin/products/{$product->id}/deactivate", ['active' => true, 'price_cents' => 1])->assertOk();
    expect($off->json('data.active'))->toBeFalse()->and($off->json('data.version'))->toBe(2);
    $this->postJson("/api/v1/admin/products/{$product->id}/deactivate")->assertOk()->assertJsonPath('data.version', 2);
    $on = $this->postJson("/api/v1/admin/products/{$product->id}/activate")->assertOk();
    expect($on->json('data.active'))->toBeTrue()->and($on->json('data.version'))->toBe(3);
    $this->postJson("/api/v1/admin/products/{$product->id}/activate")->assertOk()->assertJsonPath('data.version', 3);

    auditLogExpect(auditLogOne('product.deactivated'), $this->staff, 'product', (string) $product->id, null, ['before' => ['active' => true], 'after' => ['active' => false]]);
    auditLogExpect(auditLogOne('product.activated'), $this->staff, 'product', (string) $product->id, null, ['before' => ['active' => false], 'after' => ['active' => true]]);
});

it('audits a creation with the full after-state and no before', function () {
    $id = adminCreateProduct(['description' => 'secret description text'])->assertCreated()->json('data.id');

    $row = auditLogOne('product.created');
    expect($row['changes']['before'])->toBe([])->and($row['changes']['after'])->toMatchArray([
        'part_number' => '6205-2RS', 'name' => 'Deep groove ball bearing 6205-2RS 25x52x15', 'brand' => 'SKF', 'category_id' => $this->category->id,
        'inner_diameter_mm' => 25, 'outer_diameter_mm' => 52, 'width_mm' => 15, 'material' => 'Chrome steel', 'seal_type' => '2rs',
        'price_cents' => 1250, 'price_on_request' => false, 'stock_status' => 'in_stock', 'active' => true,
    ])->and($row['metadata'])->toEqual(['description_changed' => true])->and($row['subject_id'])->toBe((string) $id)
        ->and(json_encode($row))->not->toContain('secret description text');
});

it('flushes the catalog caches on create, update, activate and deactivate', function () {
    $product = makeProduct('CA-1');
    $primed = function () {
        CatalogCache::filters();
        CatalogCache::categories();
        expect(Cache::has(CatalogCache::FILTERS_KEY))->toBeTrue()->and(Cache::has(CatalogCache::CATEGORIES_KEY))->toBeTrue();
    };
    $flushed = fn () => expect(Cache::has(CatalogCache::FILTERS_KEY))->toBeFalse()->and(Cache::has(CatalogCache::CATEGORIES_KEY))->toBeFalse();

    $primed();
    adminCreateProduct(['part_number' => 'CA-2'])->assertCreated();
    $flushed();

    $primed();
    adminUpdateProduct($product, ['brand' => 'NSK'])->assertOk();
    $flushed();
    expect(CatalogCache::filters()['brands'])->toContain('NSK');

    $primed();
    $this->postJson("/api/v1/admin/products/{$product->id}/deactivate")->assertOk();
    $flushed();
    expect(collect(CatalogCache::categories())->firstWhere('slug', 'deep-groove-ball')['product_count'])->toBe(1);

    $primed();
    $this->postJson("/api/v1/admin/products/{$product->id}/activate")->assertOk();
    $flushed();
    expect(collect(CatalogCache::categories())->firstWhere('slug', 'deep-groove-ball')['product_count'])->toBe(2);
});

it('does not flush the cache when the update is refused', function () {
    $product = makeProduct('CF-1');
    CatalogCache::filters();

    adminUpdateProduct($product, [], 99)->assertStatus(409);

    expect(Cache::has(CatalogCache::FILTERS_KEY))->toBeTrue();
});

it('hides a deactivated product from the catalog for every role and restores it on reactivation', function () {
    $w = orderWorld();
    $product = makeProduct('HID-1', ['brand' => 'ONLYBRAND']);
    makeProduct('HID-2');
    $url = "/api/v1/products/{$product->id}";

    $this->actingAs($this->staff)->postJson("/api/v1/admin/products/{$product->id}/deactivate")->assertOk();

    foreach ([$w->a1, $w->staff, $w->admin] as $viewer) {
        $this->actingAs($viewer);
        $this->getJson($url)->assertNotFound()->assertExactJson(NOT_FOUND);
        expect(partNumbers($this->getJson('/api/v1/products?per_page=100')))->toBe(['HID-2'])
            ->and($this->getJson('/api/v1/products/filters')->json('data.brands'))->not->toContain('ONLYBRAND')
            ->and($this->getJson('/api/v1/categories')->json('data.0.product_count'))->toBe(1);
    }

    $this->actingAs($this->staff)->postJson("/api/v1/admin/products/{$product->id}/activate")->assertOk();
    $this->actingAs($w->a1)->getJson($url)->assertOk();
    expect(partNumbers($this->getJson('/api/v1/products?per_page=100')))->toContain('HID-1');
});

it('keeps a deactivated product on old orders, quotes and invoices and blocks new use', function () {
    $w = quoteWorld();
    $product = makeProduct('OLD-1', ['price_cents' => 500]);
    $order = placeOrderAs($w->a1, [[$product, 2]]);
    $quote = QuoteRequest::factory()->forCompany($w->a, $w->a1)->quoted()->withLines([[$product, 1, 500]])->create();
    $order->forceFill(['status' => 'confirmed'])->save();
    $invoiceId = issueInvoiceAs($w->staff, $order)->assertCreated()->json('data.id');
    fillCart($w->b1, [[$product, 1]]);

    $this->actingAs($w->staff)->postJson("/api/v1/admin/products/{$product->id}/deactivate")->assertOk();

    // Old documents keep their snapshot lines.
    $this->actingAs($w->a1)->getJson("/api/v1/orders/{$order->order_number}")->assertOk()
        ->assertJsonPath('data.items.0.part_number', 'OLD-1')->assertJsonPath('data.items.0.unit_price_cents', 500);
    $this->getJson("/api/v1/invoices/{$invoiceId}")->assertOk()->assertJsonPath('data.items.0.part_number', 'OLD-1');
    $this->getJson("/api/v1/quotes/{$quote->quote_number}")->assertOk()->assertJsonPath('data.items.0.part_number', 'OLD-1');

    // New use is refused with the generic message.
    $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertStatus(422)->assertJsonPath('errors.product_id.0', 'The selected product is not available.');
    $this->postJson('/api/v1/quotes', ['lines' => [['product_id' => $product->id, 'quantity' => 1]]])->assertStatus(422);

    // A cart line that already held it stays, reports why, and blocks checkout; reorder skips it.
    $this->actingAs($w->b1)->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.unavailable_reason', 'inactive')->assertJsonPath('data.can_checkout', false);
    $this->postJson('/api/v1/orders')->assertStatus(422);
    $this->actingAs($w->a1)->postJson("/api/v1/orders/{$order->order_number}/reorder")->assertOk()->assertJsonPath('data.skipped.0.reason', 'inactive');
});

it('lists every product including inactive ones with filters, sorts and pagination', function () {
    $other = category('tapered-roller');
    makeProduct('LA-1', ['brand' => 'SKF', 'price_cents' => 300, 'stock_status' => 'in_stock']);
    makeProduct('LA-2', ['brand' => 'NSK', 'price_cents' => 100, 'stock_status' => 'low_stock', 'active' => false]);
    makeProduct('LA-3', ['brand' => 'SKF', 'stock_status' => 'on_order', 'price_cents' => null, 'price_on_request' => true, 'category_id' => $other->id]);
    $this->actingAs($this->staff);
    $list = fn (string $query = '') => $this->getJson('/api/v1/admin/products'.$query)->assertOk();

    $all = $list();
    expect(partNumbers($all))->toBe(['LA-1', 'LA-2', 'LA-3'])->and(array_keys($all->json('data.0')))->toBe(array_values(array_diff(ADMIN_PRODUCT_KEYS, ['description'])))
        ->and(array_keys($all->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])
        ->and($all->json('meta.per_page'))->toBe(25)->and($all->json('meta.total'))->toBe(3);

    expect(partNumbers($list('?active=false')))->toBe(['LA-2'])
        ->and(partNumbers($list('?active=true')))->toBe(['LA-1', 'LA-3'])
        ->and(partNumbers($list('?category=tapered-roller')))->toBe(['LA-3'])
        ->and(partNumbers($list('?brand=NSK,SKF&stock_status=in_stock,low_stock')))->toBe(['LA-1', 'LA-2'])
        ->and(partNumbers($list('?q=la%202')))->toBe(['LA-2'])
        ->and(partNumbers($list('?q=!!!')))->toBe([])
        ->and(partNumbers($list('?sort=-part_number')))->toBe(['LA-3', 'LA-2', 'LA-1'])
        ->and(partNumbers($list('?sort=price')))->toBe(['LA-2', 'LA-1', 'LA-3'])
        ->and(partNumbers($list('?sort=-price')))->toBe(['LA-1', 'LA-2', 'LA-3'])
        ->and(partNumbers($list('?per_page=2&page=2')))->toBe(['LA-3'])
        ->and($list('?page=9')->json('data'))->toBe([]);
});

it('accepts every documented sort and refuses unknown ones and bad parameters with 422 on the parameter name', function () {
    makeProduct('SO-1');
    $this->actingAs($this->staff);

    foreach (['part_number', 'name', 'brand', 'price', 'updated_at'] as $sort) {
        $this->getJson("/api/v1/admin/products?sort={$sort}")->assertOk();
        $this->getJson("/api/v1/admin/products?sort=-{$sort}")->assertOk();
    }

    foreach (['sort=bogus', 'sort=--price', 'sort[]=price', 'active=maybe', 'category=nope', 'category=a,,b', 'stock_status=gone', 'per_page=101', 'per_page=0', 'page=0', 'page=10001', 'page=x', 'q[]=a', 'brand[]=x'] as $bad) {
        $key = explode('=', explode('[', $bad)[0])[0];
        $this->getJson("/api/v1/admin/products?{$bad}")->assertStatus(422)->assertJsonValidationErrors($key);
    }
    $this->getJson('/api/v1/admin/products?q=a%00b')->assertStatus(422)->assertJsonValidationErrors('q');
    $this->getJson('/api/v1/admin/products?unknown=1')->assertOk();
});

it('lists with a constant number of queries', function () {
    $this->actingAs($this->staff);
    $count = fn () => adminQueryCount(fn () => $this->getJson('/api/v1/admin/products?per_page=100')->assertOk());
    $count(); // warm-up

    Product::factory()->count(3)->create(['category_id' => $this->category->id]);
    $few = $count();
    Product::factory()->count(60)->create(['category_id' => category('tapered-roller')->id]);

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(4); // session user, count, rows, categories
});

it('leaves existing order lines untouched when a product is renamed or repriced', function () {
    $w = orderWorld();
    $product = makeProduct('SN-1', ['price_cents' => 800]);
    $order = placeOrderAs($w->a1, [[$product, 3]]);

    adminUpdateProduct($product, ['part_number' => 'SN-RENAMED', 'name' => 'Renamed', 'price_cents' => 9999])->assertOk();

    $this->actingAs($w->a1)->getJson("/api/v1/orders/{$order->order_number}")->assertOk()
        ->assertJsonPath('data.items.0.part_number', 'SN-1')->assertJsonPath('data.items.0.unit_price_cents', 800)->assertJsonPath('data.total_cents', 2400);
});

it('has no delete or customer write route for products', function () {
    $product = makeProduct('DEL-1');

    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/products/{$product->id}")->assertStatus(405);
    $this->postJson('/api/v1/products', adminProductBody())->assertStatus(405);
    $this->putJson("/api/v1/products/{$product->id}", adminProductBody())->assertStatus(405);
    expect(Product::count())->toBe(1);
});
