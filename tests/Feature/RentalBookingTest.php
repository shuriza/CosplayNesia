<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RentalReservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalBookingTest extends TestCase
{
    use RefreshDatabase;

    private function dates(int $startOffset = 1, int $days = 2): array
    {
        $start = Carbon::today(config('app.timezone'))->addDays($startOffset);

        return ['start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days - 1)->toDateString()];
    }

    public function test_rental_dates_are_conditional_and_bounded(): void
    {
        $rental = Product::factory()->create(['type' => 'Sewa']);
        $sale = Product::factory()->create(['type' => 'Beli']);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $rental->id, 'quantity' => 1]]]))->assertUnprocessable()->assertJsonValidationErrors(['items.0.start_date', 'items.0.end_date']);
        $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $sale->id, 'quantity' => 1, ...$this->dates()]]]))->assertUnprocessable()->assertJsonValidationErrors('items.0.start_date');
        $tooLong = $this->dates(1, 31);
        $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $rental->id, 'quantity' => 1, ...$tooLong]]]))->assertUnprocessable()->assertJsonValidationErrors('items.0.end_date');
    }

    public function test_mixed_sale_and_rental_checkout_snapshots_dates_and_type(): void
    {
        $rental = Product::factory()->create(['name' => 'Sewa Costume', 'type' => 'Sewa', 'price' => 90000]);
        $sale = Product::factory()->create(['name' => 'Beli Wig', 'type' => 'Beli', 'price' => 50000, 'stock' => 4]);
        $user = User::factory()->create();
        $dates = $this->dates();
        $response = $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $rental->id, 'quantity' => 1, ...$dates], ['id' => $sale->id, 'quantity' => 2]]]))->assertCreated();
        $items = $response->json('order.items');
        $rentalItem = collect($items)->firstWhere('product_id', $rental->id);
        $saleItem = collect($items)->firstWhere('product_id', $sale->id);
        $this->assertSame('Sewa', $rentalItem['product_type']);
        $this->assertSame($dates['start_date'], $rentalItem['rental_start_date']);
        $this->assertSame($dates['end_date'], $rentalItem['rental_end_date']);
        $this->assertSame('Beli', $saleItem['product_type']);
        $this->assertNull($saleItem['rental_start_date']);
        $this->assertDatabaseHas('rental_reservations', ['product_id' => $rental->id, 'status' => RentalReservation::STATUS_RESERVED]);
        $this->assertSame(2, $sale->fresh()->stock);
    }

    public function test_inclusive_overlap_rejects_and_next_day_is_available(): void
    {
        $product = Product::factory()->create(['type' => Product::TYPE_RENTAL, 'stock' => 2]);
        $first = $this->dates(2, 2);
        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 2, ...$first]]]))->assertCreated();
        $overlap = ['start_date' => $first['end_date'], 'end_date' => Carbon::parse($first['end_date'])->addDay()->toDateString()];
        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1, ...$overlap]]]))->assertConflict();
        $adjacent = ['start_date' => Carbon::parse($first['end_date'])->addDay()->toDateString(), 'end_date' => Carbon::parse($first['end_date'])->addDays(2)->toDateString()];
        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 2, ...$adjacent]]]))->assertCreated();
    }

    public function test_rental_conflict_rolls_back_mixed_checkout(): void
    {
        $rental = Product::factory()->create(['type' => Product::TYPE_RENTAL, 'stock' => 1]);
        $sale = Product::factory()->create(['type' => Product::TYPE_SALE, 'stock' => 3]);
        $dates = $this->dates(3, 2);
        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $rental->id, 'quantity' => 1, ...$dates]]]))->assertCreated();
        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $sale->id, 'quantity' => 1], ['id' => $rental->id, 'quantity' => 1, ...$dates]]]))->assertConflict();
        $this->assertSame(3, $sale->fresh()->stock);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_inactive_rental_is_rejected_by_checkout_and_availability(): void
    {
        $product = Product::factory()->create(['type' => 'Sewa', 'is_active' => false]);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1, ...$this->dates()]]]))->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
        $this->getJson('/api/products/'.$product->id.'/availability?'.http_build_query($this->dates()))->assertNotFound();
    }

    public function test_public_availability_returns_deterministic_capacity(): void
    {
        $product = Product::factory()->create(['type' => Product::TYPE_RENTAL, 'stock' => 2]);
        $dates = $this->dates();
        $this->getJson('/api/products/'.$product->id.'/availability?'.http_build_query($dates))->assertOk()->assertJson(['available' => true, 'stock' => 2, 'reserved_quantity' => 0, 'available_quantity' => 2]);
        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1, ...$dates]]]))->assertCreated();
        $this->getJson('/api/products/'.$product->id.'/availability?'.http_build_query($dates))->assertOk()->assertJson(['available' => true, 'stock' => 2, 'reserved_quantity' => 1, 'available_quantity' => 1]);
    }

    public function test_idempotency_replays_and_rejects_different_payloads(): void
    {
        $product = Product::factory()->create(['type' => Product::TYPE_RENTAL, 'stock' => 2]);
        $user = User::factory()->create();
        $payload = $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1, ...$this->dates()]]]);
        $first = $this->actingAs($user)->withHeader('Idempotency-Key', 'rental-key')->postJson('/api/checkout', $payload)->assertCreated();
        $this->actingAs($user)->withHeader('Idempotency-Key', 'rental-key')->postJson('/api/checkout', $payload)->assertOk()->assertJsonPath('order_id', $first->json('order_id'));
        $different = $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 2, ...$this->dates()]]]);
        $this->actingAs($user)->withHeader('Idempotency-Key', 'rental-key')->postJson('/api/checkout', $different)->assertConflict();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_buyer_only_cancellation_releases_dates_and_history_survives_product_deletion(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $product = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_RENTAL]);
        $dates = $this->dates(3, 2);
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1, ...$dates]]]))->assertCreated();
        $itemId = $created->json('order.items.0.id');
        $orderId = $created->json('order_id');
        $this->actingAs(User::factory()->create())->deleteJson("/api/orders/{$orderId}/items/{$itemId}/rental")->assertNotFound();
        $this->actingAs($buyer)->deleteJson("/api/orders/{$orderId}/items/{$itemId}/rental")->assertOk();
        $this->assertDatabaseHas('rental_reservations', ['order_id' => $orderId, 'status' => RentalReservation::STATUS_CANCELLED]);
        $name = $product->name;
        $product->delete();
        $this->actingAs($buyer)->getJson('/api/orders')->assertOk()->assertJsonPath('0.items.0.name', $name);
    }

    public function test_same_day_rental_cancellation_is_rejected_by_policy(): void
    {
        $product = Product::factory()->create(['type' => Product::TYPE_RENTAL]);
        $buyer = User::factory()->create();
        $dates = $this->dates(0, 2);
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1, ...$dates]]]))->assertCreated();
        $this->actingAs($buyer)->deleteJson("/api/orders/{$created->json('order_id')}/items/{$created->json('order.items.0.id')}/rental")->assertConflict();
        $this->assertDatabaseHas('rental_reservations', ['order_id' => $created->json('order_id'), 'status' => RentalReservation::STATUS_RESERVED]);
    }
}
