<?php

use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->w = orderWorld();
    $this->orderA = Order::factory()->forCompany($this->w->a, $this->w->a1)->create();
    $this->nobody = makeUser('customer');
    $this->nobody->forceFill(['company_id' => null])->save();
    $this->policy = new OrderPolicy;
});

/** @return array{bool, ?int} [allowed, HTTP status when denied] */
function verdict(Response|bool $r): array
{
    return is_bool($r) ? [$r, $r ? null : 403] : [$r->allowed(), $r->allowed() ? null : ($r->status() ?? 403)];
}

it('answers view, cancel, reorder, create and transition for every kind of user', function (string $who, string $ability, array $expected) {
    $user = ['same' => $this->w->a2, 'other' => $this->w->b1, 'nobody' => $this->nobody, 'staff' => $this->w->staff, 'admin' => $this->w->admin][$who];
    $result = $ability === 'create' ? $this->policy->create($user) : $this->policy->{$ability}($user, $this->orderA);

    expect(verdict($result))->toBe($expected);
})->with([
    // view
    ['same', 'view', [true, null]], ['other', 'view', [false, 404]], ['nobody', 'view', [false, 404]], ['staff', 'view', [true, null]], ['admin', 'view', [true, null]],
    // cancel
    ['same', 'cancel', [true, null]], ['other', 'cancel', [false, 404]], ['nobody', 'cancel', [false, 404]], ['staff', 'cancel', [true, null]], ['admin', 'cancel', [true, null]],
    // reorder (customers only)
    ['same', 'reorder', [true, null]], ['other', 'reorder', [false, 404]], ['nobody', 'reorder', [false, 404]], ['staff', 'reorder', [false, 403]], ['admin', 'reorder', [false, 403]],
    // create
    ['same', 'create', [true, null]], ['nobody', 'create', [false, 403]], ['staff', 'create', [false, 403]], ['admin', 'create', [false, 403]],
    // transition (staff and admin only, no company check)
    ['same', 'transition', [false, 403]], ['other', 'transition', [false, 403]], ['staff', 'transition', [true, null]], ['admin', 'transition', [true, null]],
]);

it('answers viewAny for the roles that may list', function () {
    expect($this->policy->viewAny($this->w->a1))->toBeTrue()
        ->and($this->policy->viewAny($this->w->staff))->toBeTrue()
        ->and($this->policy->viewAny($this->w->admin))->toBeTrue()
        ->and($this->policy->viewAny($this->nobody))->toBeFalse();
});

it('is discovered for Order and does not lazy load roles for a user without loaded relations', function () {
    $fresh = User::find($this->w->a2->id);

    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Gate::getPolicyFor(Order::class))->toBeInstanceOf(OrderPolicy::class)
        ->and(Gate::forUser($fresh)->allows('view', $this->orderA))->toBeTrue();
});

it('renders a policy not-found as the exact 404 body with debug on and off', function (bool $debug) {
    config(['app.debug' => $debug]);
    $order = $this->orderA;
    Route::middleware(['api', 'auth:sanctum'])->get('/api/v1/_probe', function () use ($order) {
        Gate::authorize('view', $order);

        return response()->json(['ok' => true]);
    });

    loginAs($this->w->b1)->getJson('/api/v1/_probe')->assertNotFound()->assertExactJson(NOT_FOUND);
    loginAs($this->w->a1)->getJson('/api/v1/_probe')->assertOk();
})->with([true, false]);

it('scopes Order::visibleTo by user kind', function () {
    $other = Order::factory()->forCompany($this->w->b, $this->w->b1)->create();

    expect(Order::visibleTo($this->w->a1)->pluck('id')->all())->toBe([$this->orderA->id])
        ->and(Order::visibleTo($this->w->b1)->pluck('id')->all())->toBe([$other->id])
        ->and(Order::visibleTo($this->w->staff)->count())->toBe(2)
        ->and(Order::visibleTo($this->w->admin)->count())->toBe(2)
        ->and(Order::visibleTo($this->nobody)->count())->toBe(0)
        ->and(Order::visibleTo(User::factory()->create())->count())->toBe(0); // no role at all
});

it('queries orders in controllers only through visibleTo', function () {
    $files = glob(app_path('Http/Controllers/Api/V1/*.php'));
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $code = file_get_contents($file);
        preg_match_all('/\bOrder::(\w+)/', $code, $m);
        foreach ($m[1] as $call) {
            expect($call)->toBeIn(['visibleTo', 'class'], "{$file} calls Order::{$call}");
        }
        expect($code)->not->toMatch('/DB::table\(.orders/');
    }
});
