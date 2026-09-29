<?php

namespace App\Notifications;

use App\Enums\BarOrderStatus;
use App\Models\BarTab;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Spec §26: the customer's bill with items, totals and a secure pay link. */
class BarBillNotification extends Notification
{
    public function __construct(public readonly BarTab $tab, public readonly bool $final) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tab = $this->tab;
        $money = fn (string $v) => Money::format(Money::toMinor($v));
        $balance = $tab->balanceMinor();

        $mail = (new MailMessage)
            ->subject(($this->final ? 'Your final bill' : 'Your bill').' – '.$tab->number.' ('.Money::format(Money::toMinor($tab->total)).')')
            ->greeting($this->final ? 'Thank you for visiting!' : 'Thanks for your order!')
            ->line('Bill '.$tab->number.($tab->table ? ' · '.$tab->table->name : ''));

        foreach ($tab->orders->where('status', '!=', BarOrderStatus::Cancelled) as $order) {
            foreach ($order->items as $item) {
                $mail->line("{$item->quantity} × {$item->name} — {$money($item->line_total)}");
            }
        }

        $mail->line("Subtotal: {$money($tab->subtotal)}");

        if (Money::toMinor($tab->discount) > 0) {
            $mail->line("Discount: −{$money($tab->discount)}");
        }

        $mail->line("Service charge: {$money($tab->service_charge)} · VAT: {$money($tab->vat)}")
            ->line("**Total: {$money($tab->total)}** · Paid: {$money($tab->amount_paid)}");

        if ($balance > 0 && $tab->isOpen()) {
            $mail->line('Outstanding: **'.Money::format($balance).'**')
                ->action('Pay '.Money::format($balance).' securely', $tab->payUrl())
                ->line('Or pay your waiter by card, cash or transfer. We will never ask for your card PIN by email or phone.');
        } else {
            $mail->line($tab->settlement === 'CHARGED_TO_ROOM' ? 'Charged to your room.' : 'Paid in full.');
        }

        return $mail;
    }
}
