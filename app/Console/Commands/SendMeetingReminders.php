<?php

namespace App\Console\Commands;

use App\Jobs\SendMeetingReminder;
use App\Models\MeetingBooking;
use App\Notifications\MeetingReminder;
use Illuminate\Console\Command;

class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:remind';

    protected $description = 'Queue due one-hour reminders for active meeting bookings';

    public function handle(): int
    {
        $email = MeetingReminder::emailAvailable();
        MeetingBooking::whereNull('cancelled_at')->where('reminder_due_at', '<=', now())
            ->whereHas('meeting', fn ($q) => $q->where('status', '!=', 'ended')->where('scheduled_at', '>', now()))
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->where(function ($q) use ($email) {
                $q->whereNull('reminder_notified_at');
                if ($email) {
                    $q->orWhereNull('reminder_emailed_at');
                }
            })
            ->eachById(fn (MeetingBooking $booking) => SendMeetingReminder::dispatch($booking->id));

        return self::SUCCESS;
    }
}
