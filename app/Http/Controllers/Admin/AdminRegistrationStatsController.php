<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Models\RiderApplication;
use Illuminate\Http\JsonResponse;

class AdminRegistrationStatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json([
                'pending_partner_applications' => PartnerApplication::query()
                    ->where('status', PartnerApplication::STATUS_PENDING)
                    ->count(),
                'pending_rider_applications' => RiderApplication::query()
                    ->where('status', RiderApplication::STATUS_PENDING)
                    ->count(),
            ])
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }
}
