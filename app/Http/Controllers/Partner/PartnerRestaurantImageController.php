<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantImage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PartnerRestaurantImageController extends Controller
{
    private const MAX_IMAGES = 12;

    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);

        if ($restaurant->locationImages()->count() >= self::MAX_IMAGES) {
            abort(422, 'Maximum of '.self::MAX_IMAGES.' location photos reached.');
        }

        $request->validate([
            'image' => ['required', 'file', 'image', 'max:5120', 'mimes:jpeg,jpg,png,webp,gif'],
        ]);

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
