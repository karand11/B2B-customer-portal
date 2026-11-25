<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\OrderStatusChanger;
use Illuminate\Validation\ValidationException;

const ALLOWED = [
    'pending' => ['confirmed', 'cancelled'], 'confirmed' => ['shipped', 'cancelled'], 'shipped' => ['delivered'],
    'delivered' => [], 'cancelled' => [],
];

beforeEach(function () {
    $this->w = orderWorld();
});

function orderIn(string $status, $w): Order
{
    return Order::factory()->forCompany($w->a, $w->a1)->{$status}()->create();
}

function transitionPairs(): array
{
    $pairs = [];
    foreach (ALLOWED as $from => $tos) {
        foreach (['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'] as $to) {
            $pairs["{$from} -> {$to}"] = [$from, $to, in_array($to, $tos, true)];
        }
    }

    return $pairs;
}

it('applies exactly the allowed transitions for staff and admin', function (string $role, string $from, string $to, bool $allowed) {
    $order = orderIn($from, $this->w);
    $historyBefore = OrderStatusHistory::where('order_id', $order->id)->count();
    $this->travelTo(now()->addDay()->startOfSecond());

    $response = loginAs($this->w->{$role})->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => $to]);

    if ($allowed) {
        $response->assertOk()->assertJsonPath('data.status', $to);
        $last = OrderStatusHistory::where('order_id', $order->id)->orderByDesc('id')->first();
        expect(OrderStatusHistory::where('order_id', $order->id)->count())->toBe($historyBefore + 1)
            ->and($last->from_status->value)->toBe($from)->and($last->to_status->value)->toBe($to)
            ->and($last->actor_user_id)->toBe($this->w->{$role}->id)->and($last->created_at->toIso8601String())->toBe(now()->toIso8601String())
            ->and($response->json('data.history.*.to_status'))->toContain($to);
    } else {
        // 'pending' as a target is rejected by validation; every other bad edge by the matrix. Both are 422 on status.
        $response->assertStatus(422)->assertJsonValidationErrors('status');
        expect($order->fresh()->status->value)->toBe($from)->and(OrderStatusHistory::where('order_id', $order->id)->count())->toBe($historyBefore);
    }
})->with(['staff', 'admin'])->with(fn () => transitionPairs());

it('rejects an invalid status value with 422', function (mixed $value) {
    $order = orderIn('pending', $this->w);

    loginAs($this->w->staff)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => $value])
        ->assertStatus(422)->assertJsonValidationErrors('status');
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
})->with(['pending', 'unknown', '', null, [['confirmed']], 5, 'CONFIRMED']);

it('rejects a missing status and answers 404 for an unknown number as staff', function () {
    $order = orderIn('pending', $this->w);

    loginAs($this->w->staff)->patchJson("/api/v1/orders/{$order->order_number}/status")->assertStatus(422)->assertJsonValidationErrors('status');
    $this->patchJson('/api/v1/orders/ORD-ZZZZZ-ZZZZZ/status', ['status' => 'confirmed'])->assertNotFound()->assertExactJson(NOT_FOUND);
});

it('lets a customer cancel only a pending order, with a 422 for every other state', function (string $state, bool $allowed) {
    $order = orderIn($state, $this->w);

    $response = loginAs($this->w->a1)->postJson("/api/v1/orders/{$order->order_number}/cancel");

    if ($allowed) {
        $response->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.allowed_transitions', []);
        $last = OrderStatusHistory::where('order_id', $order->id)->orderByDesc('id')->first();
        expect($last->from_status->value)->toBe('pending')->and($last->to_status->value)->toBe('cancelled')->and($last->actor_user_id)->toBe($this->w->a1->id);
    } else {
        $response->assertStatus(422)->assertJsonValidationErrors('status');
        expect($order->fresh()->status->value)->toBe($state);
    }
})->with([['pending', true], ['confirmed', false], ['shipped', false], ['delivered', false], ['cancelled', false]]);

it('lets staff cancel pending and confirmed orders but not shipped ones', function (string $state, bool $allowed) {
    $order = orderIn($state, $this->w);

    loginAs($this->w->staff)->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertStatus($allowed ? 200 : 422);
})->with([['pending', true], ['confirmed', true], ['shipped', false], ['delivered', false], ['cancelled', false]]);

it('refuses a second cancel without writing another history row', function () {
    $order = orderIn('pending', $this->w);
    loginAs($this->w->a1)->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertOk();
    $rows = OrderStatusHistory::where('order_id', $order->id)->count();

    $this->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertStatus(422);
    expect(OrderStatusHistory::where('order_id', $order->id)->count())->toBe($rows);
});

it('stops a customer cancel after staff confirmed the order', function () {
    $order = orderIn('pending', $this->w);
    loginAs($this->w->staff)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertOk();

    loginAs($this->w->a1)->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertStatus(422);
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('re-reads the locked row, so a stale model cannot apply a transition from an old state', function () {
    $order = orderIn('pending', $this->w);
    $stale = Order::find($order->id);
    Order::whereKey($order->id)->update(['status' => 'confirmed']);

    // The stale copy still says pending; the service must use the locked row (confirmed) and refuse the customer.
    expect(fn () => app(OrderStatusChanger::class)->change($stale, OrderStatus::Cancelled, $this->w->a1))
        ->toThrow(ValidationException::class);
});

it('computes allowed_transitions for the viewer', function (string $role, string $state, array $expected) {
    $order = orderIn($state, $this->w);
    $user = $role === 'customer' ? $this->w->a1 : $this->w->{$role};

    loginAs($user)->getJson("/api/v1/orders/{$order->order_number}")->assertOk()->assertJsonPath('data.allowed_transitions', $expected);
})->with([
    ['customer', 'pending', ['cancelled']], ['customer', 'confirmed', []], ['customer', 'shipped', []], ['customer', 'cancelled', []],
    ['staff', 'pending', ['confirmed', 'cancelled']], ['staff', 'confirmed', ['shipped', 'cancelled']], ['staff', 'shipped', ['delivered']],
    ['staff', 'delivered', []], ['staff', 'cancelled', []], ['admin', 'pending', ['confirmed', 'cancelled']],
]);

it('lists history oldest first and masks staff names for customers only', function () {
    $order = orderIn('pending', $this->w);
    loginAs($this->w->staff)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertOk();
    loginAs($this->w->admin)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'shipped'])->assertOk();

    $customerView = loginAs($this->w->a2)->getJson("/api/v1/orders/{$order->order_number}")->assertOk()->json('data.history');
    expect(array_column($customerView, 'to_status'))->toBe(['pending', 'confirmed', 'shipped'])
        ->and($customerView[0])->toMatchArray(['from_status' => null, 'actor' => ['name' => $this->w->a1->name, 'role' => 'customer']])
        ->and($customerView[1]['actor'])->toBe(['name' => null, 'role' => 'staff'])
        ->and($customerView[2]['actor'])->toBe(['name' => null, 'role' => 'staff'])
        ->and(json_encode($customerView))->not->toContain($this->w->staff->name)->not->toContain($this->w->admin->name);

    $staffView = loginAs($this->w->staff)->getJson("/api/v1/orders/{$order->order_number}")->json('data.history');
    expect($staffView[1]['actor'])->toBe(['name' => $this->w->staff->name, 'role' => 'staff'])
        ->and($staffView[2]['actor'])->toBe(['name' => $this->w->admin->name, 'role' => 'admin'])
        ->and($staffView[0]['actor'])->toBe(['name' => $this->w->a1->name, 'role' => 'customer']);
});
