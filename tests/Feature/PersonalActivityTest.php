<?php

namespace Tests\Feature;

use App\Models\PersonalActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    private function timer(int $minutes = 10, ?string $deadline = null): int
    {
        return $this->postJson('/api/personal-activities', [
            'type' => 'goal',
            'title' => 'Read 10 pages',
            'tracking_type' => 'timer',
            'target_duration_minutes' => $minutes,
            'deadline' => $deadline,
        ])->assertCreated()->json('id');
    }

    private function checklist(array $tasks = ['Buy beans', 'Brew coffee']): int
    {
        return $this->postJson('/api/personal-activities', [
            'type' => 'challenge',
            'title' => 'Morning routine',
            'tracking_type' => 'checklist',
            'tasks' => $tasks,
        ])->assertCreated()->json('id');
    }

    public function test_pausing_counts_time_up_to_the_pause(): void
    {
        $id = $this->timer();
        $this->postJson("/api/personal-activities/{$id}/timer/start")->assertCreated();

        $this->travel(60)->seconds();
        $this->postJson("/api/personal-activities/{$id}/timer/heartbeat")->assertOk();

        // 45 s after the last heartbeat: these seconds used to be lost.
        $this->travel(45)->seconds();
        $this->postJson("/api/personal-activities/{$id}/timer/pause")
            ->assertOk()
            ->assertJsonPath('completed_duration_seconds', 105)
            ->assertJsonPath('status', 'paused');
    }

    public function test_a_timer_without_heartbeats_only_keeps_confirmed_time(): void
    {
        $id = $this->timer();
        $this->postJson("/api/personal-activities/{$id}/timer/start")->assertCreated();

        $this->travel(30)->seconds();
        $this->postJson("/api/personal-activities/{$id}/timer/heartbeat")->assertOk();

        // App closed: no heartbeat for 5 minutes, so only 30 s are kept.
        $this->travel(5)->minutes();
        $this->getJson("/api/personal-activities/{$id}/timer")
            ->assertOk()
            ->assertJsonPath('is_running', false)
            ->assertJsonPath('completed_duration_seconds', 30);
    }

    public function test_heartbeat_on_a_paused_timer_says_not_running(): void
    {
        $id = $this->timer();

        $this->postJson("/api/personal-activities/{$id}/timer/heartbeat")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This timer is not running.');
    }

    public function test_reaching_the_target_completes_and_awards_one_badge(): void
    {
        $id = $this->timer(minutes: 1);
        $this->postJson("/api/personal-activities/{$id}/timer/start")->assertCreated();

        $this->travel(61)->seconds();
        $this->postJson("/api/personal-activities/{$id}/timer/heartbeat")
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->postJson("/api/personal-activities/{$id}/timer/start")->assertUnprocessable();
        $this->assertDatabaseCount('personal_activity_badges', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->user->id, 'type' => 'activity_completed']);
    }

    public function test_activities_past_their_deadline_expire(): void
    {
        $id = $this->timer(deadline: today()->toDateString());

        $this->travel(2)->days();

        $this->postJson("/api/personal-activities/{$id}/timer/start")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The deadline for this activity has passed.');

        $this->assertSame('abandoned', PersonalActivity::find($id)->status);
    }

    public function test_deadline_cannot_be_in_the_past(): void
    {
        $this->postJson('/api/personal-activities', [
            'type' => 'goal', 'title' => 'x', 'tracking_type' => 'timer',
            'target_duration_minutes' => 5, 'deadline' => today()->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('deadline');
    }

    public function test_completing_every_task_completes_the_checklist(): void
    {
        $id = $this->checklist();
        $tasks = $this->getJson("/api/personal-activities/{$id}")->json('checklist_items');

        foreach ($tasks as $task) {
            $response = $this->patchJson("/api/personal-activities/{$id}/checklist/{$task['id']}", ['is_completed' => true])->assertOk();
        }

        $response->assertJsonPath('status', 'completed');

        $this->patchJson("/api/personal-activities/{$id}/checklist/{$tasks[0]['id']}", ['is_completed' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This activity is already completed.');
    }

    public function test_the_last_checklist_task_cannot_be_deleted(): void
    {
        $id = $this->checklist(['Only task']);
        $taskId = $this->getJson("/api/personal-activities/{$id}")->json('checklist_items.0.id');

        $this->deleteJson("/api/personal-activities/{$id}/checklist/{$taskId}")->assertUnprocessable();
    }

    public function test_users_cannot_touch_each_others_activities(): void
    {
        $id = $this->timer();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/personal-activities/{$id}/timer/start")
            ->assertNotFound();
    }
}
