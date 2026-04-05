<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RiderProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $rider = $this->rider($request);

        return response()->json($this->payload($rider));
    }

    public function update(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$rider->id],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $rider->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'address' => $data['address'] ?? null,
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->payload($rider->fresh()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $rider->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $rider->update([
            'password' => $data['password'],
        ]);

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }

    private function rider(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user || ! $user->isRider()) {
            abort(403, 'Rider access required.');
        }
        return $user;
    }

    private function payload(User $rider): array
    {
        return [
            'id' => $rider->id,
            'name' => $rider->name,
            'email' => $rider->email,
            'phone' => $rider->phone,
            'address' => $rider->address,
            'is_active' => (bool) $rider->is_active,
        ];
    }
}
