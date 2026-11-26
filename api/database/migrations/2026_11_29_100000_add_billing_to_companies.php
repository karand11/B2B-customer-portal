<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Phase 5: buyer data for invoices. Not exposed or editable through any endpoint (seeders now, Phase 6 admin later). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE companies ADD COLUMN billing_address text NULL, ADD COLUMN tax_id varchar(32) NULL');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_billing_address_length CHECK (char_length(billing_address) <= 500)');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_tax_id_length CHECK (char_length(tax_id) <= 32)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_billing_address_length, DROP CONSTRAINT IF EXISTS companies_tax_id_length');
        DB::statement('ALTER TABLE companies DROP COLUMN billing_address, DROP COLUMN tax_id');
    }
};
