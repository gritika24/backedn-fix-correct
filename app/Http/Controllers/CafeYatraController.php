<?php

namespace App\Http\Controllers;

use App\Http\Requests\CafeYatra\CheckInRequest;
use App\Http\Requests\CafeYatra\CheckoutRequest;
use App\Models\Badge;
use App\Models\Cafe;
use App\Models\CafeReward;
use App\Models\CafeVisit;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserReward;
use App\Services\CafeYatraAchievementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CafeYatraController extends Controller
{
    /** GPS check-in: the customer must be within the configured radius of the café. */
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $cafe = Cafe::approved()->find($validated['cafe_id']);

        if (! $cafe) {
            return response()->json(['message' => 'This café is not available for check-in.'], 404);
        }

        if ($cafe->latitude === null || $cafe->longitude === null) {
            return response()->json(['message' => 'This café does not have a saved GPS location.'], 422);
        }

        $distance = $this->distanceInMetres(
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            (float) $cafe->latitude,
            (float) $cafe->longitude,
        );

        $radius = config('cafeverse.check_in_radius_metres');

        if ($distance > $radius) {
            return response()->json([
                'message' => "You need to be within {$radius} m of this café to check in.",
                'distance_metres' => round($distance, 2),
            ], 403);
        }

        $result = DB::transaction(function () use ($user, $cafe) {
            // Lock the user's row so two quick taps cannot create two visits.
            User::whereKey($user->id)->lockForUpdate()->first();

            // One active visit at a time, at any café.
            $activeVisit = CafeVisit::with('cafe:id,name')
                ->where('user_id', $user->id)
                ->active()
                ->first();

            if ($activeVisit) {
                return $activeVisit->cafe_id === $cafe->id
                    ? 'You already have an active visit at this café.'
                    : "Please check out of {$activeVisit->cafe?->name} before checking in here.";
            }

            if (config('cafeverse.one_visit_per_cafe_per_day') && $this->hasCompletedVisitToday($user->id, $cafe->id)) {
                return "You've already completed today's visit at {$cafe->name}. Come back tomorrow!";
            }

            return CafeVisit::create([
                'user_id' => $user->id,
                'cafe_id' => $cafe->id,
                'check_in_at' => now(),
            ]);
        });

        if (is_string($result)) {
            return response()->json(['message' => $result], 409);
        }

        return response()->json([
            'message' => 'You have successfully checked in!',
            'visit' => [
                'id' => $result->id,
                'cafe_id' => $cafe->id,
                'cafe_name' => $cafe->name,
                'check_in_at' => $result->check_in_at,
            ],
            'distance_metres' => round($distance, 2),
        ], 201);
    }

    /** QR checkout: scanning the café's QR completes the active visit and awards achievements. */
    public function checkout(CheckoutRequest $request, CafeYatraAchievementService $achievementService): JsonResponse
    {
        $user = $request->user();

        $cafe = Cafe::approved()->where('qr_token', $request->validated('qr_token'))->first();

        if (! $cafe) {
            return response()->json(['message' => 'Invalid café QR code.'], 404);
        }

        $result = DB::transaction(function () use ($user, $cafe, $achievementService) {
            $visit = CafeVisit::where('user_id', $user->id)
                ->where('cafe_id', $cafe->id)
                ->active()
                ->lockForUpdate()
                ->latest('check_in_at')
                ->first();

            if (! $visit) {
                return ['error' => 'No active visit found for this café. Please check in first.', 'status' => 404];
            }

            $minimum = config('cafeverse.min_visit_minutes');
            $minutesSoFar = (int) $visit->check_in_at->diffInMinutes(now());

            if ($minutesSoFar < $minimum) {
                $wait = $minimum - $minutesSoFar;

                return [
                    'error' => "Enjoy your café a little longer! You can check out in {$wait} ".Str::plural('minute', $wait).'.',
                    'status' => 422,
                ];
            }

            $visit->update(['check_out_at' => now()]);

            return [
                'visit' => $visit,
                'achievements' => $achievementService->processAchievements($user->id, $cafe->id),
            ];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        $visit = $result['visit'];

        return response()->json([
            'message' => 'Café visit completed successfully!',
            'visit' => [
                'id' => $visit->id,
                'cafe_name' => $cafe->name,
                'check_in_at' => $visit->check_in_at,
                'check_out_at' => $visit->check_out_at,
                'duration_minutes' => $visit->durationMinutes(),
            ],
            'achievements' => $result['achievements'],
        ]);
    }

    /** The customer's current unfinished visit, so the app can resume it after a restart. */
    public function activeVisit(Request $request): JsonResponse
    {
        $visit = CafeVisit::with('cafe:id,name,address,cover_image_path')
            ->where('user_id', $request->user()->id)
            ->active()
            ->latest('check_in_at')
            ->first();

        return response()->json([
            'visit' => $visit ? [
                'id' => $visit->id,
                'cafe_id' => $visit->cafe_id,
                'cafe_name' => $visit->cafe?->name,
                'cafe' => $visit->cafe,
                'check_in_at' => $visit->check_in_at,
            ] : null,
        ]);
    }

    // Get all completed visits for the logged-in customer
    public function completedVisits(Request $request)
    {
        $visits = CafeVisit::where('user_id', $request->user()->id)
            ->whereNotNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->with(['cafe', 'memories', 'review'])
            ->latest('check_out_at')
            ->get();

        return response()->json([
            'message' => 'Completed café visits retrieved successfully.',
            'visits' => $visits->map(function ($visit) {
                return [
                    'id' => $visit->id,
                    'cafe_id' => $visit->cafe_id,
                    'cafe_name' => $visit->cafe->name ?? 'Unknown Café',
                    'cafe' => $visit->cafe ? [
                        'id' => $visit->cafe->id,
                        'name' => $visit->cafe->name,
                        'address' => $visit->cafe->address,
                        'cover_image_path' => $visit->cafe->cover_image_path,
                        'is_verified' => $visit->cafe->status === 'approved',
                    ] : null,
                    'check_in_at' => $visit->check_in_at,
                    'check_out_at' => $visit->check_out_at,
                    'duration_minutes' => $visit->durationMinutes(),
                    'has_review' => $visit->review !== null,
                    'review_id' => $visit->review?->id,
                    'memories' => $visit->memories,
                ];
            }),
        ], 200);
    }

    // Get all earned badges for the logged-in customer
    public function earnedBadges(Request $request)
    {
        $badges = UserBadge::where('user_id', $request->user()->id)
            ->with(['badge', 'cafe'])
            ->latest('earned_at')
            ->get();

        return response()->json([
            'message' => 'Earned badges retrieved successfully.',
            'badges' => $badges->map(function ($userBadge) {
                return [
                    'id' => $userBadge->id,
                    'badge_id' => $userBadge->badge_id,
                    'name' => $userBadge->badge->name,
                    'code' => $userBadge->badge->code,
                    'description' => $userBadge->badge->description,
                    'icon' => $userBadge->badge->icon,
                    'required_visits' => $userBadge->badge->required_visits,
                    'cafe_id' => $userBadge->cafe_id,
                    'cafe_name' => $userBadge->cafe->name ?? 'Unknown Café',
                    'earned_at' => $userBadge->earned_at,
                ];
            }),
        ], 200);
    }

    // Get all badges and their earned status for one café
    public function cafeBadges(Request $request, int $cafeId)
    {
        // Make sure the café exists
        $cafe = Cafe::approved()->findOrFail($cafeId);

        $userId = $request->user()->id;

        // Count this customer's completed visits at this café
        $completedVisits = CafeVisit::where('user_id', $userId)
            ->where('cafe_id', $cafeId)
            ->whereNotNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->count();

        // Retrieve badges already earned by this customer
        // at this particular café
        $userBadges = UserBadge::where('user_id', $userId)
            ->where('cafe_id', $cafeId)
            ->get()
            ->keyBy('badge_id');

        // Retrieve all standard badge definitions
        $badges = Badge::orderBy('required_visits')
            ->get()
            ->map(function ($badge) use ($userBadges, $completedVisits) {

                $earnedBadge = $userBadges->get($badge->id);

                return [
                    'id' => $badge->id,
                    'name' => $badge->name,
                    'code' => $badge->code,
                    'description' => $badge->description,
                    'icon' => $badge->icon,
                    'required_visits' => $badge->required_visits,

                    // True only if an earned badge record exists
                    'is_earned' => $earnedBadge !== null,

                    'earned_at' => $earnedBadge?->earned_at,

                    'completed_visits' => $completedVisits,

                    'visits_remaining' => max(
                        0,
                        (int) $badge->required_visits - $completedVisits
                    ),
                ];
            });

        return response()->json([
            'message' => 'Café badges retrieved successfully.',
            'cafe_id' => $cafeId,
            'cafe_name' => $cafe->name,
            'completed_visits' => $completedVisits,
            'badges' => $badges,
        ], 200);
    }

    // Get all active rewards and their status for one café
    public function cafeRewards(Request $request, int $cafeId)
    {
        // Make sure the café exists
        $cafe = Cafe::approved()->findOrFail($cafeId);

        $userId = $request->user()->id;

        // Count this customer's completed visits at this café
        $completedVisits = CafeVisit::where('user_id', $userId)
            ->where('cafe_id', $cafeId)
            ->whereNotNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->count();

        // Retrieve all active rewards created by this café
        $rewards = CafeReward::where('cafe_id', $cafeId)
            ->where('is_active', true)
            ->where('required_visits', '>', 0)
            ->orderBy('required_visits')
            ->get();

        // Retrieve this customer's reward unlock records
        $userRewards = UserReward::where('user_id', $userId)
            ->where('cafe_id', $cafeId)
            ->get()
            ->groupBy('cafe_reward_id');

        // Prepare each reward with its progress and unlock history
        $rewardData = $rewards->map(function ($reward) use (
            $userRewards,
            $completedVisits
        ) {
            $requiredVisits = (int) $reward->required_visits;

            $rewardUnlocks = $userRewards->get($reward->id, collect())
                ->sortBy('cycle_number')
                ->values();

            $latestUnlock = $rewardUnlocks->last();

            // Default reward status
            $status = 'in_progress';
            $visitsRemaining = max(
                0,
                $requiredVisits - $completedVisits
            );

            if ($latestUnlock && ! $latestUnlock->redeemed_at) {

                // Reward is unlocked but not yet redeemed
                $status = 'unlocked';
                $visitsRemaining = 0;

            } elseif (! $reward->is_repeatable && $latestUnlock) {

                // One-time reward has already been redeemed
                $status = 'redeemed';
                $visitsRemaining = 0;

            } elseif ($reward->is_repeatable) {

                // Calculate progress towards the next reward cycle
                $nextCycle = (intdiv(
                    $completedVisits,
                    $requiredVisits
                )) + 1;

                $visitsRemaining = max(
                    0,
                    ($nextCycle * $requiredVisits) - $completedVisits
                );
            }

            return [
                'id' => $reward->id,
                'title' => $reward->title,
                'description' => $reward->description,
                'reward_type' => $reward->reward_type,
                'required_visits' => $requiredVisits,
                'is_repeatable' => $reward->is_repeatable,

                'completed_visits' => $completedVisits,
                'visits_remaining' => $visitsRemaining,

                'status' => $status,

                'current_cycle' => $latestUnlock?->cycle_number,

                'unlocked_at' => $latestUnlock?->unlocked_at,
                'redeemed_at' => $latestUnlock?->redeemed_at,

                // Keep each reward cycle separate
                'unlock_history' => $rewardUnlocks->map(function ($userReward) {
                    return [
                        'id' => $userReward->id,
                        'cycle_number' => $userReward->cycle_number,
                        'redemption_token' => $userReward->redemption_token,
                        'unlocked_at' => $userReward->unlocked_at,
                        'redeemed_at' => $userReward->redeemed_at,
                        'is_redeemed' => $userReward->redeemed_at !== null,
                    ];
                })->values(),
            ];
        });

        return response()->json([
            'message' => 'Café rewards retrieved successfully.',
            'cafe_id' => $cafeId,
            'cafe_name' => $cafe->name,
            'completed_visits' => $completedVisits,
            'rewards' => $rewardData,
        ], 200);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Great-circle (haversine) distance between two GPS points, in metres. */
    private function distanceInMetres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earthRadius * asin(min(1, sqrt($a)));
    }

    /** "Today" is measured in the café's local time zone (Nepal by default). */
    private function hasCompletedVisitToday(int $userId, int $cafeId): bool
    {
        // Start of today in the café's zone, converted to the zone dates are stored in.
        $startOfDay = Carbon::now(config('cafeverse.timezone'))
            ->startOfDay()
            ->setTimezone(config('app.timezone'));

        return CafeVisit::where('user_id', $userId)
            ->where('cafe_id', $cafeId)
            ->where('check_out_at', '>=', $startOfDay)
            ->exists();
    }
}
