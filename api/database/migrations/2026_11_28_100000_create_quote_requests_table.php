<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->char('quote_number', 15);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('submitted');
            $table->char('currency', 3);
            $table->string('po_reference', 64)->nullable();
            $table->text('customer_note')->nullable();
            $table->text('staff_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->smallInteger('revision')->default(0);
            $table->bigInteger('subtotal_cents')->nullable();
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('shipping_cents')->default(0);
            $table->bigInteger('total_cents')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique('quote_number', 'quote_requests_quote_number_unique');
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        // Same alphabet as orders: no I, L, O, U, 0, 1 (see App\Support\QuoteNumber).
        DB::statement("ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_quote_number_format CHECK (quote_number ~ '^QUO-[A-HJKMNP-TV-Z2-9]{5}-[A-HJKMNP-TV-Z2-9]{5}$')");
        DB::statement("ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_status_check CHECK (status IN ('submitted','in_review','quoted','accepted','declined','expired'))");
        DB::statement("ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_po_reference_length CHECK (char_length(po_reference) <= 64)');
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_customer_note_length CHECK (char_length(customer_note) <= 2000)');
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_staff_note_length CHECK (char_length(staff_note) <= 2000)');
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_internal_note_length CHECK (char_length(internal_note) <= 2000)');
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_revision_nonnegative CHECK (revision >= 0)');
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_subtotal_nonnegative CHECK (subtotal_cents >= 0)');
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_tax_nonnegative CHECK (tax_cents >= 0)');
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_shipping_nonnegative CHECK (shipping_cents >= 0)');
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_total_nonnegative CHECK (total_cents >= 0)');
        DB::statement('ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_totals_consistent CHECK ((subtotal_cents IS NULL AND total_cents IS NULL) OR (subtotal_cents IS NOT NULL AND total_cents IS NOT NULL AND total_cents = subtotal_cents + tax_cents + shipping_cents))');
        DB::statement("ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_sent_requires_price CHECK (status NOT IN ('quoted','accepted') OR (total_cents IS NOT NULL AND valid_until IS NOT NULL AND sent_at IS NOT NULL AND revision >= 1))");

        // Serves quotes:expire.
        DB::statement("CREATE INDEX quote_requests_quoted_valid_until_index ON quote_requests (valid_until) WHERE status = 'quoted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_requests');
    }
};
