<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued so SMTP latency never shows in the forgot-password response time. */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
