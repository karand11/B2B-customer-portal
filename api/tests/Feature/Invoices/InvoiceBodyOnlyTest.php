<?php

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
});

afterEach(fn () => $this->travelBack());

it('ignores due_date in the query string when creating an invoice', function (string $query) {
    $order = orderWith($this->w->a, [[1000, 1]]);

    $this->actingAs($this->w->staff)->postJson("/api/v1/orders/{$order->order_number}/invoice?{$query}", [])
        ->assertCreated()->assertJsonPath('data.due_date', '2026-12-26');
})->with(['due_date=2026-12-01', 'due_date=garbage', 'due_date[]=x', 'due_date=2020-01-01']);

it('still honours due_date in the JSON body and form body', function () {
    $this->actingAs($this->w->staff);
    $json = orderWith($this->w->a, [[1000, 1]]);
    $form = orderWith($this->w->a, [[1000, 1]]);

    $this->postJson("/api/v1/orders/{$json->order_number}/invoice?due_date=2026-12-02", ['due_date' => '2026-12-01'])->assertCreated()->assertJsonPath('data.due_date', '2026-12-01');
    $this->post("/api/v1/orders/{$form->order_number}/invoice?due_date=2026-12-02", ['due_date' => '2026-12-03'])->assertCreated()->assertJsonPath('data.due_date', '2026-12-03');
});

it('ignores paid_on in the query string when marking paid', function (string $query) {
    $invoice = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-11-20')->create();

    $this->actingAs($this->w->staff)->postJson("/api/v1/invoices/{$invoice->public_id}/mark-paid?{$query}", [])
        ->assertOk()->assertJsonPath('data.paid_on', '2026-11-26');
})->with(['paid_on=2026-11-24', 'paid_on=garbage', 'paid_on=2030-01-01']);

it('ignores reason in the query string: a void without a body reason is still 422', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();

    $this->actingAs($this->w->staff)->postJson("/api/v1/invoices/{$invoice->public_id}/void?reason=Sneaky", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/invoices/{$invoice->public_id}/void?reason=Sneaky")->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect($invoice->fresh()->status->value)->toBe('open');

    $this->postJson("/api/v1/invoices/{$invoice->public_id}/void?reason=Sneaky", ['reason' => 'Body wins'])->assertOk();
    expect(DB::table('invoice_status_history')->where('invoice_id', $invoice->id)->where('to_status', 'void')->value('note'))->toBe('Body wins');
});
