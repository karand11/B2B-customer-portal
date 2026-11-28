<?php

use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LogCapture;

const SEC_SECRETS = [
    'victim@secret.test', 'Wrong-Pass-Secret-9', 'pii.person@secret.test', 'SECRET-PO-123', 'secret-contract-ACME', 'header-secret-value-123',
    'Bearer abc-secret-token', 'DE89 3704 0044 0532 0130 00', 'cookie-secret-value', 'Tax-Secret-ID-77', 'job-secret@secret.test',
];

beforeEach(function () {
    Storage::fake('quotes');
    Route::get('/api/v1/_sec_db_fail', function () {
        // A failing INSERT whose BOUND values (a PO number) would appear in the QueryException message.
        // A savepoint, so the failed statement does not poison the test's own transaction.
        DB::transaction(fn () => DB::table('orders')->insert([
            'order_number' => 'ORD-BCDFG-HJKMP', 'company_id' => 1, 'created_by_user_id' => 1, 'status' => 'pending', 'currency' => 'zz', 'po_reference' => 'SECRET-PO-123',
            'subtotal_cents' => 0, 'total_cents' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]));
    });
    Route::get('/api/v1/_sec_job_fail', function () {
        config(['queue.default' => 'database']);
        dispatch(function () {
            throw new RuntimeException('Cannot mail job-secret@secret.test');
        });

        return response()->json(['queued' => true]);
    });
});

it('logs nothing personal and tags every record with a request id, across the risky paths', function () {
    $w = quoteWorld();
    $logs = LogCapture::start();
    $headers = ['X-Secret-Header' => 'header-secret-value-123', 'Authorization' => 'Bearer abc-secret-token'];

    // 1. a failed login (known and unknown email, wrong password)
    $this->postJson('/api/v1/auth/login', ['email' => 'victim@secret.test', 'password' => 'Wrong-Pass-Secret-9'], $headers)->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['email' => $w->a1->email, 'password' => 'Wrong-Pass-Secret-9'], $headers)->assertStatus(422);

    // 2. a reset request for a known email, then for an unknown one
    Notification::fake();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $w->a1->email], $headers)->assertOk();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'victim@secret.test'], $headers)->assertOk();
    $token = Notification::sent($w->a1, ResetPasswordNotification::class)->first()->token;
    $this->postJson('/api/v1/auth/reset-password', ['token' => 'bad', 'email' => 'victim@secret.test', 'password' => 'Wrong-Pass-Secret-9', 'password_confirmation' => 'Wrong-Pass-Secret-9'], $headers);

    // 3. a 422 whose body is full of personal data
    $this->actingAs($w->a1)->withCookie('portal_note', 'cookie-secret-value')->postJson('/api/v1/quotes', ['customer_note' => 'Mail pii.person@secret.test', 'lines' => 'nope', 'po_reference' => 'SECRET-PO-123'], $headers)->assertStatus(422);
    $this->actingAs($w->staff)->putJson("/api/v1/admin/companies/{$w->a->id}/billing", ['billing_address' => 'DE89 3704 0044 0532 0130 00', 'tax_id' => 'Tax-Secret-ID-77', 'version' => 1], $headers);

    // 4. an upload (the file name and content are customer data)
    $quote = makeQuote($w, 'submitted');
    $this->actingAs($w->a1);
    uploadTo($quote->quote_number, fakeUpload('secret-contract-ACME.pdf', pdfBytes()))->assertCreated();

    // 5. a forced QueryException whose message holds a bound value
    $this->getJson('/api/v1/_sec_db_fail')->assertStatus(500);

    // 6. a failing job (the exception message holds an address)
    $this->getJson('/api/v1/_sec_job_fail')->assertOk();
    Context::flush();
    Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);

    $records = LogCapture::records($logs);
    $text = LogCapture::text($logs);

    expect($records)->not->toBeEmpty();
    foreach (array_merge(SEC_SECRETS, [$token, $w->a1->email, $w->a1->name, $w->staff->email]) as $secret) {
        expect(str_contains($text, $secret))->toBeFalse("a log record contains [{$secret}]");
    }
    foreach ($records as $record) {
        expect($record['extra']['request_id'] ?? null)->not->toBeNull("{$record['message']} has no request id");
    }

    // The database error is logged as SQLSTATE and placeholder SQL, the failed job as class and queue.
    $messages = array_column($records, 'message');
    expect($messages)->toContain('Database query failed.')->toContain('Queue job failed');
    $db = collect($records)->firstWhere('message', 'Database query failed.');
    expect($db['context']['sql'])->toContain('insert into "orders"')->not->toContain('SECRET-PO-123');
});

it('never puts a password, a token or an email into any context key', function () {
    $w = quoteWorld();
    $logs = LogCapture::start();

    $this->postJson('/api/v1/auth/login', ['email' => $w->a1->email, 'password' => 'Wrong-Pass-Secret-9'])->assertStatus(422);
    $this->actingAs($w->a1)->getJson('/api/v1/me')->assertOk();

    foreach (LogCapture::records($logs) as $record) {
        foreach (array_merge(array_keys($record['context']), array_keys($record['extra'])) as $key) {
            expect($key)->not->toMatch('/pass|token|secret|email|name|authorization|cookie|header|body/i');
        }
    }
});
