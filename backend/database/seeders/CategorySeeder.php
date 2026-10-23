<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Starting furniture categories. Administrators manage them afterwards from the admin area.
 * Safe to re-run: existing categories (matched by slug) are left untouched.
 */
class CategorySeeder extends Seeder
{
    public const CATEGORIES = [
        ['sofas-living-room', 'Sofas & Living Room', 'Sofas, sectionals, armchairs, coffee tables and TV stands.'],
        ['beds-mattresses', 'Beds & Mattresses', 'Bed frames, mattresses, bunk beds and headboards.'],
        ['wardrobes-storage', 'Wardrobes & Storage', 'Wardrobes, dressers, chests and storage units.'],
        ['dining', 'Dining Tables & Chairs', 'Dining sets, tables, chairs and benches.'],
        ['office', 'Office Desks & Chairs', 'Desks, office chairs, filing cabinets and meeting tables.'],
        ['cabinets-shelves', 'Cabinets & Shelves', 'Kitchen cabinets, bookshelves, display cabinets and wall shelves.'],
        ['other-furniture', 'Other Furniture', 'Outdoor, kids and other approved furniture.'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $i => [$slug, $name, $description]) {
            Category::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description, 'is_active' => true, 'sort_order' => ($i + 1) * 10],
            );
        }
    }
}
