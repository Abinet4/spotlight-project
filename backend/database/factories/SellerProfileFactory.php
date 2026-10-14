<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\City;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SellerProfile>
 */
class SellerProfileFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' Furniture';

        return [
            'user_id' => User::factory()->seller(),
            'store_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(12),
            'business_type' => 'workshop',
            'phone' => '+2519'.fake()->numerify('########'),
            'city_id' => fn () => City::firstOrCreate(['slug' => 'addis-ababa'], ['name' => 'Addis Ababa'])->id,
            'area_id' => fn (array $attrs) => Area::firstOrCreate(
                ['city_id' => $attrs['city_id'], 'slug' => 'bole'],
                ['name' => 'Bole'],
            )->id,
            'offers_custom_orders' => true,
            'offers_delivery' => true,
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'reviewed_at' => now()]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended', 'reviewed_at' => now()]);
    }
}
