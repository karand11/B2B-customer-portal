<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs right after authentication on every authenticated route. A user who cannot sign in (deactivated or pending), or whose
 * session was created before the last deactivation, reactivation, role/company change or invitation acceptance, is logged out and gets 401.
 * Costs no query: the user is already loaded and the epoch is a column on it. A session without a stored epoch counts as stale.
 * A request WITHOUT a session cannot have been authenticated by a cookie (there is no token login in this app), so there is no epoch to compare:
 * only the sign-in state applies to it.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (! $user->canSignIn() || ! $this->epochMatches($request, $user->session_epoch))) {
            Auth::guard('web')->logout();
            $request->hasSession() && $request->session()->invalidate();

            throw new AuthenticationException;
        }

        return $next($request);
    }

    private function epochMatches(Request $request, int $epoch): bool
    {
        return ! $request->hasSession() || $request->session()->get('session_epoch') === $epoch;
    }
}
