<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An order line is a snapshot (part number, name, price at the time): "never updated or deleted by the app" becomes a database rule,
 * the same pattern as the invoice triggers. No code path updates or deletes order_items (checked in Phase 7: only INSERTs exist);
 * TRUNCATE stays allowed (migrate:fresh, test cleanup, portal:loadtest-seed).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("CREATE OR REPLACE FUNCTION order_items_forbid_change() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN RAISE EXCEPTION 'order_items are append-only: rows cannot be updated or deleted'; END; \$\$");
        DB::unprepared('CREATE TRIGGER order_items_append_only BEFORE UPDATE OR DELETE ON order_items FOR EACH ROW EXECUTE FUNCTION order_items_forbid_change()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS order_items_append_only ON order_items');
        DB::unprepared('DROP FUNCTION IF EXISTS order_items_forbid_change()');
    }
};
