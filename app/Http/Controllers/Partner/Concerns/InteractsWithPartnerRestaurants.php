<?php

namespace App\Http\Controllers\Partner\Concerns;

use App\Models\AdminSetting;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\Request;

trait InteractsWithPartnerRestaurants
{
    private function authorizePartnerRestaurant(Request $request, Restaurant $restaurant): User
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless(
            $user && $user->role === User::ROLE_RESTAURANT_OWNER && $restaurant->user_id === $user->id,
            403,
            'You do not manage this restaurant.'
        );

        return $user;
    }

    private function validatePartnerImageUpload(Request $request): void
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'max:5120', 'mimes:jpeg,jpg,png,webp,gif'],
        ]);
    }

    private function partnerSelfPauseEnabled(): bool
    {
        return AdminSetting::readBool('partner_self_pause_enabled', true);
    }
}
