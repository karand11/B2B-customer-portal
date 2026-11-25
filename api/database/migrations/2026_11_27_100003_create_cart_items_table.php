<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One cart per user; prices are never stored here.
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('quantity');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['user_id', 'product_id'], 'cart_items_user_id_product_id_unique');
        });

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_range CHECK (quantity > 0 AND quantity <= 9999)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
