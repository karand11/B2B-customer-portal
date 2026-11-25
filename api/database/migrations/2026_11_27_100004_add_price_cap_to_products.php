<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // 10^9 cents = $10M per unit (App\Models\Product::MAX_PRICE_CENTS). 100 lines x 9999 units x 10^9 = 9.999e14: fits a bigint and a PHP int and stays below JavaScript Number.MAX_SAFE_INTEGER (9.007e15), so totals are exact in the browser.
    public function up(): void
    {
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_max CHECK (price_cents IS NULL OR price_cents <= 1000000000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_price_max');
    }
};
