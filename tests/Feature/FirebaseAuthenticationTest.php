<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FirebaseAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const LOOKUP = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'local');
        config([
            'meeting.firebase_local_token_lookup' => true,
            'meeting.firebase_web_api_key' => bin2hex(random_bytes(24)),
            'meeting.firebase_project' => 'firebase-project-for-tests',
            'meeting.firebase_credentials' => null,
        ]);
        Http::preventStrayRequests();
    }

    private function account(array $overrides = []): array
    {
        return array_replace([
            'localId' => 'verified-google-user',
            'email' => 'verified@example.test',
            'emailVerified' => true,
            'displayName' => 'Verified Google User',
            'photoUrl' => 'https://example.test/avatar.png',
        ], $overrides);
    }

    /** The fake Google response represents validation of the exact submitted token. */
    private function token(array $overrides = []): string
    {
        $now = now()->getTimestamp();
        $claims = array_replace([
            'aud' => config('meeting.firebase_project'),
            'iss' => 'https://securetoken.google.com/'.config('meeting.firebase_project'),
            'sub' => 'verified-google-user',
            'exp' => $now + 3600,
            'iat' => $now - 5,
            'auth_time' => $now - 10,
            'email_verified' => true,
            'firebase' => ['sign_in_provider' => 'google.com'],
        ], $overrides);
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return $encode(json_encode(['alg' => 'RS256', 'kid' => bin2hex(random_bytes(8))])).'.'.$encode(json_encode($claims)).'.'.$encode(random_bytes(32));
    }

    private function login(string $token): TestResponse
    {
        $csrf = bin2hex(random_bytes(20));

        return $this->withSession(['_token' => $csrf])->postJson('/api/v1/auth/firebase', [
            '_token' => $csrf, 'id_token' => $token,
            'email' => 'untrusted@example.test', 'user_id' => 'untrusted',
        ]);
    }

    public function test_local_login_uses_google_verified_identity_and_creates_session(): void
    {
        Http::fake([self::LOOKUP => Http::response(['users' => [$this->account()]])]);
        $token = $this->token();
        $this->login($token)->assertOk()->assertJsonPath('user.email', 'verified@example.test');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['idToken'] === $token
            && str_starts_with($request->url(), 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?')
            && ! isset($request['localId']) && ! isset($request['email']));
        $this->assertDatabaseHas('users', ['firebase_uid' => 'verified-google-user', 'email' => 'verified@example.test']);
        $this->assertAuthenticated();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.name', 'Verified Google User');
    }

    public function test_local_login_synchronizes_existing_google_profile(): void
    {
        Http::fakeSequence(self::LOOKUP)
            ->push(['users' => [$this->account()]])
            ->push(['users' => [$this->account(['displayName' => 'Updated Name'])]]);
        $this->login($this->token())->assertOk();
        $this->login($this->token())->assertOk()->assertJsonPath('user.name', 'Updated Name');
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('rejectedGoogleTokens')]
    public function test_google_rejection_never_creates_account_or_session(string $error): void
    {
        Http::fake([self::LOOKUP => Http::response(['error' => ['message' => $error]], 400)]);
        $this->login($this->token())->assertUnprocessable()->assertJsonValidationErrors('id_token');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public static function rejectedGoogleTokens(): array
    {
        return array_map(fn (string $error): array => [$error], ['INVALID_ID_TOKEN', 'TOKEN_EXPIRED', 'USER_DISABLED', 'USER_NOT_FOUND']);
    }

    #[DataProvider('invalidClaims')]
    public function test_claims_must_match_the_configured_project_and_google_identity(array $claims): void
    {
        Http::fake([self::LOOKUP => Http::response(['users' => [$this->account()]])]);
        $this->login($this->token($claims))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public static function invalidClaims(): array
    {
        return [
            'other project' => [['aud' => 'different-project']],
            'other issuer' => [['iss' => 'https://untrusted.example.test']],
            'other subject' => [['sub' => 'different-user']],
            'empty subject' => [['sub' => '']],
            'expired' => [['exp' => 1]],
            'invalid expiration type' => [['exp' => 'never']],
            'future issued at' => [['iat' => PHP_INT_MAX]],
            'future authentication' => [['auth_time' => PHP_INT_MAX]],
            'wrong provider' => [['firebase' => ['sign_in_provider' => 'password']]],
            'missing provider' => [['firebase' => null]],
            'unverified claim' => [['email_verified' => false]],
            'string boolean is not verification' => [['email_verified' => 'true']],
        ];
    }

    #[DataProvider('invalidAccounts')]
    public function test_google_account_must_be_verified_and_active(array $account): void
    {
        Http::fake([self::LOOKUP => Http::response(['users' => [$this->account($account)]])]);
        $this->login($this->token())->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public static function invalidAccounts(): array
    {
        return [
            'different account' => [['localId' => 'another-user']],
            'unverified email' => [['emailVerified' => false]],
            'disabled account' => [['disabled' => true]],
            'invalid email' => [['email' => 'not-an-email']],
        ];
    }

    public function test_google_outage_fails_closed_without_exposing_api_key(): void
    {
        Http::fake([self::LOOKUP => Http::response([], 503)]);
        $response = $this->login($this->token())->assertStatus(503);
        $this->assertStringNotContainsString(config('meeting.firebase_web_api_key'), $response->getContent());
        $this->assertGuest();
    }

    public function test_connection_failure_has_actionable_error_and_never_creates_session(): void
    {
        Http::fake([self::LOOKUP => Http::failedConnection()]);
        $this->login($this->token())->assertStatus(503)->assertJsonPath('message', 'Cannot reach Firebase to verify your Google sign-in. Check the server internet connection and try again.');
        $this->assertGuest();
    }

    public function test_invalid_server_api_key_does_not_look_like_a_successful_login(): void
    {
        Http::fake([self::LOOKUP => Http::response(['error' => ['message' => 'API_KEY_INVALID']], 403)]);
        $this->login($this->token())->assertStatus(503)->assertJsonPath('message', 'Firebase rejected the server configuration. Check the Firebase web API key and its API restrictions.');
        $this->assertGuest();
    }

    public function test_malformed_success_response_never_creates_session(): void
    {
        Http::fake([self::LOOKUP => Http::response(['users' => []])]);
        $this->login($this->token())->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_malformed_token_is_not_trusted_even_if_lookup_returns_a_user(): void
    {
        Http::fake([self::LOOKUP => Http::response(['users' => [$this->account()]])]);
        $this->login('malformed-token')->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_missing_local_configuration_is_explicit_and_does_not_call_google(): void
    {
        config(['meeting.firebase_web_api_key' => null]);
        $this->login($this->token())->assertStatus(503)->assertJsonPath('message', 'Configure the Firebase project ID and web API key for local Google sign-in.');
        Http::assertNothingSent();
    }

    public function test_production_cannot_use_local_lookup_even_when_flag_is_enabled(): void
    {
        $this->app->instance('env', 'production');
        $this->login($this->token())->assertStatus(503)->assertJsonPath('message', 'Google sign-in is not configured on the server. Ask the administrator to configure Firebase Admin credentials.');
        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_local_lookup_can_be_disabled_to_require_admin_credentials(): void
    {
        config(['meeting.firebase_local_token_lookup' => false]);
        $this->login($this->token())->assertStatus(503);
        Http::assertNothingSent();
    }
}
