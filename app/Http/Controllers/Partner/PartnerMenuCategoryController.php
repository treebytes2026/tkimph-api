<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only list of active menu categories for building partner menus (admin manages the master list). */
class PartnerMenuCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->user()->role !== User::ROLE_RESTAURANT_OWNER) {
            abort(403, 'Partner access only.');
        }

        $items = MenuCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $items]);
    }
}
