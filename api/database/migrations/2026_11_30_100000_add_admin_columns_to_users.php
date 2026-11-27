<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Phase 6: account lifecycle. Existing users become active; sessions created before this migration die once (no stored epoch). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users
            ADD COLUMN active boolean NOT NULL DEFAULT true,
            ADD COLUMN session_epoch integer NOT NULL DEFAULT 1,
            ADD COLUMN invited_at timestamptz NULL,
            ADD COLUMN invitation_accepted_at timestamptz NULL,
            ADD COLUMN invited_by_user_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_session_epoch_positive CHECK (session_epoch >= 1)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_invitation_consistent CHECK (invitation_accepted_at IS NULL OR invited_at IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_session_epoch_positive, DROP CONSTRAINT IF EXISTS users_invitation_consistent');
        DB::statement('ALTER TABLE users DROP COLUMN invited_by_user_id, DROP COLUMN invitation_accepted_at, DROP COLUMN invited_at, DROP COLUMN session_epoch, DROP COLUMN active');
    }
};
