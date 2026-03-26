<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PartnerPasswordController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== User::ROLE_RESTAURANT_OWNER) {
            abort(403, 'Partner access only.');
        }

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user->update([
            'password' => Hash::make($request->string('password')->toString()),
        ]);

        return response()->json(['message' => 'Password updated.']);
    }
}
