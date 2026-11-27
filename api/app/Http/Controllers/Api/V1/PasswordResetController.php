<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Services\SingleUseToken;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(['email' => $request->input('email')]);

        // The known-user path hashes the token; spend the same time otherwise so timing reveals nothing.
        if ($status !== Password::RESET_LINK_SENT) {
            Hash::make(Str::random(40));
        }

        return response()->json(['message' => 'If an account exists for that email, a reset link has been sent.']);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request) {
                // Under the row lock, token consumed in the same transaction (SingleUseToken): one request wins, the rest get the generic 422.
                $invalid = ValidationException::withMessages(['email' => ['This password reset link is invalid or has expired.']]);

                SingleUseToken::consume(Password::broker(), $user, $request->input('token'), function (User $locked) use ($password, $invalid) {
                    // A deactivated or pending user cannot complete a reset (their tokens are deleted on deactivation; this is the backstop).
                    $locked->canSignIn() || throw $invalid;
                    // The epoch moves with the password: every session signed in before the reset dies on its next request.
                    $locked->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'session_epoch' => $locked->session_epoch + 1])->save();
                    event(new PasswordReset($locked));
                }) || throw $invalid;
            },
        );

        // A known user with a pending token costs one hash check inside the broker; spend the same for unknown users.
        if ($status === Password::INVALID_USER) {
            Hash::make(Str::random(40));
        }

        if ($status !== Password::PASSWORD_RESET) {
            // Same body for bad token, expired token, unknown email.
            $message = 'This password reset link is invalid or has expired.';

            throw ValidationException::withMessages(['email' => [$message]]);
        }

        return response()->json(['message' => 'Your password has been reset.']);
    }
}
