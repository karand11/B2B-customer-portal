<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Append-only audit trail, written by App\Services\AuditLog inside the transaction of the change it records.
 * UPDATE and DELETE are refused by a trigger (TRUNCATE is not blocked: migrate:fresh and test cleanup need it).
 * No IP address, no user agent: see docs/phase-6-design.md section 3.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE audit_log (
            id bigserial PRIMARY KEY,
            occurred_at timestamptz(6) NOT NULL,
            actor_user_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
            actor_role varchar(16) NOT NULL,
            action varchar(64) NOT NULL,
            subject_type varchar(16) NOT NULL,
            subject_id varchar(64) NOT NULL,
            subject_label varchar(120) NULL,
            company_id bigint NULL REFERENCES companies (id) ON DELETE RESTRICT,
            changes jsonb NOT NULL DEFAULT '{}',
            metadata jsonb NOT NULL DEFAULT '{}'
        )");

        DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_actor_role_check CHECK (actor_role IN ('customer','staff','admin','system'))");
        DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_actor_consistent CHECK ((actor_user_id IS NULL) = (actor_role = 'system'))");
        DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_action_format CHECK (action ~ '^[a-z_]+\\.[a-z_]+$')");
        DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_subject_type_check CHECK (subject_type IN ('order','quote','invoice','product','company','user','import'))");
        foreach (['changes', 'metadata'] as $column) {
            DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_{$column}_object CHECK (jsonb_typeof({$column}) = 'object')");
            DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_{$column}_size CHECK (octet_length({$column}::text) <= 16384)");
        }

        DB::statement('CREATE INDEX audit_log_occurred_idx ON audit_log (occurred_at DESC, id DESC)');
        DB::statement('CREATE INDEX audit_log_actor_idx ON audit_log (actor_user_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_log_subject_idx ON audit_log (subject_type, subject_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_log_company_idx ON audit_log (company_id, occurred_at DESC) WHERE company_id IS NOT NULL');
        DB::statement('CREATE INDEX audit_log_action_idx ON audit_log (action, occurred_at DESC)');

        DB::unprepared('CREATE OR REPLACE FUNCTION audit_log_forbid_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION \'audit_log is append-only: rows cannot be updated or deleted\'; END; $$');
        DB::unprepared('CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW EXECUTE FUNCTION audit_log_forbid_change()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_append_only ON audit_log');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_log_forbid_change()');
        DB::statement('DROP TABLE IF EXISTS audit_log');
    }
};
