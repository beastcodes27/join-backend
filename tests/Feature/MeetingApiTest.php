<?php

namespace Tests\Feature;

use App\Contracts\IdentityVerifier;
use App\Models\Meeting;
use App\Models\User;
use App\Services\LiveKitGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class MeetingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = User::factory()->create();
        $gateway = Mockery::mock(LiveKitGateway::class);
        $gateway->shouldReceive('ensureRoom')->andReturn(bin2hex(random_bytes(12)))->byDefault();
        $gateway->shouldReceive('token')->andReturn(bin2hex(random_bytes(32)))->byDefault();
        $gateway->shouldReceive('deleteRoom', 'remove')->andReturnNull()->byDefault();
        $this->app->instance(LiveKitGateway::class, $gateway);
    }

    private function host(): self
    {
        $this->actingAs($this->host)->withSession(['authenticated_at' => time()]);

        return $this;
    }

    private function meeting(array $attributes = []): Meeting
    {
        return Meeting::create(array_merge(['uuid' => (string) Str::uuid(), 'meeting_code' => bin2hex(random_bytes(16)), 'host_id' => $this->host->id, 'title' => 'Team meeting', 'max_participants' => 3, 'expires_at' => now()->addHour()], $attributes));
    }

    private function path(Meeting $m, string $action = ''): string
    {
        return '/api/v1/meetings/'.$m->meeting_code.($action ? '/'.$action : '');
    }

    public function test_guest_cannot_create_a_meeting(): void
    {
        $this->postJson('/api/v1/meetings', ['title' => 'Meeting'])->assertUnauthorized();
    }

    public function test_host_can_create_and_list_secure_meeting_links(): void
    {
        $response = $this->host()->postJson('/api/v1/meetings', ['title' => 'Team sync'])->assertCreated()->assertJsonPath('meeting.is_host', true);
        $code = $response->json('meeting.meeting_code');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $code);
        $this->assertDatabaseHas('meetings', ['meeting_code' => $code, 'host_id' => $this->host->id]);
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonCount(1, 'meetings');
    }

    public function test_meeting_creation_validates_title_and_capacity(): void
    {
        $this->host()->postJson('/api/v1/meetings', ['title' => '', 'max_participants' => 1000])->assertUnprocessable()->assertJsonValidationErrors(['title', 'max_participants']);
    }

    public function test_invalid_meeting_returns_not_found(): void
    {
        $this->getJson('/api/v1/meetings/invalid')->assertNotFound();
    }

    public function test_guest_must_wait_for_admission(): void
    {
        $m = $this->meeting();
        $this->postJson($this->path($m, 'join'), ['display_name' => 'Guest'])->assertForbidden();
        $this->postJson($this->path($m, 'admission-request'), ['display_name' => 'Guest'])->assertOk()->assertJsonPath('admission.status', 'pending');
        $this->postJson($this->path($m, 'join'), ['display_name' => 'Guest'])->assertForbidden();
    }

    public function test_approved_guest_gets_participant_token_and_server_session_identity(): void
    {
        $m = $this->meeting();
        $guest = (string) Str::uuid();
        $m->admissions()->create(['participant_identifier' => 'guest:'.$guest, 'display_name' => 'Guest', 'status' => 'approved']);
        $this->withSession(['guest_identifier' => $guest])->postJson($this->path($m, 'join'), ['display_name' => 'Guest', 'role' => 'host', 'user_id' => $this->host->id])->assertOk()->assertJsonPath('identity', 'guest:'.$guest)->assertJsonPath('meeting.is_host', false);
        $this->assertDatabaseHas('meeting_participants', ['meeting_id' => $m->id, 'role' => 'participant', 'user_id' => null]);
    }

    public function test_guest_cannot_steal_another_admission(): void
    {
        $m = $this->meeting();
        $m->admissions()->create(['participant_identifier' => 'guest:'.Str::uuid(), 'display_name' => 'Another guest', 'status' => 'approved']);
        $this->postJson($this->path($m, 'join'), ['display_name' => 'Guest'])->assertForbidden();
    }

    public function test_even_authenticated_non_hosts_require_admission(): void
    {
        $m = $this->meeting();
        $this->actingAs(User::factory()->create())->withSession(['authenticated_at' => time()])->postJson($this->path($m, 'join'), ['display_name' => 'User'])->assertForbidden();
    }

    public function test_non_host_cannot_manage_the_meeting(): void
    {
        $m = $this->meeting();
        $this->actingAs(User::factory()->create())->withSession(['authenticated_at' => time()]);
        foreach (['admit', 'reject', 'remove', 'end', 'access'] as $action) {
            $this->postJson($this->path($m, $action), [])->assertForbidden();
        }
        $this->getJson($this->path($m, 'participants'))->assertForbidden();
    }

    public function test_host_can_admit_or_reject_and_admission_is_scoped_to_meeting(): void
    {
        $m = $this->meeting();
        $a = $m->admissions()->create(['participant_identifier' => 'guest:'.Str::uuid(), 'display_name' => 'Guest']);
        $this->host()->postJson($this->path($m, 'admit'), ['admission_id' => $a->id])->assertOk()->assertJsonPath('admission.status', 'approved');
        $this->postJson($this->path($m, 'reject'), ['admission_id' => $a->id])->assertOk()->assertJsonPath('admission.status', 'rejected');
        $this->postJson($this->path($this->meeting(), 'admit'), ['admission_id' => $a->id])->assertNotFound();
    }

    public function test_rejected_admission_cannot_be_reset_by_polling(): void
    {
        $m = $this->meeting();
        $id = (string) Str::uuid();
        $m->admissions()->create(['participant_identifier' => 'guest:'.$id, 'display_name' => 'Guest', 'status' => 'rejected']);
        $this->withSession(['guest_identifier' => $id])->postJson($this->path($m, 'admission-request'), ['display_name' => 'Changed name'])->assertJsonPath('admission.status', 'rejected');
    }

    public function test_host_can_join_without_admission_and_tokens_are_not_cached(): void
    {
        $m = $this->meeting();
        $this->host()->postJson($this->path($m, 'join'), ['display_name' => 'Host'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('meeting_participants', ['meeting_id' => $m->id, 'role' => 'host']);
        $this->assertDatabaseHas('meetings', ['id' => $m->id, 'status' => 'created']);
    }

    public function test_ended_and_expired_meetings_reject_join_and_admission(): void
    {
        foreach ([['status' => 'ended'], ['expires_at' => now()->subMinute()]] as $state) {
            $m = $this->meeting($state);
            foreach (['join', 'admission-request'] as $action) {
                $this->host()->postJson($this->path($m, $action), ['display_name' => 'Host'])->assertGone();
            }
        }
    }

    public function test_capacity_includes_inflight_token_reservations(): void
    {
        $m = $this->meeting(['max_participants' => 2]);
        foreach (range(1, 2) as $i) {
            $m->participants()->create(['identity' => 'guest:'.$i, 'display_name' => 'Reserved', 'lease_until' => now()->addMinute()]);
        }
        $this->host()->postJson($this->path($m, 'join'), ['display_name' => 'Host'])->assertConflict();
    }

    public function test_expired_reservations_do_not_consume_capacity(): void
    {
        $m = $this->meeting(['max_participants' => 2]);
        foreach (range(1, 2) as $i) {
            $m->participants()->create(['identity' => 'guest:'.$i, 'display_name' => 'Reserved', 'lease_until' => now()->subMinute()]);
        }
        $this->host()->postJson($this->path($m, 'join'), ['display_name' => 'Host'])->assertOk();
    }

    public function test_host_can_lock_access(): void
    {
        $m = $this->meeting();
        $this->host()->postJson($this->path($m, 'access'), ['locked' => true])->assertOk();
        $this->assertTrue($m->fresh()->locked);
        Auth::guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->path($m, 'admission-request'), ['display_name' => 'Guest'])->assertStatus(423);
    }

    public function test_end_is_durable_and_blocks_future_tokens(): void
    {
        $m = $this->meeting();
        $this->host()->postJson($this->path($m, 'end'))->assertOk();
        $this->postJson($this->path($m, 'join'), ['display_name' => 'Host'])->assertGone();
    }

    public function test_end_remains_closed_when_livekit_is_unavailable(): void
    {
        $m = $this->meeting();
        $this->app->instance(LiveKitGateway::class, Mockery::mock(LiveKitGateway::class)->shouldReceive('deleteRoom')->andThrow(new \RuntimeException('Unavailable'))->getMock());
        $this->host()->postJson($this->path($m, 'end'))->assertStatus(503);
        $this->assertSame('ended', $m->fresh()->status);
    }

    public function test_video_service_outage_does_not_reserve_capacity(): void
    {
        $m = $this->meeting();
        $this->app->instance(LiveKitGateway::class, Mockery::mock(LiveKitGateway::class)->shouldReceive('ensureRoom')->andThrow(new \RuntimeException('Unavailable'))->getMock());
        $this->host()->postJson($this->path($m, 'join'), ['display_name' => 'Host'])->assertStatus(503);
        $this->assertDatabaseCount('meeting_participants', 0);
    }

    public function test_removed_guest_cannot_get_another_token(): void
    {
        $m = $this->meeting();
        $id = (string) Str::uuid();
        $m->participants()->create(['identity' => 'guest:'.$id, 'display_name' => 'Guest']);
        $m->admissions()->create(['participant_identifier' => 'guest:'.$id, 'display_name' => 'Guest', 'status' => 'approved']);
        $this->host()->postJson($this->path($m, 'remove'), ['identity' => 'guest:'.$id])->assertOk();
        Auth::guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->withSession(['guest_identifier' => $id])->postJson($this->path($m, 'join'), ['display_name' => 'Guest'])->assertForbidden();
    }

    public function test_only_host_meetings_appear_in_dashboard(): void
    {
        $this->meeting();
        $other = User::factory()->create();
        $this->meeting(['host_id' => $other->id]);
        $this->host()->getJson('/api/v1/meetings')->assertJsonCount(1, 'meetings');
    }

    public function test_join_requests_are_rate_limited(): void
    {
        $m = $this->meeting();
        $this->withSession(['guest_identifier' => (string) Str::uuid()]);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        for ($i = 0; $i < 30; $i++) {
            $this->postJson($this->path($m, 'join'), ['display_name' => 'Guest'])->assertForbidden();
        }
        $this->postJson($this->path($m, 'join'), ['display_name' => 'Guest'])->assertStatus(429);
    }

    public function test_verified_identity_creates_user_and_synchronizes_profile(): void
    {
        $profile = ['firebase_uid' => bin2hex(random_bytes(16)), 'email' => 'verified@example.test', 'name' => 'Verified user', 'avatar' => null];
        $this->app->instance(IdentityVerifier::class, Mockery::mock(IdentityVerifier::class)->shouldReceive('verify')->with('opaque-test-token')->twice()->andReturn($profile, array_merge($profile, ['name' => 'Updated name']))->getMock());
        $this->postJson('/api/v1/auth/firebase', ['id_token' => 'opaque-test-token', 'email' => 'attacker@example.test'])->assertOk()->assertJsonPath('user.email', $profile['email']);
        $this->postJson('/api/v1/auth/firebase', ['id_token' => 'opaque-test-token'])->assertOk()->assertJsonPath('user.name', 'Updated name');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_invalid_identity_never_establishes_session(): void
    {
        $this->app->instance(IdentityVerifier::class, Mockery::mock(IdentityVerifier::class)->shouldReceive('verify')->andThrow(ValidationException::withMessages(['id_token' => 'Invalid token']))->getMock());
        $this->postJson('/api/v1/auth/firebase', ['id_token' => 'forged'])->assertUnprocessable();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_inactive_account_cannot_sign_in(): void
    {
        $this->host->update(['firebase_uid' => bin2hex(random_bytes(16)), 'status' => 'suspended']);
        $profile = $this->host->only(['firebase_uid', 'email', 'name', 'avatar']);
        $this->app->instance(IdentityVerifier::class, Mockery::mock(IdentityVerifier::class)->shouldReceive('verify')->andReturn($profile)->getMock());
        $this->postJson('/api/v1/auth/firebase', ['id_token' => 'opaque-test-token'])->assertForbidden();
    }

    public function test_session_has_absolute_expiration_and_logout(): void
    {
        $this->actingAs($this->host)->withSession(['authenticated_at' => time() - 7201])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->host()->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_signed_events_drive_lifecycle_idempotently(): void
    {
        $m = $this->meeting();
        $m->participants()->create(['identity' => 'user:'.$this->host->id, 'display_name' => 'Host', 'lease_until' => now()->addMinute()]);
        $event = ['id' => bin2hex(random_bytes(16)), 'event' => 'participant_joined', 'createdAt' => time(), 'room' => ['name' => $m->uuid], 'participant' => ['identity' => 'user:'.$this->host->id, 'sid' => 'connection-one']];
        $this->app->instance(LiveKitGateway::class, Mockery::mock(LiveKitGateway::class)->shouldReceive('webhook')->andReturn($event)->getMock());
        $this->postJson('/api/v1/livekit/webhook', [])->assertNoContent();
        $this->postJson('/api/v1/livekit/webhook', [])->assertNoContent();
        $this->assertSame('active', $m->fresh()->status);
        $this->assertDatabaseCount('meeting_events', 1);
    }

    public function test_delayed_finish_from_an_old_room_does_not_close_current_room(): void
    {
        $m = $this->meeting(['status' => 'active', 'livekit_sid' => 'current-room']);
        $event = ['id' => bin2hex(random_bytes(16)), 'event' => 'room_finished', 'createdAt' => time(), 'room' => ['name' => $m->uuid, 'sid' => 'old-room']];
        $this->app->instance(LiveKitGateway::class, Mockery::mock(LiveKitGateway::class)->shouldReceive('webhook')->andReturn($event)->getMock());
        $this->postJson('/api/v1/livekit/webhook', [])->assertNoContent();
        $this->assertSame('active', $m->fresh()->status);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $this->app->instance(LiveKitGateway::class, Mockery::mock(LiveKitGateway::class)->shouldReceive('webhook')->andThrow(new \RuntimeException('Invalid signature'))->getMock());
        $this->postJson('/api/v1/livekit/webhook', [])->assertUnauthorized();
    }

    public function test_authorized_participant_can_resume_after_initial_token_lease_expires(): void
    {
        foreach (['host', 'participant'] as $role) {
            $m = $this->meeting();
            $identity = $role === 'host' ? 'user:'.$this->host->id : 'guest:'.Str::uuid();
            $participant = $m->participants()->create(['identity' => $identity, 'user_id' => $role === 'host' ? $this->host->id : null, 'role' => $role, 'display_name' => 'Returning participant', 'joined_at' => now()->subMinutes(5), 'lease_until' => now()->subMinutes(4)]);
            if ($role !== 'host') {
                $m->admissions()->create(['participant_identifier' => $identity, 'display_name' => 'Guest', 'status' => 'approved']);
            }
            $event = ['id' => bin2hex(random_bytes(16)), 'event' => 'participant_joined', 'createdAt' => time(), 'room' => ['name' => $m->uuid], 'participant' => ['identity' => $identity, 'sid' => 'resumed-connection']];
            $gateway = Mockery::mock(LiveKitGateway::class);
            $gateway->shouldReceive('webhook')->andReturn($event);
            $gateway->shouldNotReceive('remove');
            $this->app->instance(LiveKitGateway::class, $gateway);
            $this->postJson('/api/v1/livekit/webhook', [])->assertNoContent();
            $this->assertSame('resumed-connection', $participant->fresh()->connection_sid);
        }
    }

    public function test_expired_lease_without_prior_media_join_cannot_resume(): void
    {
        $m = $this->meeting();
        $identity = 'guest:'.Str::uuid();
        $m->participants()->create(['identity' => $identity, 'display_name' => 'Guest', 'lease_until' => now()->subMinute()]);
        $m->admissions()->create(['participant_identifier' => $identity, 'display_name' => 'Guest', 'status' => 'approved']);
        $event = ['id' => bin2hex(random_bytes(16)), 'event' => 'participant_joined', 'createdAt' => time(), 'room' => ['name' => $m->uuid], 'participant' => ['identity' => $identity, 'sid' => 'unauthorized-resume']];
        $gateway = Mockery::mock(LiveKitGateway::class);
        $gateway->shouldReceive('webhook')->andReturn($event);
        $gateway->shouldReceive('remove')->once()->with($m->uuid, $identity);
        $this->app->instance(LiveKitGateway::class, $gateway);
        $this->postJson('/api/v1/livekit/webhook', [])->assertNoContent();
        $this->assertNull($m->participants()->first()->joined_at);
    }

    public function test_old_departure_keeps_a_fresh_join_reservation(): void
    {
        $m = $this->meeting();
        $identity = 'user:'.$this->host->id;
        $participant = $m->participants()->create(['identity' => $identity, 'display_name' => 'Host', 'joined_at' => now()->subMinute(), 'connection_sid' => 'old-connection', 'lease_until' => now()->addMinute()]);
        $event = ['id' => bin2hex(random_bytes(16)), 'event' => 'participant_left', 'createdAt' => time(), 'room' => ['name' => $m->uuid], 'participant' => ['identity' => $identity, 'sid' => 'old-connection']];
        $this->app->instance(LiveKitGateway::class, Mockery::mock(LiveKitGateway::class)->shouldReceive('webhook')->andReturn($event)->getMock());
        $this->postJson('/api/v1/livekit/webhook', [])->assertNoContent();
        $this->assertTrue($participant->fresh()->lease_until->isFuture());
        $this->assertNotNull($participant->fresh()->left_at);
    }

    public function test_removed_revoked_or_long_departed_participants_cannot_use_resume_grace(): void
    {
        foreach (['removed', 'revoked', 'departed'] as $case) {
            $m = $this->meeting();
            $identity = 'guest:'.Str::uuid();
            $participant = $m->participants()->create(['identity' => $identity, 'display_name' => 'Guest', 'joined_at' => now()->subMinutes(5), 'lease_until' => now()->subMinutes(4), 'removed_at' => $case === 'removed' ? now() : null, 'left_at' => $case === 'departed' ? now()->subMinutes(3) : null]);
            $m->admissions()->create(['participant_identifier' => $identity, 'display_name' => 'Guest', 'status' => $case === 'revoked' ? 'rejected' : 'approved']);
            $event = ['id' => bin2hex(random_bytes(16)), 'event' => 'participant_joined', 'createdAt' => time(), 'room' => ['name' => $m->uuid], 'participant' => ['identity' => $identity, 'sid' => 'disallowed-resume']];
            $gateway = Mockery::mock(LiveKitGateway::class);
            $gateway->shouldReceive('webhook')->andReturn($event);
            $gateway->shouldReceive('remove')->once()->with($m->uuid, $identity);
            $this->app->instance(LiveKitGateway::class, $gateway);
            $this->postJson('/api/v1/livekit/webhook', [])->assertNoContent();
            $this->assertNull($participant->fresh()->connection_sid);
        }
    }
}
