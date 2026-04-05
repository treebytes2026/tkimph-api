<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()
            ->json($notifications)
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()
            ->json([
                'count' => $request->user()->unreadNotifications()->count(),
            ])
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()
            ->json(['message' => 'OK'])
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()
            ->json(['message' => 'OK'])
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }
}
