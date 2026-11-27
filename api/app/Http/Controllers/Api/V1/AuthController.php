<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ClientIp;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_FAILURES = 5;

    private const WINDOW_SECONDS = 60;

    // Per-email limit across all IPs, so rotating IPs cannot brute-force one account.
    private const EMAIL_MAX_FAILURES = 20;

    private const EMAIL_WINDOW_SECONDS = 900;

    public function login(LoginRequest $request): UserResource
    {
        // Sanctum only starts a session for stateful (allowed Origin/Referer) requests; fail cleanly otherwise.
        abort_unless($request->hasSession(), 419, 'CSRF token mismatch.');

        $email = $request->input('email');
        $key = $email.'|'.ClientIp::key($request->ip());
        $emailKey = 'login-email|'.$email;

        // Check BEFORE verifying the password so a correct password cannot bypass the lockout.
        $this->ensureNotLimited($key, self::MAX_FAILURES);
        $this->ensureNotLimited($emailKey, self::EMAIL_MAX_FAILURES);

        $user = User::where('email', $request->input('email'))->first();
        // One hash operation whether or not the user exists, so timing does not reveal accounts.
        // A deactivated or pending user gets the same wrong-credentials answer, after the same hash check, and counts toward the limiters.
        $valid = $user && Hash::check($request->input('password'), $user->password) && $user->canSignIn();
        if (! $user) {
            Hash::make($request->input('password'));
        }

        if (! $valid) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
            RateLimiter::hit($emailKey, self::EMAIL_WINDOW_SECONDS);

            throw ValidationException::withMessages(['email' => [trans('auth.failed')]]);
        }

        // Only the email+IP counter is cleared; the email-only one decays on its own, so a success
        // from another IP cannot reset it.
        RateLimiter::clear($key);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        // Compared by the `active` middleware on every request: bumping users.session_epoch kills this session.
        $request->session()->put('session_epoch', $user->session_epoch);
        // The api rate limiter reads the role from here (no query per request). A role change bumps the epoch, so this can never be stale.
        $request->session()->put('role', $user->primaryRole());

        return new UserResource($user->load('company', 'roles'));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function ensureNotLimited(string $key, int $maxAttempts): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException(
                "Too many attempts. Please try again in {$seconds} seconds.",
                null,
                ['Retry-After' => $seconds],
            );
        }
    }
}
