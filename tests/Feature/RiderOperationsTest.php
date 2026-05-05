<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RiderOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_can_update_assigned_order_and_send_location(): void
    {
        [$rider, $order] = $this->createAssignedOrder();
        Sanctum::actingAs($rider);

        $this->getJson('/api/rider/overview')
            ->assertOk()
            ->assertJsonPath('rider.id', $rider->id);

        $this->patchJson("/api/rider/orders/{$order->id}/status", [
            'status' => Order::STATUS_OUT_FOR_DELIVERY,
            'note' => 'Picked up and heading out',
        ])->assertOk()->assertJsonPath('order.status', Order::STATUS_OUT_FOR_DELIVERY);

        $this->postJson("/api/rider/orders/{$order->id}/location", [
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'accuracy_meters' => 12,
        ])->assertOk();

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'rider_location_ping',
            'actor_user_id' => $rider->id,
        ]);
    }

    public function test_rider_can_claim_available_order_and_second_claim_fails(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Claim Queue Restaurant',
            'slug' => 'claim-queue-restaurant',
            'user_id' => $owner->id,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'order_number' => 'TKM-CLAIM-001',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'refund_status' => Order::REFUND_STATUS_NOT_REQUIRED,
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 100,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'gross_sales' => 100,
            'restaurant_net' => 95,
            'total' => 105,
            'placed_at' => now(),
        ]);

        $riderOne = User::factory()->create(['role' => User::ROLE_RIDER, 'is_active' => true]);
        $riderTwo = User::factory()->create(['role' => User::ROLE_RIDER, 'is_active' => true]);

        Sanctum::actingAs($riderOne);
        $this->getJson('/api/rider/orders/available')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id);

        $this->postJson("/api/rider/orders/{$order->id}/claim")
            ->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_ACCEPTED);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'rider_id' => $riderOne->id,
            'status' => Order::STATUS_ACCEPTED,
        ]);

        Sanctum::actingAs($riderTwo);
        $this->postJson("/api/rider/orders/{$order->id}/claim")
            ->assertStatus(409);
    }

    public function test_rider_can_claim_unassigned_order_already_out_for_delivery(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Out For Delivery Queue',
            'slug' => 'out-for-delivery-queue',
            'user_id' => $owner->id,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'order_number' => 'TKM-CLAIM-OFD',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_OUT_FOR_DELIVERY,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'refund_status' => Order::REFUND_STATUS_NOT_REQUIRED,
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 100,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'gross_sales' => 100,
            'restaurant_net' => 95,
            'total' => 105,
            'placed_at' => now(),
        ]);

        $rider = User::factory()->create(['role' => User::ROLE_RIDER, 'is_active' => true]);
        Sanctum::actingAs($rider);

        $this->getJson('/api/rider/orders/available')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.status', Order::STATUS_OUT_FOR_DELIVERY);

        $this->postJson("/api/rider/orders/{$order->id}/claim")
            ->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_OUT_FOR_DELIVERY);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'rider_id' => $rider->id,
            'status' => Order::STATUS_OUT_FOR_DELIVERY,
        ]);
    }

    public function test_customer_can_view_live_location_on_order_show(): void
    {
        [$rider, $order, $customer] = $this->createAssignedOrder();
        $order->events()->create([
            'actor_user_id' => $rider->id,
            'actor_role' => User::ROLE_RIDER,
            'event_type' => 'rider_location_ping',
            'from_status' => $order->status,
            'to_status' => $order->status,
            'meta' => [
                'latitude' => 14.6,
                'longitude' => 121.0,
                'accuracy_meters' => 10,
                'recorded_at' => now()->toIso8601String(),
            ],
        ]);

        Sanctum::actingAs($customer);

        $this->getJson("/api/customer/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.rider.id', $rider->id)
            ->assertJsonPath('order.live_location.latitude', 14.6);
    }

    public function test_rider_application_upload_stores_private_file_and_admin_gets_signed_url(): void
    {
        Storage::fake('local');

        $this->post('/api/rider-applications', [
            'name' => 'Rider Applicant',
            'email' => 'rider@applicant.test',
            'phone' => '09123456789',
            'id_document' => UploadedFile::fake()->create('id-card.pdf', 200, 'application/pdf'),
            'license_document' => UploadedFile::fake()->image('license.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/admin/rider-applications')
            ->assertOk()
            ->json('data.0');

        $this->assertNotEmpty($res['id_document_signed_url']);

        $this->get($res['id_document_signed_url'])->assertOk();
    }

    public function test_rider_can_update_profile_and_change_password(): void
    {
        $rider = User::factory()->create([
            'role' => User::ROLE_RIDER,
            'is_active' => true,
            'password' => 'old-password-123',
        ]);
        Sanctum::actingAs($rider);

        $this->patchJson('/api/rider/profile', [
            'name' => 'Updated Rider Name',
            'email' => 'updated.rider@test.local',
            'phone' => '09999999999',
            'address' => 'Updated Address',
        ])->assertOk()->assertJsonPath('user.name', 'Updated Rider Name');

        $this->postJson('/api/rider/change-password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();
    }

    private function createAssignedOrder(): array
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER]);
        $rider = User::factory()->create(['role' => User::ROLE_RIDER, 'is_active' => true]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Rider Test Restaurant',
            'slug' => 'rider-test-restaurant',
            'user_id' => $owner->id,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'order_number' => 'TKM-RIDER-TEST-001',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'rider_id' => $rider->id,
            'status' => Order::STATUS_PREPARING,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'refund_status' => Order::REFUND_STATUS_NOT_REQUIRED,
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 100,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'gross_sales' => 100,
            'restaurant_net' => 95,
            'total' => 105,
            'placed_at' => now(),
            'assigned_at' => now(),
        ]);

        return [$rider, $order, $customer];
    }
}
