<?php

namespace Database\Seeders;

use App\Models\BusinessCategory;
use App\Models\BusinessType;
use App\Models\Cuisine;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@tkimph.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('treebytex2026'),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        User::query()->firstOrCreate(
            ['email' => 'owner@tkimph.com'],
            [
                'name' => 'Restaurant Owner',
                'password' => Hash::make('password'),
                'role' => User::ROLE_RESTAURANT_OWNER,
                'is_active' => true,
            ]
        );

        User::query()->firstOrCreate(
            ['email' => 'rider@tkimph.com'],
            [
                'name' => 'Rider User',
                'password' => Hash::make('password'),
                'role' => User::ROLE_RIDER,
                'is_active' => true,
            ]
        );

        User::query()->firstOrCreate(
            ['email' => 'customer@tkimph.com'],
            [
                'name' => 'Customer User',
                'password' => Hash::make('password'),
                'role' => User::ROLE_CUSTOMER,
                'is_active' => true,
            ]
        );

        $restaurantType = BusinessType::query()->firstOrCreate(
            ['slug' => 'restaurant'],
            [
                'name' => 'Restaurant',
                'sort_order' => 0,
                'is_active' => true,
                'requires_category' => true,
                'requires_cuisine' => true,
            ]
        );

        BusinessType::query()->firstOrCreate(
            ['slug' => 'cloud-kitchen'],
            [
                'name' => 'Cloud kitchen',
                'sort_order' => 1,
                'is_active' => true,
                'requires_category' => true,
                'requires_cuisine' => true,
            ]
        );

        BusinessType::query()->firstOrCreate(
            ['slug' => 'shop'],
            [
                'name' => 'Shop / Mart',
                'sort_order' => 2,
                'is_active' => true,
                'requires_category' => false,
                'requires_cuisine' => false,
            ]
        );

        $catFastFood = BusinessCategory::query()->firstOrCreate(
            [
                'business_type_id' => $restaurantType->id,
                'name' => 'Fast food',
            ],
            ['sort_order' => 0, 'is_active' => true]
        );

        BusinessCategory::query()->firstOrCreate(
            [
                'business_type_id' => $restaurantType->id,
                'name' => 'Casual dining',
            ],
            ['sort_order' => 1, 'is_active' => true]
        );

        BusinessCategory::query()->firstOrCreate(
            [
                'business_type_id' => $restaurantType->id,
                'name' => 'Café & bakery',
            ],
            ['sort_order' => 2, 'is_active' => true]
        );

        $cuisineFilipino = Cuisine::query()->firstOrCreate(
            ['name' => 'Filipino'],
            ['sort_order' => 0, 'is_active' => true]
        );

        foreach (
            [
                ['Pizza', 1],
                ['Chinese', 2],
                ['Japanese', 3],
                ['American', 4],
                ['Dessert', 5],
            ] as [$name, $order]
        ) {
            Cuisine::query()->firstOrCreate(
                ['name' => $name],
                ['sort_order' => $order, 'is_active' => true]
            );
        }

        foreach (
            [
                ['Appetizers', 0],
                ['Main course', 1],
                ['Sides', 2],
                ['Desserts', 3],
                ['Beverages', 4],
                ['Combo meals', 5],
            ] as [$name, $order]
        ) {
            MenuCategory::query()->firstOrCreate(
                ['name' => $name],
                ['sort_order' => $order, 'is_active' => true]
            );
        }

        $owner = User::where('email', 'owner@tkimph.com')->first();
        if ($owner) {
            $defaultOpeningHours = collect(range(0, 6))->map(function (int $day) {
                $weekday = $day >= 1 && $day <= 5;

                return [
                    'day' => $day,
                    'closed' => ! $weekday,
                    'open' => $weekday ? '09:00' : null,
                    'close' => $weekday ? '21:00' : null,
                ];
            })->values()->all();

            $demo = Restaurant::query()->firstOrCreate(
                ['user_id' => $owner->id, 'name' => 'Demo Kitchen'],
                [
                    'description' => 'Sample partner restaurant for admin testing.',
                    'phone' => '09171234567',
                    'address' => 'Cebu City, Philippines',
                    'business_type_id' => $restaurantType->id,
                    'business_category_id' => $catFastFood->id,
                    'cuisine_id' => $cuisineFilipino->id,
                    'is_active' => true,
                    'opening_hours' => $defaultOpeningHours,
                ]
            );

            if ($demo->opening_hours === null || $demo->opening_hours === []) {
                $demo->opening_hours = $defaultOpeningHours;
                $demo->saveQuietly();
            }
        }
    }
}
