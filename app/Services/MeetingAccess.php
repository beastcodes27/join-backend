<?php

namespace App\Services;

use App\Models\Meeting;
use App\Notifications\MeetingReminder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MeetingAccess
{
    public function identity(Request $request): string
    {
        if ($request->user()) {
            return 'user:'.$request->user()->id;
        }
        if (! $request->session()->has('guest_identifier')) {
            $request->session()->put('guest_identifier', (string) Str::uuid());
        }

        return 'guest:'.$request->session()->get('guest_identifier');
    }

    public function assertOpen(Meeting $meeting): void
    {
        abort_if($meeting->status === 'ended' || $meeting->expires_at->isPast(), 410, 'This meeting has ended or expired. Ask the host for a new link.');
        abort_if($meeting->scheduled_at?->isFuture(), 425, 'This meeting has not started yet. Book a place and return at the scheduled time.');
    }

    public function isHost(Request $request, Meeting $meeting): bool
    {
        return $request->user()?->id === $meeting->host_id;
    }

    public function metadata(Request $request, Meeting $meeting): array
    {
        return ['uuid' => $meeting->uuid, 'meeting_code' => $meeting->meeting_code, 'title' => $meeting->title, 'status' => $meeting->status, 'max_participants' => $meeting->max_participants, 'locked' => $meeting->locked, 'is_host' => $this->isHost($request, $meeting), 'host' => ['name' => $meeting->host->name], 'share_url' => rtrim(config('meeting.frontend_url'), '/').'/m/'.$meeting->meeting_code, 'expires_at' => $meeting->expires_at, 'created_at' => $meeting->created_at, 'scheduled_at' => $meeting->scheduled_at, 'timezone' => $meeting->timezone, 'duration_minutes' => $meeting->duration_minutes, 'booking_count' => $meeting->bookings()->whereNull('cancelled_at')->count(), 'is_booked' => $request->user() ? $meeting->bookings()->where('user_id', $request->user()->id)->whereNull('cancelled_at')->exists() : false, 'email_reminders_available' => MeetingReminder::emailAvailable(), 'email_reminder_mode' => MeetingReminder::emailMode(), 'can_join' => $meeting->status !== 'ended' && $meeting->expires_at->isFuture() && ! $meeting->scheduled_at?->isFuture()];
    }
}
