<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Step 1 of the two-step CSV import: the previewed file and its report. The uuid is the token the uploader sends to apply. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('original_name', 120);
            $table->string('storage_path', 255)->nullable()->unique();
            $table->integer('size_bytes');
            $table->char('sha256', 64);
            $table->string('status', 10);
            $table->integer('rows_read');
            $table->integer('would_create');
            $table->integer('would_update');
            $table->integer('bad_rows');
            $table->jsonb('errors')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('result')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('applied_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_status_check CHECK (status IN ('previewed','applied','expired'))");
        DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_applied_consistent CHECK ((status = 'applied') = (applied_at IS NOT NULL))");
        DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_errors_array CHECK (jsonb_typeof(errors) = 'array' AND jsonb_typeof(warnings) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
    }
};
