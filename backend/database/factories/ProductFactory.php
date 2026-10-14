<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->words(3, true)).' Sofa';

        return [
            'seller_profile_id' => SellerProfile::factory()->approved(),
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->paragraph(),
            'price_santim' => fake()->numberBetween(5_000, 150_000) * 100,
            'condition' => 'new',
            'stock_type' => 'in_stock',
            'stock_quantity' => 5,
            'material' => 'Wood',
            'color' => 'Brown',
            'offers_delivery' => true,
            'status' => 'draft',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => 'published', 'published_at' => now()])
            ->afterCreating(fn (Product $p) => $p->images()->create(['path' => "products/{$p->id}/test.jpg", 'sort_order' => 0]));
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => ['status' => 'pending_review'])
            ->afterCreating(fn (Product $p) => $p->images()->create(['path' => "products/{$p->id}/test.jpg", 'sort_order' => 0]));
    }
}
