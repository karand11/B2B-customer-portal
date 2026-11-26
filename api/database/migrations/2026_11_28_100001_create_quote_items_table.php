<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot lines. After creation only the two price columns ever change (staff pricing).
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_request_id')->constrained('quote_requests')->restrictOnDelete();
            $table->smallInteger('position');
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('part_number', 64)->nullable();
            $table->string('name', 255);
            $table->string('brand', 64)->nullable();
            $table->integer('quantity');
            $table->bigInteger('unit_price_cents')->nullable();
            $table->bigInteger('line_total_cents')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['quote_request_id', 'position'], 'quote_items_quote_request_id_position_unique');
            // NULLs are distinct: any number of custom lines, one line per catalog product.
            $table->unique(['quote_request_id', 'product_id'], 'quote_items_quote_request_id_product_id_unique');
        });

        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT quote_items_position_range CHECK (position BETWEEN 1 AND 50)');
        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT quote_items_quantity_range CHECK (quantity > 0 AND quantity <= 9999)');
        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT quote_items_unit_price_range CHECK (unit_price_cents BETWEEN 0 AND 1000000000)');
        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT quote_items_line_kind CHECK ((product_id IS NOT NULL AND part_number IS NOT NULL AND brand IS NOT NULL) OR (product_id IS NULL AND part_number IS NULL AND brand IS NULL))');
        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT quote_items_price_consistent CHECK ((unit_price_cents IS NULL AND line_total_cents IS NULL) OR (unit_price_cents IS NOT NULL AND line_total_cents IS NOT NULL AND line_total_cents = unit_price_cents * quantity))');
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
