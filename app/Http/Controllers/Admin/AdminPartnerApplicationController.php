<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\PartnerApplicationApprovedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AdminPartnerApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PartnerApplication::query()
            ->with([
                'businessType:id,name,slug',
                'businessCategory:id,name',
                'cuisine:id,name',
                'reviewer:id,name',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $s = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($s) {
                $q->where('business_name', 'like', $s)
                    ->orWhere('email', 'like', $s)
                    ->orWhere('owner_first_name', 'like', $s)
                    ->orWhere('owner_last_name', 'like', $s)
                    ->orWhere('phone', 'like', $s);
            });
        }

        $items = $query->orderByDesc('id')->paginate($request->integer('per_page', 15));

        return response()->json($items);
    }

    public function show(PartnerApplication $partnerApplication): JsonResponse
    {
        return response()->json($partnerApplication->load([
            'businessType',
            'businessCategory',
            'cuisine',
            'reviewer:id,name,email',
        ]));
    }

    public function approve(Request $request, PartnerApplication $partnerApplication): JsonResponse
    {
        if ($partnerApplication->status !== PartnerApplication::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending applications can be approved.'], 422);
        }

        $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (User::query()->where('email', $partnerApplication->email)->exists()) {
            return response()->json(['message' => 'A user with this email already exists. Reject the application or use another email.'], 422);
        }

        $newUser = DB::transaction(function () use ($partnerApplication, $request) {
            $user = User::create([
                'name' => $partnerApplication->ownerFullName(),
                'email' => $partnerApplication->email,
                'password' => Hash::make(Str::password(16)),
                'phone' => $partnerApplication->phone,
                'address' => $partnerApplication->address,
                'role' => User::ROLE_RESTAURANT_OWNER,
                'is_active' => true,
            ]);

            Restaurant::create([
                'name' => $partnerApplication->business_name,
                'description' => null,
                'phone' => $partnerApplication->phone,
                'address' => $partnerApplication->address,
                'user_id' => $user->id,
                'business_type_id' => $partnerApplication->business_type_id,
                'business_category_id' => $partnerApplication->business_category_id,
                'cuisine_id' => $partnerApplication->cuisine_id,
                'is_active' => true,
            ]);

            $partnerApplication->update([
                'status' => PartnerApplication::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
                'admin_notes' => $request->input('admin_notes'),
            ]);

            return $user;
        });

        try {
            $token = Password::broker()->createToken($newUser);
            $newUser->notify(new PartnerApplicationApprovedNotification(
                $token,
                $partnerApplication->business_name
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($partnerApplication->fresh()->load([
            'businessType',
            'businessCategory',
            'cuisine',
            'reviewer',
        ]));
    }

    public function reject(Request $request, PartnerApplication $partnerApplication): JsonResponse
    {
        if ($partnerApplication->status !== PartnerApplication::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending applications can be rejected.'], 422);
        }

        $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $partnerApplication->update([
            'status' => PartnerApplication::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
            'admin_notes' => $request->input('admin_notes'),
        ]);

        return response()->json($partnerApplication->fresh()->load(['reviewer:id,name,email']));
    }
}
