<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset link pointing at the Next.js app.
 *
 * Deliberately not queued: the reset token must never be stored in the jobs table.
 */
class ResetPasswordNotification extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('security.frontend_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $expires = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your password')
            ->line('We received a request to reset the password for your account.')
            ->action('Reset password', $url)
            ->line("This link expires in {$expires} minutes.")
            ->line('If you did not request a password reset, you can ignore this email.');
    }
}
