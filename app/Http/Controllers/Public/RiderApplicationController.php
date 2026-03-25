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
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $application = RiderApplication::create([
            ...$data,
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
