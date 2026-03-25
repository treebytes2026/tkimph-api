<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerOverviewController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== User::ROLE_RESTAURANT_OWNER) {
            abort(403, 'Partner access only.');
        }

        $restaurants = $user->restaurants()
            ->with([
                'businessType:id,name,slug',
                'businessCategory:id,name',
                'cuisine:id,name',
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
            'restaurants' => $restaurants->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'description' => $r->description,
                'phone' => $r->phone,
                'address' => $r->address,
                'is_active' => (bool) $r->is_active,
                'business_type' => $r->businessType ? [
                    'id' => $r->businessType->id,
                    'name' => $r->businessType->name,
                ] : null,
                'business_category' => $r->businessCategory ? [
                    'id' => $r->businessCategory->id,
                    'name' => $r->businessCategory->name,
                ] : null,
                'cuisine' => $r->cuisine ? [
                    'id' => $r->cuisine->id,
                    'name' => $r->cuisine->name,
                ] : null,
            ]),
        ]);
    }
}
