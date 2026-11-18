<?php

namespace App\Jobs;

use App\Models\MeetingBooking;
use App\Notifications\MeetingReminder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendMeetingReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(public int $bookingId) {}

    public function uniqueId(): string
    {
        return 'meeting-reminder:'.$this->bookingId;
    }

    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $booking = MeetingBooking::with(['meeting', 'user'])->whereKey($this->bookingId)->lockForUpdate()->first();
            if (! $booking || ! $booking->isUpcoming() || $booking->reminder_due_at->isFuture()) {
                return;
            }
            if (! $booking->reminder_notified_at) {
                $booking->user->notifications()->firstOrCreate(['id' => $booking->reminder_uuid], [
                    'type' => MeetingReminder::class, 'data' => (new MeetingReminder($booking))->data(), 'read_at' => null,
                ]);
                $booking->update(['reminder_notified_at' => now()]);
            }
        });
        $booking = MeetingBooking::with(['meeting', 'user'])->find($this->bookingId);
        if (! $booking || ! $booking->isUpcoming() || $booking->reminder_due_at->isFuture() || $booking->reminder_emailed_at || ! MeetingReminder::emailAvailable()) {
            return;
        }
        // SMTP runs outside the SQLite write transaction; failures remain retryable.
        $booking->user->notifyNow(new MeetingReminder($booking), ['mail']);
        $booking->update(['reminder_emailed_at' => now()]);
    }
}
