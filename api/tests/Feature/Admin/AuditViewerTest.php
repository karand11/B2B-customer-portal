<?php

use App\Mail\UserInvitationMail;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
    $this->admin = makeUser('admin', null, ['name' => 'Ada Admin']);
    $this->staff = makeUser('staff', null, ['name' => 'Sam Staff']);
    $this->company = Company::factory()->create(['name' => 'Acme Ltd']);
});

afterEach(fn () => $this->travelBack());

/** Insert one audit row directly (only the columns a test cares about). Returns its id. */
function auditViewerInsert(array $overrides = []): int
{
    return DB::table('audit_log')->insertGetId(array_replace([
        'occurred_at' => '2026-11-26 10:00:00', 'actor_user_id' => test()->staff->id, 'actor_role' => 'staff', 'action' => 'order.status_changed',
        'subject_type' => 'order', 'subject_id' => 'ORD-AAAAA-AAAAA', 'subject_label' => 'ORD-AAAAA-AAAAA', 'company_id' => test()->company->id,
        'changes' => json_encode(['before' => ['status' => 'pending'], 'after' => ['status' => 'confirmed']]), 'metadata' => '{}',
    ], $overrides));
}

function auditViewerList(string $query = '')
{
    return test()->actingAs(test()->admin)->getJson('/api/v1/admin/audit-log'.$query);
}

it('is readable by admins only', function () {
    auditViewerInsert();

    $this->actingAs($this->staff)->getJson('/api/v1/admin/audit-log')->assertForbidden();
    $this->actingAs(makeUser('customer', $this->company))->getJson('/api/v1/admin/audit-log')->assertForbidden();
    auditViewerList()->assertOk()->assertJsonCount(1, 'data');
});

it('returns entries with exactly the contract keys and objects for changes and metadata', function () {
    $id = auditViewerInsert();
    auditViewerInsert(['actor_user_id' => null, 'actor_role' => 'system', 'company_id' => null, 'changes' => '{}', 'action' => 'quote.sent', 'subject_type' => 'quote', 'subject_id' => 'QR-1']);

    $response = auditViewerList('?sort=occurred_at');

    $entry = $response->json('data.0');
    expect(array_keys($entry))->toBe(['id', 'occurred_at', 'action', 'actor', 'subject', 'company', 'changes', 'metadata'])
        ->and($entry)->toMatchArray([
            'id' => $id, 'occurred_at' => '2026-11-26T10:00:00Z', 'action' => 'order.status_changed',
            'actor' => ['id' => $this->staff->id, 'name' => 'Sam Staff', 'role' => 'staff'],
            'subject' => ['type' => 'order', 'id' => 'ORD-AAAAA-AAAAA', 'label' => 'ORD-AAAAA-AAAAA'],
            'company' => ['id' => $this->company->id, 'name' => 'Acme Ltd'],
            'changes' => ['before' => ['status' => 'pending'], 'after' => ['status' => 'confirmed']],
        ]);
    $system = $response->json('data.1');
    expect($system['actor'])->toBeNull()->and($system['company'])->toBeNull();
    expect($response->getContent())->toContain('"metadata":{}')->and($response->getContent())->toContain('"changes":{}')->and($response->getContent())->not->toContain('"metadata":[]');
});

it('shows the role at the time of the action, not the current role', function () {
    auditViewerInsert(['actor_user_id' => $this->staff->id, 'actor_role' => 'customer']);

    expect(auditViewerList()->json('data.0.actor.role'))->toBe('customer');
});

it('filters by actor, action, subject, company and inclusive UTC dates', function () {
    $other = Company::factory()->create();
    $a = auditViewerInsert(['occurred_at' => '2026-11-01 00:00:00']);
    $b = auditViewerInsert(['occurred_at' => '2026-11-01 23:59:59.999999', 'actor_user_id' => $this->admin->id, 'actor_role' => 'admin', 'action' => 'product.updated', 'subject_type' => 'product', 'subject_id' => '17', 'company_id' => null]);
    $c = auditViewerInsert(['occurred_at' => '2026-11-02 00:00:00', 'action' => 'quote.sent', 'subject_type' => 'quote', 'subject_id' => 'QR-9', 'company_id' => $other->id]);
    $ids = fn (string $q) => array_column(auditViewerList($q.'&sort=occurred_at')->assertOk()->json('data'), 'id');

    expect($ids("?actor_id={$this->admin->id}"))->toBe([$b])
        ->and($ids('?action=quote.sent'))->toBe([$c])
        ->and($ids('?action=quote.sent,product.updated'))->toBe([$b, $c])
        ->and($ids('?subject_type=product'))->toBe([$b])
        ->and($ids('?subject_type=order&subject_id=ORD-AAAAA-AAAAA'))->toBe([$a])
        ->and($ids('?subject_id=17'))->toBe([$b])
        ->and($ids("?company_id={$other->id}"))->toBe([$c])
        ->and($ids('?from=2026-11-01&to=2026-11-01'))->toBe([$a, $b])
        ->and($ids('?from=2026-11-02'))->toBe([$c])
        ->and($ids('?to=2026-11-01'))->toBe([$a, $b])
        ->and($ids('?from=2026-12-01'))->toBe([]);
});

it('refuses bad filters on the parameter name', function () {
    foreach (['from=2026-12-01&to=2026-11-01' => 'from', 'from=2026-02-30' => 'from', 'to=yesterday' => 'to', 'action=order.deleted' => 'action', 'action=order.status_changed,,quote.sent' => 'action',
        'subject_type=invoice,order' => 'subject_type', 'subject_type=robot' => 'subject_type', 'actor_id=abc' => 'actor_id', 'company_id=0' => 'company_id', 'sort=id' => 'sort',
        'sort=-actor' => 'sort', 'per_page=101' => 'per_page', 'page=0' => 'page', 'subject_id=' => null, 'action[]=quote.sent' => 'action', 'subject_id[]=x' => 'subject_id'] as $query => $field) {
        $response = auditViewerList("?{$query}");
        $field === null ? $response->assertOk() : $response->assertStatus(422)->assertJsonValidationErrors($field);
    }
});

it('sorts newest first by default and both ways with the id as tie-break', function () {
    $first = auditViewerInsert(['occurred_at' => '2026-11-01 10:00:00']);
    $tieA = auditViewerInsert(['occurred_at' => '2026-11-02 10:00:00']);
    $tieB = auditViewerInsert(['occurred_at' => '2026-11-02 10:00:00']);
    $last = auditViewerInsert(['occurred_at' => '2026-11-03 10:00:00']);
    $ids = fn (string $q = '') => array_column(auditViewerList($q)->json('data'), 'id');

    expect($ids())->toBe([$last, $tieA, $tieB, $first])->and($ids('?sort=-occurred_at'))->toBe([$last, $tieA, $tieB, $first])->and($ids('?sort=occurred_at'))->toBe([$first, $tieA, $tieB, $last]);
});

it('paginates with the six-key meta', function () {
    foreach (range(1, 5) as $i) {
        auditViewerInsert(['occurred_at' => "2026-11-0{$i} 10:00:00"]);
    }

    $page = auditViewerList('?per_page=2&page=3');

    expect($page->json('meta'))->toBe(['current_page' => 3, 'last_page' => 3, 'per_page' => 2, 'total' => 5, 'from' => 5, 'to' => 5])->and($page->json('data'))->toHaveCount(1)
        ->and(auditViewerList()->json('meta.per_page'))->toBe(25)->and(auditViewerList('?page=9')->json('data'))->toBe([]);
});

it('lists with a constant number of queries', function () {
    $this->actingAs($this->admin);
    $count = fn () => adminQueryCount(fn () => $this->getJson('/api/v1/admin/audit-log?per_page=100')->assertOk());
    foreach (range(1, 3) as $i) {
        auditViewerInsert();
    }
    $count(); // warm-up
    $few = $count();
    DB::statement("INSERT INTO audit_log (occurred_at, actor_user_id, actor_role, action, subject_type, subject_id, company_id)
        SELECT '2026-11-20 10:00:00', ?, 'staff', 'order.status_changed', 'order', 'ORD-' || g, ? FROM generate_series(1, 300) g", [$this->staff->id, $this->company->id]);

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(4); // count, rows, actors, companies
});

it('drops forbidden keys when a row is read, even one inserted behind the service', function () {
    auditViewerInsert([
        'changes' => json_encode(['before' => ['status' => 'a', 'password' => 'SECRET-PW', 'internal_note' => 'SECRET-NOTE'], 'after' => ['status' => 'b', 'token' => 'SECRET-TOKEN', 'nested' => ['x' => 1]], 'extra' => 'SECRET-EXTRA']),
        'metadata' => json_encode(['order_number' => 'ORD-X', 'remember_token' => 'SECRET-RT', 'reason' => 'SECRET-REASON']),
        'subject_type' => 'quote', 'action' => 'quote.sent',
    ]);

    $response = auditViewerList();

    expect($response->json('data.0.changes'))->toBe(['before' => ['status' => 'a'], 'after' => ['status' => 'b']])->and($response->json('data.0.metadata'))->toBe(['order_number' => 'ORD-X'])
        ->and($response->getContent())->not->toContain('SECRET');
});

it('never shows a password, token, hash or note after hostile keys went through the service', function () {
    DB::transaction(fn () => AuditLog::record(
        'product.updated', 'product', '5', 'P-5', $this->admin,
        ['name' => 'a', 'password' => 'SECRET-PW'], ['name' => 'b', 'token' => 'SECRET-TOKEN', 'remember_token' => 'SECRET-RT', 'internal_note' => 'SECRET-NOTE', 'reason' => 'SECRET-REASON', 'description' => 'SECRET-DESC', 'billing_address' => 'SECRET-ADDR', 'email_body' => 'SECRET-MAIL'],
        ['description_changed' => true, 'password' => 'SECRET-PW2', 'token' => 'SECRET-T2'],
    ));

    $response = auditViewerList();

    expect($response->getContent())->not->toContain('SECRET')->and($response->json('data.0.changes'))->toBe(['before' => ['name' => 'a'], 'after' => ['name' => 'b']])
        ->and(json_encode(DB::table('audit_log')->get()))->not->toContain('SECRET');
});

it('has no secret anywhere in the log after a full run of back-office actions', function () {
    $w = quoteWorld();
    $product = makeProduct('FULL-1');
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/admin/users', ['name' => 'Invited', 'email' => 'invited@acme.test', 'role' => 'customer', 'company_id' => $w->a->id])->assertCreated();
    $token = Mail::queued(UserInvitationMail::class)->first()->url;
    parse_str((string) parse_url($token, PHP_URL_QUERY), $q);
    $this->postJson('/api/v1/auth/accept-invitation', ['token' => $q['token'], 'email' => 'invited@acme.test', 'password' => 'Brand-New-Passw0rd', 'password_confirmation' => 'Brand-New-Passw0rd'])->assertOk();
    $invited = User::where('email', 'invited@acme.test')->first();
    $this->postJson("/api/v1/admin/users/{$invited->id}/deactivate")->assertOk();
    $this->postJson("/api/v1/admin/products/{$product->id}/deactivate")->assertOk();
    $this->postJson('/api/v1/admin/companies', ['name' => 'Fresh Ltd', 'billing_address' => 'SECRET-ADDRESS-LINE', 'tax_id' => 'T-1'])->assertCreated();
    $order = orderWith($w->a, [[100, 1]], 'pending');
    $this->patchJson("/api/v1/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertOk();
    $invoiceId = issueInvoiceAs($this->admin, $order)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/invoices/{$invoiceId}/void", ['reason' => 'SECRET-VOID-REASON'])->assertOk();

    $all = strtolower(json_encode(DB::table('audit_log')->get()).auditViewerList('?per_page=100')->getContent());

    expect(DB::table('audit_log')->count())->toBeGreaterThan(6)
        ->and($all)->not->toContain('password')->not->toContain('token')->not->toContain('session_epoch')->not->toContain('remember')
        ->and($all)->not->toContain('secret')->not->toContain($q['token'])->not->toContain('brand-new');
});
