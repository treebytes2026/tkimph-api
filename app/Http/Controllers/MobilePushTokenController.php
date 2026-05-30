<?php

namespace App\Http\Controllers;

use App\Models\MobilePushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobilePushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'expo_push_token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', Rule::in(['ios', 'android', 'web'])],
            'device_id' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $token = MobilePushToken::query()->updateOrCreate(
            ['token' => $data['expo_push_token']],
            [
                'user_id' => $user->id,
                'platform' => $data['platform'] ?? null,
                'device_id' => $data['device_id'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'role' => $user->role,
                'enabled' => $data['enabled'] ?? true,
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Push notifications enabled for this device.',
            'token' => [
                'id' => $token->id,
                'platform' => $token->platform,
                'enabled' => (bool) $token->enabled,
                'last_seen_at' => optional($token->last_seen_at)->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'expo_push_token' => ['required', 'string', 'max:255'],
        ]);

        MobilePushToken::query()
            ->where('user_id', $user->id)
            ->where('token', $data['expo_push_token'])
            ->delete();

        return response()->json(['message' => 'Push notifications disabled for this device.']);
    }
}
