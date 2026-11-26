<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceWorkflow;
use Illuminate\Console\Command;

/**
 * Writes `overdue` (with a system history row) into stored-open invoices whose due date is before today (UTC).
 * Housekeeping only: the API reports the effective status without this command. Idempotent, never touches paid
 * or void, sends no email.
 */
class MarkInvoicesOverdue extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Mark open invoices past their due date as overdue';

    public function handle(InvoiceWorkflow $workflow): int
    {
        $count = 0;

        Invoice::where('status', 'open')
            ->where('due_date', '<', Invoice::today())
            ->chunkById(200, function ($invoices) use ($workflow, &$count) {
                foreach ($invoices as $invoice) {
                    $workflow->markOverdue($invoice->id) && $count++;
                }
            }, 'id');

        $this->info("Marked {$count} invoice(s) overdue.");

        return self::SUCCESS;
    }
}
