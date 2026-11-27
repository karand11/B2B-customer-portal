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

        $northwind = Company::where('name', 'Northwind Machinery Ltd')->first() ?? (new Company)->forceFill(['name' => 'Northwind Machinery Ltd']);
        $globex = Company::where('name', 'Globex Industrial Supply')->first() ?? (new Company)->forceFill(['name' => 'Globex Industrial Supply']);

        // Obviously fake billing data for invoices.
        $northwind->forceFill(['billing_address' => "12 Example Road\nSampletown 00000\nExampleland", 'tax_id' => 'NW-TAX-0001'])->save();
        $globex->forceFill(['billing_address' => "99 Sample Avenue\nTestville 11111\nExampleland", 'tax_id' => 'GX-TAX-0002'])->save();

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
