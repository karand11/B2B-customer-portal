<?php

namespace App\Services;

use App\Enums\QuoteStatus;
use App\Models\QuoteRequest;
use App\Models\QuoteStatusHistory;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes quote_requests.status (CreateQuote writes the first history row).
 * Locks the row, re-reads it, checks the EFFECTIVE status against QuoteStatus::ACTIONS, runs the action's own
 * changes in the same transaction, and writes exactly one history row per status change. A refusal is a 422 on `status`.
 */
class QuoteWorkflow
{
    /** Workflow action => audit action suffix, for every action that changes the status. */
    private const AUDIT_ACTIONS = [
        'review' => 'reviewed', 'send' => 'sent', 'reject' => 'rejected', 'reopen' => 'reopened', 'accept' => 'accepted', 'decline' => 'declined',
    ];

    /**
     * @param  Closure(QuoteRequest): void|null  $effects  runs under the lock after the checks; may throw ValidationException (all rolls back)
     */
    public function run(QuoteRequest $quote, string $action, User $actor, ?Closure $effects = null, ?string $note = null): QuoteRequest
    {
        return DB::transaction(function () use ($quote, $action, $actor, $effects, $note) {
            $locked = QuoteRequest::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $effective = $locked->effectiveStatus();

            if (! in_array($action, $effective->allowedActions($actor), true)) {
                throw ValidationException::withMessages(['status' => $this->refusal($effective, $action)]);
            }

            $before = $locked->revision;
            // An effect may return audit metadata (AcceptQuote returns the order number it created).
            $extra = $effects?->__invoke($locked);
            $to = QuoteStatus::ACTIONS[$action][2];

            if ($to !== null) {
                $from = $locked->status;
                $locked->forceFill(['status' => $to]);
                $this->history($locked, $from, $to, $actor, $note);
                $this->audit($locked, $action, $from, $to, $before, $actor, is_array($extra) ? $extra : []);
            }

            $locked->save();

            return $locked;
        });
    }

    /** Status actions (not price, attach or note: pricing audits itself, the other two are not audited). `send` also records the terms that went out. */
    private function audit(QuoteRequest $locked, string $action, QuoteStatus $from, QuoteStatus $to, int $revisionBefore, User $actor, array $metadata): void
    {
        $sent = $action === 'send';

        AuditLog::record(
            'quote.'.self::AUDIT_ACTIONS[$action], 'quote', $locked->quote_number, $locked->quote_number, $actor,
            ['status' => $from->value] + ($sent ? ['revision' => $revisionBefore] : []),
            ['status' => $to->value] + ($sent ? ['revision' => $locked->revision, 'valid_until' => $locked->valid_until?->toDateString(), 'total_cents' => $locked->total_cents] : []),
            $metadata, $locked->company_id,
        );
    }

    /** Materialise an expiry (quotes:expire). False when the quote is no longer quoted-and-past-due once locked. */
    public function expire(int $quoteId): bool
    {
        return DB::transaction(function () use ($quoteId) {
            $locked = QuoteRequest::whereKey($quoteId)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== QuoteStatus::Quoted || $locked->effectiveStatus() !== QuoteStatus::Expired) {
                return false;
            }

            $locked->forceFill(['status' => QuoteStatus::Expired])->save();
            $this->history($locked, QuoteStatus::Quoted, QuoteStatus::Expired, null, null);

            return true;
        });
    }

    public function history(QuoteRequest $quote, ?QuoteStatus $from, QuoteStatus $to, ?User $actor, ?string $note): void
    {
        (new QuoteStatusHistory)->forceFill([
            'quote_request_id' => $quote->id, 'from_status' => $from, 'to_status' => $to,
            'actor_user_id' => $actor?->id, 'note' => $note,
        ])->save();
    }

    private function refusal(QuoteStatus $effective, string $action): string
    {
        return match (true) {
            $action === 'accept' && $effective === QuoteStatus::Accepted => 'This quote has already been accepted.',
            $effective === QuoteStatus::Expired => 'This quote has expired.',
            $action === 'attach' => 'Files can only be changed while the quote is submitted or in review.',
            default => "This action is not possible while the quote is {$effective->value}.",
        };
    }
}
