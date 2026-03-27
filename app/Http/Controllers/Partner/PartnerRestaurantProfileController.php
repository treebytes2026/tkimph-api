<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\InteractsWithPartnerRestaurants;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\AdminSystemNotification;
use App\Notifications\PartnerSystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Public-facing listing fields for the partner’s restaurant (name, description, phone, address, opening hours).
 * Business type, category, cuisine, and active status stay admin-controlled.
 */
class PartnerRestaurantProfileController extends Controller
{
    use InteractsWithPartnerRestaurants;

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

    public function updateAvailability(Request $request, Restaurant $restaurant): JsonResponse
    {
        $user = $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($this->partnerSelfPauseEnabled(), 422, 'Partner self-pause is disabled by admin.');

        $data = $request->validate([
            'operating_status' => ['required', 'in:open,paused'],
            'operating_note' => ['nullable', 'string', 'max:1000'],
            'paused_until' => ['nullable', 'date'],
        ]);

        $restaurant->update([
            'operating_status' => $data['operating_status'],
            'operating_note' => $data['operating_note'] ?? null,
            'paused_until' => $data['operating_status'] === Restaurant::OPERATING_STATUS_PAUSED && ! empty($data['paused_until'])
                ? Carbon::parse($data['paused_until'])
                : null,
        ]);

        User::query()
            ->admins()
            ->each(fn ($admin) => $admin->notify(new AdminSystemNotification(
                $data['operating_status'] === Restaurant::OPERATING_STATUS_PAUSED ? 'store_paused' : 'store_resumed',
                $restaurant->name.' is now '.str_replace('_', ' ', $data['operating_status']).'.',
                [
                    'restaurant_id' => $restaurant->id,
                    'restaurant_name' => $restaurant->name,
                    'operating_status' => $data['operating_status'],
                ]
            )));

        $user->notify(new PartnerSystemNotification(
            'store_status_changed',
            'Your store status is now '.str_replace('_', ' ', $data['operating_status']).'.',
            [
                'restaurant_id' => $restaurant->id,
                'operating_status' => $data['operating_status'],
            ]
        ));

        return response()->json($restaurant->fresh()->toPartnerApiArray());
    }
}
