<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 16);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->char('currency', 3);
            $table->string('po_reference', 64)->nullable();
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('shipping_cents')->default(0);
            $table->bigInteger('total_cents');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique('order_number', 'orders_order_number_unique');
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        // The alphabet has no I, L, O, U, 0, 1 (see App\Support\OrderNumber).
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_order_number_format CHECK (order_number ~ '^ORD-[A-HJKMNP-TV-Z2-9]{5}-[A-HJKMNP-TV-Z2-9]{5}$')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','confirmed','shipped','delivered','cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_po_reference_length CHECK (char_length(po_reference) <= 64)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_subtotal_nonnegative CHECK (subtotal_cents >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_tax_nonnegative CHECK (tax_cents >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_shipping_nonnegative CHECK (shipping_cents >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_nonnegative CHECK (total_cents >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_consistent CHECK (total_cents = subtotal_cents + tax_cents + shipping_cents)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
