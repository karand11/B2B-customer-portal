<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AdminDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('seeds the second admin and staff, an inactive customer, a pending invitation, a company without billing and three inactive products', function () {
    $this->seed(AdminDemoSeeder::class);

    $byEmail = fn (string $email) => User::with('roles')->where('email', $email)->firstOrFail();
    expect($byEmail('admin2@portal.test')->primaryRole())->toBe('admin')->and($byEmail('staff2@portal.test')->primaryRole())->toBe('staff')
        ->and(Hash::check('Demo-Passw0rd!', $byEmail('admin2@portal.test')->password))->toBeTrue();

    $dave = $byEmail('dave@northwind.test');
    expect($dave->status())->toBe('inactive')->and($dave->primaryRole())->toBe('customer')->and($dave->company->name)->toBe('Northwind Machinery Ltd');

    $erin = $byEmail('erin@initech.test');
    expect($erin->status())->toBe('pending')->and($erin->company->name)->toBe('Initech Bearings')->and($erin->invitation_accepted_at)->toBeNull()
        ->and(DB::table('user_invitation_tokens')->where('email', 'erin@initech.test')->count())->toBe(1)
        ->and(Hash::check('Demo-Passw0rd!', $erin->password))->toBeFalse();

    expect(Company::where('name', 'Initech Bearings')->first()->billing_address)->toBeNull()
        ->and(Product::where('part_number', 'like', 'DEMO-INACTIVE-%')->where('active', false)->orderBy('part_number')->pluck('part_number')->all())
        ->toBe(['DEMO-INACTIVE-1', 'DEMO-INACTIVE-2', 'DEMO-INACTIVE-3']);
});

it('is idempotent and keeps the same invitation on a second run', function () {
    $this->seed(AdminDemoSeeder::class);
    $token = DB::table('user_invitation_tokens')->value('token');
    $counts = [User::count(), Company::count(), Product::count()];

    $this->seed(AdminDemoSeeder::class);

    expect([User::count(), Company::count(), Product::count()])->toBe($counts)->and(DB::table('user_invitation_tokens')->value('token'))->toBe($token);
});

it('never writes audit rows', function () {
    $this->seed(DatabaseSeeder::class);

    expect(DB::table('audit_log')->count())->toBe(0);
});

it('aborts outside local and testing and DatabaseSeeder skips it', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => app(AdminDemoSeeder::class)->run())->toThrow(HttpException::class);

    app(DatabaseSeeder::class)->run();
    expect(User::where('email', 'admin2@portal.test')->exists())->toBeFalse();
})->with(['production', 'staging', 'development', 'qa']);

it('runs from DatabaseSeeder in testing and the demo accounts still sign in', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'admin2@portal.test')->exists())->toBeTrue();
    $this->postJson('/api/v1/auth/login', ['email' => 'staff2@portal.test', 'password' => TEST_PASSWORD])->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => 'dave@northwind.test', 'password' => TEST_PASSWORD])->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['email' => 'erin@initech.test', 'password' => TEST_PASSWORD])->assertStatus(422);
});
