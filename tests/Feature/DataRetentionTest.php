<?php

namespace Tests\Feature;

use App\Models\AccountSession;
use App\Models\AdminAuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function oldSession(User $user, int $days): AccountSession
    {
        $session = AccountSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'session_fingerprint' => hash('sha256', Str::random(24).$days),
            'last_seen_at' => now()->subDays($days),
        ]);

        return $session;
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $user = User::factory()->create();
        $this->oldSession($user, 200);

        $this->artisan('data:prune --dry-run')->assertExitCode(0);

        $this->assertSame(1, AccountSession::query()->count());
    }

    public function test_transient_rows_past_the_window_are_deleted(): void
    {
        $user = User::factory()->create();
        $this->oldSession($user, 200);
        SecurityEvent::query()->create([
            'user_id' => $user->id,
            'type' => 'login.succeeded',
            'created_at' => now()->subDays(200),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', 'expired-token'),
            'created_at' => now()->subDays(3),
        ]);

        $this->artisan('data:prune --days=90')->assertExitCode(0);

        $this->assertSame(0, AccountSession::query()->count());
        $this->assertSame(0, SecurityEvent::query()->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_recent_rows_survive(): void
    {
        $user = User::factory()->create();
        $this->oldSession($user, 5);
        SecurityEvent::query()->create([
            'user_id' => $user->id,
            'type' => 'login.succeeded',
            'created_at' => now()->subDays(5),
        ]);

        $this->artisan('data:prune --days=90')->assertExitCode(0);

        $this->assertSame(1, AccountSession::query()->count());
        $this->assertSame(1, SecurityEvent::query()->count());
    }

    public function test_sent_deliveries_are_pruned_but_failed_ones_are_kept(): void
    {
        $user = User::factory()->create();

        NotificationDelivery::query()->forceCreate([
            'recipient_id' => $user->id,
            'channel' => NotificationCatalog::CHANNEL_EMAIL,
            'event_key' => 'notify:sent:1',
            'type' => 'order.placed',
            'category' => 'orders',
            'status' => NotificationDelivery::STATUS_SENT,
            'sent_at' => now()->subDays(200),
            'created_at' => now()->subDays(200),
        ]);
        NotificationDelivery::query()->forceCreate([
            'recipient_id' => $user->id,
            'channel' => NotificationCatalog::CHANNEL_EMAIL,
            'event_key' => 'notify:failed:1',
            'type' => 'order.placed',
            'category' => 'orders',
            'status' => NotificationDelivery::STATUS_FAILED,
            'failed_at' => now()->subDays(200),
            'created_at' => now()->subDays(200),
        ]);

        $this->artisan('data:prune --days=90')->assertExitCode(0);

        $remaining = NotificationDelivery::query()->pluck('status')->all();
        // A failed delivery is the exception queue an operator works from. Pruning it would
        // silently swallow a broken address.
        $this->assertSame([NotificationDelivery::STATUS_FAILED], $remaining);
    }

    /**
     * The point of a retention job is to bound growth, not to shrink history. These tables are the
     * platform's evidence, so no window may touch them.
     */
    public function test_retention_never_touches_transaction_or_evidence_tables(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);

        $order = $buyer->orders()->create([
            'total_amount' => 10_000,
            'merchandise_subtotal' => 10_000,
            'platform_fee' => 0,
            'rental_deposit' => 0,
            'fee_currency' => 'IDR',
            'status' => Order::STATUS_PROCESSING,
            'created_at' => now()->subDays(500),
        ]);
        $order->fulfillments()->create([
            'seller_id' => $seller->id,
            'seller_name' => 'Seller',
            'status' => 'completed',
            'created_at' => now()->subDays(500),
        ]);

        UserNotification::query()->create([
            'recipient_id' => $buyer->id,
            'type' => 'order.placed',
            'event_key' => 'notify:1',
            'payload' => ['message' => 'Pesanan dibuat'],
            'created_at' => now()->subDays(500),
        ]);
        AdminAuditLog::query()->create([
            'actor_id' => $seller->id,
            'actor_role' => User::ROLE_ADMIN,
            'action' => 'store.payout_frozen',
            'occurred_at' => now()->subDays(500),
            'created_at' => now()->subDays(500),
        ]);
        Product::factory()->for($seller, 'owner')->create(['created_at' => now()->subDays(500)]);

        $this->artisan('data:prune --days=1')->assertExitCode(0);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, UserNotification::query()->count());
        $this->assertSame(1, AdminAuditLog::query()->count());
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(2, User::query()->count());
    }
}
