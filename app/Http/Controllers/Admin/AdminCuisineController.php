<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cuisine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCuisineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Cuisine::query();

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

        $cuisine = Cuisine::create($data);

        return response()->json($cuisine, 201);
    }

    public function show(Cuisine $cuisine): JsonResponse
    {
        return response()->json($cuisine);
    }

    public function update(Request $request, Cuisine $cuisine): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $cuisine->update($data);

        return response()->json($cuisine->fresh());
    }

    public function destroy(Cuisine $cuisine): JsonResponse
    {
        $cuisine->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
