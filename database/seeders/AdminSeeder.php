<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the admin account from ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD
 * in .env. Admins cannot sign up through the app, so this is the only way
 * to create one. Run: php artisan db:seed --class=AdminSeeder
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set in .env – admin account skipped.');

            return;
        }

        $admin = User::firstOrNew(['email' => strtolower($email)]);
        $admin->forceFill([
            'name' => env('ADMIN_NAME', 'CaféVerse Admin'),
            'role' => 'admin',
            'password' => $password,
        ])->save();

        $this->command?->info("Admin account ready: {$admin->email}");
    }
}
