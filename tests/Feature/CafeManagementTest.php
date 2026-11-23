<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CafeManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return [
            'name' => 'Brew & Books',
            'description' => 'Quiet café with a library corner.',
            'contact_number' => '9801234567',
            'address' => 'Jhamsikhel, Lalitpur',
            'latitude' => '27.6800000',
            'longitude' => '85.3100000',
            'opening_time' => '08:00',
            'closing_time' => '20:00:00',
            'operating_days' => ['Sunday', 'Monday'],
            ...$extra,
        ];
    }

    public function test_owner_submits_cafe_and_admins_are_notified(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $this->actingAs(User::factory()->owner()->create(), 'sanctum')
            ->post('/api/cafes', $this->payload(['logo' => UploadedFile::fake()->image('logo.png')]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('cafe.status', 'pending');

        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'new_cafe_submitted']);
        Storage::disk('public')->assertExists(Cafe::first()->logo_path);
    }

    public function test_replacing_the_logo_deletes_the_old_file_and_resubmits(): void
    {
        Storage::fake('public');
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'sanctum')
            ->post('/api/cafes', $this->payload(['logo' => UploadedFile::fake()->image('a.png')]), ['Accept' => 'application/json'])
            ->assertCreated();

        $cafe = Cafe::first();
        $cafe->update(['status' => 'approved']);
        $oldLogo = $cafe->logo_path;

        $this->actingAs($owner, 'sanctum')
            ->post("/api/cafes/{$cafe->id}", ['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('cafe.status', 'pending');

        Storage::disk('public')->assertMissing($oldLogo);
        Storage::disk('public')->assertExists($cafe->fresh()->logo_path);
    }

    public function test_deleting_a_cafe_removes_its_files(): void
    {
        Storage::fake('public');
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'sanctum')
            ->post('/api/cafes', $this->payload([
                'cover_image' => UploadedFile::fake()->image('cover.jpg'),
                'cafe_photos' => [UploadedFile::fake()->image('p1.jpg'), UploadedFile::fake()->image('p2.jpg')],
            ]), ['Accept' => 'application/json'])
            ->assertCreated();

        $cafe = Cafe::first();
        $paths = $cafe->imagePaths();
        $this->assertCount(3, $paths);

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/cafes/{$cafe->id}")->assertOk();

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_invalid_cafe_data_returns_field_errors(): void
    {
        $this->actingAs(User::factory()->owner()->create(), 'sanctum')
            ->postJson('/api/cafes', $this->payload(['contact_number' => 'call me', 'latitude' => '200', 'operating_days' => ['Funday']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact_number', 'latitude', 'operating_days.0']);
    }
}
