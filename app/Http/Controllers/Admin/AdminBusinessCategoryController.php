<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBusinessCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BusinessCategory::query()->with('businessType:id,name,slug');

        if ($request->filled('business_type_id')) {
            $query->where('business_type_id', $request->integer('business_type_id'));
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $items = $query->orderBy('business_type_id')->orderBy('sort_order')->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_type_id' => ['required', 'integer', 'exists:business_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        $category = BusinessCategory::create($data);

        return response()->json($category->load('businessType:id,name,slug'), 201);
    }

    public function show(BusinessCategory $businessCategory): JsonResponse
    {
        return response()->json($businessCategory->load('businessType:id,name,slug'));
    }

    public function update(Request $request, BusinessCategory $businessCategory): JsonResponse
    {
        $data = $request->validate([
            'business_type_id' => ['sometimes', 'integer', 'exists:business_types,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $businessCategory->update($data);

        return response()->json($businessCategory->fresh()->load('businessType:id,name,slug'));
    }

    public function destroy(BusinessCategory $businessCategory): JsonResponse
    {
        $businessCategory->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
