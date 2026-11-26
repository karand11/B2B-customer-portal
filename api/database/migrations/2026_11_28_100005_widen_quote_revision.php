<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** revision counts every send; smallint would overflow (a 500) after 32767 reopen/send cycles. The CHECKs (revision >= 0, sent_requires_price) are unchanged. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE quote_requests ALTER COLUMN revision TYPE integer');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE quote_requests ALTER COLUMN revision TYPE smallint');
    }
};
