<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_is_authenticated(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Surya Cosplayer',
            'email' => 'SURYA@example.com ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()->assertJsonPath('user.email', 'surya@example.com');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'surya@example.com']);
    }

    public function test_user_can_login_view_profile_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.email', $user->email);
        $this->postJson('/api/auth/logout')->assertOk()->assertJsonStructure(['csrf_token']);
        $this->getJson('/api/me')->assertOk()->assertExactJson(['user' => null]);
    }

    public function test_guest_can_check_session_without_an_authorization_error(): void
    {
        $this->getJson('/api/me')
            ->assertOk()
            ->assertExactJson(['user' => null]);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Email atau kata sandi tidak valid.');
    }
}
