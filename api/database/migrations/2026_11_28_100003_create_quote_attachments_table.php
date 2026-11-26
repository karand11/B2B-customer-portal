<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rows are immutable; delete is a hard delete. storage_path is random hex only, never user text.
        Schema::create('quote_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('quote_request_id')->constrained('quote_requests')->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('uploaded_by_role', 8);
            $table->string('original_name', 120);
            $table->string('storage_path', 64);
            $table->string('mime', 100);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('created_at');

            $table->unique('public_id', 'quote_attachments_public_id_unique');
            $table->unique('storage_path', 'quote_attachments_storage_path_unique');
            $table->index(['quote_request_id', 'id']);
        });

        DB::statement("ALTER TABLE quote_attachments ADD CONSTRAINT quote_attachments_role_check CHECK (uploaded_by_role IN ('customer','staff'))");
        DB::statement("ALTER TABLE quote_attachments ADD CONSTRAINT quote_attachments_storage_path_format CHECK (storage_path ~ '^quote-attachments/[a-f0-9]{32}$')");
        DB::statement("ALTER TABLE quote_attachments ADD CONSTRAINT quote_attachments_mime_check CHECK (mime IN ('application/pdf','image/png','image/jpeg','text/plain','text/csv'))");
        DB::statement('ALTER TABLE quote_attachments ADD CONSTRAINT quote_attachments_size_range CHECK (size_bytes > 0 AND size_bytes <= 10485760)');
        DB::statement("ALTER TABLE quote_attachments ADD CONSTRAINT quote_attachments_sha256_format CHECK (sha256 ~ '^[a-f0-9]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_attachments');
    }
};
