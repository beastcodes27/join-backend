<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use JsonException;

/** Local development verification through Google's authenticated end-user API. */
class FirebaseTokenLookup
{
    public function verify(string $token): array
    {
        $project = config('meeting.firebase_project');
        $apiKey = config('meeting.firebase_web_api_key');
        abort_unless(is_string($project) && $project !== '' && is_string($apiKey) && $apiKey !== '', 503,
            'Configure the Firebase project ID and web API key for local Google sign-in.');

        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->withQueryParameters(['key' => $apiKey])
                ->post('https://identitytoolkit.googleapis.com/v1/accounts:lookup', ['idToken' => $token]);
        } catch (ConnectionException) {
            abort(503, 'Cannot reach Firebase to verify your Google sign-in. Check the server internet connection and try again.');
        }

        if (! $response->successful()) {
            $error = $response->json('error.message');
            if (is_string($error) && in_array(explode(' : ', $error)[0], ['INVALID_ID_TOKEN', 'TOKEN_EXPIRED', 'USER_DISABLED', 'USER_NOT_FOUND'], true)) {
                $this->invalidToken();
            }
            if (in_array($response->status(), [400, 401, 403], true)) {
                abort(503, 'Firebase rejected the server configuration. Check the Firebase web API key and its API restrictions.');
            }
            abort(503, 'Firebase verification is temporarily unavailable. Please try signing in again.');
        }

        $users = $response->json('users');
        if (! is_array($users) || ! array_is_list($users) || count($users) !== 1 || ! is_array($users[0])) {
            $this->invalidToken();
        }
        $user = $users[0];

        // Claims are used only after Google has accepted this exact token, then bound to its returned UID.
        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) > 10000 || ! preg_match('/\A[A-Za-z0-9_-]+\z/', $parts[1])) {
            $this->invalidToken();
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        try {
            $claims = $payload === false ? null : json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalidToken();
        }
        $now = now()->getTimestamp();
        if (! is_array($claims)
            || ($claims['aud'] ?? null) !== $project
            || ($claims['iss'] ?? null) !== 'https://securetoken.google.com/'.$project
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 128
            || ($user['localId'] ?? null) !== $claims['sub']
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] <= 0 || $claims['iat'] > $now
            || ! is_int($claims['auth_time'] ?? null) || $claims['auth_time'] <= 0 || $claims['auth_time'] > $now
            || ($user['disabled'] ?? false) !== false) {
            $this->invalidToken();
        }
        if (! is_array($claims['firebase'] ?? null)
            || ($claims['firebase']['sign_in_provider'] ?? null) !== 'google.com'
            || ($claims['email_verified'] ?? false) !== true
            || ($user['emailVerified'] ?? false) !== true
            || ! is_string($user['email'] ?? null) || strlen($user['email']) > 255
            || ! filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['id_token' => 'A verified Google account is required.']);
        }

        $name = $user['displayName'] ?? 'Google user';
        $avatar = $user['photoUrl'] ?? null;

        return [
            'firebase_uid' => $user['localId'],
            'name' => is_string($name) ? mb_substr($name, 0, 255) : 'Google user',
            'email' => $user['email'],
            'avatar' => is_string($avatar) && strlen($avatar) <= 2048 ? $avatar : null,
        ];
    }

    private function invalidToken(): never
    {
        throw ValidationException::withMessages(['id_token' => 'Your Google session could not be verified. Please sign in again.']);
    }
}
