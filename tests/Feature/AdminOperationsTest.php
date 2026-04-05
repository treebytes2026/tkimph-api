<?php

namespace Tests\Feature;

use App\Models\CommissionCollection;
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
                'platform_commission_rate',
                'settlements_enabled',
                'delivery_fee_enabled',
                'standard_delivery_fee',
                'commission_payment_gcash_name',
                'commission_payment_gcash_number',
            ]);

        $this->patchJson('/api/admin/settings', [
            'order_transition_guardrails' => false,
            'rider_auto_assignment' => true,
            'sla_stalled_minutes' => 45,
            'partner_self_pause_enabled' => true,
            'partner_cancel_window_minutes' => 20,
            'customer_cancel_window_minutes' => 8,
            'platform_commission_rate' => 13.5,
            'settlements_enabled' => true,
            'delivery_fee_enabled' => true,
            'standard_delivery_fee' => 59,
            'commission_payment_gcash_name' => 'TKimph Admin',
            'commission_payment_gcash_number' => '09171234567',
        ])->assertOk()
            ->assertJsonPath('sla_stalled_minutes', 45)
            ->assertJsonPath('platform_commission_rate', 13.5)
            ->assertJsonPath('settlements_enabled', true)
            ->assertJsonPath('delivery_fee_enabled', true)
            ->assertJsonPath('standard_delivery_fee', 59)
            ->assertJsonPath('commission_payment_gcash_name', 'TKimph Admin')
            ->assertJsonPath('commission_payment_gcash_number', '09171234567');
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

    public function test_admin_can_generate_and_mark_commission_collection_records(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Commission Store',
            'slug' => 'commission-store',
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        Order::query()->create([
            'order_number' => 'TKM-COMM-001',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_COMPLETED,
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 200,
            'service_fee' => 26,
            'delivery_fee' => 0,
            'gross_sales' => 200,
            'restaurant_net' => 174,
            'total' => 200,
            'placed_at' => now(),
        ]);

        $this->postJson('/api/admin/commission-collections', [
            'restaurant_id' => $restaurant->id,
            'period_from' => now()->toDateString(),
            'period_to' => now()->toDateString(),
            'notes' => 'Collect by weekend',
        ])->assertCreated()
            ->assertJsonPath('collection.commission_amount', 26)
            ->assertJsonPath('collection.status', CommissionCollection::STATUS_PENDING);

        $collection = CommissionCollection::query()->firstOrFail();

        $this->postJson("/api/admin/commission-collections/{$collection->id}/mark-received", [
            'status' => CommissionCollection::STATUS_RECEIVED,
            'collection_reference' => 'GCASH-12345',
            'notes' => 'Paid to platform owner',
        ])->assertOk()
            ->assertJsonPath('collection.status', CommissionCollection::STATUS_RECEIVED)
            ->assertJsonPath('collection.collection_reference', 'GCASH-12345');
    }

    public function test_overdue_commission_collection_notifies_both_sides_without_auto_suspension(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);

        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Late Commission Store',
            'slug' => 'late-commission-store',
            'user_id' => $owner->id,
            'is_active' => true,
            'operating_status' => Restaurant::OPERATING_STATUS_OPEN,
        ]);

        CommissionCollection::query()->create([
            'restaurant_id' => $restaurant->id,
            'period_from' => now()->subDays(14)->toDateString(),
            'period_to' => now()->subDays(7)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'order_count' => 2,
            'gross_sales' => 500,
            'commission_amount' => 65,
            'restaurant_net' => 435,
            'status' => CommissionCollection::STATUS_PENDING,
        ]);

        $this->getJson('/api/admin/commission-collections')
            ->assertOk()
            ->assertJsonPath('data.0.is_overdue', true);

        $restaurant->refresh();
        $this->assertSame(Restaurant::OPERATING_STATUS_OPEN, $restaurant->operating_status);

        $this->assertNotNull(
            $owner->notifications()->where('data->category', 'commission_collection_overdue_partner')->first()
        );
        $this->assertNotNull(
            $admin->notifications()->where('data->category', 'commission_collection_overdue_admin')->first()
        );
    }

    public function test_overdue_commission_artisan_command_processes_records(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Command Late Store',
            'slug' => 'command-late-store',
            'user_id' => $owner->id,
            'is_active' => true,
            'operating_status' => Restaurant::OPERATING_STATUS_OPEN,
        ]);

        CommissionCollection::query()->create([
            'restaurant_id' => $restaurant->id,
            'period_from' => now()->subDays(10)->toDateString(),
            'period_to' => now()->subDays(5)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'order_count' => 1,
            'gross_sales' => 250,
            'commission_amount' => 32.5,
            'restaurant_net' => 217.5,
            'status' => CommissionCollection::STATUS_PENDING,
        ]);

        $this->artisan('commissions:process-overdue')
            ->expectsOutput('Processed 1 overdue commission collection(s).')
            ->assertSuccessful();

        $this->assertNotNull(
            $owner->notifications()->where('data->category', 'commission_collection_overdue_partner')->first()
        );
        $this->assertNotNull(
            $admin->notifications()->where('data->category', 'commission_collection_overdue_admin')->first()
        );
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
