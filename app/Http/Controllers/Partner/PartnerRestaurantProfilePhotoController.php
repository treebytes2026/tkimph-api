<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\InteractsWithPartnerRestaurants;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Main listing avatar / logo shown as the restaurant’s profile picture. */
class PartnerRestaurantProfilePhotoController extends Controller
{
    use InteractsWithPartnerRestaurants;

    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);

        $this->validatePartnerImageUpload($request);

        if ($restaurant->profile_image_path) {
            Storage::disk('public')->delete($restaurant->profile_image_path);
        }

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $dir = 'restaurants/'.$restaurant->id.'/profile';
        $filename = Str::uuid()->toString().'.'.$ext;
        $path = $file->storeAs($dir, $filename, 'public');

        $restaurant->update(['profile_image_path' => $path]);

        return response()->json($restaurant->fresh()->toPartnerApiArray());
    }

    public function destroy(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);

        if ($restaurant->profile_image_path) {
            Storage::disk('public')->delete($restaurant->profile_image_path);
            $restaurant->update(['profile_image_path' => null]);
        }

        return response()->json($restaurant->fresh()->toPartnerApiArray());
    }
}
