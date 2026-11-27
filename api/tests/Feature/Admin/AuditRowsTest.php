<?php

use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\QuoteRequest;
use App\Services\AuditLog;
use App\Services\OrderStatusChanger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    $this->w = quoteWorld();
});

afterEach(fn () => $this->travelBack());

function auditLogOrder(object $w, string $status = 'pending'): Order
{
    return orderWith($w->a, [[1000, 2]], $status);
}

it('writes an order.status_changed row with the staff actor for the status endpoint', function () {
    $w = $this->w;
    $order = auditLogOrder($w);

    loginAs($w->staff)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertOk();

    $row = auditLogOne('order.status_changed');
    auditLogExpect($row, $w->staff, 'order', $order->order_number, $w->a->id, auditLogStatus('pending', 'confirmed'));
    expect($row['subject_label'])->toBe($order->order_number);
});

it('writes an order.status_changed row with the admin actor', function () {
    $w = $this->w;
    $order = auditLogOrder($w, 'confirmed');

    loginAs($w->admin)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'shipped'])->assertOk();

    auditLogExpect(auditLogOne('order.status_changed'), $w->admin, 'order', $order->order_number, $w->a->id, auditLogStatus('confirmed', 'shipped'));
});

it('writes one row for a staff cancel through the status endpoint and one for a customer cancel through the cancel endpoint', function () {
    $w = $this->w;
    $staffOrder = auditLogOrder($w);
    $customerOrder = auditLogOrder($w);

    loginAs($w->staff)->patchJson("/api/v1/orders/{$staffOrder->order_number}/status", ['status' => 'cancelled'])->assertOk();
    loginAs($w->a1)->postJson("/api/v1/orders/{$customerOrder->order_number}/cancel")->assertOk();

    $rows = collect(auditLogRows('order.status_changed'))->keyBy('subject_id');
    expect($rows)->toHaveCount(2);
    auditLogExpect($rows[$staffOrder->order_number], $w->staff, 'order', $staffOrder->order_number, $w->a->id, auditLogStatus('pending', 'cancelled'));
    auditLogExpect($rows[$customerOrder->order_number], $w->a1, 'order', $customerOrder->order_number, $w->a->id, auditLogStatus('pending', 'cancelled'));
    expect($rows[$customerOrder->order_number]['actor_role'])->toBe('customer');
});

it('writes no row for a refused order transition or for a customer on the status endpoint', function () {
    $w = $this->w;
    $order = auditLogOrder($w);

    loginAs($w->staff)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'delivered'])->assertStatus(422);
    loginAs($w->a1)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'cancelled'])->assertForbidden();

    expect(auditLogRows())->toBe([]);
});

it('writes a row for every quote action, once, with the right actor', function () {
    $w = $this->w;
    $customerQuote = makeQuote($w, 'submitted');
    $n = $customerQuote->quote_number;

    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/review")->assertOk();
    auditLogExpect(auditLogOne('quote.reviewed'), $w->staff, 'quote', $n, $w->a->id, auditLogStatus('submitted', 'in_review'));

    loginAs($w->staff)->putJson("/api/v1/quotes/{$n}/pricing", ['lines' => [['position' => 1, 'unit_price_cents' => 1250], ['position' => 2, 'unit_price_cents' => 3875]], 'valid_until' => '2026-12-31'])->assertOk();
    auditLogExpect(auditLogOne('quote.priced'), $w->staff, 'quote', $n, $w->a->id, [
        'before' => ['subtotal_cents' => null, 'total_cents' => null, 'valid_until' => null],
        'after' => ['subtotal_cents' => 28000, 'total_cents' => 28000, 'valid_until' => '2026-12-31'],
    ], ['lines_changed' => 2]);

    // Saving the same prices again changes nothing: no second row.
    loginAs($w->staff)->putJson("/api/v1/quotes/{$n}/pricing", ['lines' => [['position' => 1, 'unit_price_cents' => 1250]]])->assertOk();
    expect(auditLogRows('quote.priced'))->toHaveCount(1);

    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/send")->assertOk();
    auditLogExpect(auditLogOne('quote.sent'), $w->staff, 'quote', $n, $w->a->id, [
        'before' => ['status' => 'in_review', 'revision' => 0],
        'after' => ['status' => 'quoted', 'revision' => 1, 'valid_until' => '2026-12-31', 'total_cents' => 28000],
    ]);

    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/reopen")->assertOk();
    auditLogExpect(auditLogOne('quote.reopened'), $w->staff, 'quote', $n, $w->a->id, auditLogStatus('quoted', 'in_review'));

    loginAs($w->staff)->postJson("/api/v1/quotes/{$n}/send")->assertOk();
    loginAs($w->a1)->postJson("/api/v1/quotes/{$n}/accept", ['revision' => 2])->assertOk();
    $orderNumber = Order::where('quote_request_id', $customerQuote->id)->value('order_number');
    auditLogExpect(auditLogOne('quote.accepted'), $w->a1, 'quote', $n, $w->a->id, auditLogStatus('quoted', 'accepted'), ['order_number' => $orderNumber]);
    expect(auditLogRows())->toHaveCount(6); // review, priced, sent, reopened, sent, accepted: exactly one per action
});

it('writes quote.rejected for staff and quote.declined for the customer', function () {
    $w = $this->w;
    $rejected = makeQuote($w, 'submitted');
    $declined = makeQuote($w, 'quoted');

    loginAs($w->staff)->postJson("/api/v1/quotes/{$rejected->quote_number}/reject", ['reason' => 'PRIVATE-REJECT-REASON'])->assertOk();
    loginAs($w->a1)->postJson("/api/v1/quotes/{$declined->quote_number}/decline", ['reason' => 'PRIVATE-DECLINE-REASON'])->assertOk();

    auditLogExpect(auditLogOne('quote.rejected'), $w->staff, 'quote', $rejected->quote_number, $w->a->id, auditLogStatus('submitted', 'declined'));
    auditLogExpect(auditLogOne('quote.declined'), $w->a1, 'quote', $declined->quote_number, $w->a->id, auditLogStatus('quoted', 'declined'));
    expect(json_encode(auditLogRows()))->not->toContain('PRIVATE-');
});

it('does not audit notes, drafts without a change, refused actions or the expiry job', function () {
    $w = $this->w;
    $review = makeQuote($w, 'inReview');
    $expiring = makeQuote($w, 'quoted', ['valid_until' => '2026-11-24']);

    loginAs($w->staff)->putJson("/api/v1/quotes/{$review->quote_number}/internal-note", ['internal_note' => 'SECRET-NOTE'])->assertOk();
    loginAs($w->staff)->postJson("/api/v1/quotes/{$review->quote_number}/review")->assertStatus(422);
    loginAs($w->a1)->postJson("/api/v1/quotes/{$expiring->quote_number}/accept", ['revision' => 1])->assertStatus(422);
    $this->artisan('quotes:expire')->assertSuccessful();

    expect(QuoteRequest::find($expiring->id)->status->value)->toBe('expired')->and(auditLogRows())->toBe([]);
});

it('writes invoice.created, invoice.paid and invoice.voided with the invoice uuid as subject', function () {
    $w = $this->w;
    $order = auditLogOrder($w, 'confirmed');
    $second = auditLogOrder($w, 'confirmed');

    $created = issueInvoiceAs($w->staff, $order)->assertCreated();
    $id = $created->json('data.id');
    $number = $created->json('data.number');
    auditLogExpect(auditLogOne('invoice.created'), $w->staff, 'invoice', $id, $w->a->id, [
        'before' => [], 'after' => ['status' => 'open', 'total_cents' => 2000, 'due_date' => '2026-12-25'],
    ], ['invoice_number' => $number, 'order_number' => $order->order_number]);

    loginAs($w->staff)->postJson("/api/v1/invoices/{$id}/mark-paid", ['paid_on' => '2026-11-25'])->assertOk();
    auditLogExpect(auditLogOne('invoice.paid'), $w->staff, 'invoice', $id, $w->a->id, [
        'before' => ['status' => 'open', 'paid_on' => null], 'after' => ['status' => 'paid', 'paid_on' => '2026-11-25'],
    ], ['invoice_number' => $number]);

    $voidedId = issueInvoiceAs($w->admin, $second)->assertCreated()->json('data.id');
    loginAs($w->admin)->postJson("/api/v1/invoices/{$voidedId}/void", ['reason' => 'PRIVATE-VOID-REASON'])->assertOk();
    $voided = collect(auditLogRows('invoice.voided'))->sole();
    auditLogExpect($voided, $w->admin, 'invoice', $voidedId, $w->a->id, auditLogStatus('open', 'void'), ['invoice_number' => DB::table('invoices')->where('public_id', $voidedId)->value('number')]);
    expect(json_encode(auditLogRows()))->not->toContain('PRIVATE-VOID-REASON');
});

it('writes no invoice row for a refused invoice action or the overdue job', function () {
    $w = $this->w;
    $order = auditLogOrder($w, 'pending');
    $overdue = Invoice::factory()->forCompany($w->a)->open()->issuedOn('2026-10-01')->dueOn('2026-11-01')->create();

    issueInvoiceAs($w->staff, $order)->assertStatus(422);
    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    expect($overdue->fresh()->status->value)->toBe('overdue')->and(auditLogRows())->toBe([]);
});

it('rolls the audit row back with the change when the surrounding transaction fails', function () {
    $w = $this->w;
    $order = auditLogOrder($w);
    $history = DB::table('order_status_history')->where('order_id', $order->id)->count();

    try {
        DB::transaction(function () use ($order, $w) {
            app(OrderStatusChanger::class)->change($order, OrderStatus::Confirmed, $w->staff);
            expect(auditLogRows())->toHaveCount(1);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(auditLogRows())->toBe([])->and($order->fresh()->status->value)->toBe('pending')
        ->and(DB::table('order_status_history')->where('order_id', $order->id)->count())->toBe($history);
});

it('rolls the change back when the audit row cannot be written', function () {
    $w = $this->w;
    $order = auditLogOrder($w);
    $history = DB::table('order_status_history')->where('order_id', $order->id)->count();
    // A temporary constraint (Postgres DDL is transactional, so the test rollback removes it).
    DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_tmp_refuse CHECK (action <> 'order.status_changed')");

    loginAs($w->staff)->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertStatus(500)
        ->assertExactJson(['message' => 'Server Error']);

    expect($order->fresh()->status->value)->toBe('pending')->and(DB::table('order_status_history')->where('order_id', $order->id)->count())->toBe($history);
});

it('refuses to write an audit row outside a transaction of the caller', function () {
    expect(fn () => AuditLog::record('order.status_changed', 'order', 'ORD-X', null, null, [], ['status' => 'confirmed']))
        ->toThrow(LogicException::class);

    DB::transaction(function () {
        AuditLog::record('order.status_changed', 'order', 'ORD-X', null, null, [], ['status' => 'confirmed']);
    });
    expect(auditLogRows())->toHaveCount(1)->and(auditLogRows()[0]['actor_role'])->toBe('system');
});

it('writes nothing for a no-op, keeps only changed keys and drops everything off the allowlist', function () {
    $actor = $this->w->staff;

    DB::transaction(function () use ($actor) {
        // No change and no metadata: no row.
        AuditLog::record('product.updated', 'product', '1', 'P', $actor, ['name' => 'A', 'price_cents' => 5], ['name' => 'A', 'price_cents' => 5]);
        // Hostile keys are dropped before the insert; only the changed allowlisted key survives, on both sides.
        AuditLog::record(
            'product.updated', 'product', '2', 'P2', $actor,
            ['name' => 'A', 'price_cents' => 5, 'password' => 'p1', 'internal_note' => 'n1', 'token' => 't1'],
            ['name' => 'A', 'price_cents' => 6, 'password' => 'p2', 'remember_token' => 'r', 'description' => 'D', 'billing_address' => 'B', 'email_body' => 'E', 'reason' => 'R'],
            ['description_changed' => true, 'token' => 'x', 'reason' => 'y'],
        );
    });

    $row = auditLogOne('product.updated');
    expect($row['changes'])->toEqual(['before' => ['price_cents' => 5], 'after' => ['price_cents' => 6]])
        ->and($row['metadata'])->toEqual(['description_changed' => true])
        ->and(json_encode($row))->not->toContain('p1')->not->toContain('internal_note')->not->toContain('billing_address');
});

it('cuts long strings and drops non-scalar values', function () {
    DB::transaction(fn () => AuditLog::record('company.created', 'company', '1', str_repeat('L', 300), $this->w->admin, [], ['name' => str_repeat('N', 500), 'tax_id' => ['nested']]));

    $row = auditLogOne('company.created');
    expect(strlen($row['changes']['after']['name']))->toBe(200)->and($row['changes']['after'])->not->toHaveKey('tax_id')
        ->and(strlen($row['subject_label']))->toBe(120);
});
