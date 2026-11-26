<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A copy of the order's snapshot lines. No product_id: an invoice is a document, not a link to the live catalog. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->smallInteger('position');
            $table->string('part_number', 64)->nullable();
            $table->string('name', 255);
            $table->string('brand', 64)->nullable();
            $table->integer('quantity');
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('line_total_cents');
            $table->timestamp('created_at');

            $table->unique(['invoice_id', 'position'], 'invoice_items_invoice_id_position_unique');
        });

        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_position_positive CHECK (position > 0)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_quantity_range CHECK (quantity BETWEEN 1 AND 9999)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_unit_price_range CHECK (unit_price_cents >= 0 AND unit_price_cents <= 1000000000)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_line_total_consistent CHECK (line_total_cents = unit_price_cents * quantity)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_line_kind CHECK ((part_number IS NULL) = (brand IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
