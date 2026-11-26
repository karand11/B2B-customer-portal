<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->string('number', 20);
            $table->smallInteger('number_year');
            $table->integer('number_seq');
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('order_number', 16);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('paid_on')->nullable();
            $table->char('currency', 3);
            $table->string('po_reference', 64)->nullable();
            $table->integer('vat_rate_bp');
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('tax_cents');
            $table->bigInteger('total_cents');
            $table->jsonb('seller');
            $table->jsonb('buyer');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique('public_id', 'invoices_public_id_unique');
            $table->unique('number', 'invoices_number_unique');
            $table->unique(['number_year', 'number_seq'], 'invoices_number_year_seq_unique');
            $table->index('order_id');
            $table->index(['company_id', 'issue_date']);
            $table->index(['company_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index('issue_date');
        });

        // At most one NON-void invoice per order: the DB-level guarantee behind the order row lock.
        DB::statement("CREATE UNIQUE INDEX invoices_one_active_per_order ON invoices (order_id) WHERE status <> 'void'");

        $checks = [
            'number_format' => "number ~ '^INV-[0-9]{4}-[0-9]{6,9}$'",
            'number_parts' => "number = 'INV-' || number_year::text || '-' || CASE WHEN number_seq < 1000000 THEN lpad(number_seq::text, 6, '0') ELSE number_seq::text END",
            'number_year_matches_issue_date' => 'number_year = EXTRACT(YEAR FROM issue_date)',
            'number_seq_positive' => 'number_seq > 0',
            'status_check' => "status IN ('open','overdue','paid','void')",
            'due_after_issue' => 'due_date >= issue_date',
            'paid_consistent' => "(status = 'paid') = (paid_on IS NOT NULL)",
            'paid_after_issue' => 'paid_on IS NULL OR paid_on >= issue_date',
            'currency_check' => "currency ~ '^[A-Z]{3}$'",
            'po_reference_length' => 'char_length(po_reference) <= 64',
            'vat_rate_range' => 'vat_rate_bp BETWEEN 0 AND 5000',
            'subtotal_nonnegative' => 'subtotal_cents >= 0',
            'tax_nonnegative' => 'tax_cents >= 0',
            'total_nonnegative' => 'total_cents >= 0',
            // 100 lines x 9999 x 10^9: the largest Phase 3 cart. Also keeps subtotal * rate inside bigint.
            'subtotal_max' => 'subtotal_cents <= 999900000000000',
            // Integer division truncates, so for non-negative values this is exactly "round half up, once, on the subtotal".
            'tax_consistent' => 'tax_cents = (subtotal_cents * vat_rate_bp + 5000) / 10000',
            'total_consistent' => 'total_cents = subtotal_cents + tax_cents',
            'total_js_safe' => 'total_cents <= 9007199254740991',
            'seller_object' => "jsonb_typeof(seller) = 'object'",
            'buyer_object' => "jsonb_typeof(buyer) = 'object'",
        ];
        foreach ($checks as $name => $sql) {
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_{$name} CHECK ({$sql})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
