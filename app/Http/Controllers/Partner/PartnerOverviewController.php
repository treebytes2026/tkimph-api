<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\User;
use App\Support\CommissionCollectionMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerOverviewController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        CommissionCollectionMonitor::processOverdueCollections();

        $user = $request->user();

        if ($user->role !== User::ROLE_RESTAURANT_OWNER) {
            abort(403, 'Partner access only.');
        }

        // One partner account = one restaurant they manage; other venues register as separate accounts.
        $restaurants = $user->restaurants()
            ->with([
                'businessType:id,name,slug',
                'businessCategory:id,name',
                'cuisine:id,name',
                'locationImages',
            ])
            ->orderBy('id')
            ->limit(1)
            ->get();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
            'restaurants' => $restaurants->map(fn ($r) => $r->toPartnerApiArray()),
            'settings' => [
                'partner_self_pause_enabled' => AdminSetting::readBool('partner_self_pause_enabled', true),
                'partner_cancel_window_minutes' => AdminSetting::readInt('partner_cancel_window_minutes', 15),
            ],
        ]);
    }
}
