<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The snapshot: nothing here is ever updated after creation.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('part_number', 64);
            $table->string('name', 255);
            $table->string('brand', 64);
            $table->bigInteger('unit_price_cents');
            $table->integer('quantity');
            $table->bigInteger('line_total_cents');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['order_id', 'product_id'], 'order_items_order_id_product_id_unique');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_nonnegative CHECK (unit_price_cents >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_range CHECK (quantity > 0 AND quantity <= 9999)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_total_nonnegative CHECK (line_total_cents >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_total_consistent CHECK (line_total_cents = unit_price_cents * quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
