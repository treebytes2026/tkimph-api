<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\BusinessCategory;
use App\Models\BusinessType;
use App\Models\Cuisine;
use App\Models\PartnerApplication;
use App\Models\User;
use App\Notifications\NewPartnerApplicationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class PartnerApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'owner_first_name' => ['required', 'string', 'max:255'],
            'owner_last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'business_type_id' => ['required', 'integer', 'exists:business_types,id'],
            'business_category_id' => ['nullable', 'integer', 'exists:business_categories,id'],
            'cuisine_id' => ['nullable', 'integer', 'exists:cuisines,id'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $type = BusinessType::query()->where('id', $data['business_type_id'])->where('is_active', true)->first();
        if (! $type) {
            throw ValidationException::withMessages([
                'business_type_id' => ['The selected business type is invalid.'],
            ]);
        }

        if ($type->requires_category) {
            if (empty($data['business_category_id'])) {
                throw ValidationException::withMessages([
                    'business_category_id' => ['Please select a business category.'],
                ]);
            }
            $belongs = BusinessCategory::query()
                ->where('id', $data['business_category_id'])
                ->where('business_type_id', $type->id)
                ->where('is_active', true)
                ->exists();
            if (! $belongs) {
                throw ValidationException::withMessages([
                    'business_category_id' => ['The selected category does not match this business type.'],
                ]);
            }
        } else {
            $data['business_category_id'] = null;
        }

        if ($type->requires_cuisine) {
            if (empty($data['cuisine_id'])) {
                throw ValidationException::withMessages([
                    'cuisine_id' => ['Please select a cuisine.'],
                ]);
            }
            $cuisineOk = Cuisine::query()->where('id', $data['cuisine_id'])->where('is_active', true)->exists();
            if (! $cuisineOk) {
                throw ValidationException::withMessages([
                    'cuisine_id' => ['The selected cuisine is invalid.'],
                ]);
            }
        } else {
            $data['cuisine_id'] = null;
        }

        $application = PartnerApplication::create([
            ...$data,
            'status' => PartnerApplication::STATUS_PENDING,
        ]);

        $application->load(['businessType', 'businessCategory', 'cuisine']);

        $admins = User::admins()->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewPartnerApplicationNotification($application));
        }

        return response()->json([
            'message' => 'Thank you. Your application has been received. Our team will contact you soon.',
            'id' => $application->id,
        ], 201);
    }
}
