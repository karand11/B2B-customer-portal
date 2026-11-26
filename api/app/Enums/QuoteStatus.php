<?php

namespace App\Enums;

use App\Models\User;
use Illuminate\Support\Carbon;

enum QuoteStatus: string
{
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Quoted = 'quoted';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';

    /**
     * THE transition matrix: action => who (customer|staff), from statuses (effective), resulting status (null = no status change).
     * Order matters: allowedActions() returns actions in this order, which is the order the contract lists them.
     */
    public const ACTIONS = [
        'review' => ['staff', [self::Submitted], self::InReview],
        'price' => ['staff', [self::InReview], null],
        'send' => ['staff', [self::InReview], self::Quoted],
        'reject' => ['staff', [self::Submitted, self::InReview], self::Declined],
        'reopen' => ['staff', [self::Quoted], self::InReview],
        'accept' => ['customer', [self::Quoted], self::Accepted],
        'decline' => ['customer', [self::Submitted, self::InReview, self::Quoted], self::Declined],
        'attach' => ['any', [self::Submitted, self::InReview], null],
        'note' => ['staff', [self::Submitted, self::InReview, self::Quoted, self::Accepted, self::Declined, self::Expired], null],
    ];

    /** The status every response and every state check uses: a quoted quote past its last valid day is expired, stored or not. */
    public static function effective(self $status, ?Carbon $validUntil, ?Carbon $now = null): self
    {
        $today = ($now ?? now())->utc()->toDateString();

        return $status === self::Quoted && $validUntil !== null && $validUntil->toDateString() < $today ? self::Expired : $status;
    }

    /** @return list<string> the actions this viewer may take in this (effective) status */
    public function allowedActions(User $viewer): array
    {
        $kind = $viewer->isStaff() ? 'staff' : ($viewer->isCustomer() ? 'customer' : null);
        $actions = [];

        foreach (self::ACTIONS as $action => [$who, $from]) {
            if ($kind !== null && ($who === 'any' || $who === $kind) && in_array($this, $from, true)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
