<?php

namespace Tests\Feature;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use App\Models\Meeting;
use App\Models\User;
use App\Services\LiveKitGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_is_required_on_cookie_authenticated_mutations(): void
    {
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/auth/firebase', ['id_token' => 'forged'])->assertStatus(419);
        $this->postJson('/api/v1/meetings', ['title' => 'CSRF attempt'])->assertStatus(419);
        $this->postJson('/api/v1/meetings/'.bin2hex(random_bytes(16)).'/admission-request', ['display_name' => 'Guest'])->assertStatus(419);
    }

    public function test_cors_does_not_allow_untrusted_origins(): void
    {
        $this->withHeaders(['Origin' => 'https://untrusted.example.test'])->getJson('/api/v1/meetings/invalid')->assertHeader('Access-Control-Allow-Origin', config('meeting.frontend_url'));
    }

    public function test_real_sdk_issues_only_minimal_short_lived_grants(): void
    {
        if (! class_exists(AccessToken::class)) {
            $this->markTestSkipped('LiveKit SDK is not installed in this environment.');
        }
        config(['meeting.livekit_key' => bin2hex(random_bytes(12)), 'meeting.livekit_secret' => bin2hex(random_bytes(32))]);
        $host = User::factory()->create();
        $meeting = Meeting::create(['uuid' => (string) Str::uuid(), 'meeting_code' => bin2hex(random_bytes(16)), 'host_id' => $host->id, 'title' => 'Test', 'expires_at' => now()->addHour()]);
        $token = (new LiveKitGateway)->token($meeting, 'guest:test', 'Guest');
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $this->assertSame('guest:test', $claims['sub']);
        $this->assertSame($meeting->uuid, $claims['video']['room']);
        foreach (['roomAdmin', 'roomCreate', 'roomList', 'roomRecord', 'canUpdateOwnMetadata'] as $grant) {
            $this->assertFalse($claims['video'][$grant] ?? false);
        }
        $this->assertTrue($claims['video']['roomJoin']);
        $this->assertTrue($claims['video']['canPublishData']);
        $this->assertLessThanOrEqual(60, $claims['exp'] - $claims['iat']);
        $parts = explode('.', $token);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], config('meeting.livekit_secret'), true)), '+/', '-_'), '=');
        $this->assertSame($signature, $parts[2]);
    }

    public function test_real_webhook_sdk_rejects_tampering_and_bad_signatures(): void
    {
        if (! class_exists(AccessToken::class)) {
            $this->markTestSkipped('LiveKit SDK is not installed in this environment.');
        }
        if (! extension_loaded('bcmath')) {
            $this->markTestSkipped('PHP BCMath is required to decode LiveKit webhook protobuf messages.');
        }
        config(['meeting.livekit_key' => bin2hex(random_bytes(12)), 'meeting.livekit_secret' => bin2hex(random_bytes(32))]);
        $body = json_encode(['id' => bin2hex(random_bytes(12)), 'event' => 'room_started', 'createdAt' => (string) time(), 'room' => ['name' => (string) Str::uuid()]]);
        $token = (new AccessToken(config('meeting.livekit_key'), config('meeting.livekit_secret'), (new AccessTokenOptions)->setTtl(60)))->setGrant(new VideoGrant(['roomJoin' => false]))->setSha256(base64_encode(hash('sha256', $body, true)))->toJwt();
        $this->assertSame('room_started', app(LiveKitGateway::class)->webhook($body, $token)['event']);
        $this->call('POST', '/api/v1/livekit/webhook', [], [], [], ['CONTENT_TYPE' => 'application/webhook+json', 'HTTP_AUTHORIZATION' => $token], $body)->assertNoContent();
        $this->call('POST', '/api/v1/livekit/webhook', [], [], [], ['CONTENT_TYPE' => 'application/webhook+json', 'HTTP_AUTHORIZATION' => $token], $body.' ')->assertUnauthorized();
    }
}
