<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\PartNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * DEMO ONLY (local/testing): a second admin and staff user (so the last-admin rule never blocks a demo), an inactive customer, a pending
 * invitation, a company without a billing address and three inactive products. Idempotent. Never writes audit rows (they come from actions).
 */
class AdminDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'AdminDemoSeeder only runs in local/testing.');

        $this->call(RolesSeeder::class);
        $this->call(CategorySeeder::class);

        $northwind = Company::where('name', 'Northwind Machinery Ltd')->first() ?? tap((new Company)->forceFill(['name' => 'Northwind Machinery Ltd']))->save();
        // No billing address on purpose: shows the "Billing missing" badge and the 422 `company` path when invoicing.
        $initech = Company::where('name', 'Initech Bearings')->first() ?? tap((new Company)->forceFill(['name' => 'Initech Bearings']))->save();

        $demo = ['password' => 'Demo-Passw0rd!', 'email_verified_at' => now()];

        User::provision('admin', 'admin2@portal.test', ['name' => 'Ada Second'] + $demo);
        User::provision('staff', 'staff2@portal.test', ['name' => 'Stan Second'] + $demo);
        User::provision('customer', 'dave@northwind.test', ['name' => 'Dave Deactivated', 'active' => false] + $demo, $northwind);

        // Pending invitation: nobody knows the token (use Resend in the UI to get a mail). Created once, so a re-run keeps the same invitation.
        if (! User::where('email', 'erin@initech.test')->exists()) {
            $erin = User::provision('customer', 'erin@initech.test', [
                'name' => 'Erin Invited', 'password' => Str::random(64), 'invited_at' => now(),
            ], $initech);
            Password::broker('invitations')->createToken($erin);
        }

        $category = Category::orderBy('id')->firstOrFail();
        foreach ([1, 2, 3] as $n) {
            $partNumber = "DEMO-INACTIVE-{$n}";

            Product::where('part_number_search', PartNumber::normalise($partNumber))->exists() || Product::forceCreate([
                'category_id' => $category->id, 'part_number' => $partNumber, 'name' => "Demo inactive bearing {$n}", 'brand' => 'Demo',
                'inner_diameter_mm' => 10, 'outer_diameter_mm' => 30, 'width_mm' => 9, 'material' => 'Chrome steel', 'seal_type' => 'open',
                'price_cents' => 1000, 'price_on_request' => false, 'stock_status' => 'in_stock', 'active' => false,
            ]);
        }
    }
}
