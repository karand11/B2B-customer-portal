<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Policies\InvoicePolicy;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->w = orderWorld();
    $this->invoice = Invoice::factory()->forCompany($this->w->a)->create();
    $this->policy = new InvoicePolicy;
});

it('lets staff, admin and customers of the same company view and download, and hides it from everyone else as a 404', function () {
    foreach (['staff', 'admin', 'a1', 'a2'] as $who) {
        expect($this->policy->view($this->w->{$who}, $this->invoice)->allowed())->toBeTrue()->and($this->policy->download($this->w->{$who}, $this->invoice)->allowed())->toBeTrue();
    }

    $orphan = makeUser('customer', $this->w->a);
    $orphan->forceFill(['company_id' => null])->save();
    foreach ([$this->w->b1, $orphan, User::factory()->create()] as $user) {
        foreach (['view', 'download'] as $ability) {
            $response = $this->policy->{$ability}($user, $this->invoice);
            expect($response->allowed())->toBeFalse()->and($response->status())->toBe(404);
        }
    }
});

it('reserves create, mark-paid and void for staff and admin with a plain 403', function () {
    $order = Order::findOrFail($this->invoice->order_id);

    foreach (['staff', 'admin'] as $who) {
        expect($this->policy->create($this->w->{$who}, $order))->toBeTrue()->and($this->policy->markPaid($this->w->{$who}, $this->invoice))->toBeTrue()->and($this->policy->void($this->w->{$who}, $this->invoice))->toBeTrue();
    }
    foreach (['a1', 'b1'] as $who) {
        expect($this->policy->create($this->w->{$who}, $order))->toBeFalse()->and($this->policy->markPaid($this->w->{$who}, $this->invoice))->toBeFalse()->and($this->policy->void($this->w->{$who}, $this->invoice))->toBeFalse();
    }
});

it('is auto-discovered by the gate for the Invoice model', function () {
    expect(Gate::getPolicyFor(Invoice::class))->toBeInstanceOf(InvoicePolicy::class)
        ->and(Gate::forUser($this->w->b1)->allows('view', $this->invoice))->toBeFalse()
        ->and(Gate::forUser($this->w->a1)->allows('download', $this->invoice))->toBeTrue();
});

it('derives the effective status and allowed actions from one place', function () {
    expect(InvoiceStatus::effective(InvoiceStatus::Open, '2026-12-26', '2026-12-26'))->toBe(InvoiceStatus::Open)
        ->and(InvoiceStatus::effective(InvoiceStatus::Open, '2026-12-26', '2026-12-27'))->toBe(InvoiceStatus::Overdue)
        ->and(InvoiceStatus::effective(InvoiceStatus::Overdue, '2026-12-26', '2026-12-26'))->toBe(InvoiceStatus::Overdue)
        ->and(InvoiceStatus::effective(InvoiceStatus::Paid, '2020-01-01', '2026-12-27'))->toBe(InvoiceStatus::Paid)
        ->and(InvoiceStatus::effective(InvoiceStatus::Void, '2020-01-01', '2026-12-27'))->toBe(InvoiceStatus::Void)
        ->and(InvoiceStatus::Open->allowedActions($this->w->staff))->toBe(['download', 'mark_paid', 'void'])
        ->and(InvoiceStatus::Paid->allowedActions($this->w->staff))->toBe(['download'])
        ->and(InvoiceStatus::Open->allowedActions($this->w->a1))->toBe(['download']);
});
