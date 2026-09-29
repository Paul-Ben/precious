<?php

namespace App\Notifications;

use App\Models\FolioStatement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FolioStatementNotification extends Notification
{
    public function __construct(public readonly FolioStatement $statement) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->statement->snapshot;
        $money = fn (string $v) => Money::format(Money::toMinor($v));
        $date = fn (string $d) => CarbonImmutable::parse($d)->format('D j M Y');

        $mail = (new MailMessage)
            ->subject("Your final bill – {$s['reservation']['number']} ({$this->statement->number})")
            ->greeting('Thank you for staying with us'.($s['guest']['name'] ? ', '.$s['guest']['name'] : '').'!')
            ->line("Stay: {$date($s['reservation']['check_in'])} → {$date($s['reservation']['check_out'])}")
            ->line("Accommodation: {$money($s['accommodation']['total'])}");

        foreach ($s['charges'] as $charge) {
            $mail->line("{$charge['description']}: {$money($charge['total'])}");
        }

        $balance = Money::toMinor($s['balance']);

        return $mail
            ->line("**Total: {$money($s['grand_total'])}** · Paid: {$money($s['paid'])}")
            ->line(match (true) {
                $balance > 0 => "Balance outstanding: **{$money($s['balance'])}**. The hotel will contact you about payment.",
                $balance < 0 => 'You have a credit of '.Money::format(-$balance).'. The hotel will arrange your refund.',
                default => 'Your bill is fully settled.',
            })
            ->line('Statement number: '.$this->statement->number.'. '.$s['hotel']['name'].($s['hotel']['phone'] ? ' · '.$s['hotel']['phone'] : ''));
    }
}
