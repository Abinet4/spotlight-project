<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data only. Demo data is opt-in: php artisan db:seed --class=DemoSeeder
     * Create the first administrator with: php artisan marketplace:create-admin
     */
    public function run(): void
    {
        $this->call([
            LocationSeeder::class,
            CategorySeeder::class,
        ]);
    }
}
