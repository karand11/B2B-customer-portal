<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function expectInvariants(): void
{
    foreach (User::with('roles')->get() as $user) {
        expect($user->roles)->toHaveCount(1);
        $role = $user->roles->first()->name;

        if ($role === 'customer') {
            expect($user->company_id)->not->toBeNull();
        } else {
            expect($role)->toBeIn(['staff', 'admin'])->and($user->company_id)->toBeNull();
        }
    }
}

it('holds the role/company invariant for seeded and factory users', function () {
    $this->seed(DemoSeeder::class);
    makeUser('customer');
    makeUser('staff');
    makeUser('admin');

    expect(User::count())->toBe(8);
    expectInvariants();
});

it('refuses to provision users that break the invariant', function () {
    $company = Company::factory()->create();

    expect(fn () => User::provision('customer', 'x@x.test', ['name' => 'X', 'password' => 'p'], null))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => User::provision('staff', 'y@x.test', ['name' => 'Y', 'password' => 'p'], $company))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => User::provision('root', 'z@x.test', ['name' => 'Z', 'password' => 'p']))
        ->toThrow(InvalidArgumentException::class);
});

it('seeds the demo data idempotently', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(User::count())->toBe(5)
        ->and(Company::count())->toBe(2)
        ->and(Role::count())->toBe(3)
        ->and(User::where('email', 'alice@northwind.test')->first()->company_id)
        ->toBe(User::where('email', 'bob@northwind.test')->first()->company_id)
        ->and(User::where('email', 'carol@globex.test')->first()->company->name)->toBe('Globex Industrial Supply')
        ->and(User::where('email', 'alice@northwind.test')->first()->email_verified_at)->not->toBeNull();
});

it('creates exactly the three roles', function () {
    $this->seed(RolesSeeder::class);

    expect(Role::orderBy('name')->pluck('name')->all())->toBe(['admin', 'customer', 'staff'])
        ->and(DB::table('permissions')->count())->toBe(0);
});

it('aborts the demo seeder outside local/testing and skips it from DatabaseSeeder', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => app(DemoSeeder::class)->run())->toThrow(HttpException::class);

    app(DatabaseSeeder::class)->run();
    expect(User::count())->toBe(0)->and(Role::count())->toBe(3);
})->with(['production', 'staging', 'development', 'qa']);

it('runs the demo seeder in local and testing', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    app(DatabaseSeeder::class)->run();

    // 5 demo users plus the 4 of AdminDemoSeeder (second admin and staff, an inactive customer, a pending invitation).
    expect(User::count())->toBe(9);
})->with(['local', 'testing']);

it('enforces the company foreign key and RESTRICT on delete', function () {
    $company = Company::factory()->create();
    $row = ['name' => 'N', 'email' => 'n@x.test', 'password' => 'x'];

    // Savepoints: a failed statement would otherwise poison the RefreshDatabase transaction on Postgres.
    expect(fn () => DB::transaction(fn () => DB::table('users')->insert($row + ['company_id' => 987654])))
        ->toThrow(QueryException::class);

    DB::table('users')->insert($row + ['company_id' => $company->id]);
    expect(fn () => DB::transaction(fn () => $company->delete()))->toThrow(QueryException::class);
});

it('indexes users.company_id and companies.name', function () {
    expect(Schema::hasIndex('users', ['company_id']))->toBeTrue()
        ->and(Schema::hasIndex('companies', ['name']))->toBeTrue()
        ->and(Schema::hasTable('personal_access_tokens'))->toBeFalse();
});
