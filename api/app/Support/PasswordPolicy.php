<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** The password rule of password reset and invitation acceptance: 10 to 255 characters, not in a known breach (when the check is on). */
class PasswordPolicy
{
    public static function rule(): Password
    {
        $rule = Password::min(10)->max(255);

        // Threshold 3: a password seen in at least four breaches is refused (one stray hit does not lock out a long unique phrase).
        return config('portal.password_breach_check') ? $rule->uncompromised(3) : $rule;
    }
}
