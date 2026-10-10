<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Addis Ababa and its sub-cities. Safe to re-run: existing rows are kept.
 */
class LocationSeeder extends Seeder
{
    public const ADDIS_SUB_CITIES = [
        'Addis Ketema', 'Akaky Kaliti', 'Arada', 'Bole', 'Gulele', 'Kirkos',
        'Kolfe Keranio', 'Lemi Kura', 'Lideta', 'Nifas Silk-Lafto', 'Yeka',
    ];

    public function run(): void
    {
        $city = City::firstOrCreate(['slug' => 'addis-ababa'], ['name' => 'Addis Ababa', 'is_active' => true]);

        foreach (self::ADDIS_SUB_CITIES as $name) {
            Area::firstOrCreate(
                ['city_id' => $city->id, 'slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true],
            );
        }
    }
}
