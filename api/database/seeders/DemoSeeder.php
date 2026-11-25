<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /** DEMO ONLY: published credentials. Runs only in local/testing. */
    public function run(): void
    {
        // Allowlist: published credentials must never exist in staging, production or any other environment.
        abort_unless(app()->environment(['local', 'testing']), 500, 'DemoSeeder only runs in local/testing.');

        $this->call(RolesSeeder::class);

        $northwind = Company::firstOrCreate(['name' => 'Northwind Machinery Ltd']);
        $globex = Company::firstOrCreate(['name' => 'Globex Industrial Supply']);

        $users = [
            ['admin', 'Ada Admin', 'admin@portal.test', null],
            ['staff', 'Sam Staff', 'staff@portal.test', null],
            ['customer', 'Alice Customer', 'alice@northwind.test', $northwind],
            ['customer', 'Bob Buyer', 'bob@northwind.test', $northwind],
            ['customer', 'Carol Customer', 'carol@globex.test', $globex],
        ];

        foreach ($users as [$role, $name, $email, $company]) {
            User::provision($role, $email, [
                'name' => $name,
                'password' => 'Demo-Passw0rd!',
                'email_verified_at' => now(),
            ], $company);
        }
    }
}
