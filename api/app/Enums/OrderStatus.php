<?php

namespace App\Enums;

use App\Models\User;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return list<self> the single transition matrix (staff/admin rights) */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    /**
     * Transitions this user may perform from this status (staff/admin: the matrix row;
     * customer: only pending -> cancelled). Used by the server check and by the response.
     *
     * @return list<self>
     */
    public function allowedFor(User $user): array
    {
        if ($user->isStaff()) {
            return $this->next();
        }

        return $user->isCustomer() && $this === self::Pending ? [self::Cancelled] : [];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
