<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_block_endpoints_are_owner_scoped_and_reject_mismatched_product_blocks(): void
    {
        $seller = $this->user();
        $rival = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 3,
        ]);
        $otherProduct = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 3,
        ]);
        $saleProduct = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_SALE,
        ]);
        $dates = $this->dates(2, 2);

        $this->getJson($this->calendarUrl($product, $dates))->assertUnauthorized();
        $this->postJson($this->blockUrl($product), [...$dates, 'quantity' => 1])->assertUnauthorized();

        $block = $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$dates,
            'quantity' => 1,
        ])->assertCreated();
        $blockId = $block->json('id');

        $this->actingAs($rival)->getJson($this->calendarUrl($product, $dates))->assertForbidden();
        $this->actingAs($rival)->postJson($this->blockUrl($product), [
            ...$this->dates(5),
            'quantity' => 1,
        ])->assertForbidden();
        $this->actingAs($rival)->deleteJson($this->blockUrl($product, $blockId))->assertForbidden();
        $this->actingAs($seller)->deleteJson($this->blockUrl($otherProduct, $blockId))->assertNotFound();
        $this->actingAs($seller)->getJson($this->calendarUrl($saleProduct, $dates))->assertNotFound();
        $this->actingAs($seller)->postJson($this->blockUrl($saleProduct), [...$dates, 'quantity' => 1])->assertNotFound();
    }

    public function test_block_updates_inclusive_calendar_public_capacity_and_mixed_checkout_rolls_back(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $rental = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 2,
        ]);
        $sale = Product::factory()->create([
            'type' => Product::TYPE_SALE,
            'stock' => 3,
        ]);
        $dates = $this->dates(2, 2);

        $created = $this->actingAs($seller)->postJson($this->blockUrl($rental), [
            ...$dates,
            'quantity' => 2,
            'reason' => '  Jadwal pemeliharaan internal  ',
        ])->assertCreated()
            ->assertJsonPath('start_date', $dates['start_date'])
            ->assertJsonPath('end_date', $dates['end_date'])
            ->assertJsonPath('quantity', 2)
            ->assertJsonPath('reason', 'Jadwal pemeliharaan internal');

        $this->actingAs($seller)->getJson($this->calendarUrl($rental, $dates))
            ->assertOk()
            ->assertJsonPath('product.id', $rental->id)
            ->assertJsonPath('product.stock', 2)
            ->assertJsonPath('data.0.id', $created->json('id'))
            ->assertJsonPath('data.0.reason', 'Jadwal pemeliharaan internal')
            ->assertJsonCount(2, 'days')
            ->assertJsonPath('days.0.date', $dates['start_date'])
            ->assertJsonPath('days.0.reserved_quantity', 0)
            ->assertJsonPath('days.0.blocked_quantity', 2)
            ->assertJsonPath('days.0.available_quantity', 0)
            ->assertJsonPath('days.1.date', $dates['end_date'])
            ->assertJsonPath('days.1.blocked_quantity', 2)
            ->assertJsonPath('days.1.available_quantity', 0);

        $this->getJson($this->availabilityUrl($rental, $dates))
            ->assertOk()
            ->assertJsonPath('reserved_quantity', 0)
            ->assertJsonPath('blocked_quantity', 2)
            ->assertJsonPath('unavailable_quantity', 2)
            ->assertJsonPath('available_quantity', 0)
            ->assertJsonPath('available', false)
            ->assertJsonMissingPath('reason')
            ->assertJsonMissingPath('block_id');

        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $sale->id, 'quantity' => 1],
            ['id' => $rental->id, 'quantity' => 1, ...$dates],
        ]]))->assertConflict();

        $this->assertSame(3, $sale->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_block_cannot_displace_an_existing_rental_reservation(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 2,
        ]);
        $dates = $this->dates(3, 2);

        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $product->id, 'quantity' => 2, ...$dates],
        ]]))->assertCreated();

        $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$dates,
            'quantity' => 1,
        ])->assertConflict();

        $this->actingAs($seller)->getJson($this->calendarUrl($product, $dates))
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('days.0.reserved_quantity', 2)
            ->assertJsonPath('days.0.blocked_quantity', 0)
            ->assertJsonPath('days.0.available_quantity', 0);
    }

    public function test_disjoint_reservations_and_blocks_use_the_joint_daily_peak(): void
    {
        $seller = $this->user();
        $firstBuyer = $this->user();
        $secondBuyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 5,
        ]);
        $reservationDates = $this->dates(1);
        $blockDates = $this->dates(3);
        $window = [
            'start_date' => $reservationDates['start_date'],
            'end_date' => $blockDates['end_date'],
        ];

        $this->actingAs($firstBuyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $product->id, 'quantity' => 2, ...$reservationDates],
        ]]))->assertCreated();
        $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$blockDates,
            'quantity' => 3,
        ])->assertCreated();

        $this->getJson($this->availabilityUrl($product, $window))
            ->assertOk()
            ->assertJsonPath('reserved_quantity', 2)
            ->assertJsonPath('blocked_quantity', 3)
            ->assertJsonPath('unavailable_quantity', 3)
            ->assertJsonPath('available_quantity', 2);

        $this->actingAs($secondBuyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $product->id, 'quantity' => 2, ...$window],
        ]]))->assertCreated();
    }

    public function test_cancelling_a_block_is_idempotent_and_removes_its_capacity_and_listing(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 2,
        ]);
        $dates = $this->dates(2);

        $block = $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$dates,
            'quantity' => 2,
        ])->assertCreated();
        $blockId = $block->json('id');

        $this->getJson($this->availabilityUrl($product, $dates))
            ->assertOk()
            ->assertJsonPath('available_quantity', 0);

        $this->actingAs($seller)->deleteJson($this->blockUrl($product, $blockId))->assertNoContent();
        $this->actingAs($seller)->deleteJson($this->blockUrl($product, $blockId))->assertNoContent();

        $this->getJson($this->availabilityUrl($product, $dates))
            ->assertOk()
            ->assertJsonPath('blocked_quantity', 0)
            ->assertJsonPath('unavailable_quantity', 0)
            ->assertJsonPath('available_quantity', 2);
        $this->actingAs($seller)->getJson($this->calendarUrl($product, $dates))
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $product->id, 'quantity' => 2, ...$dates],
        ]]))->assertCreated();
    }

    public function test_future_blocks_guard_stock_and_type_while_expired_blocks_do_not(): void
    {
        $seller = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 3,
        ]);
        $future = $this->dates(2, 2);

        $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$future,
            'quantity' => 2,
        ])->assertCreated();

        $this->actingAs($seller)->patchJson("/api/products/{$product->id}", ['stock' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');
        $this->actingAs($seller)->patchJson("/api/products/{$product->id}", ['type' => Product::TYPE_SALE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
        $this->actingAs($seller)->patchJson("/api/products/{$product->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
        $this->actingAs($seller)->getJson($this->calendarUrl($product, $future))
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$future,
            'quantity' => 1,
        ])->assertCreated();

        $this->travelTo(CarbonImmutable::parse($future['end_date'], config('app.timezone'))->addDay()->setTime(12, 0));

        try {
            $this->actingAs($seller)->patchJson("/api/products/{$product->id}", ['stock' => 0])
                ->assertOk()
                ->assertJsonPath('stock', 0);
            $this->actingAs($seller)->patchJson("/api/products/{$product->id}", ['type' => Product::TYPE_SALE])
                ->assertOk()
                ->assertJsonPath('type', Product::TYPE_SALE);
        } finally {
            $this->travelBack();
        }
    }

    public function test_block_dates_and_quantities_are_validated(): void
    {
        $seller = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 3,
        ]);
        $today = CarbonImmutable::today(config('app.timezone'));

        $this->actingAs($seller)->postJson($this->blockUrl($product), [
            'start_date' => $today->subDay()->toDateString(),
            'end_date' => $today->toDateString(),
            'quantity' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'quantity']);

        $this->actingAs($seller)->postJson($this->blockUrl($product), [
            'start_date' => $today->addDays(2)->toDateString(),
            'end_date' => $today->addDay()->toDateString(),
            'quantity' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');

        $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$this->dates(1, 31),
            'quantity' => 10001,
            'reason' => str_repeat('a', 201),
        ])->assertUnprocessable()->assertJsonValidationErrors(['end_date', 'quantity', 'reason']);
    }

    public function test_calendar_lists_only_active_overlapping_blocks_and_ignores_page_size_for_daily_counts(): void
    {
        $seller = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create([
            'type' => Product::TYPE_RENTAL,
            'stock' => 10,
        ]);
        $window = $this->dates(1, 4);

        $first = $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$this->dates(1, 2),
            'quantity' => 1,
        ])->assertCreated();
        $second = $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$this->dates(2, 2),
            'quantity' => 2,
        ])->assertCreated();
        $cancelled = $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$this->dates(2, 3),
            'quantity' => 3,
        ])->assertCreated();
        $third = $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$this->dates(4),
            'quantity' => 1,
        ])->assertCreated();
        $this->actingAs($seller)->postJson($this->blockUrl($product), [
            ...$this->dates(6),
            'quantity' => 1,
        ])->assertCreated();
        $this->actingAs($seller)->deleteJson($this->blockUrl($product, $cancelled->json('id')))->assertNoContent();

        $firstPage = $this->actingAs($seller)->getJson($this->calendarUrl($product, $window, ['per_page' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->json('id'))
            ->assertJsonPath('pagination.per_page', 1)
            ->assertJsonPath('pagination.has_more', true)
            ->assertJsonPath('days.0.blocked_quantity', 1)
            ->assertJsonPath('days.1.blocked_quantity', 3)
            ->assertJsonPath('days.1.available_quantity', 7)
            ->assertJsonPath('days.2.blocked_quantity', 2)
            ->assertJsonPath('days.3.blocked_quantity', 1);

        $secondPage = $this->actingAs($seller)->getJson($this->calendarUrl($product, $window, [
            'per_page' => 1,
            'cursor' => $firstPage->json('pagination.next_cursor'),
        ]))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->json('id'));

        $this->assertSame($firstPage->json('days'), $secondPage->json('days'));

        $all = $this->actingAs($seller)->getJson($this->calendarUrl($product, $window, ['per_page' => 50]))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $first->json('id'))
            ->assertJsonPath('data.1.id', $second->json('id'))
            ->assertJsonPath('data.2.id', $third->json('id'));

        $this->assertSame($firstPage->json('days'), $all->json('days'));
    }

    private function dates(int $startOffset = 1, int $days = 1): array
    {
        $start = CarbonImmutable::today(config('app.timezone'))->addDays($startOffset);

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $start->addDays($days - 1)->toDateString(),
        ];
    }

    private function blockUrl(Product $product, ?int $blockId = null): string
    {
        $url = "/api/products/{$product->id}/rental-blocks";

        return $blockId === null ? $url : "{$url}/{$blockId}";
    }

    private function calendarUrl(Product $product, array $dates, array $query = []): string
    {
        return $this->blockUrl($product).'?'.http_build_query([...$dates, ...$query]);
    }

    private function availabilityUrl(Product $product, array $dates): string
    {
        return "/api/products/{$product->id}/availability?".http_build_query($dates);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
