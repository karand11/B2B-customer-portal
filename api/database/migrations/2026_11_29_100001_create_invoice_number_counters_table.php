<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One row per year, written only by InvoiceNumberAllocator inside the invoice transaction (never a Postgres sequence: those burn numbers on rollback). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_counters', function (Blueprint $table) {
            $table->smallInteger('year')->primary();
            $table->integer('last_value')->default(0);
            $table->timestamp('updated_at');
        });

        DB::statement('ALTER TABLE invoice_number_counters ADD CONSTRAINT invoice_number_counters_year_range CHECK (year BETWEEN 2000 AND 2999)');
        DB::statement('ALTER TABLE invoice_number_counters ADD CONSTRAINT invoice_number_counters_value_range CHECK (last_value >= 0 AND last_value <= 999999999)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_counters');
    }
};
