<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Second layer behind Invoice::visibleTo(). "Who may" only; state rules (can it be paid or voided now?) live in
 * InvoiceWorkflow so a state conflict is a 422, not a 403. No Gate::before and no blanket admin allow.
 */
class InvoicePolicy
{
    /** Foreign invoices are indistinguishable from missing ones: 404, never 403. */
    public function view(User $user, Invoice $invoice): Response
    {
        $allowed = $user->isStaff()
            || ($user->isCustomer() && $user->company_id !== null && $user->company_id === $invoice->company_id);

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    /** The PDF is authorized exactly like the JSON detail. */
    public function download(User $user, Invoice $invoice): Response
    {
        return $this->view($user, $invoice);
    }

    public function create(User $user, Order $order): bool
    {
        return $user->isStaff();
    }

    public function markPaid(User $user, Invoice $invoice): bool
    {
        return $user->isStaff();
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->isStaff();
    }
}
