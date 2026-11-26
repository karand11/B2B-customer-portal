<?php

namespace App\Console\Commands;

use App\Enums\QuoteStatus;
use App\Models\QuoteRequest;
use App\Services\QuoteWorkflow;
use Illuminate\Console\Command;

/**
 * Writes `expired` into quotes that are already effectively expired (valid_until before today, UTC).
 * Housekeeping only: the API reports the effective status without this command. Idempotent, sends no email.
 */
class ExpireQuotes extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Mark quoted quotes past their valid-until date as expired';

    public function handle(QuoteWorkflow $workflow): int
    {
        $count = 0;

        QuoteRequest::where('status', QuoteStatus::Quoted)
            ->where('valid_until', '<', now()->utc()->toDateString())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use ($workflow, &$count) {
                $workflow->expire($id) && $count++;
            });

        $this->info("Expired {$count} quote(s).");

        return self::SUCCESS;
    }
}
