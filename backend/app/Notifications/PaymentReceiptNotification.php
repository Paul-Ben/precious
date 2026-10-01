<?php

namespace App\Notifications;

use App\Models\Receipt;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Queued: sent by the queue worker so a slow mail provider never delays the desk. */
class PaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Receipt $receipt) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->receipt->snapshot;
        $money = fn (string $v) => Money::format(Money::toMinor($v));
        $date = fn (string $d) => CarbonImmutable::parse($d)->format('D j M Y');

        $isBar = ($s['for']['type'] ?? 'reservation') === 'bar_tab';

        $mail = (new MailMessage)
            ->subject("Payment received – {$s['for']['number']} ({$this->receipt->number})")
            ->greeting('Thank you'.($s['received_from'] ? ', '.$s['received_from'] : '').'!')
            ->line($isBar
                ? "We have received your payment of **{$money($s['amount'])}** for bar bill **{$s['for']['number']}**".(($s['for']['table'] ?? null) ? " ({$s['for']['table']})" : '').'.'
                : "We have received your payment of **{$money($s['amount'])}** for reservation **{$s['for']['number']}**.");

        if (! $isBar) {
            $mail->line("Stay: {$date($s['for']['check_in'])} → {$date($s['for']['check_out'])} ({$s['for']['nights']} night".($s['for']['nights'] === 1 ? '' : 's').').');
        }

        $mail->line('Receipt number: **'.$this->receipt->number.'**')
            ->line('Payment method: '.$s['payment']['method_label'].($s['payment']['channel'] ? ' ('.str_replace('_', ' ', $s['payment']['channel']).')' : ''));

        if (Money::toMinor($s['processing_fee']) > 0) {
            $mail->line("Payment processing fee: {$money($s['processing_fee'])} · Total charged: {$money($s['total_charged'])}");
        }

        $balance = Money::toMinor($s['balance_after']);

        return $mail
            ->line($balance > 0
                ? "Balance remaining: **{$money($s['balance_after'])}**".($isBar ? '.' : ', payable before or at check-out.')
                : ($isBar ? 'Your bill is fully paid.' : 'Your reservation is fully paid.'))
            ->line('Please keep this email as your receipt. '.$s['hotel']['name'].($s['hotel']['phone'] ? ' · '.$s['hotel']['phone'] : ''));
    }
}
