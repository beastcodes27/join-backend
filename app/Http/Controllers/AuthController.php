<?php

namespace App\Http\Controllers;

use App\Contracts\IdentityVerifier;
use App\Http\Requests\FirebaseLoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(FirebaseLoginRequest $request, IdentityVerifier $verifier): JsonResponse
    {
        $profile = $verifier->verify($request->validated('id_token'));
        // Only the verified UID links accounts. Never merge accounts by a client-supplied email.
        $user = User::firstOrNew(['firebase_uid' => $profile['firebase_uid']]);
        abort_if($user->exists && $user->status !== 'active', 403, 'This account is unavailable.');
        $user->fill($profile);
        $user->save();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('authenticated_at', time());

        return response()->json(['user' => $user]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
