<?php

namespace App\Services;

use App\Contracts\IdentityVerifier;
use Illuminate\Validation\ValidationException;
use Kreait\Firebase\Exception\AuthException;
use Kreait\Firebase\Factory;

class FirebaseIdentityVerifier implements IdentityVerifier
{
    public function verify(string $token): array
    {
        $auth = (new Factory)->withServiceAccount(config('meeting.firebase_credentials'))->withProjectId(config('meeting.firebase_project'))->createAuth();
        try {
            $claims = $auth->verifyIdToken($token, true)->claims();
        } catch (AuthException|\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['id_token' => 'Your Google session could not be verified. Please sign in again.']);
        }
        if ($claims->get('firebase')['sign_in_provider'] !== 'google.com' || ! $claims->get('email_verified', false)) {
            throw ValidationException::withMessages(['id_token' => 'A verified Google account is required.']);
        }

        return ['firebase_uid' => $claims->get('sub'), 'name' => mb_substr($claims->get('name', 'Google user'), 0, 255), 'email' => $claims->get('email'), 'avatar' => $claims->get('picture')];
    }
}
