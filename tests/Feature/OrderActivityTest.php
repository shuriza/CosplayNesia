<?php

namespace Tests\Feature;

use App\Models\OrderActivity;
use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\RentalReservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderActivityTest extends TestCase
{
    use RefreshDatabase;

    private function rentalDates(int $startOffset = 1, int $days = 2): array
    {
        $start = Carbon::today(config('app.timezone'))->addDays($startOffset);
        $end = (clone $start)->addDays($days - 1);

        return [$start->toDateString(), $end->toDateString()];
    }

    public function test_fresh_checkout_records_expected_activity_rows(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $sale = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_SALE,
            'stock' => 5,
        ]);
        $rental = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 5,
        ]);
        [$start, $end] = $this->rentalDates();

        $response = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [
                ['id' => $sale->id, 'quantity' => 1],
                ['id' => $rental->id, 'quantity' => 1, 'start_date' => $start, 'end_date' => $end],
            ],
        ]))->assertCreated();

        $orderId = $response->json('order_id');
        $fulfillmentId = $response->json('order.fulfillments.0.id');
        $reservationId = RentalReservation::query()->where('order_id', $orderId)->value('id');

        $this->assertDatabaseCount('order_activities', 3);
        $this->assertDatabaseHas('order_activities', [
            'order_id' => $orderId,
            'event_type' => 'checkout.created',
            'actor_id' => $buyer->id,
            'actor_role' => OrderActivity::ROLE_BUYER,
            'to_status' => 'processing',
            'event_key' => "order:{$orderId}:checkout.created",
        ]);
        $this->assertDatabaseHas('order_activities', [
            'order_id' => $orderId,
            'fulfillment_id' => $fulfillmentId,
            'event_type' => 'fulfillment.received',
            'actor_id' => $buyer->id,
            'actor_role' => OrderActivity::ROLE_BUYER,
            'to_status' => OrderFulfillment::STATUS_RECEIVED,
            'event_key' => "fulfillment:{$fulfillmentId}:received",
        ]);
        $this->assertDatabaseHas('order_activities', [
            'order_id' => $orderId,
            'rental_reservation_id' => $reservationId,
            'event_type' => 'rental.reserved',
            'actor_id' => $buyer->id,
            'actor_role' => OrderActivity::ROLE_BUYER,
            'to_status' => RentalReservation::STATUS_RESERVED,
            'event_key' => "rental:{$reservationId}:reserved",
        ]);
    }

    public function test_seller_transitions_and_same_state_retry_do_not_duplicate_rows(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_SALE,
            'stock' => 5,
        ]);

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $fulfillmentId = $created->json('order.fulfillments.0.id');

        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'accepted'])->assertOk();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'accepted'])->assertOk();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'ready'])->assertOk();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'completed'])->assertOk();

        $this->assertDatabaseCount('order_activities', 5);
        $this->assertSame([
            'fulfillment.received',
            'fulfillment.accepted',
            'fulfillment.ready',
            'fulfillment.completed',
        ], OrderActivity::query()
            ->where('fulfillment_id', $fulfillmentId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->pluck('event_type')
            ->all());
        $this->assertSame(1, OrderActivity::query()->where('fulfillment_id', $fulfillmentId)->where('event_type', 'fulfillment.accepted')->count());
    }

    public function test_seller_cancelled_transition_records_once(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_SALE,
            'stock' => 5,
        ]);

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $fulfillmentId = $created->json('order.fulfillments.0.id');

        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'cancelled'])->assertOk();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertDatabaseHas('order_activities', [
            'fulfillment_id' => $fulfillmentId,
            'event_type' => 'fulfillment.cancelled',
            'actor_id' => $seller->id,
            'actor_role' => OrderActivity::ROLE_SELLER,
            'to_status' => OrderFulfillment::STATUS_CANCELLED,
        ]);
        $this->assertSame(1, OrderActivity::query()->where('fulfillment_id', $fulfillmentId)->where('event_type', 'fulfillment.cancelled')->count());
    }

    public function test_buyer_rental_cancellation_and_seller_completion_write_rental_events(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $rental = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 5,
        ]);

        [$start, $end] = $this->rentalDates();
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $rental->id, 'quantity' => 1, 'start_date' => $start, 'end_date' => $end]],
        ]))->assertCreated();
        $orderId = $created->json('order_id');
        $orderItemId = $created->json('order.items.0.id');
        $reservationId = RentalReservation::query()->where('order_id', $orderId)->value('id');

        $this->actingAs($buyer)->deleteJson("/api/orders/{$orderId}/items/{$orderItemId}/rental")->assertOk();
        $this->assertDatabaseHas('order_activities', [
            'rental_reservation_id' => $reservationId,
            'event_type' => 'rental.cancelled',
            'actor_id' => $buyer->id,
            'actor_role' => OrderActivity::ROLE_BUYER,
            'to_status' => RentalReservation::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('order_activities', [
            'fulfillment_id' => $created->json('order.fulfillments.0.id'),
            'event_type' => 'fulfillment.cancelled',
            'from_status' => OrderFulfillment::STATUS_RECEIVED,
            'to_status' => OrderFulfillment::STATUS_CANCELLED,
        ]);

        $completionDate = Carbon::today(config('app.timezone'))->toDateString();
        $completedRental = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 5,
        ]);
        $createdCompletion = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $completedRental->id, 'quantity' => 1, 'start_date' => $completionDate, 'end_date' => $completionDate]],
        ]))->assertCreated();
        $completionFulfillmentId = $createdCompletion->json('order.fulfillments.0.id');
        $completionReservationId = RentalReservation::query()->where('order_id', $createdCompletion->json('order_id'))->value('id');

        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$completionFulfillmentId}/status", ['status' => 'accepted'])->assertOk();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$completionFulfillmentId}/status", ['status' => 'ready'])->assertOk();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$completionFulfillmentId}/status", ['status' => 'completed'])->assertOk();

        $this->assertDatabaseHas('order_activities', [
            'rental_reservation_id' => $completionReservationId,
            'event_type' => 'rental.completed',
            'actor_id' => $seller->id,
            'actor_role' => OrderActivity::ROLE_SELLER,
            'to_status' => RentalReservation::STATUS_COMPLETED,
        ]);
    }

    public function test_idempotency_replay_and_conflict_do_not_duplicate_activity_rows(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_SALE,
            'stock' => 5,
        ]);

        $payload = $this->checkoutPayload([
            'idempotency_key' => 'timeline-idempotency',
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]);

        $this->actingAs($buyer)->postJson('/api/checkout', $payload)->assertCreated();
        $baselineCount = OrderActivity::count();

        $this->actingAs($buyer)->postJson('/api/checkout', $payload)->assertOk();
        $this->assertSame($baselineCount, OrderActivity::count());

        $conflict = $this->checkoutPayload([
            'idempotency_key' => 'timeline-idempotency',
            'items' => [['id' => $product->id, 'quantity' => 2]],
        ]);
        $this->actingAs($buyer)->postJson('/api/checkout', $conflict)->assertConflict();
        $this->assertSame($baselineCount, OrderActivity::count());
    }

    public function test_timeline_projections_scope_and_redact_actor_ids_and_pii(): void
    {
        $buyer = User::factory()->create(['name' => 'Buyer']);
        $sellerA = User::factory()->create(['name' => 'Seller A']);
        $sellerB = User::factory()->create(['name' => 'Seller B']);
        $productA = Product::factory()->for($sellerA, 'owner')->create(['type' => Product::TYPE_SALE, 'stock' => 5]);
        $productB = Product::factory()->for($sellerB, 'owner')->create(['type' => Product::TYPE_SALE, 'stock' => 5]);

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [
                ['id' => $productA->id, 'quantity' => 1],
                ['id' => $productB->id, 'quantity' => 1],
            ],
        ]))->assertCreated();
        $orderId = $created->json('order_id');
        $sellerAFulfillmentId = $created->json('order.fulfillments.0.id');
        $sellerBFulfillmentId = $created->json('order.fulfillments.1.id');

        $buyerDetail = $this->actingAs($buyer)->getJson("/api/orders/{$orderId}")->assertOk();
        $buyerDetail->assertJsonPath('timeline.0.event_type', 'checkout.created');
        $buyerDetail->assertJsonPath('timeline.0.actor_label', 'Anda');
        $buyerDetail->assertJsonMissingPath('timeline.0.actor_id');
        $buyerDetail->assertJsonMissingPath('timeline.0.metadata.recipient_email');
        $buyerDetail->assertJsonMissingPath('timeline.0.metadata.recipient_phone');
        $buyerDetail->assertJsonMissingPath('timeline.0.metadata.address_line1');
        $buyerDetail->assertJsonMissingPath('timeline.0.metadata.handoff_note');

        $sellerDetail = $this->actingAs($sellerA)->getJson("/api/seller/fulfillments/{$sellerAFulfillmentId}")->assertOk();
        $sellerDetail->assertJsonCount(1, 'timeline');
        $sellerDetail->assertJsonPath('timeline.0.event_type', 'fulfillment.received');
        $sellerDetail->assertJsonPath('timeline.0.actor_label', 'Pembeli');
        $sellerDetail->assertJsonMissingPath('timeline.0.actor_id');
        $sellerDetail->assertJsonMissingPath('handoff.recipient_email');

        $siblingDetail = $this->actingAs($sellerB)->getJson("/api/seller/fulfillments/{$sellerBFulfillmentId}")->assertOk();
        $siblingDetail->assertJsonCount(1, 'timeline');
        $siblingDetail->assertJsonPath('timeline.0.event_type', 'fulfillment.received');
        $siblingDetail->assertJsonMissingPath('timeline.0.actor_id');
    }

    public function test_timeline_detail_endpoints_enforce_ownership(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $intruder = User::factory()->create();
        $product = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_SALE, 'stock' => 5]);

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $orderId = $created->json('order_id');
        $fulfillmentId = $created->json('order.fulfillments.0.id');

        $this->actingAs($intruder)->getJson("/api/orders/{$orderId}")->assertForbidden();
        $this->actingAs($intruder)->getJson("/api/seller/fulfillments/{$fulfillmentId}")->assertForbidden();
    }

    public function test_activity_rows_reject_model_updates_and_deletes(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create([
            'type' => Product::TYPE_SALE,
            'stock' => 5,
        ]);

        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();

        $activity = OrderActivity::query()->where('event_type', 'checkout.created')->firstOrFail();
        $originalType = $activity->event_type;

        try {
            $activity->update(['event_type' => 'checkout.changed']);
            $this->fail('An audit activity update must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Order activities are immutable.', $exception->getMessage());
        }

        $this->assertSame($originalType, $activity->fresh()->event_type);

        try {
            $activity->delete();
            $this->fail('An audit activity deletion must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Order activities are immutable.', $exception->getMessage());
        }

        $this->assertDatabaseHas('order_activities', [
            'id' => $activity->id,
            'event_type' => $originalType,
        ]);
    }

    public function test_legacy_baseline_import_and_rollback_only_remove_imported_rows(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $sale = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_SALE, 'stock' => 5]);
        $rental = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_RENTAL, 'stock' => 5]);
        [$start, $end] = $this->rentalDates();

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [
                ['id' => $sale->id, 'quantity' => 1],
                ['id' => $rental->id, 'quantity' => 1, 'start_date' => $start, 'end_date' => $end],
            ],
        ]))->assertCreated();

        $migration = require database_path('migrations/2026_08_27_000010_import_baseline_order_activities.php');
        $migration->up();

        $orderId = $created->json('order_id');
        $fulfillmentId = $created->json('order.fulfillments.0.id');
        $reservationId = RentalReservation::query()->where('order_id', $orderId)->value('id');

        $this->assertDatabaseHas('order_activities', ['event_key' => "order:{$orderId}:imported", 'event_type' => 'order.imported']);
        $this->assertDatabaseHas('order_activities', ['event_key' => "fulfillment:{$fulfillmentId}:imported", 'event_type' => 'fulfillment.imported']);
        $this->assertDatabaseHas('order_activities', ['event_key' => "rental:{$reservationId}:imported", 'event_type' => 'rental.imported']);

        $countAfterFirstImport = OrderActivity::whereIn('event_type', ['order.imported', 'fulfillment.imported', 'rental.imported'])->count();
        $migration->up();
        $this->assertSame($countAfterFirstImport, OrderActivity::whereIn('event_type', ['order.imported', 'fulfillment.imported', 'rental.imported'])->count());

        $migration->down();
        $this->assertSame(0, OrderActivity::whereIn('event_type', ['order.imported', 'fulfillment.imported', 'rental.imported'])->count());
        $this->assertSame(3, OrderActivity::count());
    }
}
