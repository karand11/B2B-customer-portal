<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: no updated_at.
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->index(['order_id', 'id']);
        });

        $statuses = "('pending','confirmed','shipped','delivered','cancelled')";
        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_from_check CHECK (from_status IN {$statuses})");
        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_to_check CHECK (to_status IN {$statuses})");
        DB::statement('ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_changed CHECK (from_status IS NULL OR from_status <> to_status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
