<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingEvent;
use App\Services\LiveKitGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function __invoke(Request $request, LiveKitGateway $livekit): Response
    {
        try {
            $event = $livekit->webhook($request->getContent(), $request->header('Authorization'));
        } catch (\Throwable $e) {
            abort(401, 'Invalid LiveKit webhook signature.');
        }
        abort_unless(isset($event['id'],$event['event'],$event['createdAt']), 422, 'Webhook event metadata is required.');
        // LiveKit retries are accepted for 24 hours; the unique event ID prevents replays.
        abort_if(abs(time() - (int) $event['createdAt']) > 86400, 422, 'Webhook is outside the processing window.');
        DB::transaction(function () use ($event, $livekit) {
            $meeting = Meeting::where('uuid', $event['room']['name'] ?? '')->lockForUpdate()->first();
            if (! $meeting || MeetingEvent::where('event_id', $event['id'])->exists()) {
                return;
            }
            $identity = $event['participant']['identity'] ?? null;
            $participant = $identity ? $meeting->participants()->where('identity', $identity)->first() : null;
            $ended = $meeting->status === 'ended' || $meeting->expires_at->isPast();
            if ($ended && in_array($event['event'], ['room_started', 'participant_joined'])) {
                $livekit->deleteRoom($meeting->uuid);
            } elseif (isset($event['room']['sid']) && $meeting->livekit_sid && $event['room']['sid'] !== $meeting->livekit_sid) {
                // Delayed events from an older incarnation must not mutate the current room.
            } elseif ($event['event'] === 'participant_joined') {
                if (! $participant || $participant->removed_at || $participant->lease_until?->isPast()) {
                    $livekit->remove($meeting->uuid, $identity);
                } else {
                    $participant->update(['joined_at' => now(), 'left_at' => null, 'connection_sid' => $event['participant']['sid'] ?? null]);
                    $meeting->update(['status' => 'active', 'started_at' => $meeting->started_at ?? now()]);
                }
            } elseif ($event['event'] === 'participant_left' && $participant && $participant->connection_sid === ($event['participant']['sid'] ?? null)) {
                $participant->update(['left_at' => now(), 'lease_until' => null]);
            } elseif ($event['event'] === 'room_finished') {
                // Ignore a delayed finish from an old room incarnation when newer participants are connected.
                $hasNewer = $meeting->participants()->whereNull('left_at')->where('joined_at', '>', date('Y-m-d H:i:s', (int) $event['createdAt']))->exists();
                if (! $hasNewer) {
                    $meeting->update(['status' => 'ended', 'ended_at' => $meeting->ended_at ?? now()]);
                    $meeting->participants()->update(['left_at' => now(), 'lease_until' => null]);
                }
            }
            $meeting->events()->create(['event_id' => $event['id'], 'event_type' => $event['event'], 'payload' => ['identity' => $identity, 'occurred_at' => $event['createdAt']]]);
        });

        return response()->noContent();
    }
}
