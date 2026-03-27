<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_orders_and_summary(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant',
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        Order::query()->create([
            'order_number' => 'TKM-TEST-001',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 100,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'total' => 105,
            'placed_at' => now(),
        ]);

        $this->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', 'TKM-TEST-001');

        $this->getJson('/api/admin/orders/summary')
            ->assertOk()
            ->assertJsonStructure([
                'total_orders',
                'pending',
                'accepted',
                'preparing',
                'out_for_delivery',
                'completed',
                'failed',
                'undeliverable',
                'unassigned_active_orders',
                'stalled_orders',
                'active_riders',
                'gross_sales',
                'restaurant_net',
                'sla_stalled_minutes',
            ]);
    }

    public function test_admin_can_assign_rider_and_add_note_to_order(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER]);
        $rider = User::factory()->create(['role' => User::ROLE_RIDER, 'is_active' => true]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant',
            'user_id' => $owner->id,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'order_number' => 'TKM-TEST-002',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 100,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'total' => 105,
            'placed_at' => now(),
        ]);

        $this->patchJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $rider->id,
            'note' => 'Assigning nearest rider',
        ])->assertOk();

        $this->postJson("/api/admin/orders/{$order->id}/notes", [
            'note' => 'Called rider for confirmation',
        ])->assertCreated();
    }

    public function test_admin_can_read_and_update_operational_settings(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonStructure([
                'order_transition_guardrails',
                'rider_auto_assignment',
                'sla_stalled_minutes',
                'partner_self_pause_enabled',
                'partner_cancel_window_minutes',
                'customer_cancel_window_minutes',
            ]);

        $this->patchJson('/api/admin/settings', [
            'order_transition_guardrails' => false,
            'rider_auto_assignment' => true,
            'sla_stalled_minutes' => 45,
            'partner_self_pause_enabled' => true,
            'partner_cancel_window_minutes' => 20,
            'customer_cancel_window_minutes' => 8,
        ])->assertOk()->assertJsonPath('sla_stalled_minutes', 45);
    }

    public function test_admin_can_update_restaurant_operating_status_and_support_note(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);

        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Control Store',
            'slug' => 'control-store',
            'description' => 'Ready to trade',
            'phone' => '09123456789',
            'address' => 'Sample Address',
            'user_id' => $owner->id,
            'is_active' => true,
            'operating_status' => Restaurant::OPERATING_STATUS_OPEN,
            'opening_hours' => [
                ['day' => 0, 'closed' => true, 'open' => null, 'close' => null],
                ['day' => 1, 'closed' => false, 'open' => '09:00', 'close' => '21:00'],
                ['day' => 2, 'closed' => false, 'open' => '09:00', 'close' => '21:00'],
                ['day' => 3, 'closed' => false, 'open' => '09:00', 'close' => '21:00'],
                ['day' => 4, 'closed' => false, 'open' => '09:00', 'close' => '21:00'],
                ['day' => 5, 'closed' => false, 'open' => '09:00', 'close' => '21:00'],
                ['day' => 6, 'closed' => true, 'open' => null, 'close' => null],
            ],
        ]);

        $this->patchJson("/api/admin/restaurants/{$restaurant->id}/operating-status", [
            'operating_status' => Restaurant::OPERATING_STATUS_SUSPENDED,
            'operating_note' => 'Quality review in progress',
        ])->assertOk()->assertJsonPath('operating_status', Restaurant::OPERATING_STATUS_SUSPENDED);

        $this->postJson("/api/admin/restaurants/{$restaurant->id}/support-notes", [
            'note_type' => 'issue_tag',
            'body' => 'Flagged for relaunch checklist',
        ])->assertCreated()->assertJsonPath('note_type', 'issue_tag');
    }

    public function test_non_admin_cannot_access_admin_operations_endpoints(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Sanctum::actingAs($customer);

        $this->getJson('/api/admin/orders')->assertForbidden();
        $this->getJson('/api/admin/riders')->assertForbidden();
        $this->getJson('/api/admin/settings')->assertForbidden();
    }
}
