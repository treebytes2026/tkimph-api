<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $user = $this->authenticate($request);

        if ($user->role === User::ROLE_ADMIN) {
            return $this->invalidCredentialsResponse();
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'This account has been deactivated.',
            ], 403);
        }

        $token = $user->createToken('customer-token')->plainTextToken;

        return $this->successResponse($user, $token);
    }

    public function adminLogin(Request $request): JsonResponse
    {
        $user = $this->authenticate($request);

        if ($user->role !== User::ROLE_ADMIN) {
            return $this->invalidCredentialsResponse();
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'This administrator account has been deactivated.',
            ], 403);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        return $this->successResponse($user, $token);
    }

    private function authenticate(Request $request): User
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            abort(response()->json([
                'message' => 'The email and password are not correct.',
            ], 401));
        }

        return $user;
    }

    private function invalidCredentialsResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The email and password are not correct.',
        ], 401);
    }

    private function successResponse(User $user, string $token): JsonResponse
    {
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

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'phone' => $user->phone,
            'address' => $user->address,
            'email_verified' => (bool) $user->email_verified_at,
            'phone_verified' => (bool) $user->phone_verified_at,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
