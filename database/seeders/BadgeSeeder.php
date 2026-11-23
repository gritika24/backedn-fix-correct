<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            [
                'code' => 'first_visit',
                'name' => 'First Visit',
                'description' => 'Complete your first visit to this café.',
                'icon' => 'first_visit',
                'required_visits' => 1,
            ],
            [
                'code' => 'loyal_visitor',
                'name' => 'Loyal Visitor',
                'description' => 'Complete 3 visits to this café.',
                'icon' => 'loyal_visitor',
                'required_visits' => 3,
            ],
            [
                'code' => 'cafe_regular',
                'name' => 'Café Regular',
                'description' => 'Complete 5 visits to this café.',
                'icon' => 'cafe_regular',
                'required_visits' => 5,
            ],
            [
                'code' => 'loyalty_champion',
                'name' => 'Loyalty Champion',
                'description' => 'Complete 10 visits to this café.',
                'icon' => 'loyalty_champion',
                'required_visits' => 10,
            ],
        ];

        foreach ($badges as $badge) {
            Badge::updateOrCreate(
                ['code' => $badge['code']],
                $badge
            );
        }
    }
}
