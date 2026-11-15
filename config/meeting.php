<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'max_participants' => (int) env('MEETING_MAX_PARTICIPANTS', 12),
    'max_minutes' => (int) env('MEETING_MAX_MINUTES', 60),
    'token_ttl' => 60,
    'livekit_url' => env('LIVEKIT_URL', 'ws://localhost:7880'),
    'livekit_http_url' => env('LIVEKIT_HTTP_URL', 'http://localhost:7880'),
    'livekit_key' => env('LIVEKIT_API_KEY'),
    'livekit_secret' => env('LIVEKIT_API_SECRET'),
    'firebase_credentials' => env('FIREBASE_CREDENTIALS'),
    'firebase_project' => env('FIREBASE_PROJECT_ID'),
    'firebase_web_api_key' => env('FIREBASE_WEB_API_KEY'),
    'firebase_local_token_lookup' => env('FIREBASE_LOCAL_TOKEN_LOOKUP', false),
];
