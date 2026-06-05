<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SocialAuthController extends Controller
{
    /**
     * Google's public x509 certs used to sign Firebase ID tokens.
     */
    private const CERT_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /**
     * Exchange a Firebase ID token (from Google/Facebook sign-in on the client)
     * for a Sanctum API token. Find-or-create the matching customer account.
     */
    public function firebase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => 'required|string',
        ]);

        $projectId = config('services.firebase.project_id');

        if (! $projectId) {
            return response()->json([
                'message' => 'Social login is not configured.',
            ], 500);
        }

        try {
            $claims = $this->verifyIdToken($data['id_token'], $projectId);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Could not verify your sign-in. Please try again.',
            ], 401);
        }

        $uid = $claims['sub'] ?? null;
        $email = isset($claims['email']) ? Str::lower(trim($claims['email'])) : null;
        $name = $claims['name'] ?? ($email ? Str::before($email, '@') : 'Customer');
        $avatar = $claims['picture'] ?? null;
        $emailVerified = (bool) ($claims['email_verified'] ?? false);

        if (! $uid) {
            return response()->json([
                'message' => 'Could not verify your sign-in. Please try again.',
            ], 401);
        }

        $user = $this->resolveUser($uid, $email, $name, $avatar, $emailVerified);

        if ($user->role === User::ROLE_ADMIN) {
            return response()->json([
                'message' => 'The email and password are not correct.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'This account has been deactivated.',
            ], 403);
        }

        $token = $user->createToken('customer-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'address' => $user->address,
                'email_verified' => (bool) $user->email_verified_at,
                'phone_verified' => (bool) $user->phone_verified_at,
            ],
            'token' => $token,
        ]);
    }

    /**
     * Match an existing user by Firebase uid or email, otherwise create a
     * brand-new customer. Always backfills the firebase_uid for later logins.
     */
    private function resolveUser(string $uid, ?string $email, string $name, ?string $avatar, bool $emailVerified): User
    {
        $user = User::where('firebase_uid', $uid)->first();

        if (! $user && $email) {
            $user = User::where('email', $email)->first();
        }

        if (! $user) {
            return User::create([
                'name' => $name,
                'email' => $email,
                'firebase_uid' => $uid,
                'avatar_url' => $avatar,
                'role' => User::ROLE_CUSTOMER,
                'is_active' => true,
                'password' => null,
                'email_verified_at' => $emailVerified ? now() : null,
            ]);
        }

        // Link the provider to an existing account and keep the profile fresh.
        $user->firebase_uid = $user->firebase_uid ?: $uid;
        if (! $user->avatar_url && $avatar) {
            $user->avatar_url = $avatar;
        }
        if (! $user->email_verified_at && $emailVerified) {
            $user->email_verified_at = now();
        }
        $user->save();

        return $user;
    }

    /**
     * Verify a Firebase ID token's signature and claims against Google's certs.
     *
     * @return array<string, mixed>
     */
    private function verifyIdToken(string $idToken, string $projectId): array
    {
        $keys = $this->publicKeys();

        $decoded = (array) JWT::decode($idToken, $keys);

        $expectedIssuer = "https://securetoken.google.com/{$projectId}";

        if (($decoded['aud'] ?? null) !== $projectId) {
            throw new \RuntimeException('Token audience mismatch.');
        }

        if (($decoded['iss'] ?? null) !== $expectedIssuer) {
            throw new \RuntimeException('Token issuer mismatch.');
        }

        if (empty($decoded['sub'])) {
            throw new \RuntimeException('Token subject missing.');
        }

        return $decoded;
    }

    /**
     * Fetch and cache Google's signing certs as JWT Key objects keyed by kid.
     *
     * @return array<string, Key>
     */
    private function publicKeys(): array
    {
        $certs = Cache::remember('firebase:secure-token-certs', now()->addHour(), function () {
            try {
                $response = Http::timeout(10)->get(self::CERT_URL);
            } catch (ConnectionException $e) {
                throw new \RuntimeException('Unable to reach Google cert endpoint.');
            }

            if (! $response->successful()) {
                throw new \RuntimeException('Unable to load Google certs.');
            }

            return $response->json();
        });

        $keys = [];
        foreach ((array) $certs as $kid => $pem) {
            $keys[$kid] = new Key($pem, 'RS256');
        }

        return $keys;
    }
}
