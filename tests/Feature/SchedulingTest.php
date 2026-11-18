<?php

namespace Tests\Feature;

use App\Jobs\SendMeetingReminder;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = User::factory()->create();
        $this->actingAs($this->host)->withSession(['authenticated_at' => time()]);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.example.test', 'mail.mailers.smtp.port' => 587, 'mail.from.address' => 'sender@example.test', 'meeting.email_reminders' => true]);
    }

    private function schedule(array $data = []): Meeting
    {
        $response = $this->postJson('/api/v1/meetings', array_merge(['title' => 'Planning meeting', 'scheduled_at' => now()->addHours(3)->toIso8601String(), 'timezone' => 'Africa/Addis_Ababa', 'duration_minutes' => 45], $data))->assertCreated();

        return Meeting::where('meeting_code', $response->json('meeting.meeting_code'))->firstOrFail();
    }

    private function asUser(User $user): self
    {
        $this->actingAs($user)->withSession(['authenticated_at' => time()]);

        return $this;
    }

    private function book(Meeting $m, User $user)
    {
        return $this->asUser($user)->postJson('/api/v1/meetings/'.$m->meeting_code.'/book', ['timezone' => 'Europe/London']);
    }

    public function test_schedule_uses_utc_and_expires_after_the_scheduled_duration(): void
    {
        $m = $this->schedule(['scheduled_at' => now()->addDays(2)->timezone('Africa/Addis_Ababa')->toIso8601String()]);
        $this->assertSame('scheduled', $m->status);
        $this->assertEquals(45, $m->scheduled_at->diffInMinutes($m->expires_at));
        $this->assertSame(1, $m->bookings()->count());
        $booking = $m->bookings()->first();
        $this->assertSame($this->host->id, $booking->user_id);
        $this->assertTrue($booking->reminder_due_at->equalTo($m->scheduled_at->copy()->subHour()));
    }

    public function test_booking_is_authenticated_scoped_and_idempotent(): void
    {
        $m = $this->schedule();
        $user = User::factory()->create();
        $this->book($m, $user)->assertOk()->assertJsonPath('meeting.is_booked', true);
        $this->book($m, $user)->assertOk();
        $this->assertSame(2, $m->bookings()->count());
        $this->assertSame($user->id, $m->bookings()->where('user_id', $user->id)->first()->user_id);
    }

    public function test_booking_cannot_choose_a_different_email_or_user(): void
    {
        $m = $this->schedule();
        $user = User::factory()->create();
        $this->asUser($user)->postJson('/api/v1/meetings/'.$m->meeting_code.'/book', ['user_id' => $this->host->id, 'email' => 'untrusted@example.test'])->assertOk();
        $this->assertSame(1, $m->bookings()->where('user_id', $user->id)->count());
    }

    public function test_capacity_includes_the_automatic_host_booking(): void
    {
        $m = $this->schedule(['max_participants' => 2]);
        $this->book($m, User::factory()->create())->assertOk();
        $this->book($m, User::factory()->create())->assertConflict();
    }

    public function test_cancel_and_rebook_release_and_reclaim_capacity(): void
    {
        $m = $this->schedule(['max_participants' => 2]);
        $user = User::factory()->create();
        $this->book($m, $user)->assertOk();
        $this->postJson('/api/v1/meetings/'.$m->meeting_code.'/unbook')->assertOk();
        $this->assertSame(1, $m->bookings()->whereNull('cancelled_at')->count());
        $this->book($m, $user)->assertOk();
        $this->assertSame(2, $m->bookings()->count());
    }

    public function test_host_cannot_cancel_automatic_host_booking(): void
    {
        $m = $this->schedule();
        $this->postJson('/api/v1/meetings/'.$m->meeting_code.'/unbook')->assertUnprocessable();
    }

    public function test_meeting_cannot_be_joined_before_start_time(): void
    {
        $m = $this->schedule();
        foreach (['join', 'admission-request'] as $action) {
            $this->postJson('/api/v1/meetings/'.$m->meeting_code.'/'.$action, ['display_name' => 'Host'])->assertStatus(425);
        }
    }

    public function test_past_ambiguous_or_invalid_schedule_is_rejected(): void
    {
        foreach ([['scheduled_at' => now()->subHour()->toIso8601String(), 'timezone' => 'UTC'], ['scheduled_at' => '2030-01-01T12:00:00', 'timezone' => 'UTC'], ['scheduled_at' => now()->addDay()->toIso8601String(), 'timezone' => 'invalid/timezone']] as $data) {
            $this->postJson('/api/v1/meetings', array_merge(['title' => 'Invalid'], $data))->assertUnprocessable();
        }
    }

    public function test_reminders_are_not_delivered_early(): void
    {
        Notification::fake();
        $m = $this->schedule();
        $booking = $m->bookings()->first();
        (new SendMeetingReminder($booking->id))->handle();
        $this->assertDatabaseCount('notifications', 0);
        Notification::assertNothingSent();
    }

    public function test_both_reminders_are_delivered_at_one_hour_and_not_duplicated(): void
    {
        Notification::fake();
        $m = $this->schedule();
        $booking = $m->bookings()->first();
        $this->travelTo($m->scheduled_at->copy()->subHour());
        (new SendMeetingReminder($booking->id))->handle();
        (new SendMeetingReminder($booking->id))->handle();
        $this->assertDatabaseCount('notifications', 1);
        Notification::assertSentToTimes($this->host, MeetingReminder::class, 1);
        $this->assertNotNull($booking->fresh()->reminder_notified_at);
        $this->assertNotNull($booking->fresh()->reminder_emailed_at);
    }

    public function test_scheduler_selects_due_bookings_for_every_booked_user(): void
    {
        Queue::fake();
        $m = $this->schedule();
        $user = User::factory()->create();
        $this->book($m, $user)->assertOk();
        $this->travelTo($m->scheduled_at->copy()->subHour());
        $this->artisan('meetings:remind')->assertSuccessful();
        Queue::assertPushed(SendMeetingReminder::class, 2);
    }

    public function test_booking_inside_one_hour_queues_an_immediate_reminder(): void
    {
        Queue::fake();
        $m = $this->schedule(['scheduled_at' => now()->addMinutes(30)->toIso8601String()]);
        $this->book($m, User::factory()->create())->assertOk();
        Queue::assertPushed(SendMeetingReminder::class, 2);
    }

    public function test_cancelled_booking_gets_no_reminder(): void
    {
        Notification::fake();
        $m = $this->schedule();
        $user = User::factory()->create();
        $this->book($m, $user)->assertOk();
        $booking = $m->bookings()->where('user_id', $user->id)->first();
        $this->postJson('/api/v1/meetings/'.$m->meeting_code.'/unbook')->assertOk();
        $this->travelTo($m->scheduled_at->copy()->subHour());
        (new SendMeetingReminder($booking->id))->handle();
        $this->assertDatabaseCount('notifications', 0);
        Notification::assertNothingSent();
    }

    public function test_cancelled_scheduled_meeting_is_closed_without_contacting_livekit(): void
    {
        Notification::fake();
        $m = $this->schedule();
        $booking = $m->bookings()->first();
        $this->postJson('/api/v1/meetings/'.$m->meeting_code.'/end')->assertOk();
        $this->postJson('/api/v1/meetings/'.$m->meeting_code.'/join', ['display_name' => 'Host'])->assertGone();
        $this->travelTo($m->scheduled_at->copy()->subHour());
        (new SendMeetingReminder($booking->id))->handle();
        $this->assertDatabaseCount('notifications', 0);
        Notification::assertNothingSent();
    }

    public function test_started_meeting_gets_no_stale_reminder(): void
    {
        Notification::fake();
        $m = $this->schedule();
        $this->travelTo($m->scheduled_at->copy()->addMinute());
        (new SendMeetingReminder($m->bookings()->first()->id))->handle();
        $this->assertDatabaseCount('notifications', 0);
        Notification::assertNothingSent();
    }

    public function test_email_unavailable_still_delivers_in_app_then_can_catch_up(): void
    {
        Notification::fake();
        $m = $this->schedule();
        $booking = $m->bookings()->first();
        $this->travelTo($m->scheduled_at->copy()->subHour());
        config(['mail.default' => 'log']);
        (new SendMeetingReminder($booking->id))->handle();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNull($booking->fresh()->reminder_emailed_at);
        Notification::assertNothingSent();
        config(['mail.default' => 'smtp']);
        (new SendMeetingReminder($booking->id))->handle();
        $this->assertDatabaseCount('notifications', 1);
        Notification::assertSentToTimes($this->host, MeetingReminder::class, 1);
    }

    public function test_mail_content_contains_recipient_timezone_and_meeting_link(): void
    {
        $m = $this->schedule();
        $message = (new MeetingReminder($m->bookings()->first()))->toMail($this->host);
        $this->assertStringContainsString('/m/'.$m->meeting_code, $message->actionUrl);
        $this->assertStringContainsString('Africa/Addis_Ababa', implode(' ', $message->introLines));
    }

    public function test_notification_list_and_read_action_are_user_scoped(): void
    {
        Notification::fake();
        $m = $this->schedule();
        $this->travelTo($m->scheduled_at->copy()->subHour());
        $booking = $m->bookings()->first();
        (new SendMeetingReminder($booking->id))->handle();
        $id = $booking->reminder_uuid;
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1);
        $this->asUser(User::factory()->create())->getJson('/api/v1/notifications')->assertJsonCount(0, 'notifications');
        $this->postJson('/api/v1/notifications/'.$id.'/read')->assertNotFound();
        $this->asUser($this->host)->postJson('/api/v1/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 0);
    }

    public function test_booked_meeting_list_does_not_leak_other_users_bookings(): void
    {
        $m = $this->schedule();
        $user = User::factory()->create();
        $this->asUser($user)->getJson('/api/v1/bookings')->assertJsonCount(0, 'meetings');
        $this->book($m, $user)->assertOk();
        $this->getJson('/api/v1/bookings')->assertJsonCount(1, 'meetings');
    }

    public function test_metadata_opens_the_room_at_start_time_for_waiting_browsers(): void
    {
        $m = $this->schedule();
        $this->getJson('/api/v1/meetings/'.$m->meeting_code)->assertJsonPath('meeting.can_join', false);
        $this->travelTo($m->scheduled_at);
        $this->getJson('/api/v1/meetings/'.$m->meeting_code)->assertJsonPath('meeting.can_join', true);
    }

    public function test_booking_does_not_bypass_host_admission(): void
    {
        $m = $this->schedule();
        $user = User::factory()->create();
        $this->book($m, $user)->assertOk();
        $this->travelTo($m->scheduled_at);
        $this->postJson('/api/v1/meetings/'.$m->meeting_code.'/join', ['display_name' => 'Booked guest'])->assertForbidden();
    }

    public function test_unbooked_guest_cannot_consume_reserved_places(): void
    {
        $m = $this->schedule(['max_participants' => 2]);
        $this->book($m, User::factory()->create())->assertOk();
        $id = (string) Str::uuid();
        $m->admissions()->create(['participant_identifier' => 'guest:'.$id, 'display_name' => 'Walk-in', 'status' => 'approved']);
        Auth::guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->travelTo($m->scheduled_at);
        $this->withSession(['guest_identifier' => $id])->postJson('/api/v1/meetings/'.$m->meeting_code.'/join', ['display_name' => 'Walk-in'])->assertConflict();
    }

    public function test_guest_cannot_book_without_google_account_session(): void
    {
        $m = $this->schedule();
        Auth::guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/meetings/'.$m->meeting_code.'/book')->assertUnauthorized();
    }

    public function test_mail_failure_keeps_in_app_reminder_and_leaves_email_retryable(): void
    {
        $m = $this->schedule();
        $booking = $m->bookings()->first();
        $this->travelTo($m->scheduled_at->copy()->subHour());
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('sendNow')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        try {
            (new SendMeetingReminder($booking->id))->handle();
            $this->fail('Mail transport failure should remain retryable.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('SMTP unavailable', $failure->getMessage());
        }
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull($booking->fresh()->reminder_notified_at);
        $this->assertNull($booking->fresh()->reminder_emailed_at);
    }
}
