<?php

namespace App\Services;

use App\Mail\QuoteSentMail;
use App\Mail\QuoteSubmittedMail;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The two quote emails. Call only AFTER the transaction has committed. A mail problem is logged and never
 * fails the request (the quote exists either way). No customer free text goes into any email.
 */
class QuoteNotifier
{
    /** One queued message per staff recipient (never one message with many addresses in To). */
    public function submitted(QuoteRequest $quote, User $requester, int $lineCount): void
    {
        try {
            $to = $this->staffRecipients();

            if ($to === []) {
                Log::warning('Quote submitted but there is nobody to notify.', ['quote' => $quote->quote_number]);

                return;
            }

            $companyName = $requester->loadMissing('company')->company->name;
            foreach ($to as $address) {
                try {
                    Mail::to($address)->queue(new QuoteSubmittedMail(
                        number: $quote->quote_number,
                        companyName: $companyName,
                        requesterName: $requester->name,
                        lineCount: $lineCount,
                        poReference: $quote->po_reference,
                        url: $this->url($quote),
                    ));
                } catch (Throwable $e) {
                    report($e); // one bad recipient must not stop the others
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The shared mailbox when configured, else active staff and admin users by id, at most max_notify_recipients (a warning is logged when more exist). */
    private function staffRecipients(): array
    {
        if (config('quotes.notify_address')) {
            return [config('quotes.notify_address')];
        }

        $max = config('quotes.max_notify_recipients');
        $emails = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['staff', 'admin']))->orderBy('id')->limit($max + 1)->pluck('email')->all();

        if (count($emails) > $max) {
            Log::warning("More than {$max} staff users: the new-quote email goes to the first {$max} only. Set QUOTES_NOTIFY_ADDRESS to a shared mailbox.");
            $emails = array_slice($emails, 0, $max);
        }

        return $emails;
    }

    /** To the user who created the quote (not to every user of the company). Needs createdBy and items loaded. */
    public function sent(QuoteRequest $quote): void
    {
        try {
            Mail::to($quote->createdBy->email)->queue(new QuoteSentMail(
                number: $quote->quote_number,
                total: number_format($quote->total_cents / 100, 2, '.', ',').' '.$quote->currency,
                validUntil: $quote->valid_until->toDateString(),
                lineCount: $quote->items->count(),
                url: $this->url($quote),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Built from config only, never from the request Host header. */
    private function url(QuoteRequest $quote): string
    {
        return rtrim(config('app.web_url'), '/').'/quotes/'.$quote->quote_number;
    }
}
