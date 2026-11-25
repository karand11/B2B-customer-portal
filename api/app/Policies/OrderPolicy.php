<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Second layer behind Order::visibleTo(). "Who may" only; state rules (can it be cancelled now?)
 * live in OrderStatus/OrderStatusChanger so a state conflict is a 422, not a 403.
 * No Gate::before and no blanket admin allow: staff and admin are covered explicitly.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || ($user->isCustomer() && $user->company_id !== null);
    }

    /** Foreign orders are indistinguishable from missing ones: 404, never 403. */
    public function view(User $user, Order $order): Response
    {
        $allowed = $user->isStaff()
            || ($user->isCustomer() && $user->company_id !== null && $user->company_id === $order->company_id);

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->isCustomer() && $user->company_id !== null;
    }

    public function cancel(User $user, Order $order): Response
    {
        return $this->view($user, $order);
    }

    public function reorder(User $user, Order $order): Response
    {
        return $user->isCustomer() ? $this->view($user, $order) : Response::deny();
    }

    public function transition(User $user, Order $order): bool
    {
        return $user->isStaff();
    }
}
