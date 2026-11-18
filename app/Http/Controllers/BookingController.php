<?php

namespace App\Http\Controllers;

use App\Jobs\SendMeetingReminder;
use App\Models\Meeting;
use App\Services\MeetingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request, MeetingAccess $access): JsonResponse
    {
        $meetings = Meeting::whereHas('bookings', fn ($q) => $q->where('user_id', $request->user()->id)->whereNull('cancelled_at'))
            ->whereNotNull('scheduled_at')->where('status', '!=', 'ended')->where('expires_at', '>', now())->with('host')->orderBy('scheduled_at')->limit(100)->get();

        return response()->json(['meetings' => $meetings->map(fn ($m) => $access->metadata($request, $m))]);
    }

    public function store(Request $request, Meeting $meeting, MeetingAccess $access): JsonResponse
    {
        $data = $request->validate(['timezone' => ['sometimes', 'required', 'timezone']]);
        $booking = DB::transaction(function () use ($request, $meeting, $data) {
            $meeting = Meeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            abort_unless($meeting->scheduled_at && $meeting->scheduled_at->isFuture() && $meeting->status !== 'ended', 410, 'This meeting is no longer open for booking.');
            $booking = $meeting->bookings()->where('user_id', $request->user()->id)->first();
            if (! $booking || $booking->cancelled_at) {
                abort_if($meeting->bookings()->whereNull('cancelled_at')->count() >= $meeting->max_participants, 409, 'All available places are booked.');
            }

            return $meeting->bookings()->updateOrCreate(['user_id' => $request->user()->id], ['timezone' => $data['timezone'] ?? $meeting->timezone, 'cancelled_at' => null, 'reminder_due_at' => $meeting->scheduled_at->copy()->subHour()]);
        });
        if ($booking->reminder_due_at->isPast()) {
            SendMeetingReminder::dispatch($booking->id);
        }

        return response()->json(['meeting' => $access->metadata($request, $meeting->fresh()), 'message' => 'Your place is booked. A reminder is scheduled one hour before the meeting.']);
    }

    public function cancel(Request $request, Meeting $meeting, MeetingAccess $access): JsonResponse
    {
        abort_if($meeting->host_id === $request->user()->id, 422, 'The host booking is automatic. Cancel the meeting to cancel all reminders.');
        DB::transaction(function () use ($request, $meeting) {
            $meeting = Meeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $meeting->bookings()->where('user_id', $request->user()->id)->whereNull('cancelled_at')->firstOrFail()->update(['cancelled_at' => now()]);
        });

        return response()->json(['meeting' => $access->metadata($request, $meeting->fresh()), 'message' => 'Booking cancelled.']);
    }
}
