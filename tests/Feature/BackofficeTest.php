<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\Store;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function confirmStepUp(User $user): void
    {
        $this->actingAs($user)
            ->postJson('/api/backoffice/step-up', ['password' => 'password'])
            ->assertOk();
    }

    public function test_buyers_and_sellers_cannot_reach_the_backoffice(): void
    {
        foreach ([User::ROLE_BUYER, User::ROLE_SELLER] as $role) {
            $this->flushSession()->actingAs($this->staff($role))
                ->getJson('/api/backoffice/users')
                ->assertForbidden();
        }
    }

    public function test_guests_cannot_reach_the_backoffice(): void
    {
        // The auth guard rejects unauthenticated requests before the backoffice gate runs.
        $this->getJson('/api/backoffice/users')->assertUnauthorized();
    }

    public function test_support_can_list_users_but_not_read_finance_or_queue(): void
    {
        $support = $this->staff(User::ROLE_SUPPORT);

        $this->actingAs($support)->getJson('/api/backoffice/users')->assertOk();
        $this->getJson('/api/backoffice/ledger/transactions')->assertForbidden();
        $this->getJson('/api/backoffice/queue')->assertForbidden();
    }

    public function test_finance_can_read_ledger_but_not_queue(): void
    {
        $finance = $this->staff(User::ROLE_FINANCE);

        $this->actingAs($finance)->getJson('/api/backoffice/ledger/accounts')->assertOk();
        $this->getJson('/api/backoffice/queue')->assertForbidden();
    }

    public function test_admin_can_read_queue(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)->getJson('/api/backoffice/queue')->assertOk();
    }

    public function test_sensitive_read_requires_step_up(): void
    {
        $support = $this->staff(User::ROLE_SUPPORT);
        $target = User::factory()->create();

        $this->actingAs($support)->getJson("/api/backoffice/users/{$target->id}")->assertStatus(423);

        $this->confirmStepUp($support);
        $this->getJson("/api/backoffice/users/{$target->id}")->assertOk();
    }

    public function test_step_up_rejects_a_wrong_password_and_audits_it(): void
    {
        $support = $this->staff(User::ROLE_SUPPORT);

        $this->actingAs($support)
            ->postJson('/api/backoffice/step-up', ['password' => 'not-the-password'])
            ->assertStatus(422);

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $support->id,
            'action' => 'backoffice.step_up_failed',
        ]);
    }

    public function test_user_list_masks_email_and_detail_does_not(): void
    {
        $support = $this->staff(User::ROLE_SUPPORT);
        $target = User::factory()->create(['email' => 'secret.person@example.test']);

        $list = $this->actingAs($support)->getJson('/api/backoffice/users')->assertOk();
        $this->assertSame('s***@example.test', $list->json('data.0.email'));

        $this->confirmStepUp($support);
        $this->getJson("/api/backoffice/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('email', 'secret.person@example.test');
    }

    public function test_order_trace_reaches_ledger_transaction(): void
    {
        $finance = $this->staff(User::ROLE_FINANCE);
        $buyer = User::factory()->create();
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);

        $order = $buyer->orders()->create([
            'total_amount' => 150_000,
            'merchandise_subtotal' => 150_000,
            'platform_fee' => 0,
            'rental_deposit' => 0,
            'fee_currency' => 'IDR',
            'status' => Order::STATUS_PROCESSING,
        ]);
        $fulfillment = $order->fulfillments()->create([
            'seller_id' => $seller->id,
            'seller_name' => 'Seller',
            'status' => OrderFulfillment::STATUS_COMPLETED,
            'settlement_status' => OrderFulfillment::SETTLEMENT_AVAILABLE,
        ]);
        $order->items()->create([
            'fulfillment_id' => $fulfillment->id,
            'product_name' => 'Costume',
            'unit_price' => 150_000,
            'quantity' => 1,
        ]);

        app(LedgerService::class)->post('checkout:'.$order->id.':paid', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 150_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 150_000],
        ], referenceType: 'order', referenceId: $order->id);

        $this->actingAs($finance)->getJson('/api/backoffice/orders')->assertOk();

        $this->confirmStepUp($finance);
        $this->getJson("/api/backoffice/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('fulfillments.0.settlement_status', OrderFulfillment::SETTLEMENT_AVAILABLE);

        // Trace the same order into the ledger by reference.
        $this->getJson("/api/backoffice/ledger/transactions?reference_type=order&reference_id={$order->id}")
            ->assertOk()
            ->assertJsonPath('data.0.reference_id', $order->id)
            ->assertJsonPath('data.0.entries.1.account_code', LedgerAccount::sellerPayableCode($seller->id));

        $this->getJson("/api/backoffice/ledger/sellers/{$seller->id}")
            ->assertOk()
            ->assertJsonPath('available', 150_000)
            ->assertJsonPath('balanced', true);
    }

    public function test_store_list_includes_the_owner_without_loading_an_undefined_relation(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER, 'email' => 'owner.person@example.test']);
        Store::factory()->for($seller, 'owner')->create(['status' => 'approved']);

        // The relationship is `owner`, not `user`. Getting this wrong is a 500 that no capability
        // check catches, and it only surfaces once an operator opens the console.
        $this->actingAs($this->staff(User::ROLE_SUPPORT))
            ->getJson('/api/backoffice/stores')
            ->assertOk()
            ->assertJsonPath('data.0.owner.id', $seller->id)
            ->assertJsonPath('data.0.owner.email', 'o***@example.test');
    }

    public function test_store_detail_resolves_the_owner_under_step_up(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $store = Store::factory()->for($seller, 'owner')->create(['status' => 'approved']);
        $support = $this->staff(User::ROLE_SUPPORT);

        $this->confirmStepUp($support);
        $this->getJson("/api/backoffice/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('owner.id', $seller->id)
            ->assertJsonPath('counts.products', 0);
    }

    public function test_audit_log_is_append_only(): void
    {
        $support = $this->staff(User::ROLE_SUPPORT);
        $this->confirmStepUp($support);

        $log = AdminAuditLog::query()->firstOrFail();

        $this->expectException(\LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $support = $this->staff(User::ROLE_SUPPORT);
        $this->confirmStepUp($support);

        $log = AdminAuditLog::query()->firstOrFail();

        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_audit_view_requires_step_up_and_lists_entries(): void
    {
        $support = $this->staff(User::ROLE_SUPPORT);

        $this->actingAs($support)->getJson('/api/backoffice/audit')->assertStatus(423);

        $this->confirmStepUp($support);
        $this->getJson('/api/backoffice/audit')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'backoffice.step_up_confirmed');
    }
}
