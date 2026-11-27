<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued so SMTP latency never shows in the forgot-password response time. The job carries the plaintext token, so the payload
 * is encrypted (ShouldBeEncrypted, honoured by SendQueuedNotifications): the token is not readable in the `jobs` table.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** Deactivated and pending users receive nothing (the forgot-password answer stays the same constant 200). Checked again when the queued job runs. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User ? $notifiable->canSignIn() : true;
    }
}
