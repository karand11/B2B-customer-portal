<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 3 amendments: custom quote lines become order lines (no product), and an order can point at the quote it came from (exactly once). */
return new class extends Migration
{
    public function up(): void
    {
        // NULLs are distinct, so cart orders are unaffected; the unique index is the database-level exactly-once guarantee.
        DB::statement('ALTER TABLE orders ADD COLUMN quote_request_id bigint NULL');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_quote_request_id_foreign FOREIGN KEY (quote_request_id) REFERENCES quote_requests (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_quote_request_id_unique UNIQUE (quote_request_id)');

        foreach (['product_id', 'part_number', 'brand'] as $column) {
            DB::statement("ALTER TABLE order_items ALTER COLUMN {$column} DROP NOT NULL");
        }
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_kind CHECK ((product_id IS NOT NULL AND part_number IS NOT NULL AND brand IS NOT NULL) OR (product_id IS NULL AND part_number IS NULL AND brand IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_line_kind');
        // Custom lines cannot exist under the old NOT NULL columns: only restore them when there are none.
        if (DB::table('order_items')->whereNull('product_id')->doesntExist()) {
            foreach (['product_id', 'part_number', 'brand'] as $column) {
                DB::statement("ALTER TABLE order_items ALTER COLUMN {$column} SET NOT NULL");
            }
        }

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_quote_request_id_unique');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_quote_request_id_foreign');
        Schema::table('orders', fn ($t) => $t->dropColumn('quote_request_id'));
    }
};
