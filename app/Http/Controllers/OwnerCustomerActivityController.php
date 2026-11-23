<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UserListRequest;
use App\Models\Cafe;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerCustomerActivityController extends Controller
{
    /**
     * Get customers who have visited cafés owned by the
     * currently authenticated café owner.
     */
    public function index(UserListRequest $request): JsonResponse
    {
        $search = $request->validated('search');

        // Get only cafés belonging to the logged-in owner.
        $cafeIds = Cafe::where('user_id', $request->user()->id)
            ->pluck('id');

        // Retrieve customers who visited at least one owned café.
        $customers = User::where('role', 'user')
            ->select(['id', 'name', 'email', 'profile_image', 'created_at'])
            ->whereHas('cafeVisits', fn ($query) => $query->whereIn('cafe_id', $cafeIds))
            ->search(['name', 'email'], $search)
            ->withCount([
                'cafeVisits as owner_cafe_visits_count' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds);
                },

                'cafeVisits as completed_visits_count' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds)
                        ->whereNotNull('check_out_at');
                },

                'userBadges as owner_cafe_badges_count' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds);
                },

                'userRewards as owner_cafe_rewards_count' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds);
                },
            ])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'message' => 'Owner customers retrieved successfully',
            'customers' => $customers,
        ], 200);
    }

    /**
     * Get one customer's activity across the authenticated
     * owner's cafés only.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        // Get only cafés belonging to the logged-in owner.
        $cafeIds = Cafe::where('user_id', $request->user()->id)
            ->pluck('id');

        // The customer must have visited one of the owner's cafés.
        $customer = User::where('role', 'user')
            ->whereHas('cafeVisits', function ($query) use ($cafeIds) {
                $query->whereIn('cafe_id', $cafeIds);
            })
            ->withCount([
                'cafeVisits as owner_cafe_visits_count' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds);
                },

                'cafeVisits as completed_visits_count' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds)
                        ->whereNotNull('check_out_at');
                },

                'userBadges as owner_cafe_badges_count' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds);
                },

                'userRewards as owner_cafe_rewards_count' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds);
                },
            ])
            ->with([
                // Only visits to the authenticated owner's cafés.
                'cafeVisits' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds)
                        ->select([
                            'id',
                            'user_id',
                            'cafe_id',
                            'check_in_at',
                            'check_out_at',
                            'created_at',
                            'updated_at',
                        ])
                        ->with([
                            'cafe:id,name,address,logo_path,cover_image_path',
                        ])
                        ->orderByDesc('check_in_at');
                },

                // Only badges earned at the owner's cafés.
                'userBadges' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds)
                        ->with([
                            'badge',
                            'cafe:id,name,address,logo_path',
                        ]);
                },

                // Only rewards unlocked at the owner's cafés.
                // redemption_token is deliberately excluded.
                'userRewards' => function ($query) use ($cafeIds) {
                    $query->whereIn('cafe_id', $cafeIds)
                        ->select([
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
                        ])
                        ->with([
                            'cafeReward:id,cafe_id,title,description,reward_type,required_visits,is_active,is_repeatable',
                            'cafe:id,name,address,logo_path',
                        ]);
                },
            ])
            ->findOrFail($id);

        // Return only the customer information needed by the owner.
        return response()->json([
            'message' => 'Customer activity retrieved successfully',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'profile_image' => $customer->profile_image,
                'created_at' => $customer->created_at,
                'owner_cafe_visits_count' => $customer->owner_cafe_visits_count,
                'completed_visits_count' => $customer->completed_visits_count,
                'owner_cafe_badges_count' => $customer->owner_cafe_badges_count,
                'owner_cafe_rewards_count' => $customer->owner_cafe_rewards_count,
                'cafe_visits' => $customer->cafeVisits,
                'user_badges' => $customer->userBadges,
                'user_rewards' => $customer->userRewards,
            ],
        ], 200);
    }
}
