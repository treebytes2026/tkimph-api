<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_can_pause_store_and_public_listing_is_hidden(): void
    {
        [$owner, $restaurant] = $this->createReadyRestaurant();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/partner/restaurants/{$restaurant->id}/availability", [
            'operating_status' => 'paused',
            'operating_note' => 'Kitchen maintenance',
        ])->assertOk()->assertJsonPath('operating_status', 'paused');

        $this->getJson('/api/public/restaurants')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_partner_can_cancel_eligible_order_and_timeline_is_recorded(): void
    {
        [$owner, $restaurant] = $this->createReadyRestaurant();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = Order::query()->create([
            'order_number' => 'TKM-PARTNER-001',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 150,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'gross_sales' => 150,
            'restaurant_net' => 145,
            'total' => 155,
            'placed_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/partner/orders/{$order->id}/status", [
            'status' => Order::STATUS_CANCELLED,
            'reason' => 'Out of stock',
        ])->assertOk()->assertJsonPath('order.status', Order::STATUS_CANCELLED);

        $this->getJson('/api/partner/orders?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.timeline.0.event_type', 'partner_order_exception');
    }

    public function test_partner_earnings_summary_excludes_cancelled_and_failed_orders(): void
    {
        [$owner, $restaurant] = $this->createReadyRestaurant();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        foreach ([
            [Order::STATUS_COMPLETED, 200, 190],
            [Order::STATUS_ACCEPTED, 120, 115],
            [Order::STATUS_CANCELLED, 90, 85],
            [Order::STATUS_FAILED, 80, 75],
        ] as [$status, $gross, $net]) {
            Order::query()->create([
                'order_number' => 'TKM-EARN-'.uniqid(),
                'customer_id' => $customer->id,
                'restaurant_id' => $restaurant->id,
                'status' => $status,
                'payment_method' => 'cod',
                'payment_status' => 'unpaid',
                'delivery_mode' => 'delivery',
                'delivery_address' => 'Sample Address',
                'subtotal' => $gross,
                'service_fee' => 10,
                'delivery_fee' => 0,
                'gross_sales' => $gross,
                'restaurant_net' => $net,
                'total' => $gross + 10,
                'placed_at' => now(),
            ]);
        }

        Sanctum::actingAs($owner);

        $this->getJson('/api/partner/earnings')
            ->assertOk()
            ->assertJsonPath('gross_sales', 320)
            ->assertJsonPath('restaurant_net', 305)
            ->assertJsonPath('pending_settlement_amount', 115);
    }

    public function test_partner_can_create_promotion_and_public_listing_shows_real_promo_label(): void
    {
        [$owner, $restaurant] = $this->createReadyRestaurant();
        Sanctum::actingAs($owner);

        $this->postJson("/api/partner/restaurants/{$restaurant->id}/promotions", [
            'code' => 'PARTNER15',
            'name' => 'Partner Launch Deal',
            'is_active' => true,
            'min_spend' => 300,
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'per_user_usage_limit' => 2,
            'stackable' => false,
            'auto_apply' => false,
            'first_order_only' => false,
        ])->assertCreated();

        $this->getJson('/api/public/restaurants?limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.promo_label', '15% off | min PHP 300');
    }

    private function createReadyRestaurant(): array
    {
        AdminSetting::write('partner_self_pause_enabled', '1');

        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER, 'is_active' => true]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Ready Restaurant',
            'slug' => 'ready-restaurant',
            'description' => 'Great food',
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

        $category = MenuCategory::query()->create([
            'name' => 'Mains',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $menu = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main menu',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        MenuItem::query()->create([
            'menu_id' => $menu->id,
            'menu_category_id' => $category->id,
            'name' => 'Chicken meal',
            'description' => 'Best seller',
            'price' => 150,
            'sort_order' => 1,
            'is_available' => true,
        ]);

        return [$owner, $restaurant];
    }
}
