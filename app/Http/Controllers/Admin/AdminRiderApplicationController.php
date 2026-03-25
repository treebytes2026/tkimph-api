<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiderApplication;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminRiderApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RiderApplication::query()->with(['reviewer:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $s = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                    ->orWhere('email', 'like', $s)
                    ->orWhere('phone', 'like', $s);
            });
        }

        $items = $query->orderByDesc('id')->paginate($request->integer('per_page', 15));

        return response()->json($items);
    }

    public function show(RiderApplication $riderApplication): JsonResponse
    {
        return response()->json($riderApplication->load(['reviewer:id,name,email']));
    }

    public function approve(Request $request, RiderApplication $riderApplication): JsonResponse
    {
        if ($riderApplication->status !== RiderApplication::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending applications can be approved.'], 422);
        }

        $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (User::query()->where('email', $riderApplication->email)->exists()) {
            return response()->json(['message' => 'A user with this email already exists.'], 422);
        }

        DB::transaction(function () use ($riderApplication, $request) {
            User::create([
                'name' => $riderApplication->name,
                'email' => $riderApplication->email,
                'password' => Hash::make(Str::password(16)),
                'phone' => $riderApplication->phone,
                'address' => $riderApplication->address,
                'role' => User::ROLE_RIDER,
                'is_active' => true,
            ]);

            $riderApplication->update([
                'status' => RiderApplication::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
                'admin_notes' => $request->input('admin_notes'),
            ]);
        });

        return response()->json($riderApplication->fresh()->load(['reviewer']));
    }

    public function reject(Request $request, RiderApplication $riderApplication): JsonResponse
    {
        if ($riderApplication->status !== RiderApplication::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending applications can be rejected.'], 422);
        }

        $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $riderApplication->update([
            'status' => RiderApplication::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
            'admin_notes' => $request->input('admin_notes'),
        ]);

        return response()->json($riderApplication->fresh()->load(['reviewer:id,name,email']));
    }
}
