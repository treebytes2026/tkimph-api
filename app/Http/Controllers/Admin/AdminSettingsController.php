<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Support\PlatformPricing;
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
            'platform_commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'settlements_enabled' => ['required', 'boolean'],
            'delivery_fee_enabled' => ['required', 'boolean'],
            'standard_delivery_fee' => ['required', 'numeric', 'min:0', 'max:9999'],
            'commission_payment_gcash_name' => ['nullable', 'string', 'max:120'],
            'commission_payment_gcash_number' => ['nullable', 'string', 'max:40'],
        ]);

        AdminSetting::write('order_transition_guardrails', $data['order_transition_guardrails'] ? '1' : '0');
        AdminSetting::write('rider_auto_assignment', $data['rider_auto_assignment'] ? '1' : '0');
        AdminSetting::write('sla_stalled_minutes', (string) $data['sla_stalled_minutes']);
        AdminSetting::write('partner_self_pause_enabled', $data['partner_self_pause_enabled'] ? '1' : '0');
        AdminSetting::write('partner_cancel_window_minutes', (string) $data['partner_cancel_window_minutes']);
        AdminSetting::write('customer_cancel_window_minutes', (string) $data['customer_cancel_window_minutes']);
        AdminSetting::write('platform_commission_rate', (string) round(((float) $data['platform_commission_rate']) / 100, 4));
        AdminSetting::write('settlements_enabled', $data['settlements_enabled'] ? '1' : '0');
        AdminSetting::write('delivery_fee_enabled', $data['delivery_fee_enabled'] ? '1' : '0');
        AdminSetting::write('standard_delivery_fee', (string) round((float) $data['standard_delivery_fee'], 2));
        AdminSetting::write('commission_payment_gcash_name', trim((string) ($data['commission_payment_gcash_name'] ?? '')));
        AdminSetting::write('commission_payment_gcash_number', trim((string) ($data['commission_payment_gcash_number'] ?? '')));

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
            'platform_commission_rate' => round(AdminSetting::readFloat('platform_commission_rate', 0.13) * 100, 2),
            'settlements_enabled' => AdminSetting::readBool('settlements_enabled', false),
            'delivery_fee_enabled' => PlatformPricing::deliveryFeeEnabled(),
            'standard_delivery_fee' => PlatformPricing::standardDeliveryFee(),
            'commission_payment_gcash_name' => AdminSetting::read('commission_payment_gcash_name', ''),
            'commission_payment_gcash_number' => AdminSetting::read('commission_payment_gcash_number', ''),
        ];
    }
}
