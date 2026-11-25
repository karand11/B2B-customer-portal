<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Support\OrderNumber;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->w = orderWorld();
    $this->p1 = makeProduct('CK-1', ['price_cents' => 1250]);
    $this->p2 = makeProduct('CK-2', ['price_cents' => 300]);
});

it('creates the order from the cart with server-side totals and ignores every tampered field', function () {
    fillCart($this->w->a1, [[$this->p1, 2], [$this->p2, 1]]);

    $response = loginAs($this->w->a1)->postJson('/api/v1/orders', [
        'total_cents' => 1, 'subtotal_cents' => 1, 'tax_cents' => 99999, 'shipping_cents' => 5,
        'items' => [['product_id' => $this->p1->id, 'quantity' => 999, 'unit_price_cents' => 1]],
        'currency' => 'EUR', 'status' => 'delivered', 'company_id' => $this->w->b->id, 'created_by_user_id' => $this->w->b1->id,
        'user_id' => $this->w->b1->id, 'number' => 'ORD-AAAAA-AAAAA', 'created_at' => '2000-01-01T00:00:00Z', 'unit_price_cents' => 1,
    ])->assertCreated();

    expect(array_keys($response->json('data')))->toBe(DETAIL_KEYS)
        ->and($response->json('data'))->toMatchArray([
            'status' => 'pending', 'currency' => config('catalog.currency'), 'subtotal_cents' => 2800, 'tax_cents' => 0,
            'shipping_cents' => 0, 'total_cents' => 2800, 'line_count' => 2, 'po_reference' => null,
            'allowed_transitions' => ['cancelled'],
        ])
        ->and($response->json('data.company'))->toBe(['id' => $this->w->a->id, 'name' => $this->w->a->name])
        ->and($response->json('data.placed_by'))->toBe(['name' => $this->w->a1->name])
        ->and($response->json('data.items'))->toBe([
            ['product_id' => $this->p1->id, 'part_number' => 'CK-1', 'name' => 'Bearing CK-1', 'brand' => 'SKF', 'unit_price_cents' => 1250, 'quantity' => 2, 'line_total_cents' => 2500],
            ['product_id' => $this->p2->id, 'part_number' => 'CK-2', 'name' => 'Bearing CK-2', 'brand' => 'SKF', 'unit_price_cents' => 300, 'quantity' => 1, 'line_total_cents' => 300],
        ])
        ->and($response->json('data.history'))->toHaveCount(1)
        ->and($response->json('data.history.0'))->toMatchArray(['from_status' => null, 'to_status' => 'pending', 'actor' => ['name' => $this->w->a1->name, 'role' => 'customer']]);

    $order = Order::where('order_number', $response->json('data.number'))->firstOrFail();
    expect($order->company_id)->toBe($this->w->a->id)
        ->and($order->created_by_user_id)->toBe($this->w->a1->id)
        ->and($order->created_at->year)->not->toBe(2000)
        ->and(CartItem::where('user_id', $this->w->a1->id)->count())->toBe(0)
        ->and(OrderStatusHistory::where('order_id', $order->id)->count())->toBe(1);
});

it('never exposes numeric ids of orders, users or companies except the company object id', function () {
    $order = placeOrderAs($this->w->a1, [[$this->p1, 1]]);

    $body = loginAs($this->w->a1)->getJson("/api/v1/orders/{$order->order_number}")->json('data');

    expect($body)->not->toHaveKeys(['id', 'order_id', 'user_id', 'company_id', 'created_by', 'created_by_user_id'])
        ->and(json_encode($body))->not->toContain('@');
});

it('keeps totals consistent for several carts', function (array $quantities) {
    $products = [];
    foreach ($quantities as $i => $q) {
        $products[] = [makeProduct("TC-{$i}", ['price_cents' => 137 * ($i + 1)]), $q];
    }
    $order = placeOrderAs($this->w->a1, $products);

    $items = OrderItem::where('order_id', $order->id)->get();
    expect($order->total_cents)->toBe($items->sum('line_total_cents'))->and($order->subtotal_cents)->toBe($order->total_cents);
    foreach ($items as $item) {
        expect($item->line_total_cents)->toBe($item->unit_price_cents * $item->quantity);
    }
})->with([[[1]], [[9999, 1]], [[3, 7, 11]], [[9999, 9999, 9999, 9999]]]);

it('does not change old orders when the product changes later, and reorders at the new price', function () {
    $order = placeOrderAs($this->w->a1, [[$this->p1, 2], [$this->p2, 1]]);
    $before = loginAs($this->w->a1)->getJson("/api/v1/orders/{$order->order_number}")->json('data');
    $listBefore = $this->getJson('/api/v1/orders')->json('data.0.total_cents');
    $rows = OrderItem::orderBy('id')->get()->toArray();

    $this->p1->forceFill(['price_cents' => 9999, 'name' => 'Renamed', 'brand' => 'FAG', 'part_number' => 'CK-1-NEW', 'active' => false])->save();
    $this->p2->forceFill(['price_cents' => 9999])->save();

    $after = $this->getJson("/api/v1/orders/{$order->order_number}")->assertOk()->json('data');
    expect($after)->toBe($before)
        ->and(OrderItem::orderBy('id')->get()->toArray())->toBe($rows)
        ->and($this->getJson('/api/v1/orders')->json('data.0.total_cents'))->toBe($listBefore);

    $reorder = $this->postJson("/api/v1/orders/{$order->order_number}/reorder")->assertOk();
    expect($reorder->json('data.skipped.0.reason'))->toBe('inactive')
        ->and($reorder->json('data.cart.items.0.unit_price_cents'))->toBe(9999)
        ->and($reorder->json('data.cart.subtotal_cents'))->toBe(9999);
});

it('rejects an empty cart with 422 on cart and writes nothing', function () {
    loginAs($this->w->a1)->postJson('/api/v1/orders')->assertStatus(422)
        ->assertJsonValidationErrors('cart')->assertJsonPath('errors.cart.0', 'Your cart is empty.');

    expect(Order::count())->toBe(0);
});

it('blocks checkout on unavailable lines with one message per line and leaves the cart alone', function (array $change, string $message) {
    fillCart($this->w->a1, [[$this->p1, 2], [$this->p2, 1]]);
    $this->p2->forceFill($change)->save();

    $response = loginAs($this->w->a1)->postJson('/api/v1/orders')->assertStatus(422)->assertJsonValidationErrors('cart');

    expect($response->json('errors.cart'))->toBe(["CK-2 {$message}"])
        ->and(Order::count())->toBe(0)->and(CartItem::where('user_id', $this->w->a1->id)->count())->toBe(2);
})->with([
    'inactive' => [['active' => false], 'is no longer available.'],
    'price on request' => [['price_on_request' => true, 'price_cents' => null], 'is price on request.'],
    'out of stock' => [['stock_status' => 'out_of_stock'], 'is out of stock.'],
]);

it('accepts low stock and on order products at checkout', function (string $stock) {
    $p = makeProduct("ST-{$stock}", ['stock_status' => $stock]);

    expect(placeOrderAs($this->w->a1, [[$p, 1]])->status->value)->toBe('pending');
})->with(['low_stock', 'on_order']);

it('forbids a customer without a company from creating orders', function () {
    $orphan = makeUser('customer');
    $orphan->forceFill(['company_id' => null])->save();

    loginAs($orphan)->postJson('/api/v1/orders')->assertForbidden();
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/orders/ORD-ZZZZZ-ZZZZZ')->assertNotFound();
});

it('rolls everything back when a later step of checkout fails', function () {
    fillCart($this->w->a1, [[$this->p1, 2]]);
    OrderStatusHistory::creating(fn () => throw new RuntimeException('boom'));

    loginAs($this->w->a1)->postJson('/api/v1/orders')->assertStatus(500);

    expect(Order::count())->toBe(0)->and(OrderItem::count())->toBe(0)->and(CartItem::where('user_id', $this->w->a1->id)->count())->toBe(1);
    OrderStatusHistory::flushEventListeners();
});

it('creates exactly one order for a double submit, then allows a new one after refilling the cart', function () {
    fillCart($this->w->a1, [[$this->p1, 1]]);
    loginAs($this->w->a1);

    $this->postJson('/api/v1/orders')->assertCreated();
    $this->postJson('/api/v1/orders')->assertStatus(422)->assertJsonPath('errors.cart.0', 'Your cart is empty.');

    expect(Order::count())->toBe(1)->and(OrderStatusHistory::count())->toBe(1)->and(CartItem::count())->toBe(0);

    fillCart($this->w->a1, [[$this->p1, 1]]);
    $this->postJson('/api/v1/orders')->assertCreated();
    expect(Order::count())->toBe(2);
});

it('takes the user row lock inside the checkout transaction', function () {
    fillCart($this->w->a1, [[$this->p1, 1]]);
    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });

    loginAs($this->w->a1)->postJson('/api/v1/orders')->assertCreated();

    $lock = collect($statements)->search(fn ($s) => str_contains($s, 'from "users"') && str_contains($s, 'for update'));
    $insert = collect($statements)->search(fn ($s) => str_starts_with($s, 'insert into "orders"'));
    expect($lock)->not->toBeFalse()->and($lock)->toBeLessThan($insert);
});

it('uses the same number of INSERT statements for 1 and 20 lines', function () {
    $count = function (int $lines) {
        $products = [];
        foreach (range(1, $lines) as $i) {
            $products[] = [makeProduct("BULK-{$lines}-{$i}"), 1];
        }
        fillCart($this->w->a1, $products);
        $inserts = 0;
        DB::listen(function ($q) use (&$inserts) {
            $inserts += str_starts_with($q->sql, 'insert') ? 1 : 0;
        });
        loginAs($this->w->a1)->postJson('/api/v1/orders')->assertCreated();

        return $inserts;
    };

    expect($count(20))->toBe($count(1));
});

it('generates order numbers in the documented format, unique, and retries on a collision', function () {
    $generator = new OrderNumber;
    $numbers = array_map(fn () => $generator->generate(), range(1, 3000));

    expect(count(array_unique($numbers)))->toBe(3000)
        ->and(preg_grep('/^ORD-[A-HJKMNP-TV-Z2-9]{5}-[A-HJKMNP-TV-Z2-9]{5}$/D', $numbers))->toHaveCount(3000);

    $existing = Order::factory()->forCompany($this->w->a)->create();
    $fake = new class($existing->order_number) extends OrderNumber
    {
        private int $calls = 0;

        public function __construct(private string $taken) {}

        public function generate(): string
        {
            return ++$this->calls <= 2 ? $this->taken : parent::generate();
        }
    };
    app()->instance(OrderNumber::class, $fake);

    $placed = placeOrderAs($this->w->a1, [[$this->p1, 1]]);
    expect($placed->order_number)->not->toBe($existing->order_number);
});

it('has an empty $fillable on every Phase 3 model', function (string $class) {
    $model = new $class;

    expect($model->getFillable())->toBe([])
        ->and(fn () => $model->fill(['company_id' => 5, 'status' => 'delivered', 'quantity' => 3]))->toThrow(MassAssignmentException::class);
})->with([Order::class, OrderItem::class, OrderStatusHistory::class, CartItem::class]);

it('ignores forged fields on cancel, status and reorder bodies', function () {
    $order = placeOrderAs($this->w->a1, [[$this->p1, 1]]);
    $forged = ['company_id' => $this->w->b->id, 'status' => 'delivered', 'total_cents' => 1, 'po_reference' => 'HACK', 'number' => 'ORD-AAAAA-AAAAA'];

    loginAs($this->w->a1)->postJson("/api/v1/orders/{$order->order_number}/reorder", $forged)->assertOk();
    $this->postJson("/api/v1/orders/{$order->order_number}/cancel", $forged)->assertOk()->assertJsonPath('data.status', 'cancelled');

    $fresh = $order->fresh();
    expect($fresh->company_id)->toBe($this->w->a->id)->and($fresh->total_cents)->toBe(1250)
        ->and($fresh->po_reference)->toBeNull()->and($fresh->order_number)->toBe($order->order_number);

    CartItem::query()->delete();
    $order2 = placeOrderAs($this->w->a1, [[$this->p1, 1]]);
    loginAs($this->w->staff)->patchJson("/api/v1/orders/{$order2->order_number}/status", ['status' => 'confirmed'] + $forged)->assertOk();
    expect($order2->fresh()->only(['company_id', 'total_cents', 'po_reference']))->toBe(['company_id' => $this->w->a->id, 'total_cents' => 1250, 'po_reference' => null]);
});

it('keeps product rows untouched by checkout', function () {
    placeOrderAs($this->w->a1, [[$this->p1, 5]]);

    expect(Product::find($this->p1->id)->price_cents)->toBe(1250);
});
