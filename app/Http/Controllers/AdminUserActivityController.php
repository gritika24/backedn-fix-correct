<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UserListRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminUserActivityController extends Controller
{
    /**
     * Get all registered users with their activity summaries.
     */
    public function index(UserListRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $users = User::query()
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->search(['name', 'email'], $filters['search'] ?? null)
            ->withCount([
                'cafeVisits as completed_visits_count' => function ($query) {
                    $query->whereNotNull('check_out_at');
                },
                'userBadges',
                'userRewards',
                'cafes',
            ])
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'message' => 'Users and activity retrieved successfully',
            'users' => $users,
        ], 200);
    }

    /**
     * Get an individual user's details and activity history.
     */
    public function show(int $id): JsonResponse
    {
        $user = User::withCount([
            'cafeVisits as completed_visits_count' => function ($query) {
                $query->whereNotNull('check_out_at');
            },
            'userBadges',
            'userRewards',
        ])
            ->with([
                'cafeVisits' => function ($query) {
                    $query->whereNotNull('check_out_at')
                        ->with([
                            'cafe:id,name,address,logo_path,cover_image_path',
                        ])
                        ->orderByDesc('check_out_at');
                },

                'userBadges.badge',

                'userBadges.cafe:id,name,address,logo_path',

                'userRewards' => function ($query) {
                    $query->select([
                        'id',
                        'user_id',
                        'cafe_reward_id',
                        'cafe_id',
                        'cycle_number',
                        'unlocked_at',
                        'redeemed_at',
                        'redeemed_by',
                        'created_at',
                        'updated_at',
                    ])->with([
                        'cafeReward:id,cafe_id,title,description,reward_type,required_visits,is_active,is_repeatable',
                        'cafe:id,name,address,logo_path',
                    ]);
                },
            ])
            ->findOrFail($id);

        return response()->json([
            'message' => 'User activity retrieved successfully',
            'user' => $user,
        ], 200);
    }
}
