<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TemporaryPasswordIssuedNotification extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $temporaryPassword) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your password has been reset')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('An administrator has issued you a new temporary password.')
            ->line('Temporary password: **'.$this->temporaryPassword.'**')
            ->action('Sign in', config('security.frontend_url').'/staff/login')
            ->line('You will be asked to choose a new password when you sign in. All your other sessions have been signed out.');
    }
}
