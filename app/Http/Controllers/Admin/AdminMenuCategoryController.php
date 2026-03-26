<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMenuCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MenuCategory::query();

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $items = $query->orderBy('sort_order')->orderBy('name')->paginate($request->integer('per_page', 50));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        $category = MenuCategory::create($data);

        return response()->json($category, 201);
    }

    public function show(MenuCategory $menuCategory): JsonResponse
    {
        return response()->json($menuCategory);
    }

    public function update(Request $request, MenuCategory $menuCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $menuCategory->update($data);

        return response()->json($menuCategory->fresh());
    }

    public function destroy(MenuCategory $menuCategory): JsonResponse
    {
        $menuCategory->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
