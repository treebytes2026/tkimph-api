<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePartner($request);

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
        $this->authorizePartner($request);

        return response()
            ->json([
                'count' => $request->user()->unreadNotifications()->count(),
            ])
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $this->authorizePartner($request);

        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()
            ->json(['message' => 'OK'])
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->authorizePartner($request);

        $request->user()->unreadNotifications->markAsRead();

        return response()
            ->json(['message' => 'OK'])
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    private function authorizePartner(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->isRestaurantOwner(), 403, 'Partner access only.');
    }
}
