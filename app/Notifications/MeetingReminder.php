<?php

namespace App\Notifications;

use App\Models\MeetingBooking;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingReminder extends Notification
{
    public function __construct(public MeetingBooking $booking) {}

    public static function emailAvailable(): bool
    {
        if (config('mail.default') === 'smtp' && (! filled(config('mail.mailers.smtp.host')) || (int) config('mail.mailers.smtp.port') < 1)) {
            return false;
        }

        return config('meeting.email_reminders', true) && filled(config('mail.from.address')) && in_array(config('mail.default'), ['smtp', 'sendmail', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend'], true);
    }

    public static function emailMode(): string
    {
        if (! self::emailAvailable()) {
            return 'unavailable';
        }

        return app()->environment('local') && config('meeting.email_preview') ? 'preview' : 'delivery';
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $meeting = $this->booking->meeting;
        $time = $meeting->scheduled_at->copy()->timezone($this->booking->timezone)->format('l, j F Y \a\t H:i T');

        return (new MailMessage)->subject('Meeting reminder: '.$meeting->title)
            ->greeting('Hello '.$notifiable->name)->line('Your booked meeting is coming up: '.$meeting->title)
            ->line($time)->line('Timezone: '.$this->booking->timezone)
            ->action('Open meeting', $this->data()['url'])->line('The host will approve participants when the meeting opens.');
    }

    public function data(): array
    {
        $meeting = $this->booking->meeting;

        return ['title' => $meeting->title, 'body' => 'Your booked meeting is coming up.', 'meeting_code' => $meeting->meeting_code,
            'scheduled_at' => $meeting->scheduled_at->toIso8601String(), 'timezone' => $this->booking->timezone,
            'url' => rtrim(config('meeting.frontend_url'), '/').'/m/'.$meeting->meeting_code];
    }
}
