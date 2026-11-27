<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Optimistic concurrency: `version` is bumped by the database on EVERY update (also the importer's upsert), so no code path can forget it.
 * Also two small indexes for the back office: the inactive-only filter/dashboard count and the updated_at sort (the Phase 2 indexes are partial WHERE active).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'companies'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN version integer NOT NULL DEFAULT 1");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_version_positive CHECK (version >= 1)");
        }

        DB::unprepared('CREATE OR REPLACE FUNCTION bump_row_version() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN NEW.version := OLD.version + 1; RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER products_bump_version BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION bump_row_version()');
        DB::unprepared('CREATE TRIGGER companies_bump_version BEFORE UPDATE ON companies FOR EACH ROW EXECUTE FUNCTION bump_row_version()');

        DB::statement('CREATE INDEX products_updated_at_idx ON products (updated_at)');
        DB::statement('CREATE INDEX products_inactive_part_idx ON products (part_number_search) WHERE NOT active');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_inactive_part_idx');
        DB::statement('DROP INDEX IF EXISTS products_updated_at_idx');
        DB::unprepared('DROP TRIGGER IF EXISTS companies_bump_version ON companies');
        DB::unprepared('DROP TRIGGER IF EXISTS products_bump_version ON products');
        DB::unprepared('DROP FUNCTION IF EXISTS bump_row_version()');

        foreach (['products', 'companies'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_version_positive");
            DB::statement("ALTER TABLE {$table} DROP COLUMN version");
        }
    }
};
