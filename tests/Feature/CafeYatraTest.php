<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\CafeVisit;
use App\Models\User;
use App\Models\UserReward;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CafeYatraTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    private Cafe $cafe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);

        $this->owner = User::factory()->owner()->create();
        $this->customer = User::factory()->create();

        $this->cafe = Cafe::create([
            'user_id' => $this->owner->id,
            'name' => 'Himalayan Java',
            'description' => 'Coffee',
            'contact_number' => '9800000000',
            'address' => 'Thamel',
            'latitude' => 27.7150000,
            'longitude' => 85.3120000,
            'status' => 'approved',
        ]);
        $this->cafe->forceFill(['qr_token' => (string) Str::uuid()])->save();
    }

    private function checkIn(?Cafe $cafe = null, float $lat = 27.7150, float $lng = 85.3120)
    {
        return $this->actingAs($this->customer, 'sanctum')->postJson('/api/cafe-yatra/check-in', [
            'cafe_id' => ($cafe ?? $this->cafe)->id,
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    private function checkout()
    {
        return $this->actingAs($this->customer, 'sanctum')->postJson('/api/cafe-yatra/checkout', [
            'qr_token' => $this->cafe->qr_token,
        ]);
    }

    public function test_customers_never_receive_the_cafe_qr_token(): void
    {
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/cafes')
            ->assertOk()
            ->assertJsonMissingPath('cafes.0.qr_token');

        $this->actingAs($this->customer, 'sanctum')->getJson("/api/cafes/{$this->cafe->id}")
            ->assertOk()
            ->assertJsonMissingPath('cafe.qr_token');
    }

    public function test_owner_can_see_their_own_qr_token(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/my-cafe-qrs')
            ->assertOk()
            ->assertJsonPath('cafes.0.qr_token', $this->cafe->qr_token);
    }

    public function test_check_in_requires_being_near_the_cafe(): void
    {
        $this->checkIn(lat: 27.70, lng: 85.30)->assertForbidden();
        $this->checkIn()->assertCreated();
    }

    public function test_checkout_too_soon_is_refused(): void
    {
        config(['cafeverse.min_visit_minutes' => 5]);
        $this->checkIn()->assertCreated();

        $this->checkout()->assertUnprocessable();

        $this->travel(6)->minutes();
        $this->checkout()->assertOk()->assertJsonPath('achievements.badges_earned', 1);
    }

    public function test_only_one_active_visit_at_a_time(): void
    {
        $other = $this->cafe->replicate()->fill(['name' => 'Brew & Books']);
        $other->qr_token = (string) Str::uuid();
        $other->save();

        $this->checkIn()->assertCreated();
        $this->checkIn($other)->assertConflict();
    }

    public function test_one_counted_visit_per_cafe_per_day(): void
    {
        config(['cafeverse.min_visit_minutes' => 0]);

        $this->checkIn()->assertCreated();
        $this->checkout()->assertOk();

        $this->checkIn()->assertConflict();

        $this->travel(1)->days();
        $this->checkIn()->assertCreated();
    }

    public function test_reward_unlocks_and_can_be_redeemed_only_once(): void
    {
        config(['cafeverse.min_visit_minutes' => 0, 'cafeverse.one_visit_per_cafe_per_day' => false]);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/owner/cafes/{$this->cafe->id}/rewards", [
            'title' => 'Free latte',
            'reward_type' => 'free_item',
            'required_visits' => 2,
        ])->assertCreated()->assertJsonPath('reward.is_active', true);

        // The customer was told about the new reward.
        $this->assertDatabaseHas('notifications', ['user_id' => $this->customer->id, 'type' => 'cafe_reward_available']);

        foreach ([1, 2] as $visit) {
            $this->checkIn()->assertCreated();
            $this->checkout()->assertOk();
        }

        $token = UserReward::where('user_id', $this->customer->id)->value('redemption_token');
        $this->assertNotNull($token);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/owner/rewards/confirm-redemption', ['redemption_token' => $token])
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/owner/rewards/confirm-redemption', ['redemption_token' => $token])
            ->assertConflict();
    }

    public function test_another_owner_cannot_redeem_this_cafes_reward(): void
    {
        $reward = $this->cafe->rewards()->create(['title' => 'Cookie', 'reward_type' => 'free_item', 'required_visits' => 1]);
        $userReward = UserReward::create([
            'user_id' => $this->customer->id,
            'cafe_reward_id' => $reward->id,
            'cafe_id' => $this->cafe->id,
            'cycle_number' => 1,
            'redemption_token' => Str::random(64),
            'unlocked_at' => now(),
        ]);

        $this->actingAs(User::factory()->owner()->create(), 'sanctum')
            ->postJson('/api/owner/rewards/confirm-redemption', ['redemption_token' => $userReward->redemption_token])
            ->assertNotFound();

        $this->assertNull($userReward->fresh()->redeemed_at);
    }

    public function test_active_visit_endpoint_restores_an_open_visit(): void
    {
        $this->checkIn()->assertCreated();

        $this->actingAs($this->customer, 'sanctum')->getJson('/api/cafe-yatra/active-visit')
            ->assertOk()
            ->assertJsonPath('visit.cafe_id', $this->cafe->id);
    }

    public function test_abandoned_visits_do_not_block_new_check_ins(): void
    {
        CafeVisit::create(['user_id' => $this->customer->id, 'cafe_id' => $this->cafe->id, 'check_in_at' => now()->subDay()]);

        $this->checkIn()->assertCreated();
    }
}
