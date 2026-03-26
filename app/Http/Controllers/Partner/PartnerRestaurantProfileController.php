<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Public-facing listing fields for the partner’s restaurant (name, description, phone, address, opening hours).
 * Business type, category, cuisine, and active status stay admin-controlled.
 */
class PartnerRestaurantProfileController extends Controller
{
    public function update(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'opening_hours' => ['sometimes', 'nullable', 'array', 'size:7'],
            'opening_hours.*.day' => ['required', 'integer', 'between:0,6'],
            'opening_hours.*.closed' => ['required', 'boolean'],
            'opening_hours.*.open' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'opening_hours.*.close' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        if (isset($data['opening_hours']) && is_array($data['opening_hours'])) {
            $days = collect($data['opening_hours'])->pluck('day');
            if ($days->unique()->count() !== 7) {
                throw ValidationException::withMessages([
                    'opening_hours' => ['Each day of the week (0–6) must appear exactly once.'],
                ]);
            }
            $data['opening_hours'] = collect($data['opening_hours'])->sortBy('day')->values()->all();
        }

        if ($data === []) {
            abort(422, 'No valid fields to update.');
        }

        $restaurant->update($data);

        return response()->json($restaurant->fresh()->toPartnerApiArray());
    }

    private function authorizePartnerRestaurant(Request $request, Restaurant $restaurant): void
    {
        $user = $request->user();
        abort_unless(
            $user && $user->role === User::ROLE_RESTAURANT_OWNER && $restaurant->user_id === $user->id,
            403,
            'You do not manage this restaurant.'
        );
    }
}
