<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use Agence104\LiveKit\WebhookReceiver;
use App\Models\Meeting;

class LiveKitGateway
{
    private function client(): RoomServiceClient
    {
        return new RoomServiceClient(config('meeting.livekit_http_url'), config('meeting.livekit_key'), config('meeting.livekit_secret'));
    }

    public function ensureRoom(Meeting $meeting): string
    {
        return $this->client()->createRoom(new RoomCreateOptions(['name' => $meeting->uuid, 'maxParticipants' => $meeting->max_participants, 'emptyTimeout' => 300, 'departureTimeout' => 60]))->getSid();
    }

    public function token(Meeting $meeting, string $identity, string $name): string
    {
        $options = (new AccessTokenOptions)->setIdentity($identity)->setName($name)->setTtl(config('meeting.token_ttl'));
        $grant = new VideoGrant(['roomJoin' => true, 'room' => $meeting->uuid, 'roomAdmin' => false, 'roomCreate' => false, 'roomList' => false, 'roomRecord' => false, 'canPublish' => true, 'canSubscribe' => true, 'canPublishData' => true, 'canUpdateOwnMetadata' => false, 'canPublishSources' => ['camera', 'microphone', 'screen_share', 'screen_share_audio']]);

        return (new AccessToken(config('meeting.livekit_key'), config('meeting.livekit_secret'), $options))->setGrant($grant)->toJwt();
    }

    public function deleteRoom(string $room): void
    {
        try {
            $this->client()->deleteRoom($room);
        } catch (\Throwable $e) {
            // A missing room is already disconnected; every other transport/API error must be retried.
            if (! str_contains($e->getMessage(), 'not_found') && ! str_contains($e->getMessage(), 'does not exist')) {
                throw $e;
            }
        }
    }

    public function remove(string $room, string $identity): void
    {
        try {
            $this->client()->removeParticipant($room, $identity);
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'not_found') && ! str_contains($e->getMessage(), 'does not exist')) {
                throw $e;
            }
        }
    }

    public function webhook(string $body, ?string $authorization): array
    {
        $event = (new WebhookReceiver(config('meeting.livekit_key'), config('meeting.livekit_secret')))->receive($body, $authorization);

        return json_decode($event->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
    }
}
