<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OrderDemoSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

function seededOrdersOf(string $email): array
{
    return Order::visibleTo(User::where('email', $email)->first())->orderBy('order_number')->pluck('order_number')->all();
}

function orderSnapshot(): array
{
    return Order::orderBy('order_number')->get()->map->only(['order_number', 'status', 'created_at', 'total_cents'])->all();
}

it('seeds the 8 fixed orders idempotently with the documented visibility', function () {
    $this->seed(OrderDemoSeeder::class);
    $first = orderSnapshot();
    $this->seed(OrderDemoSeeder::class);

    expect(Order::count())->toBe(8)
        ->and(orderSnapshot())->toEqual($first)
        ->and(seededOrdersOf('alice@northwind.test'))->toHaveCount(5)->toBe(seededOrdersOf('bob@northwind.test'))
        ->and(seededOrdersOf('carol@globex.test'))->toBe(['ORD-GXAAA-AAAA2', 'ORD-GXAAA-AAAA3', 'ORD-GXAAA-AAAA4'])
        ->and(seededOrdersOf('staff@portal.test'))->toHaveCount(8)
        ->and(Order::where('order_number', 'ORD-NWAAA-AAAA2')->first()->status)->toBe(OrderStatus::Pending);
});

it('seeds the PO references and keeps them inside their company', function () {
    $this->seed(OrderDemoSeeder::class);

    expect(Order::whereNotNull('po_reference')->orderBy('order_number')->pluck('po_reference', 'order_number')->all())
        ->toBe(['ORD-GXAAA-AAAA2' => 'GX-PO-2001', 'ORD-NWAAA-AAAA2' => 'NW-PO-1001', 'ORD-NWAAA-AAAA3' => 'NW-PO-1002']);

    foreach ([['alice@northwind.test', 'GX-PO', 0], ['carol@globex.test', 'NW-PO', 0], ['alice@northwind.test', 'NW-PO', 2], ['staff@portal.test', 'PO-', 3]] as [$email, $q, $count]) {
        $this->actingAs(User::where('email', $email)->first())->getJson('/api/v1/orders?q='.$q)->assertOk()->assertJsonPath('meta.total', $count);
    }
});

it('creates internally consistent orders, items and history', function () {
    $this->seed(OrderDemoSeeder::class);

    foreach (Order::with(['items', 'history'])->get() as $order) {
        expect($order->items)->toHaveCount(3)
            ->and($order->subtotal_cents)->toBe($order->items->sum('line_total_cents'))
            ->and($order->total_cents)->toBe($order->subtotal_cents + $order->tax_cents + $order->shipping_cents)
            ->and($order->history->first()->from_status)->toBeNull()
            ->and($order->history->last()->to_status)->toBe($order->status);
        foreach ($order->items as $item) {
            expect($item->line_total_cents)->toBe($item->unit_price_cents * $item->quantity);
        }
        foreach ($order->history->values() as $i => $step) {
            $i === 0 || expect($step->from_status)->toBe($order->history[$i - 1]->to_status);
        }
    }
    expect(Order::where('order_number', 'ORD-NWAAA-AAAA5')->first()->created_at->toIso8601String())->toBe('2026-09-15T08:45:00+00:00');
});

it('aborts outside local and testing and DatabaseSeeder skips it', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => app(OrderDemoSeeder::class)->run())->toThrow(HttpException::class);

    app(DatabaseSeeder::class)->run();
    expect(Order::count())->toBe(0);
})->with(['production', 'staging', 'development', 'qa']);

it('runs from DatabaseSeeder in testing', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Order::count())->toBe(8);
});
