<?php

use App\Models\CartItem;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Insert inside a savepoint so a failed statement does not poison the test transaction. */
function rawInsert(string $table, array $row): void
{
    DB::transaction(fn () => DB::table($table)->insert($row));
}

function validOrderRow(Order $order, array $overrides = []): array
{
    return $overrides + [
        'order_number' => 'ORD-ZZZZZ-ZZZZ2', 'company_id' => $order->company_id, 'created_by_user_id' => $order->created_by_user_id,
        'status' => 'pending', 'currency' => 'USD', 'subtotal_cents' => 100, 'tax_cents' => 0, 'shipping_cents' => 0, 'total_cents' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ];
}

beforeEach(function () {
    $this->order = Order::factory()->create(['order_number' => 'ORD-BCDFG-HJKMN']);
    $this->item = OrderItem::where('order_id', $this->order->id)->first();
});

it('creates the designed indexes', function () {
    $names = DB::table('pg_indexes')->whereIn('tablename', ['orders', 'order_items', 'order_status_history', 'cart_items'])->pluck('indexdef', 'indexname');

    foreach ([
        'orders_pkey', 'orders_order_number_unique', 'orders_company_id_created_at_index', 'orders_company_id_status_index',
        'orders_status_created_at_index', 'orders_created_at_index', 'order_items_order_id_product_id_unique',
        'order_status_history_order_id_id_index', 'cart_items_user_id_product_id_unique',
    ] as $name) {
        expect($names)->toHaveKey($name);
    }
    expect($names['orders_company_id_created_at_index'])->toContain('(company_id, created_at)')
        ->and($names['orders_status_created_at_index'])->toContain('(status, created_at)')
        ->and($names['order_status_history_order_id_id_index'])->toContain('(order_id, id)');
});

it('rejects duplicate and malformed order numbers', function (string $number) {
    expect(fn () => rawInsert('orders', validOrderRow($this->order, ['order_number' => $number])))->toThrow(QueryException::class);
})->with([
    'duplicate' => ['ORD-BCDFG-HJKMN'], 'lowercase' => ['ord-bcdfg-hjkmp'], 'forbidden letter I' => ['ORD-BCDFI-HJKMP'], 'forbidden letter O' => ['ORD-BCDFG-HJKMO'],
    'forbidden digit 0' => ['ORD-BCDFG-HJKM0'], 'forbidden digit 1' => ['ORD-BCDFG-HJKM1'], 'too short' => ['ORD-BCDF-HJKMP'], 'no prefix' => ['XXX-BCDFG-HJKMP'],
    'trailing text' => ['ORD-BCDFG-HJKMPX'],
]);

it('rejects bad statuses, currencies and negative or inconsistent money on orders', function (array $bad) {
    expect(fn () => rawInsert('orders', validOrderRow($this->order, ['order_number' => 'ORD-BCDFG-HJKMP'] + $bad)))->toThrow(QueryException::class);
})->with([
    'bad status' => [['status' => 'paid']], 'bad currency lower' => [['currency' => 'usd']], 'bad currency long' => [['currency' => 'USDX']],
    'negative subtotal' => [['subtotal_cents' => -1, 'total_cents' => -1]], 'negative tax' => [['tax_cents' => -1, 'total_cents' => 99]],
    'negative shipping' => [['shipping_cents' => -5, 'total_cents' => 95]], 'negative total' => [['subtotal_cents' => 0, 'total_cents' => -1]],
    'total below sum' => [['total_cents' => 99]], 'total above sum' => [['total_cents' => 101]], 'tax missing from total' => [['tax_cents' => 10]],
    'po too long' => [['po_reference' => str_repeat('A', 65)]],
]);

it('accepts a consistent order with tax and shipping', function () {
    rawInsert('orders', validOrderRow($this->order, ['order_number' => 'ORD-BCDFG-HJKMP', 'tax_cents' => 10, 'shipping_cents' => 5, 'total_cents' => 115]));

    expect(Order::count())->toBe(2);
});

it('rejects invalid order items', function (array $bad) {
    $row = [
        'order_id' => $this->order->id, 'product_id' => makeProduct('SCH-1')->id, 'part_number' => 'x', 'name' => 'x', 'brand' => 'x',
        'unit_price_cents' => 10, 'quantity' => 2, 'line_total_cents' => 20, 'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => rawInsert('order_items', $bad + $row))->toThrow(QueryException::class);
})->with([
    'quantity zero' => [['quantity' => 0, 'line_total_cents' => 0]], 'quantity 10000' => [['quantity' => 10000, 'line_total_cents' => 100000]],
    'negative price' => [['unit_price_cents' => -1, 'line_total_cents' => -2]], 'negative line' => [['unit_price_cents' => 0, 'line_total_cents' => -1]],
    'line mismatch' => [['line_total_cents' => 21]],
]);

it('allows one line per product per order', function () {
    $dup = OrderItem::where('id', $this->item->id)->first()->only(['order_id', 'product_id', 'part_number', 'name', 'brand', 'unit_price_cents', 'quantity', 'line_total_cents']);

    expect(fn () => rawInsert('order_items', $dup + ['created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

it('rejects invalid history rows', function (array $bad) {
    $row = ['order_id' => $this->order->id, 'from_status' => 'pending', 'to_status' => 'confirmed', 'actor_user_id' => $this->order->created_by_user_id, 'created_at' => now()];

    expect(fn () => rawInsert('order_status_history', $bad + $row))->toThrow(QueryException::class);
})->with([
    'bad from' => [['from_status' => 'paid']], 'bad to' => [['to_status' => 'paid']], 'same status' => [['from_status' => 'pending', 'to_status' => 'pending']],
]);

it('accepts a creation history row with a NULL from_status', function () {
    rawInsert('order_status_history', ['order_id' => $this->order->id, 'from_status' => null, 'to_status' => 'pending', 'actor_user_id' => $this->order->created_by_user_id, 'created_at' => now()]);

    expect(OrderStatusHistory::where('order_id', $this->order->id)->count())->toBe(2);
});

it('rejects invalid cart quantities and duplicate lines', function (int $quantity) {
    $user = makeUser();

    expect(fn () => rawInsert('cart_items', ['user_id' => $user->id, 'product_id' => $this->item->product_id, 'quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
})->with([0, -1, 10000]);

it('rejects a duplicate cart line for one user', function () {
    $line = CartItem::factory()->create();

    expect(fn () => rawInsert('cart_items', ['user_id' => $line->user_id, 'product_id' => $line->product_id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

it('restricts deleting rows that orders, history or carts point to', function () {
    $line = CartItem::factory()->create();

    foreach ([
        'company with orders' => fn () => Company::whereKey($this->order->company_id)->delete(),
        'creator of an order' => fn () => User::whereKey($this->order->created_by_user_id)->delete(),
        'user with a cart' => fn () => User::whereKey($line->user_id)->delete(),
        'product on an order' => fn () => Product::whereKey($this->item->product_id)->delete(),
        'product in a cart' => fn () => Product::whereKey($line->product_id)->delete(),
        'order with items' => fn () => Order::whereKey($this->order->id)->delete(),
    ] as $label => $delete) {
        expect(fn () => DB::transaction($delete))->toThrow(QueryException::class, 'violates foreign key');
    }
});

it('restricts deleting the actor of a history row that is not the order creator', function () {
    $staff = makeUser('staff');
    rawInsert('order_status_history', ['order_id' => $this->order->id, 'from_status' => 'pending', 'to_status' => 'confirmed', 'actor_user_id' => $staff->id, 'created_at' => now()]);

    expect(fn () => DB::transaction(fn () => User::whereKey($staff->id)->delete()))->toThrow(QueryException::class);
});

it('makes order lines append-only: no UPDATE, no DELETE (the trigger), while INSERT and TRUNCATE stay possible', function () {
    violates(fn () => DB::table('order_items')->where('id', $this->item->id)->update(['quantity' => 3, 'line_total_cents' => $this->item->unit_price_cents * 3]), 'order_items are append-only');
    violates(fn () => DB::table('order_items')->where('id', $this->item->id)->delete(), 'order_items are append-only');

    expect(OrderItem::whereKey($this->item->id)->value('quantity'))->toBe($this->item->quantity);
    expect(DB::selectOne("select count(*) as n from pg_trigger where tgname = 'order_items_append_only' and not tgisinternal")->n)->toBe(1);
});

it('has no code path that updates or deletes order lines (only inserts)', function () {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        expect(preg_match('/order_items.{0,80}->(update|delete|truncate)\(|OrderItem::[^;]{0,120}->(update|delete)\(|OrderItem::(destroy|truncate)\(/s', $code))->toBe(0, $file->getPathname());
    }
});
