<?php

use App\Models\Invoice;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
});

afterEach(fn () => $this->travelBack());

function invoiceHistory(Invoice $invoice): array
{
    return DB::table('invoice_status_history')->where('invoice_id', $invoice->id)->orderBy('id')->get(['from_status', 'to_status', 'actor_user_id', 'note'])
        ->map(fn ($r) => (array) $r)->all();
}

// ---------------------------------------------------------------- mark paid

it('marks an open invoice paid with the given date, one history row and terminal actions', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-20')->create();

    $response = loginAs($this->w->staff)->postJson("/api/v1/invoices/{$invoice->public_id}/mark-paid", ['paid_on' => '2026-11-24'])->assertOk();

    expect(array_keys($response->json('data')))->toBe(INVOICE_DETAIL_KEYS)
        ->and($response->json('data.status'))->toBe('paid')->and($response->json('data.paid_on'))->toBe('2026-11-24')
        ->and($response->json('data.allowed_actions'))->toBe(['download'])
        ->and(array_slice(invoiceHistory($invoice), 1))->toBe([['from_status' => 'open', 'to_status' => 'paid', 'actor_user_id' => $this->w->staff->id, 'note' => null]])
        ->and($invoice->fresh()->paid_on->toDateString())->toBe('2026-11-24');
});

it('defaults paid_on to today (UTC) and accepts today and the issue date as bounds', function (?string $paidOn, string $expected) {
    $invoice = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-20')->create();

    loginAs($this->w->admin)->postJson("/api/v1/invoices/{$invoice->public_id}/mark-paid", $paidOn === null ? [] : ['paid_on' => $paidOn])
        ->assertOk()->assertJsonPath('data.paid_on', $expected);
})->with([[null, '2026-11-26'], ['2026-11-26', '2026-11-26'], ['2026-11-20', '2026-11-20']]);

it('rejects a future, pre-issue or invalid paid_on with 422 and changes nothing', function (mixed $paidOn) {
    $invoice = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-20')->create();

    loginAs($this->w->staff)->postJson("/api/v1/invoices/{$invoice->public_id}/mark-paid", ['paid_on' => $paidOn])->assertUnprocessable()->assertJsonValidationErrors('paid_on');

    expect($invoice->fresh()->status->value)->toBe('open')->and(invoiceHistory($invoice))->toHaveCount(1);
})->with(['future' => '2026-11-27', 'before issue' => '2026-11-19', 'impossible' => '2026-02-30', 'datetime' => '2026-11-24T10:00:00Z', 'number' => 20261124, 'array' => [['2026-11-24']], 'text' => 'yesterday']);

it('pays an overdue invoice, stored or effective, recording the stored status as from_status', function () {
    $stored = Invoice::factory()->forCompany($this->w->a)->overdue()->create();
    $effective = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-10-01')->dueOn('2026-11-01')->create();
    loginAs($this->w->staff);

    $this->postJson("/api/v1/invoices/{$stored->public_id}/mark-paid")->assertOk()->assertJsonPath('data.status', 'paid');
    $this->postJson("/api/v1/invoices/{$effective->public_id}/mark-paid")->assertOk()->assertJsonPath('data.status', 'paid');

    expect(invoiceHistory($stored)[2])->toMatchArray(['from_status' => 'overdue', 'to_status' => 'paid'])
        ->and(invoiceHistory($effective)[1])->toMatchArray(['from_status' => 'open', 'to_status' => 'paid']);
});

it('refuses a second payment and a payment on a void invoice with 422 on status', function () {
    $paid = Invoice::factory()->forCompany($this->w->a)->paid()->create();
    $void = Invoice::factory()->forCompany($this->w->a)->void()->create();
    loginAs($this->w->staff);

    $this->postJson("/api/v1/invoices/{$paid->public_id}/mark-paid")->assertUnprocessable()->assertJsonPath('errors.status.0', 'This invoice is already paid.');
    $this->postJson("/api/v1/invoices/{$void->public_id}/mark-paid")->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(invoiceHistory($paid))->toHaveCount(2)->and(invoiceHistory($void))->toHaveCount(2);
});

it('ignores forged fields on mark-paid', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();

    loginAs($this->w->staff)->postJson("/api/v1/invoices/{$invoice->public_id}/mark-paid", ['status' => 'void', 'total_cents' => 1, 'number' => 'X', 'company_id' => 99])->assertOk()
        ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.total_cents', $invoice->total_cents)->assertJsonPath('data.number', $invoice->number);
});

// ---------------------------------------------------------------- void

it('voids an open or overdue invoice, keeps the number and listing, and shows the reason to staff only', function (string $state) {
    $invoice = Invoice::factory()->forCompany($this->w->a)->{$state}()->create();

    $response = loginAs($this->w->staff)->postJson("/api/v1/invoices/{$invoice->public_id}/void", ['reason' => "Wrong billing address\nreissue please"])->assertOk();
    $note = fn ($viewer) => collect(loginAs($viewer)->getJson("/api/v1/invoices/{$invoice->public_id}")->assertOk()->json('data.history'))->pluck('note')->last();

    expect($response->json('data.status'))->toBe('void')->and($response->json('data.number'))->toBe($invoice->number)->and($response->json('data.allowed_actions'))->toBe(['download'])
        ->and(collect($response->json('data.history'))->last()['note'])->toBe("Wrong billing address\nreissue please")
        ->and($note($this->w->staff))->toBe("Wrong billing address\nreissue please")->and($note($this->w->admin))->toBe("Wrong billing address\nreissue please")
        ->and($note($this->w->a1))->toBeNull()
        ->and(collect(loginAs($this->w->a1)->getJson('/api/v1/invoices')->json('data'))->pluck('status', 'number')->all())->toBe([$invoice->number => 'void'])
        ->and(DB::table('invoice_status_history')->where('invoice_id', $invoice->id)->where('to_status', 'void')->value('actor_user_id'))->toBe($this->w->staff->id);
})->with(['open', 'overdue']);

it('voids an effective-overdue invoice that the command has not yet materialised', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-10-01')->dueOn('2026-11-01')->create();

    loginAs($this->w->staff)->postJson("/api/v1/invoices/{$invoice->public_id}/void", ['reason' => 'Mistake'])->assertOk()->assertJsonPath('data.status', 'void');

    expect(invoiceHistory($invoice)[1])->toMatchArray(['from_status' => 'open', 'to_status' => 'void']);
});

it('requires a clean reason of 1 to 500 characters (422 on reason)', function (mixed $reason) {
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();

    loginAs($this->w->staff)->postJson("/api/v1/invoices/{$invoice->public_id}/void", $reason === '__missing__' ? [] : ['reason' => $reason])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    expect($invoice->fresh()->status->value)->toBe('open')->and(invoiceHistory($invoice))->toHaveCount(1);
})->with([
    'missing' => '__missing__', 'null' => null, 'empty' => '', 'blank' => '   ', '501 chars' => str_repeat('a', 501), 'control char' => "bad\u{0007}reason", 'bidi override' => "ab\u{202E}cd",
    'zero width' => "ab\u{200B}cd", 'interior NUL' => "ab\x00cd", 'array' => [['x']], 'number' => 5,
]);

it('accepts a reason of exactly 500 characters, including newline and tab', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();
    $reason = str_repeat('é', 498)."\n\t";

    loginAs($this->w->staff)->postJson("/api/v1/invoices/{$invoice->public_id}/void", ['reason' => $reason])->assertOk();

    expect(mb_strlen(invoiceHistory($invoice)[1]['note']))->toBeLessThanOrEqual(500);
});

it('refuses to void a paid or an already void invoice with 422 on status', function () {
    $paid = Invoice::factory()->forCompany($this->w->a)->paid()->create();
    $void = Invoice::factory()->forCompany($this->w->a)->void()->create();
    loginAs($this->w->staff);

    foreach ([$paid, $void] as $invoice) {
        $this->postJson("/api/v1/invoices/{$invoice->public_id}/void", ['reason' => 'x'])->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Only open or overdue invoices can be voided.');
    }
    expect($paid->fresh()->status->value)->toBe('paid')->and($void->fresh()->status->value)->toBe('void');
});

// ---------------------------------------------------------------- order interplay

it('refuses to cancel an order with a non-void invoice and allows it after the void', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();
    $order = Order::findOrFail($invoice->order_id);
    loginAs($this->w->staff);

    $this->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'cancelled'])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'This order has an invoice. Void the invoice first.');
    $this->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertUnprocessable()->assertJsonValidationErrors('status');
    expect($order->fresh()->status->value)->toBe('confirmed');

    $this->postJson("/api/v1/invoices/{$invoice->public_id}/void", ['reason' => 'Cancelling the order'])->assertOk();
    $this->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('lets a paid invoice block cancellation too, and an unrelated cancellation is unaffected', function () {
    $paid = Invoice::factory()->forCompany($this->w->a)->paid()->create();
    $plain = orderWith($this->w->a, [[1000, 1]]);
    loginAs($this->w->staff);

    $this->patchJson('/api/v1/orders/'.Order::findOrFail($paid->order_id)->order_number.'/status', ['status' => 'cancelled'])->assertUnprocessable();
    $this->patchJson("/api/v1/orders/{$plain->order_number}/status", ['status' => 'cancelled'])->assertOk();
});

it('invoices the order again after a void and then shows only the new non-void invoice on the order', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();
    $order = Order::findOrFail($invoice->order_id);
    loginAs($this->w->staff);
    $this->postJson("/api/v1/invoices/{$invoice->public_id}/void", ['reason' => 'Redo'])->assertOk();

    expect($this->getJson("/api/v1/orders/{$order->order_number}")->json('data.invoice'))->toBeNull();

    $second = issueInvoiceAs($this->w->staff, $order)->assertCreated();

    expect($second->json('data.number'))->toBe('INV-2026-000002')
        ->and($this->getJson("/api/v1/orders/{$order->order_number}")->json('data.invoice'))->toBe(['id' => $second->json('data.id'), 'number' => 'INV-2026-000002', 'status' => 'open']);
});
