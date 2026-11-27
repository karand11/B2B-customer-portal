<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditRedactor;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Appends one audit_log row. Called by the service that makes the change, INSIDE its transaction, so there is never a change
 * without a row and never a row without a change. The actor is always passed in (never read from auth()), so jobs and tests behave the same.
 */
class AuditLog
{
    /** Every action the log can contain (the contract vocabulary); the viewer validates its `action` filter against it. */
    public const ACTIONS = [
        'order.status_changed',
        'quote.reviewed', 'quote.sent', 'quote.reopened', 'quote.rejected', 'quote.accepted', 'quote.declined', 'quote.priced',
        'invoice.created', 'invoice.paid', 'invoice.voided',
        'product.created', 'product.updated', 'product.activated', 'product.deactivated', 'product.imported',
        'company.created', 'company.updated',
        'user.invited', 'user.invitation_resent', 'user.invitation_revoked', 'user.invitation_accepted', 'user.updated',
        'user.assignment_changed', 'user.deactivated', 'user.reactivated', 'user.password_reset_requested',
    ];

    /**
     * Transaction depth that existed before the test harness wrapped each test in one. Always 0 in production; TestCase sets it,
     * so a test proves that the CALLER opened its own transaction, not just the ambient one.
     */
    public static int $baseTransactionLevel = 0;

    /**
     * Nothing is written when the (allowlisted) change and metadata are both empty: a no-op has no row.
     * `$before` empty means a creation: `$after` is kept whole. Otherwise only the keys whose value differs are kept on both sides.
     * `$always` is for events that change nothing the log can show (an invitation accepted, a reset email requested): the row is written anyway.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $action, string $subjectType, string $subjectId, ?string $label, ?User $actor,
        array $before = [], array $after = [], array $metadata = [], ?int $companyId = null, bool $always = false,
    ): void {
        if (DB::transactionLevel() <= self::$baseTransactionLevel) {
            throw new LogicException('AuditLog::record() must be called inside the transaction of the change it records.');
        }

        $before = AuditRedactor::filter($subjectType, $before, AuditRedactor::CHANGES);
        $after = AuditRedactor::filter($subjectType, $after, AuditRedactor::CHANGES);
        $metadata = AuditRedactor::filter($subjectType, $metadata, AuditRedactor::METADATA);

        if ($before !== []) {
            // Keep the keys whose value differs (a key missing on one side counts as null there, but is not invented on that side).
            $keys = array_unique([...array_keys($before), ...array_keys($after)]);
            $changed = array_flip(array_filter($keys, fn ($k) => ($before[$k] ?? null) !== ($after[$k] ?? null)));
            $before = array_intersect_key($before, $changed);
            $after = array_intersect_key($after, $changed);
        }

        if ($before === [] && $after === [] && $metadata === [] && ! $always) {
            return;
        }

        $changes = ($before === [] && $after === []) ? new \stdClass : ['before' => (object) $before, 'after' => (object) $after];

        DB::table('audit_log')->insert([
            'occurred_at' => now()->utc()->format('Y-m-d H:i:s.u'),
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor?->primaryRole() ?? 'system',
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => mb_substr($subjectId, 0, 64),
            'subject_label' => $label === null ? null : mb_substr($label, 0, 120),
            'company_id' => $companyId,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'metadata' => json_encode((object) $metadata, JSON_THROW_ON_ERROR),
        ]);
    }
}
