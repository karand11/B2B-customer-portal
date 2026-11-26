<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: no updated_at. A NULL actor is the system (expiry only).
        Schema::create('quote_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_request_id')->constrained('quote_requests')->restrictOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');

            $table->index(['quote_request_id', 'id']);
        });

        $statuses = "('submitted','in_review','quoted','accepted','declined','expired')";
        DB::statement("ALTER TABLE quote_status_history ADD CONSTRAINT quote_status_history_from_check CHECK (from_status IN {$statuses})");
        DB::statement("ALTER TABLE quote_status_history ADD CONSTRAINT quote_status_history_to_check CHECK (to_status IN {$statuses})");
        DB::statement('ALTER TABLE quote_status_history ADD CONSTRAINT quote_status_history_changed CHECK (from_status IS NULL OR from_status <> to_status)');
        DB::statement("ALTER TABLE quote_status_history ADD CONSTRAINT quote_status_history_actor_check CHECK (actor_user_id IS NOT NULL OR to_status = 'expired')");
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_status_history');
    }
};
