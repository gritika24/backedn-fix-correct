<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reward\RedemptionRequest;
use App\Http\Requests\Reward\RewardRequest;
use App\Models\Cafe;
use App\Models\CafeReward;
use App\Models\CafeVisit;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserReward;
use App\Services\CafeYatraAchievementService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CafeRewardController extends Controller
{
    /** Changing any of these can unlock rewards for past visits. */
    private const UNLOCK_FIELDS = ['required_visits', 'is_active', 'is_repeatable'];

    public function __construct(
        private readonly CafeYatraAchievementService $achievements,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request, int $cafeId): JsonResponse
    {
        $cafe = $this->ownedApprovedCafe($request, $cafeId);

        return response()->json([
            'message' => 'Café rewards retrieved successfully.',
            'cafe_id' => $cafe->id,
            'cafe_name' => $cafe->name,
            'rewards' => $cafe->rewards()->latest()->get(),
        ]);
    }

    public function store(RewardRequest $request, int $cafeId): JsonResponse
    {
        $cafe = $this->ownedApprovedCafe($request, $cafeId);

        $reward = DB::transaction(function () use ($cafe, $request) {
            $reward = $cafe->rewards()->create($request->validated())->fresh();

            // Customers who already have enough visits unlock it straight away.
            $this->processExistingCustomers($cafe);

            return $reward;
        });

        if ($reward->is_active) {
            $this->announceReward($cafe, $reward);
        }

        return response()->json([
            'message' => 'Reward created successfully.',
            'reward' => $reward,
        ], 201);
    }

    public function update(RewardRequest $request, int $rewardId): JsonResponse
    {
        $cafe = $this->ownedApprovedCafe($request, (int) $request->validated('cafe_id'));
        $reward = $cafe->rewards()->findOrFail($rewardId);

        $data = collect($request->validated())->except('cafe_id')->all();
        $wasActive = (bool) $reward->is_active;

        DB::transaction(function () use ($reward, $data, $cafe) {
            $reward->update($data);

            if ($reward->wasChanged(self::UNLOCK_FIELDS)) {
                $this->processExistingCustomers($cafe);
            }
        });

        if (! $wasActive && $reward->is_active) {
            $this->announceReward($cafe, $reward);
        }

        return response()->json([
            'message' => 'Reward updated successfully.',
            'reward' => $reward->fresh(),
        ]);
    }

    /** Rewards that customers have already unlocked are kept for their history. */
    public function destroy(Request $request, int $rewardId): JsonResponse
    {
        $validated = $request->validate(['cafe_id' => ['required', 'integer']]);

        $cafe = $this->ownedApprovedCafe($request, (int) $validated['cafe_id']);
        $reward = $cafe->rewards()->findOrFail($rewardId);

        if ($reward->userRewards()->exists()) {
            return response()->json([
                'message' => 'This reward has customer history and cannot be deleted. You can deactivate it instead.',
            ], 422);
        }

        $reward->delete();

        return response()->json(['message' => 'Reward deleted successfully.']);
    }

    /** Step 1 of redemption: the owner scans the customer's reward QR and sees what it is. */
    public function verifyRedemption(RedemptionRequest $request): JsonResponse
    {
        $userReward = UserReward::with(['user:id,name', 'cafeReward'])
            ->where('redemption_token', $request->validated('redemption_token'))
            ->first();

        // A reward from another owner's café is reported as "invalid" too,
        // so owners cannot probe other cafés' reward codes.
        $cafe = $userReward ? $this->findOwnedApprovedCafe($request, $userReward->cafe_id) : null;

        if (! $userReward || ! $cafe) {
            return response()->json(['message' => 'Invalid reward QR code.'], 404);
        }

        if ($userReward->redeemed_at !== null) {
            return response()->json([
                'message' => 'This reward has already been redeemed.',
                'redeemed_at' => $userReward->redeemed_at,
            ], 409);
        }

        return response()->json([
            'message' => 'Reward verified successfully.',
            'reward' => [
                'user_reward_id' => $userReward->id,
                'title' => $userReward->cafeReward->title,
                'description' => $userReward->cafeReward->description,
                'reward_type' => $userReward->cafeReward->reward_type,
                'cycle_number' => $userReward->cycle_number,
                'customer_name' => $userReward->user->name,
                'cafe_name' => $cafe->name,
                'unlocked_at' => $userReward->unlocked_at,
            ],
        ]);
    }

    /** Step 2: the owner confirms. The row lock stops the same QR being redeemed twice. */
    public function confirmRedemption(RedemptionRequest $request): JsonResponse
    {
        $result = DB::transaction(function () use ($request) {
            $userReward = UserReward::with('cafeReward:id,title')
                ->where('redemption_token', $request->validated('redemption_token'))
                ->lockForUpdate()
                ->first();

            $cafe = $userReward ? $this->findOwnedApprovedCafe($request, $userReward->cafe_id) : null;

            if (! $userReward || ! $cafe) {
                return response()->json(['message' => 'Invalid reward QR code.'], 404);
            }

            if ($userReward->redeemed_at !== null) {
                return response()->json([
                    'message' => 'This reward has already been redeemed.',
                    'redeemed_at' => $userReward->redeemed_at,
                ], 409);
            }

            $userReward->update([
                'redeemed_at' => now(),
                'redeemed_by' => $request->user()->id,
            ]);

            $this->notifications->send(
                $userReward->user_id,
                'cafe_reward_redeemed',
                'Reward redeemed',
                "You redeemed \"{$userReward->cafeReward?->title}\" at {$cafe->name}. Enjoy!",
                UserReward::class,
                $userReward->id,
                ['cafe_id' => $cafe->id, 'cafe_name' => $cafe->name],
            );

            return response()->json([
                'message' => 'Reward redeemed successfully.',
                'redeemed_at' => $userReward->redeemed_at,
            ]);
        });

        return $result;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function ownedApprovedCafe(Request $request, int $cafeId): Cafe
    {
        return $request->user()->cafes()->approved()->findOrFail($cafeId);
    }

    private function findOwnedApprovedCafe(Request $request, int $cafeId): ?Cafe
    {
        return $request->user()->cafes()->approved()->find($cafeId);
    }

    /** Re-checks badges and rewards for every customer with a completed visit here. */
    private function processExistingCustomers(Cafe $cafe): void
    {
        CafeVisit::where('cafe_id', $cafe->id)
            ->completed()
            ->distinct()
            ->pluck('user_id')
            ->each(fn ($userId) => $this->achievements->processAchievements((int) $userId, $cafe->id));
    }

    /**
     * Tells customers about a new reward, skipping anyone who already
     * unlocked it or was already told. Two set-based queries plus bulk
     * inserts, instead of two queries per customer.
     */
    private function announceReward(Cafe $cafe, CafeReward $reward): void
    {
        $recipients = User::where('role', 'user')
            ->whereNotIn('id', UserReward::where('cafe_reward_id', $reward->id)->select('user_id'))
            ->whereNotIn('id', Notification::where('type', 'cafe_reward_available')
                ->where('related_type', CafeReward::class)
                ->where('related_id', $reward->id)
                ->select('user_id'))
            ->pluck('id');

        $this->notifications->sendToMany(
            $recipients,
            'cafe_reward_available',
            'A new café reward awaits!',
            "Visit {$cafe->name}! Complete {$reward->required_visits} visits to unlock \"{$reward->title}\". Join Café Yatra and be part of the experience!",
            CafeReward::class,
            $reward->id,
            [
                'cafe_id' => $cafe->id,
                'cafe_name' => $cafe->name,
                'cafe_reward_id' => $reward->id,
                'reward_title' => $reward->title,
                'required_visits' => $reward->required_visits,
                'reward_type' => $reward->reward_type,
            ],
        );
    }
}
