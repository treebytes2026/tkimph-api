<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\InteractsWithPartnerRestaurants;
use App\Models\Restaurant;
use App\Models\RestaurantImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PartnerRestaurantImageController extends Controller
{
    use InteractsWithPartnerRestaurants;

    private const MAX_IMAGES = 12;

    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);

        if ($restaurant->locationImages()->count() >= self::MAX_IMAGES) {
            abort(422, 'Maximum of '.self::MAX_IMAGES.' location photos reached.');
        }

        $this->validatePartnerImageUpload($request);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $dir = 'restaurants/'.$restaurant->id.'/location';
        $filename = Str::uuid()->toString().'.'.$ext;
        $path = $file->storeAs($dir, $filename, 'public');

        $sort = (int) ($restaurant->locationImages()->max('sort_order') ?? -1) + 1;

        $image = $restaurant->locationImages()->create([
            'path' => $path,
            'sort_order' => $sort,
        ]);

        return response()->json([
            'id' => $image->id,
            'path' => $image->path,
            'url' => $image->url,
            'sort_order' => $image->sort_order,
        ], 201);
    }

    public function destroy(Request $request, Restaurant $restaurant, RestaurantImage $image): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($image->restaurant_id === $restaurant->id, 404);

        $image->delete();

        return response()->json(['message' => 'Removed.']);
    }
}
