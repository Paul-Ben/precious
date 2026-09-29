<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffAccountCreatedNotification extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $temporaryPassword) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your staff account has been created')
            ->greeting('Welcome, '.$notifiable->name.'!')
            ->line('A staff account has been created for you on '.config('app.name').'.')
            ->line('Email: **'.$notifiable->email.'**')
            ->line('Temporary password: **'.$this->temporaryPassword.'**')
            ->action('Sign in', config('security.frontend_url').'/staff/login')
            ->line('You will be asked to choose a new password the first time you sign in.');
    }
}
