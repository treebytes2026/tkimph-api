<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_validate_promo_and_place_discounted_order(): void
    {
        [$customer, $restaurant, $item] = $this->createCustomerRestaurantItem();
        Promotion::query()->create([
            'code' => 'SAVE20',
            'name' => 'Save twenty percent',
            'is_active' => true,
            'min_spend' => 100,
            'discount_type' => Promotion::TYPE_PERCENTAGE,
            'discount_value' => 20,
            'per_user_usage_limit' => 1,
            'stackable' => false,
        ]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/promotions/validate', [
            'code' => 'SAVE20',
            'subtotal' => 150,
        ])->assertOk()->assertJsonPath('valid', true);

        $this->postJson('/api/customer/orders', [
            'restaurant_id' => $restaurant->id,
            'delivery_mode' => 'delivery',
            'payment_method' => 'cod',
            'promo_code' => 'SAVE20',
            'delivery_address' => 'Sample Address',
            'items' => [
                ['item_id' => $item->id, 'qty' => 1],
            ],
        ])->assertCreated()->assertJsonPath('order.discounts_total', 30);
    }

    public function test_customer_can_request_cancellation_within_window(): void
    {
        AdminSetting::write('customer_cancel_window_minutes', '15');

        [$customer, $restaurant] = $this->createCustomerRestaurantItem();
        $order = Order::query()->create([
            'order_number' => 'TKM-CUST-CANCEL-1',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'refund_status' => Order::REFUND_STATUS_NOT_REQUIRED,
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 150,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'discounts_total' => 0,
            'gross_sales' => 150,
            'restaurant_net' => 145,
            'total' => 155,
            'placed_at' => now(),
        ]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders/'.$order->id.'/cancel-request', [
            'reason' => 'Need to change address',
        ])->assertOk()->assertJsonPath('issue.issue_type', 'cancel_request');
    }

    public function test_customer_refund_request_requires_paid_exception_order(): void
    {
        [$customer, $restaurant] = $this->createCustomerRestaurantItem();
        $order = Order::query()->create([
            'order_number' => 'TKM-CUST-REFUND-1',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_CANCELLED,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'refund_status' => Order::REFUND_STATUS_PENDING,
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 150,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'discounts_total' => 0,
            'gross_sales' => 150,
            'restaurant_net' => 145,
            'total' => 155,
            'placed_at' => now(),
        ]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders/'.$order->id.'/issues', [
            'issue_type' => 'refund_request',
            'subject' => 'Refund request',
            'description' => 'Order got cancelled after payment.',
        ])->assertCreated()->assertJsonPath('issue.issue_type', 'refund_request');
    }

    public function test_customer_can_submit_review_for_completed_order(): void
    {
        [$customer, $restaurant] = $this->createCustomerRestaurantItem();
        $order = Order::query()->create([
            'order_number' => 'TKM-CUST-REVIEW-1',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_COMPLETED,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'refund_status' => Order::REFUND_STATUS_NOT_REQUIRED,
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 150,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'discounts_total' => 0,
            'gross_sales' => 150,
            'restaurant_net' => 145,
            'total' => 155,
            'placed_at' => now(),
        ]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders/'.$order->id.'/reviews', [
            'restaurant_rating' => 5,
            'comment' => 'Great order.',
        ])->assertCreated()->assertJsonPath('review.restaurant_rating', 5);
    }

    public function test_customer_can_submit_menu_item_review_for_completed_order(): void
    {
        [$customer, $restaurant, $item] = $this->createCustomerRestaurantItem();
        $order = Order::query()->create([
            'order_number' => 'TKM-CUST-ITEM-REVIEW-1',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_COMPLETED,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'refund_status' => Order::REFUND_STATUS_NOT_REQUIRED,
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 150,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'discounts_total' => 0,
            'gross_sales' => 150,
            'restaurant_net' => 145,
            'total' => 155,
            'placed_at' => now(),
        ]);
        $order->items()->create([
            'menu_item_id' => $item->id,
            'name' => $item->name,
            'unit_price' => $item->price,
            'quantity' => 1,
            'line_total' => $item->price,
        ]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders/'.$order->id.'/item-reviews', [
            'menu_item_id' => $item->id,
            'rating' => 4,
            'comment' => 'Dish is good.',
        ])->assertCreated()->assertJsonPath('review.rating', 4);
    }

    public function test_first_order_only_promotion_is_blocked_after_customer_has_prior_order(): void
    {
        [$customer, $restaurant, $item] = $this->createCustomerRestaurantItem();

        Order::query()->create([
            'order_number' => 'TKM-PRIOR-ORDER-1',
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_COMPLETED,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'refund_status' => Order::REFUND_STATUS_NOT_REQUIRED,
            'delivery_mode' => 'delivery',
            'delivery_address' => 'Sample Address',
            'subtotal' => 150,
            'service_fee' => 5,
            'delivery_fee' => 0,
            'discounts_total' => 0,
            'gross_sales' => 150,
            'restaurant_net' => 145,
            'total' => 155,
            'placed_at' => now(),
        ]);

        Promotion::query()->create([
            'restaurant_id' => $restaurant->id,
            'code' => 'FIRST100',
            'name' => 'First order promo',
            'is_active' => true,
            'min_spend' => 100,
            'discount_type' => Promotion::TYPE_FIXED,
            'discount_value' => 100,
            'per_user_usage_limit' => 1,
            'stackable' => false,
            'auto_apply' => false,
            'first_order_only' => true,
        ]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/promotions/validate', [
            'code' => 'FIRST100',
            'subtotal' => 150,
            'restaurant_id' => $restaurant->id,
        ])->assertOk()->assertJsonPath('valid', false);

        $this->postJson('/api/customer/orders', [
            'restaurant_id' => $restaurant->id,
            'delivery_mode' => 'delivery',
            'payment_method' => 'cod',
            'promo_code' => 'FIRST100',
            'delivery_address' => 'Sample Address',
            'items' => [
                ['item_id' => $item->id, 'qty' => 1],
            ],
        ])->assertStatus(422);
    }

    private function createCustomerRestaurantItem(): array
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $owner = User::factory()->create(['role' => User::ROLE_RESTAURANT_OWNER, 'is_active' => true]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Customer Test Restaurant',
            'slug' => 'customer-test-restaurant',
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
        $item = MenuItem::query()->create([
            'menu_id' => $menu->id,
            'menu_category_id' => $category->id,
            'name' => 'Chicken meal',
            'description' => 'Best seller',
            'price' => 150,
            'sort_order' => 1,
            'is_available' => true,
        ]);

        return [$customer, $restaurant, $item];
    }
}
