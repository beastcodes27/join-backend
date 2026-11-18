<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateMeetingRequest;
use App\Jobs\SendMeetingReminder;
use App\Models\Meeting;
use App\Services\LiveKitGateway;
use App\Services\MeetingAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class MeetingController extends Controller
{
    public function __construct(private MeetingAccess $access, private LiveKitGateway $livekit) {}

    public function index(Request $request): JsonResponse
    {
        $meetings = Meeting::where('host_id', $request->user()->id)->with('host')->latest()->limit(30)->get();

        return response()->json(['meetings' => $meetings->map(fn ($m) => $this->access->metadata($request, $m))]);
    }

    public function store(CreateMeetingRequest $request): JsonResponse
    {
        $meeting = DB::transaction(function () use ($request) {
            $scheduled = $request->validated('scheduled_at') ? Carbon::parse($request->validated('scheduled_at'))->utc() : null;
            $duration = $request->validated('duration_minutes', config('meeting.max_minutes'));
            $meeting = Meeting::create(['uuid' => (string) Str::uuid(), 'host_id' => $request->user()->id, 'meeting_code' => bin2hex(random_bytes(16)),
                'title' => $request->validated('title'), 'max_participants' => $request->validated('max_participants', config('meeting.max_participants')),
                'status' => $scheduled ? 'scheduled' : 'created', 'scheduled_at' => $scheduled, 'timezone' => $request->validated('timezone', 'UTC'),
                'duration_minutes' => $duration, 'expires_at' => ($scheduled ? $scheduled->copy() : now())->addMinutes($duration)]);
            if ($scheduled) {
                $booking = $meeting->bookings()->create(['user_id' => $request->user()->id, 'timezone' => $meeting->timezone, 'reminder_due_at' => $scheduled->copy()->subHour()]);
                if ($booking->reminder_due_at->isPast()) {
                    SendMeetingReminder::dispatch($booking->id)->afterCommit();
                }
            }
            $meeting->events()->create(['event_type' => $scheduled ? 'meeting.scheduled' : 'meeting.created', 'payload' => ['actor' => $request->user()->id]]);

            return $meeting;
        });

        return response()->json(['meeting' => $this->access->metadata($request, $meeting)], 201);
    }

    public function show(Request $request, Meeting $meeting): JsonResponse
    {
        return response()->json(['meeting' => $this->access->metadata($request, $meeting)]);
    }

    public function admission(Request $request, Meeting $meeting): JsonResponse
    {
        $data = $request->validate(['display_name' => ['required', 'string', 'max:80']]);

        return DB::transaction(function () use ($request, $meeting, $data) {
            $meeting = Meeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $this->access->assertOpen($meeting);
            abort_if($meeting->locked && ! $this->access->isHost($request, $meeting), 423, 'The host has locked this meeting.');
            $identity = $this->access->identity($request);
            $admission = $meeting->admissions()->firstOrCreate(['participant_identifier' => $identity], ['display_name' => $data['display_name'], 'status' => $this->access->isHost($request, $meeting) ? 'approved' : 'pending']);

            return response()->json(['admission' => $admission->only(['id', 'display_name', 'status'])]);
        });
    }

    public function join(Request $request, Meeting $meeting): JsonResponse
    {
        $data = $request->validate(['display_name' => ['required', 'string', 'max:80']]);

        return DB::transaction(function () use ($request, $meeting, $data) {
            $meeting = Meeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $this->access->assertOpen($meeting);
            $identity = $this->access->identity($request);
            $isHost = $this->access->isHost($request, $meeting);
            abort_if($meeting->locked && ! $isHost, 423, 'The host has locked this meeting.');
            $admission = $meeting->admissions()->where('participant_identifier', $identity)->first();
            abort_unless($isHost || $admission?->status === 'approved', 403, 'Ask the host to admit you before joining.');
            $participant = $meeting->participants()->where('identity', $identity)->first();
            abort_if($participant?->removed_at, 403, 'You were removed from this meeting.');
            $reserved = $meeting->participants()->where('identity', '!=', $identity)->whereNull('removed_at')->where(function ($q) {
                $q->where(fn ($q) => $q->whereNotNull('joined_at')->whereNull('left_at'))->orWhere('lease_until', '>', now());
            })->count();
            if ($meeting->scheduled_at && ! $isHost && ! $meeting->bookings()->where('user_id', $request->user()?->id)->whereNull('cancelled_at')->exists()) {
                $occupiedUsers = $meeting->participants()->whereNull('removed_at')->where(function ($q) {
                    $q->where(fn ($q) => $q->whereNotNull('joined_at')->whereNull('left_at'))->orWhere('lease_until', '>', now());
                })->whereNotNull('user_id')->pluck('user_id');
                $unclaimed = $meeting->bookings()->whereNull('cancelled_at')->whereNotIn('user_id', $occupiedUsers)->count();
                abort_if($reserved + $unclaimed >= $meeting->max_participants, 409, 'The remaining places are reserved for booked participants.');
            }
            abort_if($reserved >= $meeting->max_participants, 409, 'This meeting is full. Try again when someone leaves.');
            try {
                $roomSid = $this->livekit->ensureRoom($meeting);
                $meeting->update(['livekit_sid' => $roomSid]);
                $token = $this->livekit->token($meeting, $identity, $data['display_name']);
            } catch (\Throwable $e) {
                report($e);
                abort(503, 'The video service is unavailable. Please try again shortly.');
            }
            $meeting->participants()->updateOrCreate(['identity' => $identity], ['user_id' => $request->user()?->id, 'guest_identifier' => $request->user() ? null : $request->session()->get('guest_identifier'), 'display_name' => $data['display_name'], 'role' => $isHost ? 'host' : 'participant', 'lease_until' => now()->addSeconds(config('meeting.token_ttl') + 15)]);
            $meeting->events()->create(['event_type' => 'participant.token_issued', 'payload' => ['identity' => $identity]]);

            return response()->json(['token' => $token, 'server_url' => config('meeting.livekit_url'), 'identity' => $identity, 'meeting' => $this->access->metadata($request, $meeting)])->header('Cache-Control', 'no-store');
        });
    }

    public function participants(Request $request, Meeting $meeting): JsonResponse
    {
        Gate::authorize('manage', $meeting);

        return response()->json(['participants' => $meeting->participants()->get(['id', 'identity', 'display_name', 'role', 'joined_at', 'left_at', 'removed_at']), 'admissions' => $meeting->admissions()->where('status', 'pending')->get(['id', 'participant_identifier', 'display_name', 'status'])]);
    }

    public function admit(Request $request, Meeting $meeting): JsonResponse
    {
        return $this->review($request, $meeting, 'approved');
    }

    public function reject(Request $request, Meeting $meeting): JsonResponse
    {
        return $this->review($request, $meeting, 'rejected');
    }

    private function review(Request $request, Meeting $meeting, string $status): JsonResponse
    {
        Gate::authorize('manage', $meeting);
        $data = $request->validate(['admission_id' => ['required', 'integer']]);

        return DB::transaction(function () use ($request, $meeting, $data, $status) {
            $meeting = Meeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $this->access->assertOpen($meeting);
            $admission = $meeting->admissions()->findOrFail($data['admission_id']);
            $admission->update(['status' => $status, 'reviewed_by' => $request->user()->id]);
            $meeting->events()->create(['event_type' => 'admission.'.$status, 'payload' => ['identity' => $admission->participant_identifier, 'actor' => $request->user()->id]]);

            return response()->json(['admission' => $admission]);
        });
    }

    public function remove(Request $request, Meeting $meeting): JsonResponse
    {
        Gate::authorize('manage', $meeting);
        $data = $request->validate(['identity' => ['required', 'string', 'max:100']]);

        return DB::transaction(function () use ($meeting, $data) {
            $meeting = Meeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $participant = $meeting->participants()->where('identity', $data['identity'])->firstOrFail();
            abort_if($participant->role === 'host', 422, 'The host cannot be removed.');
            $this->livekit->remove($meeting->uuid, $participant->identity);
            $participant->update(['removed_at' => now(), 'left_at' => now(), 'lease_until' => null]);
            $meeting->admissions()->where('participant_identifier', $participant->identity)->update(['status' => 'rejected']);
            $meeting->events()->create(['event_type' => 'participant.removed', 'payload' => ['identity' => $participant->identity]]);

            return response()->json(['message' => 'Participant removed.']);
        });
    }

    public function leave(Request $request, Meeting $meeting): JsonResponse
    {
        $identity = $this->access->identity($request);
        $participant = $meeting->participants()->where('identity', $identity)->firstOrFail();
        $this->livekit->remove($meeting->uuid, $identity);
        $participant->update(['left_at' => now(), 'lease_until' => null]);

        return response()->json(['message' => 'You left the meeting.']);
    }

    public function end(Request $request, Meeting $meeting): JsonResponse
    {
        Gate::authorize('manage', $meeting);
        // Persist the tombstone first. A video-service outage must never reopen joins.
        $scheduledOnly = DB::transaction(function () use ($meeting, $request) {
            $meeting = Meeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $neverOpened = $meeting->status === 'scheduled' && ! $meeting->livekit_sid;
            $meeting->update(['status' => 'ended', 'ended_at' => $meeting->ended_at ?? now()]);
            $meeting->events()->create(['event_type' => 'meeting.ended', 'payload' => ['actor' => $request->user()->id]]);

            return $neverOpened;
        });
        try {
            if (! $scheduledOnly) {
                $this->livekit->deleteRoom($meeting->uuid);
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Meeting closed to new joins. Video disconnection is pending; the server will retry.'], 503);
        }
        $meeting->participants()->update(['left_at' => now(), 'lease_until' => null]);

        return response()->json(['message' => 'Meeting ended for everyone.']);
    }

    public function access(Request $request, Meeting $meeting): JsonResponse
    {
        Gate::authorize('manage', $meeting);
        $data = $request->validate(['locked' => ['required', 'boolean']]);
        DB::transaction(function () use ($meeting, $data) {
            $meeting = Meeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $this->access->assertOpen($meeting);
            $meeting->update($data);
            $meeting->events()->create(['event_type' => 'meeting.access_changed', 'payload' => $data]);
        });

        return response()->json(['message' => 'Meeting access updated.']);
    }
}
