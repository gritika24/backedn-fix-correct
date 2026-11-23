<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\CafeReview;
use App\Models\CafeVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function cafe(User $owner, string $status = 'approved'): Cafe
    {
        return Cafe::create([
            'user_id' => $owner->id,
            'name' => 'Café '.$owner->id,
            'description' => 'Coffee',
            'contact_number' => '9800000000',
            'address' => 'Lalitpur',
            'status' => $status,
        ]);
    }

    public function test_roles_are_enforced(): void
    {
        $customer = User::factory()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($customer, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($customer, 'sanctum')->getJson('/api/my-cafes')->assertForbidden();
        $this->actingAs($owner, 'sanctum')->postJson('/api/cafe-yatra/check-in', [])->assertForbidden();
    }

    public function test_owner_cannot_edit_or_delete_another_owners_cafe(): void
    {
        $cafe = $this->cafe(User::factory()->owner()->create());
        $intruder = User::factory()->owner()->create();

        $this->actingAs($intruder, 'sanctum')->putJson("/api/cafes/{$cafe->id}", ['name' => 'Hacked'])
            ->assertNotFound()
            ->assertJsonPath('message', 'The requested item was not found.');

        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/cafes/{$cafe->id}")->assertNotFound();
        $this->assertSame('Café '.$cafe->user_id, $cafe->fresh()->name);
    }

    public function test_pending_cafes_are_hidden_from_customers(): void
    {
        $pending = $this->cafe(User::factory()->owner()->create(), 'pending');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/cafes/{$pending->id}")
            ->assertNotFound();
    }

    public function test_admin_approval_creates_qr_and_notifies_owner(): void
    {
        $owner = User::factory()->owner()->create();
        $cafe = $this->cafe($owner, 'pending');

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->patchJson("/api/admin/cafes/{$cafe->id}/approve")
            ->assertOk();

        $this->assertNotNull($cafe->fresh()->qr_token);
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'cafe_approved']);
    }

    public function test_only_reviewer_and_owner_can_join_a_review_discussion(): void
    {
        $owner = User::factory()->owner()->create();
        $customer = User::factory()->create();
        $cafe = $this->cafe($owner);
        $visit = CafeVisit::create(['user_id' => $customer->id, 'cafe_id' => $cafe->id, 'check_in_at' => now()->subHour(), 'check_out_at' => now()]);
        $review = CafeReview::create(['cafe_visit_id' => $visit->id, 'rating' => 4, 'comment' => 'Nice']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/cafe-reviews/{$review->id}/comments", ['comment' => 'Thank you!'])
            ->assertCreated()
            ->assertJsonPath('comment.author_role', 'cafe_owner');

        $this->assertDatabaseHas('notifications', ['user_id' => $customer->id, 'type' => 'new_review_comment']);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/cafe-reviews/{$review->id}/comments", ['comment' => 'Spam'])
            ->assertForbidden();

        $this->actingAs($customer, 'sanctum')->getJson("/api/cafes/{$cafe->id}/reviews")
            ->assertOk()
            ->assertJsonPath('reviews.0.is_mine', true)
            ->assertJsonPath('reviews.0.comments.0.comment', 'Thank you!');
    }

    public function test_admin_can_search_and_filter_users(): void
    {
        User::factory()->create(['name' => 'Aarati Gurung']);
        User::factory()->owner()->create(['name' => 'Rajesh Maharjan']);

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/admin/users?role=cafe_owner')
            ->assertOk()
            ->assertJsonPath('users.total', 1)
            ->assertJsonPath('users.data.0.name', 'Rajesh Maharjan');

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/admin/users?search=aarati')
            ->assertJsonPath('users.total', 1);
    }

    public function test_non_numeric_ids_return_404_not_500(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/cafes/abc')->assertNotFound();
    }

    public function test_search_treats_wildcards_as_plain_text(): void
    {
        User::factory()->create(['name' => 'Aarati Gurung']);
        User::factory()->create(['name' => '100% Coffee Fan']);
        $admin = User::factory()->admin()->create(['name' => 'Admin']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?search=%25')
            ->assertOk()
            ->assertJsonPath('users.total', 1)
            ->assertJsonPath('users.data.0.name', '100% Coffee Fan');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?search=GURUNG')
            ->assertJsonPath('users.total', 1);
    }
}
