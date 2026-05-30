<?php

namespace App\Support;

use App\Models\MobilePushToken;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushService
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    /**
     * @param  User|int  $user
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User|int $user, string $title, string $body, array $data = []): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        $tokens = MobilePushToken::query()
            ->where('user_id', $userId)
            ->where('enabled', true)
            ->pluck('token');

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  iterable<int, User|int>  $users
     * @param  array<string, mixed>  $data
     */
    public function sendToUsers(iterable $users, string $title, string $body, array $data = []): void
    {
        $userIds = collect($users)
            ->map(fn (User|int $user) => $user instanceof User ? $user->id : $user)
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $tokens = MobilePushToken::query()
            ->whereIn('user_id', $userIds)
            ->where('enabled', true)
            ->pluck('token');

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToRole(string $role, string $title, string $body, array $data = [], ?callable $userScope = null): void
    {
        $query = User::query()->where('role', $role);
        if ($userScope) {
            $userScope($query);
        }

        $this->sendToUsers($query->pluck('id'), $title, $body, $data);
    }

    /**
     * @param  iterable<int, string>  $tokens
     * @param  array<string, mixed>  $data
     */
    public function sendToTokens(iterable $tokens, string $title, string $body, array $data = []): void
    {
        $messages = collect($tokens)
            ->filter(fn (string $token) => $this->isExpoToken($token))
            ->unique()
            ->values()
            ->map(fn (string $token) => [
                'to' => $token,
                'sound' => 'default',
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);

        if ($messages->isEmpty()) {
            return;
        }

        $messages->chunk(100)->each(function (Collection $chunk) {
            try {
                $response = Http::timeout(10)
                    ->acceptJson()
                    ->post(self::EXPO_PUSH_URL, $chunk->values()->all());

                if (! $response->successful()) {
                    Log::warning('Expo push request failed.', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    return;
                }

                $this->disableInvalidTokens($chunk->values(), $response->json('data') ?? []);
            } catch (\Throwable $exception) {
                Log::warning('Expo push request errored.', [
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function isExpoToken(string $token): bool
    {
        return str_starts_with($token, 'ExponentPushToken[')
            || str_starts_with($token, 'ExpoPushToken[');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tickets
     */
    private function disableInvalidTokens(Collection $messages, array $tickets): void
    {
        foreach ($tickets as $index => $ticket) {
            $details = $ticket['details'] ?? [];
            if (($details['error'] ?? null) !== 'DeviceNotRegistered') {
                continue;
            }

            $token = $messages->get($index)['to'] ?? null;
            if (is_string($token)) {
                MobilePushToken::query()->where('token', $token)->update(['enabled' => false]);
            }
        }
    }
}
