<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** A valid audit_log row with overrides (raw insert, so a test can hit a constraint or trigger). */
function adminSchemaAuditRow(array $overrides = []): array
{
    return array_replace([
        'occurred_at' => '2026-11-27 10:00:00', 'actor_user_id' => null, 'actor_role' => 'system', 'action' => 'order.status_changed', 'subject_type' => 'order',
        'subject_id' => 'ORD-AAAAA-AAAAA', 'subject_label' => null, 'company_id' => null, 'changes' => '{}', 'metadata' => '{}',
    ], $overrides);
}

it('gives users their lifecycle columns with safe defaults', function () {
    $id = DB::table('users')->insertGetId(['name' => 'Raw', 'email' => 'raw@portal.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);

    $row = DB::table('users')->find($id);
    expect($row->active)->toBeTrue()->and($row->session_epoch)->toBe(1)->and($row->invited_at)->toBeNull()->and($row->invitation_accepted_at)->toBeNull()->and($row->invited_by_user_id)->toBeNull();
    expect((new User)->active)->toBeTrue()->and((new User)->session_epoch)->toBe(1);
});

it('checks the user epoch and the invitation consistency', function () {
    $user = makeUser('staff');

    violates(fn () => DB::table('users')->where('id', $user->id)->update(['session_epoch' => 0]), 'users_session_epoch_positive');
    violates(fn () => DB::table('users')->where('id', $user->id)->update(['invitation_accepted_at' => now()]), 'users_invitation_consistent');
    violates(fn () => DB::table('users')->where('id', $user->id)->update(['invited_by_user_id' => 999999]), 'users_invited_by_user_id_fkey');
    DB::table('users')->where('id', $user->id)->update(['invited_at' => now(), 'invitation_accepted_at' => now()]);
    expect(User::find($user->id)->status())->toBe('active');
});

it('restricts deleting a user who invited, acted or uploaded', function () {
    $admin = makeUser('admin');
    makeUser('staff', null, ['invited_by_user_id' => $admin->id]);

    violates(fn () => DB::table('users')->where('id', $admin->id)->delete(), 'users_invited_by_user_id_fkey');
});

it('bumps the version of products and companies on every update, by the database', function () {
    $product = makeProduct('V-1');
    $company = Company::factory()->create();

    expect(Product::find($product->id)->version)->toBe(1)->and(Company::find($company->id)->version)->toBe(1);

    DB::table('products')->where('id', $product->id)->update(['name' => 'one']);
    DB::table('products')->where('id', $product->id)->update(['name' => 'one']); // even an update that changes nothing is a write
    DB::table('companies')->where('id', $company->id)->update(['name' => 'one']);

    expect(Product::find($product->id)->version)->toBe(3)->and(Company::find($company->id)->version)->toBe(2);
    DB::table('products')->where('id', $product->id)->update(['version' => 50]); // an explicit value is overwritten by the trigger
    expect(Product::find($product->id)->version)->toBe(4);
});

it('has the new indexes', function () {
    $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->pluck('indexname')->all();

    expect($indexes)->toContain('products_updated_at_idx', 'products_inactive_part_idx', 'audit_log_occurred_idx', 'audit_log_actor_idx', 'audit_log_subject_idx', 'audit_log_company_idx',
        'audit_log_action_idx', 'product_imports_user_id_created_at_index', 'product_imports_status_expires_at_index', 'user_invitation_tokens_pkey');
});

it('stores the audit log without any IP address or user agent', function () {
    expect(Schema::getColumnListing('audit_log'))->toBe(['id', 'occurred_at', 'actor_user_id', 'actor_role', 'action', 'subject_type', 'subject_id', 'subject_label', 'company_id', 'changes', 'metadata']);
});

it('refuses to update or delete an audit row', function () {
    $id = DB::table('audit_log')->insertGetId(adminSchemaAuditRow());

    violates(fn () => DB::table('audit_log')->where('id', $id)->update(['subject_label' => 'edited']), 'append-only');
    violates(fn () => DB::table('audit_log')->where('id', $id)->delete(), 'append-only');
    violates(fn () => DB::table('audit_log')->delete(), 'append-only');
    expect(DB::table('audit_log')->count())->toBe(1);
});

it('checks the audit row constraints', function (array $override, string $constraint) {
    ($override['actor_user_id'] ?? null) === 'USER' && $override['actor_user_id'] = makeUser('staff')->id;

    violates(fn () => DB::table('audit_log')->insert(adminSchemaAuditRow($override)), $constraint);
})->with([
    'action without a dot' => [['action' => 'orderchanged'], 'audit_log_action_format'],
    'action uppercase' => [['action' => 'Order.Changed'], 'audit_log_action_format'],
    'action with a space' => [['action' => 'order. changed'], 'audit_log_action_format'],
    'action with a digit' => [['action' => 'order.changed2'], 'audit_log_action_format'],
    'system role with an actor' => [['actor_user_id' => 'USER', 'actor_role' => 'system'], 'audit_log_actor_consistent'],
    'staff role without an actor' => [['actor_role' => 'staff'], 'audit_log_actor_consistent'],
    'unknown role' => [['actor_user_id' => 'USER', 'actor_role' => 'root'], 'audit_log_actor_role_check'],
    'unknown subject type' => [['subject_type' => 'robot'], 'audit_log_subject_type_check'],
    'changes not an object' => [['changes' => '[]'], 'audit_log_changes_object'],
    'metadata not an object' => [['metadata' => '"text"'], 'audit_log_metadata_object'],
    'changes too large' => [['changes' => '{"k":"'.str_repeat('x', 17000).'"}'], 'audit_log_changes_size'],
    'metadata too large' => [['metadata' => '{"k":"'.str_repeat('x', 17000).'"}'], 'audit_log_metadata_size'],
]);

it('accepts a user actor with a matching role and a real company', function () {
    $staff = makeUser('staff');
    $company = Company::factory()->create();

    DB::table('audit_log')->insert(adminSchemaAuditRow(['actor_user_id' => $staff->id, 'actor_role' => 'staff', 'company_id' => $company->id, 'changes' => '{"before":{},"after":{}}']));

    expect(DB::table('audit_log')->count())->toBe(1);
    violates(fn () => DB::table('companies')->where('id', $company->id)->delete(), 'audit_log_company_id_fkey');
});

it('checks the product import constraints', function (array $override, string $constraint) {
    $user = makeUser('staff');
    $row = array_replace([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'original_name' => 'a.csv', 'storage_path' => null, 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
        'status' => 'previewed', 'rows_read' => 0, 'would_create' => 0, 'would_update' => 0, 'bad_rows' => 0, 'errors' => '[]', 'warnings' => '[]', 'result' => null,
        'expires_at' => now(), 'applied_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ], $override);

    violates(fn () => DB::table('product_imports')->insert($row), $constraint);
})->with([
    'unknown status' => [['status' => 'done'], 'product_imports_status_check'],
    'applied without a time' => [['status' => 'applied'], 'product_imports_applied_consistent'],
    'previewed with a time' => [['applied_at' => '2026-11-27 10:00:00'], 'product_imports_applied_consistent'],
    'errors not an array' => [['errors' => '{}'], 'product_imports_errors_array'],
    'warnings not an array' => [['warnings' => '"x"'], 'product_imports_errors_array'],
    'unknown user' => [['user_id' => 999999], 'product_imports_user_id_foreign'],
]);

it('keeps stored file names unique', function () {
    $user = makeUser('staff');
    $insert = fn (?string $path) => DB::table('product_imports')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'original_name' => 'a.csv', 'storage_path' => $path, 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'status' => 'previewed',
        'rows_read' => 0, 'would_create' => 0, 'would_update' => 0, 'bad_rows' => 0, 'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $insert('same');
    violates(fn () => $insert('same'), 'product_imports_storage_path_unique');
    $insert(null);
    $insert(null);
    expect(DB::table('product_imports')->count())->toBe(3);
});

it('has the invitation token table shaped like password_reset_tokens', function () {
    expect(Schema::getColumnListing('user_invitation_tokens'))->toBe(Schema::getColumnListing('password_reset_tokens'));
});
