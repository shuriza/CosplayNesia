<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupportNote;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\SellerBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class BackofficeModerationTest extends TestCase
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

    private function publishedListing(string $status = Product::STATUS_PUBLISHED): Product
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $store = Store::factory()->for($seller, 'owner')->create(['status' => Store::STATUS_APPROVED]);

        return Product::factory()->for($seller, 'owner')->create([
            'store_id' => $store->id,
            'status' => $status,
            'stock' => 3,
        ]);
    }

    public function test_buyers_and_sellers_cannot_reach_moderation_actions(): void
    {
        $product = $this->publishedListing();

        foreach ([User::ROLE_BUYER, User::ROLE_SELLER] as $role) {
            $this->flushSession()->actingAs($this->staff($role))
                ->postJson("/api/backoffice/products/{$product->id}/takedown", ['reason' => 'Melanggar aturan.'])
                ->assertForbidden();
        }

        $this->assertSame(Product::STATUS_PUBLISHED, $product->fresh()->status);
    }

    public function test_support_cannot_take_down_a_listing_but_moderator_can(): void
    {
        $product = $this->publishedListing();
        $support = $this->staff(User::ROLE_SUPPORT);

        $this->actingAs($support)->postJson("/api/backoffice/products/{$product->id}/takedown", [
            'reason' => 'Melanggar aturan komunitas.',
        ])->assertForbidden();

        $moderator = $this->staff(User::ROLE_MODERATOR);
        $this->flushSession()->actingAsGuest();
        $this->confirmStepUp($moderator);
        $this->postJson("/api/backoffice/products/{$product->id}/takedown", [
            'reason' => 'Melanggar aturan komunitas.',
        ])->assertOk()->assertJsonPath('product.status', Product::STATUS_TAKEN_DOWN);
    }

    public function test_takedown_requires_step_up_and_a_reason(): void
    {
        $product = $this->publishedListing();
        $moderator = $this->staff(User::ROLE_MODERATOR);

        // Without a fresh confirmation the request is refused before anything is mutated.
        $this->actingAs($moderator)->postJson("/api/backoffice/products/{$product->id}/takedown", [
            'reason' => 'Melanggar aturan komunitas.',
        ])->assertStatus(423)->assertJsonPath('step_up_required', true);
        $this->assertSame(Product::STATUS_PUBLISHED, $product->fresh()->status);

        $this->confirmStepUp($moderator);
        $this->postJson("/api/backoffice/products/{$product->id}/takedown", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(Product::STATUS_PUBLISHED, $product->fresh()->status);
    }

    public function test_taken_down_listing_leaves_the_catalog_cart_and_checkout(): void
    {
        $product = $this->publishedListing();
        $buyer = User::factory()->create();
        $moderator = $this->staff(User::ROLE_MODERATOR);

        $this->getJson('/api/products')->assertOk()->assertJsonFragment(['id' => $product->id]);

        $this->confirmStepUp($moderator);
        $this->postJson("/api/backoffice/products/{$product->id}/takedown", [
            'reason' => 'Foto tidak sesuai barang.',
        ])->assertOk();

        $this->flushSession()->actingAsGuest();
        $this->getJson('/api/products')->assertOk()->assertJsonMissing(['id' => $product->id]);

        // The cart resolver silently drops it, and checkout refuses it as an invalid item.
        $this->postJson('/api/cart/resolve', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $product->variants()->value('id'), 'quantity' => 1]],
        ])->assertOk()->assertJsonCount(0, 'items');

        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
    }

    public function test_seller_cannot_mutate_a_taken_down_listing(): void
    {
        $product = $this->publishedListing();
        $seller = $product->owner;
        $moderator = $this->staff(User::ROLE_MODERATOR);

        $this->confirmStepUp($moderator);
        $this->postJson("/api/backoffice/products/{$product->id}/takedown", [
            'reason' => 'Konten tidak layak.',
        ])->assertOk();

        $this->flushSession()->actingAsGuest();
        $this->actingAs($seller)->patchJson("/api/products/{$product->id}", ['name' => 'Nama Baru'])
            ->assertStatus(422);
        $this->actingAs($seller)->patchJson("/api/products/{$product->id}/status", ['status' => Product::STATUS_PUBLISHED])
            ->assertStatus(422);
        $this->actingAs($seller)->deleteJson("/api/products/{$product->id}")->assertStatus(422);

        $this->assertSame(Product::STATUS_TAKEN_DOWN, $product->fresh()->status);
        $this->assertSame($product->name, $product->fresh()->name);
    }

    public function test_restore_returns_the_listing_to_the_exact_previous_status(): void
    {
        $paused = $this->publishedListing(Product::STATUS_PAUSED);
        $live = $this->publishedListing(Product::STATUS_PUBLISHED);
        $moderator = $this->staff(User::ROLE_MODERATOR);

        $this->confirmStepUp($moderator);
        foreach ([$paused, $live] as $product) {
            $this->postJson("/api/backoffice/products/{$product->id}/takedown", [
                'reason' => 'Pemeriksaan sementara.',
            ])->assertOk();
        }

        // A paused listing comes back paused; only a listing that was live comes back live.
        $this->postJson("/api/backoffice/products/{$paused->id}/restore")->assertOk()
            ->assertJsonPath('product.status', Product::STATUS_PAUSED);
        $this->postJson("/api/backoffice/products/{$live->id}/restore")->assertOk()
            ->assertJsonPath('product.status', Product::STATUS_PUBLISHED);

        $this->assertNull($paused->fresh()->moderation_hold_status);
        $this->assertNull($live->fresh()->takedown_reason);

        // Restoring something that is not taken down is a conflict, not a silent no-op.
        $this->postJson("/api/backoffice/products/{$live->id}/restore")->assertStatus(422);
    }

    public function test_takedown_is_idempotent_and_audited_with_before_and_after(): void
    {
        $product = $this->publishedListing();
        $moderator = $this->staff(User::ROLE_MODERATOR);
        $this->confirmStepUp($moderator);

        $this->postJson("/api/backoffice/products/{$product->id}/takedown", ['reason' => 'Pelanggaran pertama.'])->assertOk();
        // A retry must not fail or rewrite the recorded reason.
        $this->postJson("/api/backoffice/products/{$product->id}/takedown", ['reason' => 'Pelanggaran kedua.'])->assertOk();

        $this->assertSame('Pelanggaran pertama.', $product->fresh()->takedown_reason);
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'product.taken_down')->count());

        $audit = AdminAuditLog::query()->where('action', 'product.taken_down')->sole();
        $this->assertSame($moderator->id, $audit->actor_id);
        $this->assertSame('product', $audit->target_type);
        $this->assertSame($product->id, $audit->target_id);
        $this->assertSame('Pelanggaran pertama.', $audit->reason);
        $this->assertSame(
            ['status' => ['from' => Product::STATUS_PUBLISHED, 'to' => Product::STATUS_TAKEN_DOWN]],
            $audit->context['changes'],
        );
    }

    public function test_store_suspension_and_reactivation_are_operator_only_and_audited(): void
    {
        $store = Store::factory()->create(['status' => Store::STATUS_APPROVED]);
        $admin = $this->staff(User::ROLE_ADMIN);
        $support = $this->staff(User::ROLE_SUPPORT);

        $this->actingAs($support)->postJson("/api/backoffice/stores/{$store->id}/suspend", [
            'reason' => 'Dokumen kedaluwarsa.',
        ])->assertForbidden();

        $this->flushSession()->actingAsGuest();
        $this->confirmStepUp($admin);
        $this->postJson("/api/backoffice/stores/{$store->id}/suspend", ['reason' => 'Dokumen kedaluwarsa.'])
            ->assertOk()->assertJsonPath('store.status', Store::STATUS_SUSPENDED);

        $this->postJson("/api/backoffice/stores/{$store->id}/reactivate", ['reason' => 'Dokumen sudah diperbarui.'])
            ->assertOk()->assertJsonPath('store.status', Store::STATUS_APPROVED);

        $suspend = AdminAuditLog::query()->where('action', 'store.'.Store::STATUS_SUSPENDED)->sole();
        $this->assertSame(
            ['status' => ['from' => Store::STATUS_APPROVED, 'to' => Store::STATUS_SUSPENDED]],
            $suspend->context['changes'],
        );
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'store.'.Store::STATUS_APPROVED)->count());
    }

    public function test_payout_freeze_is_finance_only_and_audited(): void
    {
        $store = Store::factory()->create(['status' => Store::STATUS_APPROVED]);
        $finance = $this->staff(User::ROLE_FINANCE);
        $moderator = $this->staff(User::ROLE_MODERATOR);

        // A moderator can suspend a store but has no claim over money leaving the platform.
        $this->flushSession()->actingAs($moderator)
            ->postJson("/api/backoffice/stores/{$store->id}/freeze-payout", ['reason' => 'Ada dispute buyer.'])
            ->assertForbidden();

        $this->flushSession()->actingAsGuest();
        $this->confirmStepUp($finance);
        $this->postJson("/api/backoffice/stores/{$store->id}/freeze-payout", ['reason' => 'Ada dispute buyer.'])
            ->assertOk()
            ->assertJsonPath('store.payout_frozen', true)
            ->assertJsonPath('store.payout_freeze_reason', 'Ada dispute buyer.');

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $finance->id,
            'action' => 'store.payout_frozen',
            'target_type' => 'store',
            'target_id' => $store->id,
        ]);
    }

    public function test_payout_freeze_requires_step_up_and_a_reason(): void
    {
        $store = Store::factory()->create(['status' => Store::STATUS_APPROVED]);
        $finance = $this->staff(User::ROLE_FINANCE);

        $this->actingAs($finance)
            ->postJson("/api/backoffice/stores/{$store->id}/freeze-payout", ['reason' => 'Dispute terbuka.'])
            ->assertStatus(423);

        $this->confirmStepUp($finance);
        $this->postJson("/api/backoffice/stores/{$store->id}/freeze-payout", ['reason' => 'abc'])
            ->assertStatus(422);

        $this->postJson("/api/backoffice/stores/{$store->id}/freeze-payout", [])
            ->assertStatus(422);

        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'store.payout_frozen']);
    }

    public function test_payout_unfreeze_clears_the_reason_and_freezing_twice_is_idempotent(): void
    {
        $store = Store::factory()->create(['status' => Store::STATUS_APPROVED]);
        $finance = $this->staff(User::ROLE_FINANCE);
        $this->confirmStepUp($finance);

        $this->postJson("/api/backoffice/stores/{$store->id}/freeze-payout", ['reason' => 'Dispute terbuka.'])->assertOk();
        $frozenAt = $store->fresh()->payout_frozen_at;

        // Re-freezing with a newer reason must not reset the original freeze timestamp: that
        // timestamp is how long the money has actually been held.
        $this->postJson("/api/backoffice/stores/{$store->id}/freeze-payout", ['reason' => 'Dispute diperluas.'])->assertOk();
        $this->assertSame(
            $frozenAt?->toDateTimeString(),
            $store->fresh()->payout_frozen_at?->toDateTimeString(),
        );

        $this->postJson("/api/backoffice/stores/{$store->id}/unfreeze-payout", ['reason' => 'Dispute selesai.'])
            ->assertOk()
            ->assertJsonPath('store.payout_frozen', false)
            ->assertJsonPath('store.payout_freeze_reason', null);

        // Each freeze is a distinct operator action and is audited as one, even when the store
        // was already frozen: the second row is what shows the reason was revised.
        $this->assertSame(2, AdminAuditLog::query()->where('action', 'store.payout_frozen')->count());
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'store.payout_unfrozen')->count());
    }

    public function test_a_frozen_store_keeps_its_ledger_balance_untouched(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $store = Store::factory()->for($seller, 'owner')->create(['status' => Store::STATUS_APPROVED]);
        $finance = $this->staff(User::ROLE_FINANCE);

        app(LedgerService::class)->post('checkout:1:paid', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 90_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 90_000],
        ]);

        $before = app(SellerBalance::class)->for($seller->id);

        $this->confirmStepUp($finance);
        $this->postJson("/api/backoffice/stores/{$store->id}/freeze-payout", ['reason' => 'Investigasi internal.'])->assertOk();

        // A freeze is a policy flag, not a money movement. If it changed the balance, the
        // reconciliation report could no longer prove where every rupiah came from.
        $this->assertSame($before['liability'], app(SellerBalance::class)->for($seller->id)['liability']);
        $this->assertSame(1, LedgerTransaction::query()->count());
    }

    public function test_ledger_correction_posts_a_mirror_and_never_edits_the_original(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $finance = $this->staff(User::ROLE_FINANCE);

        $original = app(LedgerService::class)->post('checkout:7:paid', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 50_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 50_000],
        ], description: 'Pembayaran salah input');

        $originalEventKey = $original->event_key;

        $this->flushSession()->actingAs($this->staff(User::ROLE_MODERATOR))
            ->postJson("/api/backoffice/ledger/transactions/{$original->id}/reverse", ['reason' => 'Nominal salah.'])
            ->assertForbidden();

        $this->flushSession()->actingAsGuest();
        $this->confirmStepUp($finance);
        $this->postJson("/api/backoffice/ledger/transactions/{$original->id}/reverse", [
            'reason' => 'Nominal salah input oleh sistem.',
        ])
            ->assertOk()
            ->assertJsonPath('correction.reverses_transaction_id', $original->id);

        // The original is still exactly as it was posted.
        $this->assertDatabaseHas('ledger_transactions', [
            'id' => $original->id,
            'event_key' => $originalEventKey,
            'reverses_transaction_id' => null,
        ]);

        // Net effect is zero: the correction mirrors every entry with the direction swapped.
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerAccount::CASH));
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerAccount::sellerPayableCode($seller->id)));

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $finance->id,
            'action' => 'ledger.corrected',
            'target_type' => 'ledger_transaction',
            'target_id' => $original->id,
        ]);
    }

    public function test_ledger_correction_is_idempotent_under_retry(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $finance = $this->staff(User::ROLE_FINANCE);

        $original = app(LedgerService::class)->post('checkout:8:paid', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 20_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 20_000],
        ]);

        $this->confirmStepUp($finance);
        $this->postJson("/api/backoffice/ledger/transactions/{$original->id}/reverse", ['reason' => 'Salah input nominal.'])->assertOk();
        $this->postJson("/api/backoffice/ledger/transactions/{$original->id}/reverse", ['reason' => 'Salah input nominal.'])->assertOk();

        // Reversing twice would put the balance back at the wrong value, which is worse than
        // not correcting it at all.
        $this->assertSame(2, LedgerTransaction::query()->count());
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerAccount::CASH));
    }

    public function test_ledger_correction_requires_step_up_and_an_explanatory_reason(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $finance = $this->staff(User::ROLE_FINANCE);

        $original = app(LedgerService::class)->post('checkout:9:paid', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 10_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 10_000],
        ]);

        $this->actingAs($finance)
            ->postJson("/api/backoffice/ledger/transactions/{$original->id}/reverse", ['reason' => 'Nominal tidak sesuai.'])
            ->assertStatus(423);

        $this->confirmStepUp($finance);
        $this->postJson("/api/backoffice/ledger/transactions/{$original->id}/reverse", ['reason' => 'salah'])
            ->assertStatus(422);

        $this->assertSame(1, LedgerTransaction::query()->count());
    }

    public function test_transaction_list_exposes_reversal_state_so_the_console_cannot_correct_a_correction(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $finance = $this->staff(User::ROLE_FINANCE);

        $original = app(LedgerService::class)->post('checkout:11:paid', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 30_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 30_000],
        ]);

        $this->confirmStepUp($finance);
        $this->postJson("/api/backoffice/ledger/transactions/{$original->id}/reverse", ['reason' => 'Nominal salah input.'])
            ->assertOk();

        // Without reversal state the console would offer to "correct" the correction, which puts
        // the balance back at the wrong value.
        $list = $this->getJson('/api/backoffice/ledger/transactions')->assertOk();
        $originalRow = collect($list->json('data'))->firstWhere('id', $original->id);
        $correctionRow = collect($list->json('data'))->firstWhere('reverses_transaction_id', $original->id);

        $this->assertNull($originalRow['reverses_transaction_id']);
        $this->assertSame($correctionRow['id'], $originalRow['reversed_by_id']);
        $this->assertNotNull($correctionRow['reverses_transaction_id']);
    }

    public function test_store_list_carries_payout_freeze_state(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $store = Store::factory()->for($seller, 'owner')->create(['status' => Store::STATUS_APPROVED]);
        $finance = $this->staff(User::ROLE_FINANCE);

        $this->confirmStepUp($finance);
        $this->postJson("/api/backoffice/stores/{$store->id}/freeze-payout", ['reason' => 'Dispute terbuka.'])->assertOk();

        // The console renders the held flag from the list, so it must be there without a second
        // request per row.
        $this->getJson('/api/backoffice/stores')
            ->assertOk()
            ->assertJsonPath('data.0.payout_frozen', true);
    }

    public function test_support_notes_are_append_only_and_never_reach_the_seller(): void
    {
        $product = $this->publishedListing();
        $seller = $product->owner;
        $support = $this->staff(User::ROLE_SUPPORT);

        $this->actingAs($support)->postJson('/api/backoffice/support-notes', [
            'target_type' => SupportNote::TARGET_PRODUCT,
            'target_id' => $product->id,
            'body' => 'Pembeli melaporkan bahan berbeda dari foto.',
        ])->assertCreated();

        $this->getJson('/api/backoffice/support-notes?target_type=product&target_id='.$product->id)
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Pembeli melaporkan bahan berbeda dari foto.')
            ->assertJsonPath('data.0.author_role', User::ROLE_SUPPORT);

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'support_note.created', 'target_id' => $product->id]);

        // The seller's own listing payload must not carry the internal note anywhere.
        $this->flushSession()->actingAsGuest();
        $owned = $this->actingAs($seller)->getJson('/api/my-products')->assertOk();
        $this->assertStringNotContainsString('Pembeli melaporkan bahan', $owned->getContent());

        $note = SupportNote::query()->sole();
        $this->expectException(LogicException::class);
        $note->update(['body' => 'diubah']);
    }

    public function test_support_note_listing_is_scoped_and_rejects_unknown_targets(): void
    {
        $support = $this->staff(User::ROLE_SUPPORT);
        SupportNote::query()->create([
            'author_id' => $support->id, 'author_role' => User::ROLE_SUPPORT,
            'target_type' => SupportNote::TARGET_USER, 'target_id' => 7,
            'body' => 'Catatan akun.', 'occurred_at' => now(),
        ]);

        $this->actingAs($support)
            ->getJson('/api/backoffice/support-notes?target_type=user&target_id=7')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/backoffice/support-notes?target_type=user&target_id=8')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/backoffice/support-notes', [
            'target_type' => 'invoice', 'target_id' => 1, 'body' => 'Tidak dikenal.',
        ])->assertStatus(422)->assertJsonValidationErrors('target_type');
    }

    public function test_privacy_export_requires_step_up_and_omits_secrets(): void
    {
        $support = $this->staff(User::ROLE_SUPPORT);
        $target = User::factory()->create([
            'role' => User::ROLE_SELLER,
            'password' => 'super-secret-password',
        ]);

        $this->actingAs($support)->getJson("/api/backoffice/users/{$target->id}/privacy-export")->assertStatus(423);

        $this->confirmStepUp($support);
        $export = $this->getJson("/api/backoffice/users/{$target->id}/privacy-export")->assertOk();

        $this->assertSame($target->email, $export->json('export.account.email'));
        $this->assertArrayNotHasKey('password', $export->json('export.account'));
        $this->assertArrayNotHasKey('pending_email_token_hash', $export->json('export.account'));
        $this->assertStringNotContainsString('super-secret-password', $export->getContent());

        // Exporting an account is itself an auditable act.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'privacy_export.read',
            'target_type' => 'user',
            'target_id' => $target->id,
        ]);
    }

    public function test_taken_down_listing_is_listed_for_restore(): void
    {
        $product = $this->publishedListing();
        $moderator = $this->staff(User::ROLE_MODERATOR);
        $this->confirmStepUp($moderator);

        $this->postJson("/api/backoffice/products/{$product->id}/takedown", ['reason' => 'Menunggu klarifikasi.'])->assertOk();

        $this->getJson('/api/backoffice/products/taken-down')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.moderation_hold_status', Product::STATUS_PUBLISHED)
            ->assertJsonPath('data.0.takedown_reason', 'Menunggu klarifikasi.');
    }
}
