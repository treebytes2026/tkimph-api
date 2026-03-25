<?php

namespace Database\Seeders;

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
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@tkimph.com',
            'password' => Hash::make('treebytex2026'),
            'role' => User::ROLE_ADMIN,
        ]);

        User::factory()->create([
            'name' => 'Restaurant Owner',
            'email' => 'owner@tkimph.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_RESTAURANT_OWNER,
        ]);

        User::factory()->create([
            'name' => 'Rider User',
            'email' => 'rider@tkimph.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_RIDER,
        ]);

        User::factory()->create([
            'name' => 'Customer User',
            'email' => 'customer@tkimph.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_CUSTOMER,
        ]);

        $owner = User::where('email', 'owner@tkimph.com')->first();
        if ($owner) {
            Restaurant::query()->firstOrCreate(
                ['user_id' => $owner->id, 'name' => 'Demo Kitchen'],
                [
                    'description' => 'Sample partner restaurant for admin testing.',
                    'phone' => '09171234567',
                    'address' => 'Cebu City, Philippines',
                    'is_active' => true,
                ]
            );
        }
    }
}
