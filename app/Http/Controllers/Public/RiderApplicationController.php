<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\RiderApplication;
use App\Models\User;
use App\Notifications\NewRiderApplicationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class RiderApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'vehicle_type' => ['nullable', 'string', 'max:80'],
            'license_number' => ['nullable', 'string', 'max:80'],
            'id_document' => ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,pdf'],
            'license_document' => ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,pdf'],
            'id_document_url' => ['nullable', 'url', 'max:2000'],
            'license_document_url' => ['nullable', 'url', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $idDocumentPath = $request->hasFile('id_document')
            ? $request->file('id_document')->store('rider-applications/documents/id', 'local')
            : null;
        $licenseDocumentPath = $request->hasFile('license_document')
            ? $request->file('license_document')->store('rider-applications/documents/license', 'local')
            : null;

        $application = RiderApplication::create([
            ...$data,
            'id_document_path' => $idDocumentPath,
            'license_document_path' => $licenseDocumentPath,
            // Backward compatibility for older clients that still send document links.
            'id_document_url' => $idDocumentPath ? null : ($data['id_document_url'] ?? null),
            'license_document_url' => $licenseDocumentPath ? null : ($data['license_document_url'] ?? null),
            'status' => RiderApplication::STATUS_PENDING,
        ]);

        $admins = User::admins()->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewRiderApplicationNotification($application));
        }

        return response()->json([
            'message' => 'Thank you. Your rider application has been received. Our team will contact you soon.',
            'id' => $application->id,
        ], 201);
    }
}
