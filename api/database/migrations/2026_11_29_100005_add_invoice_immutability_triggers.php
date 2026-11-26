<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Gap-free" and "never changes" are promises, so the database enforces them: issued invoices cannot be deleted
 * and only status, paid_on and updated_at can change; paid and void are final (no status or paid_on change once reached). TRUNCATE is not blocked (migrate:fresh and test cleanup need it).
 */
return new class extends Migration
{
    private const IMMUTABLE = [
        'public_id', 'number', 'number_year', 'number_seq', 'company_id', 'order_id', 'order_number', 'created_by_user_id',
        'issue_date', 'due_date', 'currency', 'po_reference', 'vat_rate_bp', 'subtotal_cents', 'tax_cents', 'total_cents',
        'seller', 'buyer', 'created_at',
    ];

    public function up(): void
    {
        DB::unprepared("CREATE OR REPLACE FUNCTION invoice_forbid_delete() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN RAISE EXCEPTION 'invoices are append-only: % rows cannot be deleted', TG_TABLE_NAME; END; \$\$");
        DB::unprepared("CREATE OR REPLACE FUNCTION invoice_forbid_update() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN RAISE EXCEPTION 'invoices are append-only: % rows cannot be updated', TG_TABLE_NAME; END; \$\$");

        $changed = implode(' OR ', array_map(fn ($c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}", self::IMMUTABLE));
        DB::unprepared("CREATE OR REPLACE FUNCTION invoice_guard_immutable_columns() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF {$changed} THEN
                    RAISE EXCEPTION 'issued invoices are immutable: only status and paid_on can change';
                END IF;
                IF OLD.status IN ('paid', 'void') AND (NEW.status <> OLD.status OR NEW.paid_on IS DISTINCT FROM OLD.paid_on) THEN
                    RAISE EXCEPTION 'paid and void invoices are final: status and paid_on cannot change';
                END IF;
                RETURN NEW;
            END; \$\$");

        foreach (['invoices', 'invoice_items', 'invoice_status_history'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION invoice_forbid_delete()");
        }
        foreach (['invoice_items', 'invoice_status_history'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION invoice_forbid_update()");
        }
        DB::unprepared('CREATE TRIGGER invoices_immutable_columns BEFORE UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION invoice_guard_immutable_columns()');
    }

    public function down(): void
    {
        foreach (['invoices', 'invoice_items', 'invoice_status_history'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete ON {$table}");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_items_no_update ON invoice_items');
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_status_history_no_update ON invoice_status_history');
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_immutable_columns ON invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS invoice_forbid_delete()');
        DB::unprepared('DROP FUNCTION IF EXISTS invoice_forbid_update()');
        DB::unprepared('DROP FUNCTION IF EXISTS invoice_guard_immutable_columns()');
    }
};
