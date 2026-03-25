<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Models\Cuisine;
use Illuminate\Http\JsonResponse;

class RegistrationOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $types = BusinessType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->with(['categories' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order')->orderBy('name');
            }])
            ->get();

        $cuisines = Cuisine::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'sort_order']);

        return response()->json([
            'business_types' => $types->map(fn (BusinessType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'requires_category' => $t->requires_category,
                'requires_cuisine' => $t->requires_cuisine,
                'categories' => $t->categories->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'sort_order' => $c->sort_order,
                ]),
            ]),
            'cuisines' => $cuisines,
        ]);
    }
}
