<?php

namespace App\Policies;

use App\Models\QuoteAttachment;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Second layer behind QuoteRequest::visibleTo(). "Who may" only; state rules (is the status right?) live in
 * QuoteStatus::ACTIONS / QuoteWorkflow so a state conflict is a 422, not a 403.
 * No Gate::before and no blanket admin allow: staff and admin are covered explicitly.
 */
class QuotePolicy
{
    /** Foreign quotes are indistinguishable from missing ones: 404, never 403. */
    public function view(User $user, QuoteRequest $quote): Response
    {
        $allowed = $user->isStaff()
            || ($user->isCustomer() && $user->company_id !== null && $user->company_id === $quote->company_id);

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->isCustomer() && $user->company_id !== null;
    }

    public function accept(User $user, QuoteRequest $quote): Response
    {
        // Explicit: a user who also holds a staff role never acts as a customer (no reliance on the action matrix).
        return $user->isCustomer() && ! $user->isStaff() ? $this->view($user, $quote) : Response::deny();
    }

    public function decline(User $user, QuoteRequest $quote): Response
    {
        return $this->accept($user, $quote);
    }

    public function review(User $user, QuoteRequest $quote): bool
    {
        return $user->isStaff();
    }

    public function price(User $user, QuoteRequest $quote): bool
    {
        return $user->isStaff();
    }

    public function send(User $user, QuoteRequest $quote): bool
    {
        return $user->isStaff();
    }

    public function reject(User $user, QuoteRequest $quote): bool
    {
        return $user->isStaff();
    }

    public function reopen(User $user, QuoteRequest $quote): bool
    {
        return $user->isStaff();
    }

    public function note(User $user, QuoteRequest $quote): bool
    {
        return $user->isStaff();
    }

    public function attach(User $user, QuoteRequest $quote): Response
    {
        return $user->isStaff() || $user->isCustomer() ? $this->view($user, $quote) : Response::denyAsNotFound();
    }

    /** A customer cannot see a staff file until the quote has been sent. */
    public function viewAttachment(User $user, QuoteRequest $quote, QuoteAttachment $attachment): Response
    {
        $visible = $this->view($user, $quote)->allowed()
            && ($user->isStaff() || $attachment->uploaded_by_role === 'customer' || $quote->sent_at !== null);

        return $visible ? Response::allow() : Response::denyAsNotFound();
    }

    /** Staff: any file. Customer: only customer-uploaded files they can see. */
    public function deleteAttachment(User $user, QuoteRequest $quote, QuoteAttachment $attachment): Response
    {
        $allowed = $this->viewAttachment($user, $quote, $attachment)->allowed()
            && ($user->isStaff() || $attachment->uploaded_by_role === 'customer');

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }
}
