<?php

namespace App\Enums;

use App\Models\User;

enum InvoiceStatus: string
{
    case Open = 'open';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Void = 'void';

    /**
     * The status every response and every state check uses: a stored open invoice whose due date is before
     * today (UTC) is overdue, stored or not. Due today is NOT overdue. $dueDate and $today are YYYY-MM-DD strings.
     */
    public static function effective(self $stored, string $dueDate, string $today): self
    {
        return $stored === self::Open && $dueDate < $today ? self::Overdue : $stored;
    }

    /** Open and overdue invoices can still be paid or voided; paid and void are terminal. */
    public function isSettleable(): bool
    {
        return $this === self::Open || $this === self::Overdue;
    }

    /** @return list<string> what this viewer may do with an invoice in this (effective) status */
    public function allowedActions(User $viewer): array
    {
        return $viewer->isStaff() && $this->isSettleable() ? ['download', 'mark_paid', 'void'] : ['download'];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
