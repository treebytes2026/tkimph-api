<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_transition_guardrails' => ['required', 'boolean'],
            'rider_auto_assignment' => ['required', 'boolean'],
            'sla_stalled_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'partner_self_pause_enabled' => ['required', 'boolean'],
            'partner_cancel_window_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'customer_cancel_window_minutes' => ['required', 'integer', 'min:0', 'max:120'],
        ]);

        AdminSetting::write('order_transition_guardrails', $data['order_transition_guardrails'] ? '1' : '0');
        AdminSetting::write('rider_auto_assignment', $data['rider_auto_assignment'] ? '1' : '0');
        AdminSetting::write('sla_stalled_minutes', (string) $data['sla_stalled_minutes']);
        AdminSetting::write('partner_self_pause_enabled', $data['partner_self_pause_enabled'] ? '1' : '0');
        AdminSetting::write('partner_cancel_window_minutes', (string) $data['partner_cancel_window_minutes']);
        AdminSetting::write('customer_cancel_window_minutes', (string) $data['customer_cancel_window_minutes']);

        return response()->json($this->payload());
    }

    private function payload(): array
    {
        return [
            'order_transition_guardrails' => AdminSetting::readBool('order_transition_guardrails', true),
            'rider_auto_assignment' => AdminSetting::readBool('rider_auto_assignment', false),
            'sla_stalled_minutes' => AdminSetting::readInt('sla_stalled_minutes', 30),
            'partner_self_pause_enabled' => AdminSetting::readBool('partner_self_pause_enabled', true),
            'partner_cancel_window_minutes' => AdminSetting::readInt('partner_cancel_window_minutes', 15),
            'customer_cancel_window_minutes' => AdminSetting::readInt('customer_cancel_window_minutes', 5),
        ];
    }
}
