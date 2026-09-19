<?php

namespace Tests\Feature;

use App\Models\AccountSession;
use App\Models\Product;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_account_can_verify_and_cannot_transact_before_verification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $product = Product::factory()->create(['stock' => 2]);

        $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertForbidden()->assertJsonPath('message', 'Verifikasi email diperlukan untuk bertransaksi.');
        $this->actingAs($user)->postJson('/api/products', [
            'name' => 'Listing Ditolak', 'category' => 'Anime', 'price' => 100000, 'type' => 'Beli', 'stock' => 1,
        ])->assertForbidden();

        $this->postJson('/api/me/email-verification')->assertOk();
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'user' => $user->id,
            'hash' => sha1($user->email),
        ]);
        $this->flushSession()->actingAsGuest();
        $this->getJson($url)->assertOk()->assertJsonPath('user.email_verified_at', fn ($value) => $value !== null);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->actingAs($user->fresh());
        $this->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
    }

    public function test_stale_legal_consent_blocks_transaction_until_reaccepted(): void
    {
        $user = User::factory()->create(['terms_version' => 'old']);
        $product = Product::factory()->create(['stock' => 1]);

        $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertConflict()->assertJsonPath('message', 'Persetujuan syarat dan kebijakan terbaru diperlukan.');

        $this->postJson('/api/me/legal-consent')->assertOk()
            ->assertJsonPath('user.terms_version', config('cosplaynesia.legal.terms_version'));
        $this->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
    }

    public function test_password_reset_is_non_enumerating_single_use_and_revokes_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'old-password']);
        AccountSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'session_fingerprint' => hash('sha256', Str::random()),
            'last_seen_at' => now(),
        ]);

        $message = 'Jika email terdaftar, tautan reset telah dikirim.';
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('message', $message);
        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk()->assertJsonPath('message', $message);

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password456',
            'password_confirmation' => 'new-password456',
        ];
        $this->postJson('/api/auth/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-password456', $user->fresh()->password));
        $this->assertDatabaseMissing('account_sessions', ['user_id' => $user->id, 'revoked_at' => null]);
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
    }

    public function test_session_list_revoke_and_revoke_all_are_owner_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherSession = AccountSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'session_fingerprint' => hash('sha256', 'other-device'),
            'ip_address' => '203.0.113.10',
            'last_seen_at' => now()->subMinute(),
        ]);
        $foreignSession = AccountSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $other->id,
            'session_fingerprint' => hash('sha256', 'foreign-device'),
            'last_seen_at' => now(),
        ]);

        $list = $this->actingAs($user)->getJson('/api/me/sessions')->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertCount(1, collect($list->json('data'))->where('is_current', true));

        $this->deleteJson('/api/me/sessions/'.$foreignSession->id)->assertNotFound();
        $this->deleteJson('/api/me/sessions/'.$otherSession->id)->assertNoContent();
        $this->assertNotNull($otherSession->fresh()->revoked_at);

        $this->deleteJson('/api/me/sessions')->assertOk()->assertJsonStructure(['csrf_token']);
        $this->assertGuest();
        $this->getJson('/api/me')->assertOk()->assertExactJson(['user' => null]);
    }

    public function test_deactivation_anonymizes_account_preserves_order_and_disables_listing(): void
    {
        $seller = User::factory()->create(['name' => 'Seller Lama', 'email' => 'seller@example.test', 'password' => 'password123']);
        $buyer = User::factory()->create();
        $product = Product::factory()->for($seller, 'owner')->create(['seller' => 'Seller Lama', 'stock' => 1]);
        $orderId = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated()->json('order.id');

        $this->flushSession()->actingAsGuest();
        $this->actingAs($seller)->deleteJson('/api/me', ['current_password' => 'password123'])
            ->assertOk()->assertJsonPath('message', 'Akun telah dinonaktifkan.');

        $anonymized = $seller->fresh();
        $this->assertNotNull($anonymized->deactivated_at);
        $this->assertNotNull($anonymized->anonymized_at);
        $this->assertSame('Akun dinonaktifkan', $anonymized->name);
        $this->assertStringEndsWith('@invalid.local', $anonymized->email);
        $this->assertFalse($product->fresh()->is_active);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'user_id' => $buyer->id]);
        $this->postJson('/api/auth/login', ['email' => 'seller@example.test', 'password' => 'password123'])->assertUnprocessable();
    }

    public function test_auth_identity_change_rotates_device_token_instead_of_reusing_another_users_session(): void
    {
        $first = User::factory()->create(['password' => 'password123']);
        $second = User::factory()->create(['password' => 'password456']);

        $this->postJson('/api/auth/login', ['email' => $first->email, 'password' => 'password123'])->assertOk();
        $firstSession = AccountSession::query()->where('user_id', $first->id)->sole();
        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/login', ['email' => $second->email, 'password' => 'password456'])
            ->assertOk()->assertJsonPath('user.id', $second->id);
        $secondSession = AccountSession::query()->where('user_id', $second->id)->sole();

        $this->assertNotSame($firstSession->session_fingerprint, $secondSession->session_fingerprint);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $second->id);
    }

    public function test_security_events_are_append_only(): void
    {
        $event = SecurityEvent::query()->create(['type' => 'test.event', 'created_at' => now()]);

        $this->expectException(LogicException::class);
        $event->update(['type' => 'rewritten']);
    }
}
