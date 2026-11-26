<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. A NULL actor is the system (the overdue command only).
        Schema::create('invoice_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');

            $table->index(['invoice_id', 'id']);
        });

        $statuses = "('open','overdue','paid','void')";
        DB::statement("ALTER TABLE invoice_status_history ADD CONSTRAINT invoice_status_history_from_check CHECK (from_status IN {$statuses})");
        DB::statement("ALTER TABLE invoice_status_history ADD CONSTRAINT invoice_status_history_to_check CHECK (to_status IN {$statuses})");
        DB::statement('ALTER TABLE invoice_status_history ADD CONSTRAINT invoice_status_history_changed CHECK (from_status IS NULL OR from_status <> to_status)');
        DB::statement("ALTER TABLE invoice_status_history ADD CONSTRAINT invoice_status_history_system_actor CHECK (actor_user_id IS NOT NULL OR to_status = 'overdue')");
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_status_history');
    }
};
