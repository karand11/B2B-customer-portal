<?php

use App\Models\AuditLogEntry;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\UserAdmin;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/*
 * Races between real database sessions. The in-test transaction hides rows from other sessions, so the race tests commit their data
 * (committed()) and wipe it afterwards. A contender is a separate PHP process (tests/Support/race_child.php). The test holds the
 * lock the contenders need on a second connection until both wait on it, then lets go: both are then queued at the same instant,
 * which is the interleaving that broke the old code (the second contender only re-checks what the first one changed).
 */

// Real child processes and TRUNCATE: never inside a parallel worker pool with other tests (CI runs this group serially, after the parallel step).
pest()->group('race');

beforeEach(fn () => Mail::fake());

/** Commit what the test has created so far (and what it will create), run the body, then empty the tables it touched. */
function committed(Closure $body): void
{
    $database = (string) DB::connection()->getDatabaseName();
    preg_match('/_test(_\d+)?$/D', $database) === 1 || throw new RuntimeException("Refusing to TRUNCATE the database [$database]: it must end in _test.");

    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
    AuditLog::$baseTransactionLevel = 0;

    try {
        $body();
    } finally {
        DB::statement('TRUNCATE users, companies, roles, permissions, audit_log, password_reset_tokens, user_invitation_tokens RESTART IDENTITY CASCADE');
    }
}

/**
 * Starts one child process per job, waits until every one of them is blocked on a lock held by $hold (run on a second connection),
 * releases it (commit or rollback) and returns the HTTP-like status each child reported, in job order.
 *
 * @param  list<array<string, mixed>>  $jobs
 * @return list<int>
 */
function race(Closure $hold, array $jobs, bool $commitHold = false): array
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    $b = DB::connection('pgsql_b');
    $db = config('database.connections.pgsql');
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
        'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4'];
    $processes = [];

    try {
        $b->beginTransaction();
        $hold($b);

        foreach ($jobs as $i => $job) {
            $processes[$i] = (new Process([PHP_BINARY, base_path('tests/Support/race_child.php'), json_encode($job)], base_path(), $env))->setTimeout(120);
            $processes[$i]->start();
        }

        $deadline = microtime(true) + 90;
        // pg_stat_activity is cached for the length of a transaction: clear the snapshot or the count never changes.
        while ((int) $b->scalar("select count(*) from (select pg_stat_clear_snapshot()) s, pg_stat_activity a where a.datname = current_database() and a.wait_event_type = 'Lock'") < count($jobs)) {
            foreach ($processes as $process) {
                if (! $process->isRunning() && ! $process->isSuccessful()) {
                    throw new RuntimeException('A contender died: '.$process->getErrorOutput().$process->getOutput());
                }
            }
            microtime(true) < $deadline || throw new RuntimeException('The contenders never blocked on the lock.');
            usleep(100_000);
        }

        $commitHold ? $b->commit() : $b->rollBack();

        return array_map(function (Process $process) {
            $process->wait();

            return json_decode($process->getOutput(), true)['status'] ?? throw new RuntimeException('A contender failed: '.$process->getErrorOutput().$process->getOutput());
        }, $processes);
    } finally {
        foreach ($processes as $process) {
            $process->stop(0);
        }
        while ($b->transactionLevel() > 0) {
            $b->rollBack();
        }
        DB::purge('pgsql_b');
    }
}

/** Hold the user rows locked (what the contenders' first write needs) AND the admin-set lock the service takes. */
function holdUsers(array $ids): Closure
{
    return function (Connection $c) use ($ids) {
        $c->select('select pg_advisory_xact_lock('.(int) UserAdmin::ADMIN_SET_LOCK.')');
        $c->select('select id from users where id in ('.implode(',', array_map('intval', $ids)).') order by id for update');
    };
}

// --- F1: the last administrator ---------------------------------------------------------------------------------------------

it('lets exactly one of two admins demote the other when both try at the same instant', function () {
    committed(function () {
        [$a, $b] = [makeUser('admin'), makeUser('admin')];

        $statuses = race(holdUsers([$a->id, $b->id]), [
            ['kind' => 'assign', 'actor' => $a->id, 'target' => $b->id, 'role' => 'staff'],
            ['kind' => 'assign', 'actor' => $b->id, 'target' => $a->id, 'role' => 'staff'],
        ]);

        sort($statuses);
        expect($statuses)->toBe([200, 422])
            ->and(User::role('admin')->count())->toBe(1)
            ->and(AuditLogEntry::where('action', 'user.assignment_changed')->count())->toBe(1);
    });
});

it('takes the admin-set lock before it reads or decides anything, for every action that can remove an admin', function (string $action) {
    [$actor, $target] = [makeUser('admin'), makeUser('admin')];
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    $b = DB::connection('pgsql_b');

    try {
        $b->beginTransaction();
        $b->select('select pg_advisory_xact_lock('.(int) UserAdmin::ADMIN_SET_LOCK.')');
        DB::statement("SET LOCAL lock_timeout = '300ms'");

        $code = null;
        try {
            $action === 'demote' ? app(UserAdmin::class)->assign($actor, $target, 'staff', null) : app(UserAdmin::class)->deactivate($actor, $target);
        } catch (QueryException $e) {
            $code = $e->getCode();
        }

        expect($code)->toBe('55P03');
    } finally {
        while ($b->transactionLevel() > 0) {
            $b->rollBack();
        }
        DB::purge('pgsql_b');
    }
})->with(['demote' => 'demote', 'deactivate' => 'deactivate']);

it('refuses to demote the last administrator', function () {
    $admin = makeUser('admin');
    $staff = makeUser('staff');

    $this->actingAs(makeUser('staff'))->putJson("/api/v1/admin/users/{$admin->id}/assignment", ['role' => 'staff', 'company_id' => null])->assertForbidden();
    expect(fn () => app(UserAdmin::class)->assign($staff, $admin, 'staff', null))->toThrow(ValidationException::class, 'At least one active administrator is required.');
    expect($admin->fresh()->primaryRole())->toBe('admin');
});

it('refuses to deactivate the last administrator', function () {
    $admin = makeUser('admin');

    expect(fn () => app(UserAdmin::class)->deactivate(makeUser('staff'), $admin))->toThrow(ValidationException::class, 'At least one active administrator is required.');
    expect($admin->fresh()->active)->toBeTrue();
});

it('still lets an admin demote or deactivate one of two administrators', function () {
    [$actor, $demoted, $deactivated] = [makeUser('admin'), makeUser('admin'), makeUser('admin')];

    $this->actingAs($actor)->putJson("/api/v1/admin/users/{$demoted->id}/assignment", ['role' => 'staff', 'company_id' => null])->assertOk();
    $this->actingAs($actor)->postJson("/api/v1/admin/users/{$deactivated->id}/deactivate")->assertOk();

    expect($demoted->fresh()->primaryRole())->toBe('staff')->and($deactivated->fresh()->active)->toBeFalse()->and(User::role('admin')->where('active', true)->count())->toBe(1);
    // The one left cannot be removed by anybody.
    $this->actingAs($actor)->postJson("/api/v1/admin/users/{$actor->id}/deactivate")->assertUnprocessable();
});

// --- F2: single-use tokens -------------------------------------------------------------------------------------------------

it('accepts an invitation exactly once when two requests carry the same token at the same instant', function () {
    committed(function () {
        $user = makeUser('staff', null, ['invited_at' => now(), 'invitation_accepted_at' => null, 'active' => true]);
        $token = Password::broker('invitations')->createToken($user);
        $body = fn (string $password) => ['token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password];
        $passwords = ['First-Passw0rd!!', 'Second-Passw0rd!!'];

        $statuses = race(holdUsers([$user->id]), array_map(fn ($p) => ['kind' => 'accept', 'body' => $body($p)], $passwords));

        $winner = array_search(200, $statuses, true);
        expect($statuses)->toContain(200)->and(array_count_values($statuses)[200])->toBe(1)->and(array_count_values($statuses)[422])->toBe(1)
            ->and(AuditLogEntry::where('action', 'user.invitation_accepted')->count())->toBe(1)
            ->and(Hash::check($passwords[$winner], $user->fresh()->password))->toBeTrue()
            ->and(DB::table('user_invitation_tokens')->count())->toBe(0);
    });
});

it('resets a password exactly once when two requests carry the same token at the same instant (Phase 1 flow)', function () {
    committed(function () {
        $user = makeUser('customer');
        $token = Password::broker('users')->createToken($user);
        $body = fn (string $password) => ['token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password];
        $passwords = ['First-Passw0rd!!', 'Second-Passw0rd!!'];

        $statuses = race(holdUsers([$user->id]), array_map(fn ($p) => ['kind' => 'reset', 'body' => $body($p)], $passwords));

        $winner = array_search(200, $statuses, true);
        expect($statuses)->toContain(200)->and(array_count_values($statuses)[200])->toBe(1)->and(array_count_values($statuses)[422])->toBe(1)
            ->and(Hash::check($passwords[$winner], $user->fresh()->password))->toBeTrue()
            ->and(DB::table('password_reset_tokens')->count())->toBe(0);
    });
});

it('refuses to accept an invitation that was revoked after the token was looked up (accept is serialised with revoke)', function () {
    committed(function () {
        $user = makeUser('staff', null, ['invited_at' => now(), 'invitation_accepted_at' => null, 'active' => true]);
        $token = Password::broker('invitations')->createToken($user);
        $body = ['token' => $token, 'email' => $user->email, 'password' => 'First-Passw0rd!!', 'password_confirmation' => 'First-Passw0rd!!'];

        // The contender has passed the broker lookup and waits for the row lock; the revoke commits first (the token row is left in place on purpose).
        $statuses = race(fn (Connection $c) => $c->update('update users set active = false where id = ?', [$user->id]), [['kind' => 'accept', 'body' => $body]], commitHold: true);

        expect($statuses)->toBe([422])
            ->and($user->fresh()->invitation_accepted_at)->toBeNull()->and($user->fresh()->active)->toBeFalse()
            ->and(AuditLogEntry::where('action', 'user.invitation_accepted')->count())->toBe(0);
    });
});

it('answers a failing audit insert while accepting with the generic 500 (not the invalid-link 422) and changes nothing', function () {
    $user = makeUser('staff', null, ['invited_at' => now(), 'invitation_accepted_at' => null, 'active' => true]);
    $token = Password::broker('invitations')->createToken($user);
    $oldHash = $user->fresh()->password;
    DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_tmp_fail CHECK (action <> 'user.invitation_accepted')");

    $response = $this->postJson('/api/v1/auth/accept-invitation', ['token' => $token, 'email' => $user->email, 'password' => 'First-Passw0rd!!', 'password_confirmation' => 'First-Passw0rd!!']);

    $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
    expect($user->fresh()->invitation_accepted_at)->toBeNull()->and($user->fresh()->password)->toBe($oldHash)
        ->and(DB::table('user_invitation_tokens')->count())->toBe(1);
});
