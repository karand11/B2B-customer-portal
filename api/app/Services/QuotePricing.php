<?php

namespace App\Services;

use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Staff pricing and sending. Every number is an integer computed here from staff-entered unit prices; customers never supply any. */
class QuotePricing
{
    public function __construct(private QuoteWorkflow $workflow, private QuoteNotifier $notifier) {}

    /**
     * Save a DRAFT (no history row, no email). Idempotent.
     *
     * @param  list<array{position: int, unit_price_cents: int|null}>  $lines
     */
    public function save(QuoteRequest $quote, User $actor, array $lines, bool $setValidUntil, ?string $validUntil): QuoteRequest
    {
        return $this->workflow->run($quote, 'price', $actor, function (QuoteRequest $locked) use ($actor, $lines, $setValidUntil, $validUntil) {
            $items = $locked->items()->get()->keyBy('position');

            $problems = [];
            foreach ($lines as $i => $line) {
                $items->has($line['position']) || $problems["lines.{$i}.position"] = 'There is no line at this position.';
            }
            if ($problems) {
                throw ValidationException::withMessages($problems);
            }

            $before = $this->terms($locked);
            $changed = 0;
            foreach ($lines as $line) {
                $item = $items[$line['position']];
                $price = $line['unit_price_cents'];
                $changed += $item->unit_price_cents === $price ? 0 : 1;
                $item->forceFill(['unit_price_cents' => $price, 'line_total_cents' => $price === null ? null : $price * $item->quantity])->save();
            }

            $setValidUntil && $locked->forceFill(['valid_until' => $validUntil]);
            $this->totals($locked, $items);

            AuditLog::record(
                'quote.priced', 'quote', $locked->quote_number, $locked->quote_number, $actor,
                $before, $this->terms($locked), $changed > 0 ? ['lines_changed' => $changed] : [], $locked->company_id,
            );
        });
    }

    /** in_review -> quoted. The email goes out after the transaction has committed. */
    public function send(QuoteRequest $quote, User $actor, bool $setNote, ?string $staffNote): QuoteRequest
    {
        $sent = $this->workflow->run($quote, 'send', $actor, function (QuoteRequest $locked) use ($setNote, $staffNote) {
            $items = $locked->items()->get();

            if ($items->contains(fn ($item) => $item->unit_price_cents === null)) {
                throw ValidationException::withMessages(['lines' => 'All lines must be priced.']);
            }

            $today = now()->utc();
            $until = $locked->valid_until?->toDateString();
            if ($until === null || $until <= $today->toDateString() || $until > $today->copy()->addDays(config('quotes.max_validity_days'))->toDateString()) {
                throw ValidationException::withMessages(['valid_until' => 'Set a valid-until date after today and at most '.config('quotes.max_validity_days').' days ahead (UTC).']);
            }

            $this->totals($locked, $items->keyBy('position'));
            $locked->forceFill(['sent_at' => now(), 'revision' => $locked->revision + 1]);
            $setNote && $locked->forceFill(['staff_note' => $staffNote]);
        });

        $this->notifier->sent($sent->load(['createdBy', 'items']));

        return $sent;
    }

    /** @return array<string, mixed> what a draft save can change, for the audit row */
    private function terms(QuoteRequest $quote): array
    {
        return ['subtotal_cents' => $quote->subtotal_cents, 'total_cents' => $quote->total_cents, 'valid_until' => $quote->valid_until?->toDateString()];
    }

    /** subtotal and total only when EVERY line is priced; tax and shipping stay 0. */
    private function totals(QuoteRequest $quote, Collection $items): void
    {
        $priced = $items->every(fn ($item) => $item->unit_price_cents !== null);
        $subtotal = $priced ? (int) $items->sum('line_total_cents') : null;

        $quote->forceFill(['subtotal_cents' => $subtotal, 'total_cents' => $subtotal]);
    }
}
