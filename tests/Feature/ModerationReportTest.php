<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\FulfillmentMessage;
use App\Models\ModerationReport;
use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModerationReportTest extends TestCase
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

    private function publishedListing(): Product
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $store = Store::factory()->for($seller, 'owner')->create(['status' => Store::STATUS_APPROVED]);

        return Product::factory()->for($seller, 'owner')->create([
            'store_id' => $store->id,
            'status' => Product::STATUS_PUBLISHED,
            'stock' => 3,
        ]);
    }

    public function test_guest_cannot_file_a_report(): void
    {
        $product = $this->publishedListing();

        $this->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => ModerationReport::CATEGORY_COUNTERFEIT,
            'reason' => 'Foto tidak sesuai dengan barang asli.',
        ])->assertUnauthorized();

        $this->assertSame(0, ModerationReport::query()->count());
    }

    public function test_report_is_filed_with_an_evidence_snapshot(): void
    {
        $product = $this->publishedListing();
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => ModerationReport::CATEGORY_COUNTERFEIT,
            'reason' => 'Foto tidak sesuai dengan barang asli.',
        ])->assertCreated()->assertJsonPath('message', 'Laporan diterima dan akan ditinjau oleh tim moderasi.');

        $report = ModerationReport::query()->sole();
        $this->assertSame(ModerationReport::STATUS_OPEN, $report->status);
        $this->assertSame($buyer->id, $report->reporter_id);
        $this->assertSame($product->id, $report->evidence['product_id']);
        $this->assertSame($product->name, $report->evidence['name']);
        // Evidence carries content, never contact details.
        $this->assertArrayNotHasKey('email', $report->evidence);
    }

    public function test_evidence_survives_the_reported_listing_being_changed(): void
    {
        $product = $this->publishedListing();
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => ModerationReport::CATEGORY_MISLEADING,
            'reason' => 'Deskripsi bahan berbeda dari kenyataan.',
        ])->assertCreated();

        $reportedName = $product->name;
        $product->forceFill(['name' => 'Nama yang Sudah Diubah'])->save();

        // The snapshot is what was reported, not what the listing says now.
        $this->assertSame($reportedName, ModerationReport::query()->sole()->evidence['name']);
    }

    public function test_reporter_cannot_flood_the_queue_with_duplicate_open_reports(): void
    {
        $product = $this->publishedListing();
        $buyer = User::factory()->create();

        $payload = [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => ModerationReport::CATEGORY_SPAM,
            'reason' => 'Listing duplikat yang mengganggu pencarian.',
        ];

        $this->actingAs($buyer)->postJson('/api/reports', $payload)->assertCreated();
        $this->actingAs($buyer)->postJson('/api/reports', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('target_id');

        $this->assertSame(1, ModerationReport::query()->count());
    }

    public function test_report_about_a_missing_target_is_refused(): void
    {
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => 999999,
            'category' => ModerationReport::CATEGORY_OTHER,
            'reason' => 'Target ini sebenarnya tidak ada di sistem.',
        ])->assertNotFound();

        $this->assertSame(0, ModerationReport::query()->count());
    }

    public function test_report_rejects_unknown_category_and_a_too_short_reason(): void
    {
        $product = $this->publishedListing();
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => 'balas-dendam',
            'reason' => 'Alasan yang jelas dan cukup panjang.',
        ])->assertStatus(422)->assertJsonValidationErrors('category');

        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => ModerationReport::CATEGORY_OTHER,
            'reason' => 'pendek',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_operator_queue_requires_step_up_and_hides_nothing_from_authorized_staff(): void
    {
        $product = $this->publishedListing();
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => ModerationReport::CATEGORY_INAPPROPRIATE,
            'reason' => 'Konten tidak pantas untuk katalog publik.',
        ])->assertCreated();

        $moderator = $this->staff(User::ROLE_MODERATOR);
        $this->flushSession()->actingAsGuest();
        $this->actingAs($moderator)->getJson('/api/backoffice/reports')->assertStatus(423);

        $this->confirmStepUp($moderator);
        $this->getJson('/api/backoffice/reports')
            ->assertOk()
            ->assertJsonPath('data.0.category', ModerationReport::CATEGORY_INAPPROPRIATE)
            ->assertJsonPath('data.0.status', ModerationReport::STATUS_OPEN)
            ->assertJsonPath('data.0.evidence.name', $product->name);

        $this->getJson('/api/backoffice/reports?status='.ModerationReport::STATUS_RESOLVED)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_resolution_is_recorded_and_a_takedown_resolution_is_actually_applied(): void
    {
        $product = $this->publishedListing();
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => ModerationReport::CATEGORY_COUNTERFEIT,
            'reason' => 'Barang terbukti bukan produk asli.',
        ])->assertCreated();
        $report = ModerationReport::query()->sole();

        $moderator = $this->staff(User::ROLE_MODERATOR);
        $this->flushSession()->actingAsGuest();
        $this->confirmStepUp($moderator);

        $this->postJson("/api/backoffice/reports/{$report->id}/resolve", [
            'status' => ModerationReport::STATUS_RESOLVED,
            'resolution' => 'Bukti foto cukup; listing diturunkan.',
            'action' => ModerationReport::ACTION_TAKEDOWN,
        ])->assertOk()->assertJsonPath('report.status', ModerationReport::STATUS_RESOLVED);

        // The recorded decision and its effect must agree.
        $this->assertSame(ModerationReport::ACTION_TAKEDOWN, $report->fresh()->resolution_action);
        $this->assertSame(Product::STATUS_TAKEN_DOWN, $product->fresh()->status);
        $this->assertSame('Bukti foto cukup; listing diturunkan.', $product->fresh()->takedown_reason);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'moderation_report.'.ModerationReport::STATUS_RESOLVED,
            'target_type' => 'moderation_report',
            'target_id' => $report->id,
        ]);
    }

    public function test_dismissing_a_report_cannot_take_the_listing_down(): void
    {
        $product = $this->publishedListing();
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => ModerationReport::CATEGORY_OTHER,
            'reason' => 'Laporan ini tidak memiliki dasar yang jelas.',
        ])->assertCreated();
        $report = ModerationReport::query()->sole();

        $admin = $this->staff(User::ROLE_ADMIN);
        $this->flushSession()->actingAsGuest();
        $this->confirmStepUp($admin);

        // Even when a caller asks for a punitive action on a dismissal, it is dropped: being
        // reported is not grounds for punishment.
        $this->postJson("/api/backoffice/reports/{$report->id}/resolve", [
            'status' => ModerationReport::STATUS_DISMISSED,
            'resolution' => 'Tidak ada pelanggaran; laporan ditolak.',
            'action' => ModerationReport::ACTION_TAKEDOWN,
        ])->assertOk()->assertJsonPath('report.resolution_action', ModerationReport::ACTION_NONE);

        $this->assertSame(Product::STATUS_PUBLISHED, $product->fresh()->status);
    }

    public function test_a_report_cannot_be_resolved_twice(): void
    {
        $product = $this->publishedListing();
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_PRODUCT,
            'target_id' => $product->id,
            'category' => ModerationReport::CATEGORY_SPAM,
            'reason' => 'Listing ini jelas-jelas spam berulang.',
        ])->assertCreated();
        $report = ModerationReport::query()->sole();

        $moderator = $this->staff(User::ROLE_MODERATOR);
        $this->flushSession()->actingAsGuest();
        $this->confirmStepUp($moderator);

        $this->postJson("/api/backoffice/reports/{$report->id}/resolve", [
            'status' => ModerationReport::STATUS_RESOLVED,
            'resolution' => 'Ditindaklanjuti dengan peringatan.',
            'action' => ModerationReport::ACTION_WARN,
        ])->assertOk();

        $this->postJson("/api/backoffice/reports/{$report->id}/resolve", [
            'status' => ModerationReport::STATUS_DISMISSED,
            'resolution' => 'Mencoba mengubah keputusan setelah faktanya.',
            'action' => ModerationReport::ACTION_NONE,
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(ModerationReport::STATUS_RESOLVED, $report->fresh()->status);
        $this->assertSame('Ditindaklanjuti dengan peringatan.', $report->fresh()->resolution);
    }

    public function test_support_cannot_resolve_reports_and_message_evidence_is_bounded(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        $buyer = User::factory()->create();
        $order = $buyer->orders()->create([
            'total_amount' => 0,
            'merchandise_subtotal' => 0,
            'platform_fee' => 0,
            'rental_deposit' => 0,
            'fee_currency' => 'IDR',
            'status' => 'processing',
        ]);
        $fulfillment = $order->fulfillments()->create([
            'seller_id' => $seller->id,
            'seller_name' => 'Seller',
            'status' => OrderFulfillment::STATUS_ACCEPTED,
        ]);
        $message = FulfillmentMessage::query()->create([
            'fulfillment_id' => $fulfillment->id,
            'sender_id' => $seller->id,
            'sender_role' => FulfillmentMessage::ROLE_SELLER,
            'body' => str_repeat('pesan kasar ', 200),
            'created_at' => now(),
        ]);

        $this->actingAs($buyer)->postJson('/api/reports', [
            'target_type' => ModerationReport::TARGET_MESSAGE,
            'target_id' => $message->id,
            'category' => ModerationReport::CATEGORY_ABUSE,
            'reason' => 'Penjual mengirim pesan yang kasar.',
        ])->assertCreated();

        $report = ModerationReport::query()->sole();
        // Evidence is capped so a long message cannot bloat the queue row.
        $this->assertSame(500, mb_strlen($report->evidence['body']));

        $support = $this->staff(User::ROLE_SUPPORT);
        $this->flushSession()->actingAsGuest();
        $this->confirmStepUp($support);
        $this->postJson("/api/backoffice/reports/{$report->id}/resolve", [
            'status' => ModerationReport::STATUS_RESOLVED,
            'resolution' => 'Support mencoba menyelesaikan laporan.',
            'action' => ModerationReport::ACTION_WARN,
        ])->assertForbidden();

        $this->assertSame(ModerationReport::STATUS_OPEN, $report->fresh()->status);
        $this->assertSame(0, AdminAuditLog::query()->where('action', 'moderation_report.resolved')->count());
    }
}
