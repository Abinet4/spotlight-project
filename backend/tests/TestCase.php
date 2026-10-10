<?php

namespace Tests;

use App\Models\Area;
use App\Models\Category;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    protected function seedReferenceData(): void
    {
        $this->seed([LocationSeeder::class, CategorySeeder::class]);
    }

    protected function area(string $slug = 'bole'): Area
    {
        return Area::where('slug', $slug)->firstOrFail();
    }

    protected function category(string $slug = 'sofas-living-room'): Category
    {
        return Category::where('slug', $slug)->firstOrFail();
    }

    protected function customer(): User
    {
        return User::factory()->create();
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function approvedSeller(): SellerProfile
    {
        return SellerProfile::factory()->approved()->create(['area_id' => $this->area()->id]);
    }

    protected function pendingSeller(): SellerProfile
    {
        return SellerProfile::factory()->create(['area_id' => $this->area()->id]);
    }

    protected function photo(string $name = 'sofa.jpg', int $kb = 200): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 600)->size($kb);
    }

    /**
     * @return array<string, mixed>
     */
    protected function productPayload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category()->id,
            'name' => 'Three-Seater Fabric Sofa',
            'description' => 'Comfortable three seater sofa with removable covers.',
            'price' => '38500',
            'condition' => 'new',
            'stock_type' => 'in_stock',
            'stock_quantity' => 4,
            'material' => 'Fabric',
            'color' => 'Grey',
            'offers_delivery' => true,
        ], $overrides);
    }
}
