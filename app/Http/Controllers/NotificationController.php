<?php

namespace App\Http\Controllers;

use App\Notifications\MeetingReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['email_reminder_mode' => MeetingReminder::emailMode(), 'notifications' => $request->user()->notifications()->latest()->limit(30)->get(['id', 'data', 'read_at', 'created_at']), 'unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }
}
