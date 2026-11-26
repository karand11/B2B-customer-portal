<?php

use App\Models\Order;
use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\QuoteDemoSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

function seededQuotesOf(string $email): array
{
    return QuoteRequest::visibleTo(User::where('email', $email)->first())->orderBy('quote_number')->pluck('quote_number')->all();
}

it('seeds the 8 documented quotes idempotently with the documented visibility', function () {
    $this->seed(QuoteDemoSeeder::class);
    $first = QuoteRequest::orderBy('quote_number')->get()->map->only(['quote_number', 'status', 'created_at', 'total_cents'])->all();
    $this->seed(QuoteDemoSeeder::class);

    expect(QuoteRequest::count())->toBe(8)->and(QuoteRequest::orderBy('quote_number')->get()->map->only(['quote_number', 'status', 'created_at', 'total_cents'])->all())->toEqual($first)
        ->and(QuoteRequest::orderBy('quote_number')->pluck('status', 'quote_number')->map->value->all())->toBe([
            'QUO-GXAAA-AAAA2' => 'submitted', 'QUO-GXAAA-AAAA3' => 'quoted', 'QUO-NWAAA-AAAA2' => 'submitted', 'QUO-NWAAA-AAAA3' => 'in_review',
            'QUO-NWAAA-AAAA4' => 'quoted', 'QUO-NWAAA-AAAA5' => 'accepted', 'QUO-NWAAA-AAAA6' => 'declined', 'QUO-NWAAA-AAAA7' => 'expired',
        ])
        ->and(seededQuotesOf('alice@northwind.test'))->toHaveCount(6)->toBe(seededQuotesOf('bob@northwind.test'))
        ->and(seededQuotesOf('carol@globex.test'))->toBe(['QUO-GXAAA-AAAA2', 'QUO-GXAAA-AAAA3'])
        ->and(seededQuotesOf('staff@portal.test'))->toHaveCount(8)
        ->and(QuoteRequest::whereIn('status', ['submitted', 'in_review'])->count())->toBe(3)
        ->and(QuoteAttachment::count())->toBe(0);
});

it('links the accepted demo quote to the existing order without creating one', function () {
    $this->seed(QuoteDemoSeeder::class);

    $quote = QuoteRequest::where('quote_number', 'QUO-NWAAA-AAAA5')->firstOrFail();
    $order = Order::where('order_number', 'ORD-NWAAA-AAAA3')->firstOrFail();
    expect(Order::count())->toBe(8)->and($order->quote_request_id)->toBe($quote->id)->and(Order::whereNotNull('quote_request_id')->count())->toBe(1)
        ->and($quote->total_cents)->toBe($order->total_cents)->and($quote->items()->pluck('line_total_cents')->all())->toBe($order->items()->pluck('line_total_cents')->all())
        ->and($quote->po_reference)->toBe($order->po_reference);

    $this->actingAs(User::where('email', 'bob@northwind.test')->first())->getJson('/api/v1/orders/ORD-NWAAA-AAAA3')->assertOk()->assertJsonPath('data.quote_number', 'QUO-NWAAA-AAAA5');
    $this->getJson('/api/v1/quotes/QUO-NWAAA-AAAA5')->assertOk()->assertJsonPath('data.order.number', 'ORD-NWAAA-AAAA3')->assertJsonPath('data.status', 'accepted');
});

it('creates internally consistent quotes, items and history', function () {
    $this->seed(QuoteDemoSeeder::class);

    foreach (QuoteRequest::with(['items', 'history'])->get() as $quote) {
        $priced = $quote->items->every(fn ($i) => $i->unit_price_cents !== null);
        $priced && expect($quote->items->sum('line_total_cents'))->toBe($quote->subtotal_cents);
        expect($quote->items->count())->toBeGreaterThanOrEqual(1)
            ->and($quote->total_cents)->toBe($priced ? $quote->subtotal_cents : null)
            ->and($quote->history->first()->from_status)->toBeNull()->and($quote->history->first()->to_status->value)->toBe('submitted')
            ->and($quote->history->last()->to_status)->toBe($quote->status)
            ->and($quote->history->pluck('id')->all())->toBe($quote->history->sortBy('id')->pluck('id')->all());

        foreach ($quote->items as $item) {
            expect($item->line_total_cents)->toBe($item->unit_price_cents === null ? null : $item->unit_price_cents * $item->quantity);
        }
        if (in_array($quote->status->value, ['quoted', 'accepted', 'declined', 'expired'], true)) {
            expect($quote->sent_at)->not->toBeNull()->and($quote->valid_until)->not->toBeNull()->and($quote->revision)->toBe(1);
        }
    }

    $expired = QuoteRequest::where('quote_number', 'QUO-NWAAA-AAAA7')->with('history')->first();
    expect($expired->history->last()->actor_user_id)->toBeNull();
});

it('keeps the hidden draft hidden and the internal note away from customers', function () {
    $this->seed(QuoteDemoSeeder::class);

    $bob = $this->actingAs(User::where('email', 'bob@northwind.test')->first())->getJson('/api/v1/quotes/QUO-NWAAA-AAAA3')->assertOk();
    expect($bob->json('data'))->not->toHaveKey('internal_note')->and($bob->json('data.items.0.unit_price_cents'))->toBeNull()->and($bob->getContent())->not->toContain('lead time');

    $staff = $this->actingAs(User::where('email', 'staff@portal.test')->first())->getJson('/api/v1/quotes/QUO-NWAAA-AAAA3')->assertOk();
    expect($staff->json('data.internal_note'))->toBe('Check lead time with supplier')->and($staff->json('data.items.0.unit_price_cents'))->not->toBeNull();

    $this->actingAs(User::where('email', 'alice@northwind.test')->first())->getJson('/api/v1/quotes/QUO-GXAAA-AAAA2')->assertNotFound();
});

it('runs from DatabaseSeeder in testing and refuses to run elsewhere', function () {
    $this->seed(DatabaseSeeder::class);
    expect(QuoteRequest::count())->toBe(8)->and(Order::count())->toBe(8);

});

it('aborts outside local and testing and DatabaseSeeder skips it', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => app(QuoteDemoSeeder::class)->run())->toThrow(HttpException::class);

    app(DatabaseSeeder::class)->run();
    expect(QuoteRequest::count())->toBe(0);
})->with(['production', 'staging', 'development', 'qa']);
