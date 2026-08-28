<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

        $response->assertCreated()
            ->assertJsonPath('user.email', 'surya@example.com')
            ->assertSessionHas('password_hash_web', Auth::hashPasswordForCookie(Auth::user()->getAuthPassword()));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'surya@example.com']);
    }

    public function test_user_can_login_view_profile_and_logout(): void
    {
        $user = $this->user(['password' => 'password123']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertSessionHas('password_hash_web', Auth::hashPasswordForCookie($user->password));
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
        $user = $this->user();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Email atau kata sandi tidak valid.');
    }

    public function test_user_can_update_identity_and_owned_listing_names_with_current_password(): void
    {
        $user = $this->user([
            'name' => 'Nama Lama',
            'email' => 'old@example.test',
            'email_verified_at' => now(),
            'password' => 'password123',
        ]);
        $product = Product::factory()->for($user, 'owner')->create(['seller' => 'Nama Lama']);
        $buyer = $this->user();
        $order = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $fulfillmentId = $order->json('order.fulfillments.0.id');

        $this->flushSession()->actingAsGuest();
        $this->actingAs($user)->patchJson('/api/me', [
            'name' => '  Nama Baru  ',
            'email' => ' NEW@Example.TEST ',
            'current_password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.name', 'Nama Baru')
            ->assertJsonPath('user.email', 'new@example.test')
            ->assertJsonPath('user.email_verified_at', null);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Nama Baru',
            'email' => 'new@example.test',
            'email_verified_at' => null,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'seller' => 'Nama Baru',
        ]);
        $this->assertDatabaseHas('order_fulfillments', [
            'id' => $fulfillmentId,
            'seller_name' => 'Nama Lama',
        ]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_identity_update_requires_valid_password_and_unique_email_without_partial_changes(): void
    {
        $user = $this->user([
            'name' => 'Tetap Sama',
            'email' => 'same@example.test',
            'password' => 'password123',
        ]);
        $other = $this->user(['email' => 'taken@example.test']);
        $product = Product::factory()->for($user, 'owner')->create(['seller' => 'Tetap Sama']);

        $this->actingAs($user)->patchJson('/api/me', [
            'name' => 'Tidak Boleh Berubah',
            'email' => 'changed@example.test',
            'current_password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->patchJson('/api/me', [
            'name' => 'Tidak Boleh Berubah',
            'email' => $other->email,
            'current_password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Tetap Sama',
            'email' => 'same@example.test',
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'seller' => 'Tetap Sama',
        ]);
    }

    public function test_user_can_rotate_password_and_session_stays_authenticated(): void
    {
        $user = $this->user(['password' => 'password123']);

        $this->actingAs($user)->patchJson('/api/me/password', [
            'current_password' => 'password123',
            'password' => 'new-password456',
            'password_confirmation' => 'new-password456',
        ])->assertOk()->assertJsonPath('message', 'Kata sandi berhasil diperbarui.');

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('new-password456', $user->fresh()->password));

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertUnprocessable();
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'new-password456',
        ])->assertOk();
    }

    public function test_password_change_invalidates_sessions_with_the_previous_password_hash(): void
    {
        $user = $this->user(['password' => 'password123']);
        $previousPasswordHash = $user->password;
        $user->update(['password' => 'new-password456']);

        $this->withSession(['password_hash_web' => $previousPasswordHash])
            ->actingAs($user->fresh())
            ->getJson('/api/me')
            ->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_password_rotation_rejects_wrong_reused_and_unconfirmed_passwords(): void
    {
        $user = $this->user(['password' => 'password123']);

        $this->actingAs($user)->patchJson('/api/me/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password456',
            'password_confirmation' => 'new-password456',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->actingAs($user)->patchJson('/api/me/password', [
            'current_password' => 'password123',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->actingAs($user)->patchJson('/api/me/password', [
            'current_password' => 'password123',
            'password' => 'new-password456',
            'password_confirmation' => 'different-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
