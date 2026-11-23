<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Badges are required for Café Yatra to award anything, and the admin
     * account is needed to approve cafés. Both seeders are safe to re-run.
     */
    public function run(): void
    {
        $this->call([
            BadgeSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
