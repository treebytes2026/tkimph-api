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
use Illuminate\Support\Facades\URL;

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
        $items->getCollection()->transform(fn (RiderApplication $item) => $this->serialize($item));

        return response()->json($items);
    }

    public function show(RiderApplication $riderApplication): JsonResponse
    {
        return response()->json($this->serialize($riderApplication->load(['reviewer:id,name,email'])));
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

        return response()->json($this->serialize($riderApplication->fresh()->load(['reviewer'])));
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

        return response()->json($this->serialize($riderApplication->fresh()->load(['reviewer:id,name,email'])));
    }

    private function serialize(RiderApplication $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'email' => $item->email,
            'phone' => $item->phone,
            'address' => $item->address,
            'vehicle_type' => $item->vehicle_type,
            'license_number' => $item->license_number,
            'id_document_url' => $item->id_document_url,
            'license_document_url' => $item->license_document_url,
            'id_document_signed_url' => $this->signedDocumentUrl($item, 'id_document', $item->id_document_path),
            'license_document_signed_url' => $this->signedDocumentUrl($item, 'license_document', $item->license_document_path),
            'notes' => $item->notes,
            'status' => $item->status,
            'admin_notes' => $item->admin_notes,
            'reviewed_at' => $item->reviewed_at?->toIso8601String(),
            'reviewed_by' => $item->reviewed_by,
            'reviewer' => $item->reviewer ? [
                'id' => $item->reviewer->id,
                'name' => $item->reviewer->name,
                'email' => $item->reviewer->email,
            ] : null,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    private function signedDocumentUrl(RiderApplication $item, string $type, ?string $path): ?string
    {
        if ($path) {
            return URL::temporarySignedRoute(
                'public.rider-applications.documents.show',
                now()->addMinutes(30),
                [
                    'riderApplication' => $item->id,
                    'type' => $type,
                ]
            );
        }

        $legacyUrl = $type === 'id_document' ? $item->id_document_url : $item->license_document_url;

        if ($legacyUrl && ! str_starts_with($legacyUrl, 'http://') && ! str_starts_with($legacyUrl, 'https://')) {
            return URL::temporarySignedRoute(
                'public.rider-applications.documents.show',
                now()->addMinutes(30),
                [
                    'riderApplication' => $item->id,
                    'type' => $type,
                ]
            );
        }

        return $legacyUrl;
    }
}
