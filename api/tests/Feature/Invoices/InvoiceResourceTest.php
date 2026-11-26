<?php

use App\Models\Invoice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->w = orderWorld();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'UTC'));
});

afterEach(fn () => $this->travelBack());

/** Every key name anywhere in a decoded JSON structure. */
function allKeys(mixed $data): array
{
    $keys = [];
    foreach ((array) $data as $key => $value) {
        is_string($key) && $keys[] = $key;
        is_array($value) && $keys = [...$keys, ...allKeys($value)];
    }

    return $keys;
}

it('emits exactly the documented keys for list items and details, with typed values', function () {
    $invoice = Invoice::factory()->forOrder(orderWith($this->w->a, [[1250, 3], [333, 10]], 'confirmed', ['po_reference' => 'PO-1']))->paid('2026-11-26')->create();

    foreach (['a1', 'staff'] as $who) {
        $list = loginAs($this->w->{$who})->getJson('/api/v1/invoices')->assertOk()->json('data.0');
        $detail = $this->getJson("/api/v1/invoices/{$invoice->public_id}")->assertOk()->json('data');

        expect(array_keys($list))->toBe(INVOICE_KEYS)->and(array_keys($detail))->toBe(INVOICE_DETAIL_KEYS)
            ->and($list['id'])->toMatch(UUID_V4)->and($detail['id'])->toBe($list['id'])
            ->and($list['status'])->toBe('paid')->and($list['paid_on'])->toBe('2026-11-26')->and($list['days_overdue'])->toBeInt()
            ->and($list['issue_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')->and($list['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($list['company'])->toBe(['id' => $this->w->a->id, 'name' => $this->w->a->name])
            ->and(array_keys($detail['items'][0]))->toBe(['position', 'part_number', 'name', 'brand', 'quantity', 'unit_price_cents', 'line_total_cents'])
            ->and(array_keys($detail['history'][0]))->toBe(['from_status', 'to_status', 'actor', 'note', 'created_at'])
            ->and(array_keys($detail['seller']))->toBe(['name', 'address_lines', 'tax_id', 'email', 'phone', 'bank', 'payment_terms_text', 'footer', 'vat_label'])
            ->and(array_keys($detail['seller']['bank']))->toBe(['account_holder', 'bank_name', 'iban', 'bic'])
            ->and(array_keys($detail['buyer']))->toBe(['name', 'address_lines', 'tax_id']);

        foreach (['subtotal_cents', 'vat_rate_bp', 'tax_cents', 'total_cents'] as $money) {
            expect($list[$money])->toBeInt();
        }
        foreach ($detail['items'] as $item) {
            expect($item['unit_price_cents'])->toBeInt()->and($item['line_total_cents'])->toBeInt()->and($item['quantity'])->toBeInt();
        }
        // No numeric database ids, company_id, user ids or internal columns anywhere (company.id is the documented company object).
        expect(array_diff(allKeys($detail), ['id']))->not->toContain('company_id')->not->toContain('user_id')->not->toContain('created_by_user_id')
            ->not->toContain('order_id')->not->toContain('invoice_id')->not->toContain('actor_user_id')->not->toContain('email_verified_at');
    }
});

it('gives customers only download and staff mark_paid and void while open or overdue', function () {
    $open = Invoice::factory()->forCompany($this->w->a)->create();
    $overdue = Invoice::factory()->forCompany($this->w->a)->issuedOn('2026-10-01')->dueOn('2026-11-01')->create();
    $paid = Invoice::factory()->forCompany($this->w->a)->paid()->create();
    $void = Invoice::factory()->forCompany($this->w->a)->void()->create();
    $actions = fn ($user, Invoice $i) => loginAs($user)->getJson("/api/v1/invoices/{$i->public_id}")->json('data.allowed_actions');

    foreach ([$open, $overdue, $paid, $void] as $invoice) {
        expect($actions($this->w->a1, $invoice))->toBe(['download']);
    }
    foreach (['staff', 'admin'] as $who) {
        expect($actions($this->w->{$who}, $open))->toBe(['download', 'mark_paid', 'void'])->and($actions($this->w->{$who}, $overdue))->toBe(['download', 'mark_paid', 'void'])
            ->and($actions($this->w->{$who}, $paid))->toBe(['download'])->and($actions($this->w->{$who}, $void))->toBe(['download']);
    }
});

it('shows the system actor as name null and role system, and masks staff for customers', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->by($this->w->staff)->overdue()->create();
    $history = fn ($viewer) => loginAs($viewer)->getJson("/api/v1/invoices/{$invoice->public_id}")->json('data.history');

    expect($history($this->w->staff))->toMatchArray([
        ['from_status' => null, 'to_status' => 'open', 'actor' => ['name' => $this->w->staff->name, 'role' => 'staff'], 'note' => null, 'created_at' => $history($this->w->staff)[0]['created_at']],
        ['from_status' => 'open', 'to_status' => 'overdue', 'actor' => ['name' => null, 'role' => 'system'], 'note' => null, 'created_at' => $history($this->w->staff)[1]['created_at']],
    ]);

    $customerView = $history($this->w->a1);
    expect($customerView[0]['actor'])->toBe(['name' => null, 'role' => 'staff'])->and($customerView[1]['actor'])->toBe(['name' => null, 'role' => 'system'])
        ->and(json_encode($customerView))->not->toContain($this->w->staff->name);
});

it('shows an admin actor as admin to staff viewers', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->by($this->w->admin)->create();

    expect(loginAs($this->w->staff)->getJson("/api/v1/invoices/{$invoice->public_id}")->json('data.history.0.actor'))->toBe(['name' => $this->w->admin->name, 'role' => 'admin']);
});

it('never exposes the live company name: the buyer snapshot name is used in the list', function () {
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();
    $original = $this->w->a->name;
    $this->w->a->forceFill(['name' => 'Renamed After Issue Ltd'])->save();

    expect(loginAs($this->w->a1)->getJson('/api/v1/invoices')->json('data.0.company.name'))->toBe($original)
        ->and(json_encode(loginAs($this->w->staff)->getJson("/api/v1/invoices/{$invoice->public_id}")->json()))->not->toContain('Renamed After Issue');
});

it('exposes invoice text only as plain strings', function () {
    config(['invoicing.seller.name' => 'Evil <script>alert(1)</script> Seller']);
    $invoice = Invoice::factory()->forCompany($this->w->a)->create();

    $detail = loginAs($this->w->a1)->getJson("/api/v1/invoices/{$invoice->public_id}");

    expect($detail->json('data.seller.name'))->toBe('Evil <script>alert(1)</script> Seller')->and($detail->headers->get('Content-Type'))->toContain('application/json');
});
