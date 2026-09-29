<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorCodeNotification extends Notification
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your sign-in code: '.$this->code)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Use this code to finish signing in:')
            ->line('**'.$this->code.'**')
            ->line("The code expires in {$this->ttlMinutes} minutes and can only be used once.")
            ->line('If you did not try to sign in, change your password immediately and contact an administrator.');
    }
}
