<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private array $valid = [
        'name' => 'Aarati Gurung',
        'email' => 'Aarati@Example.com ',
        'role' => 'user',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ];

    public function test_customer_can_register_and_email_is_normalised(): void
    {
        $this->postJson('/api/register', $this->valid)
            ->assertCreated()
            ->assertJsonPath('user.email', 'aarati@example.com')
            ->assertJsonPath('user.role', 'user')
            ->assertJsonStructure(['token']);
    }

    public function test_registration_requires_a_role(): void
    {
        $data = $this->valid;
        unset($data['role']);

        $this->postJson('/api/register', $data)->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_nobody_can_register_as_admin(): void
    {
        $this->postJson('/api/register', [...$this->valid, 'role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_weak_passwords_are_rejected(): void
    {
        $this->postJson('/api/register', [...$this->valid, 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_login_with_wrong_password_gives_generic_message(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'wrong'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid email or password');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_login_records_last_login_and_me_returns_user(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);

        $token = $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'password'])
            ->assertOk()
            ->json('token');

        $this->assertNotNull($user->fresh()->last_login_at);

        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_unauthenticated_requests_get_a_clean_401(): void
    {
        $this->getJson('/api/cafes')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your session has expired. Please log in again.');
    }

    public function test_changing_password_signs_out_other_devices(): void
    {
        $user = User::factory()->create();
        $user->createToken('other-phone');
        $current = $user->createToken('this-phone')->plainTextToken;

        $this->withToken($current)->putJson('/api/profile/password', [
            'current_password' => 'password',
            'new_password' => 'N3w!Password',
            'new_password_confirmation' => 'N3w!Password',
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/profile/password', [
            'current_password' => 'nope',
            'new_password' => 'N3w!Password',
            'new_password_confirmation' => 'N3w!Password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }
}
