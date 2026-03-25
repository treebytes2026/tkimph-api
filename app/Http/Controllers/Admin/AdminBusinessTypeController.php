<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminBusinessTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BusinessType::query()->orderBy('sort_order')->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $items = $query->paginate($request->integer('per_page', 50));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:business_types,slug'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'requires_category' => ['sometimes', 'boolean'],
            'requires_cuisine' => ['sometimes', 'boolean'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        $type = BusinessType::create($data);

        return response()->json($type, 201);
    }

    public function show(BusinessType $businessType): JsonResponse
    {
        return response()->json($businessType->load('categories'));
    }

    public function update(Request $request, BusinessType $businessType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:business_types,slug,'.$businessType->id],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'requires_category' => ['sometimes', 'boolean'],
            'requires_cuisine' => ['sometimes', 'boolean'],
        ]);

        $businessType->update($data);

        return response()->json($businessType->fresh());
    }

    public function destroy(BusinessType $businessType): JsonResponse
    {
        if ($businessType->partnerApplications()->exists()) {
            return response()->json(['message' => 'This business type is used by applications and cannot be deleted.'], 422);
        }

        $businessType->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
