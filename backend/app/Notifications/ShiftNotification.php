<?php

namespace App\Notifications;

use App\Models\Property;
use App\Models\Shift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Spec §31 / P27: a shift was assigned, changed or cancelled.
 */
class ShiftNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const ASSIGNED = 'assigned';

    public const CHANGED = 'changed';

    public const CANCELLED = 'cancelled';

    /**
     * @param  string|null  $previous  the old time/place, for "changed" emails
     */
    public function __construct(
        public readonly Shift $shift,
        public readonly string $kind,
        public readonly ?string $previous = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = self::describe($this->shift);

        $mail = (new MailMessage)->greeting('Hello '.$notifiable->name.',');

        $mail = match ($this->kind) {
            self::ASSIGNED => $mail->subject('New shift: '.$when)->line('You have been given a shift:')->line('**'.$when.'**'),
            self::CHANGED => $mail->subject('Shift changed: '.$when)->line('One of your shifts has changed.')
                ->line('Now: **'.$when.'**')->when($this->previous, fn (MailMessage $m) => $m->line('Was: '.$this->previous)),
            // When a shift moves to someone else, $previous is what the first person had.
            default => $mail->subject('Shift cancelled: '.($this->previous ?? $when))->line('You are no longer on this shift:')->line('**'.($this->previous ?? $when).'**')
                ->when($this->shift->cancel_reason, fn (MailMessage $m) => $m->line('Reason: '.$this->shift->cancel_reason)),
        };

        return $mail
            ->action('See my shifts', config('security.frontend_url').'/staff/my-shifts')
            ->line('Questions? Speak to your manager.');
    }

    /** "Mon 6 Oct, 07:00–15:00 · Bar (Front bar)" in hotel time. */
    public static function describe(Shift $shift): string
    {
        $tz = Property::current()->timezone;
        $start = $shift->starts_at->copy()->setTimezone($tz);
        $end = $shift->ends_at->copy()->setTimezone($tz);
        $place = array_filter([$shift->department?->name, $shift->location]);

        return $start->format('D j M, H:i').'–'.$end->format('H:i')
            .($end->toDateString() !== $start->toDateString() ? ' (next day)' : '')
            .($place ? ' · '.implode(' · ', $place) : '');
    }
}
