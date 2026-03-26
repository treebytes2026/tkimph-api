<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Account details the signed-in restaurant partner may update (not email — use password reset / support). */
class PartnerProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== User::ROLE_RESTAURANT_OWNER) {
            abort(403, 'Partner access only.');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        if ($data === []) {
            abort(422, 'No valid fields to update.');
        }

        $user->update($data);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
        ]);
    }
}
