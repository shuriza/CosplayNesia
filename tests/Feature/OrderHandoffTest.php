<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_normalizes_handoff_fields_and_persists_snapshot(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        $payload = $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
            'recipient' => ['name' => '  Rani  ', 'phone' => '+62 (812) 3456-7890', 'email' => '  RANI@Example.TEST '],
            'address' => ['line1' => '  Jalan Mawar 9  ', 'line2' => '  Blok B  ', 'city' => '  Bandung ', 'province' => ' Jawa Barat ', 'postal_code' => '40123'],
            'handoff_note' => '  Hubungi sebelum datang  ',
        ]);

        $response = $this->actingAs($buyer)->postJson('/api/checkout', $payload)->assertCreated();
        $orderId = $response->json('order_id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'recipient_name' => 'Rani',
            'recipient_phone' => '+6281234567890',
            'recipient_email' => 'rani@example.test',
            'address_line1' => 'Jalan Mawar 9',
            'address_line2' => 'Blok B',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => '40123',
            'handoff_note' => 'Hubungi sebelum datang',
        ]);
        $this->actingAs($buyer)->getJson('/api/orders/'.$orderId)->assertOk()->assertJsonPath('handoff.recipient_phone', '+6281234567890');
    }

    public function test_invalid_phone_and_postal_code_are_actionable_validation_errors(): void
    {
        $payload = $this->checkoutPayload(['recipient' => ['phone' => '0812abc'], 'address' => ['postal_code' => '1234']]);
        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors(['recipient.phone', 'address.postal_code']);
    }

    public function test_idempotency_includes_normalized_handoff_snapshot(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        $payload = $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1]]]);
        $this->actingAs($buyer)->withHeader('Idempotency-Key', 'handoff-1')->postJson('/api/checkout', $payload)->assertCreated();
        $sameNormalized = $this->checkoutPayload(['recipient' => ['phone' => '+62 812-3456-7890'], 'items' => [['id' => $product->id, 'quantity' => 1]]]);
        $this->actingAs($buyer)->withHeader('Idempotency-Key', 'handoff-1')->postJson('/api/checkout', $sameNormalized)->assertOk();
        $changed = $this->checkoutPayload(['address' => ['line1' => 'Jalan Berubah 10'], 'items' => [['id' => $product->id, 'quantity' => 1]]]);
        $this->actingAs($buyer)->withHeader('Idempotency-Key', 'handoff-1')->postJson('/api/checkout', $changed)->assertConflict();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_buyer_detail_is_owner_only_and_list_omits_handoff_pii(): void
    {
        $buyer = User::factory()->create(['email' => 'buyer@example.test']);
        $other = User::factory()->create();
        $product = Product::factory()->create();
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['recipient' => ['email' => 'buyer@example.test'], 'items' => [['id' => $product->id, 'quantity' => 1]]]))->assertCreated();
        $id = $created->json('order_id');
        $this->actingAs($buyer)->getJson('/api/orders')->assertOk()->assertJsonMissing(['recipient_phone' => '081234567890']);
        $this->actingAs($buyer)->getJson('/api/orders/'.$id)->assertOk()->assertJsonPath('handoff.recipient_email', 'buyer@example.test');
        $this->actingAs($other)->getJson('/api/orders/'.$id)->assertForbidden();
    }

    public function test_seller_detail_is_scoped_and_minimizes_contact_data(): void
    {
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $buyer = User::factory()->create(['email' => 'private@example.test']);
        $product = Product::factory()->for($seller, 'owner')->create();
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['recipient' => ['email' => 'private@example.test'], 'items' => [['id' => $product->id, 'quantity' => 1]]]))->assertCreated();
        $id = $created->json('order.fulfillments.0.id');
        $this->actingAs($seller)->getJson('/api/seller/fulfillments')->assertOk()->assertJsonMissing(['buyer' => ['id' => $buyer->id]])->assertJsonMissing(['recipient_phone' => '081234567890']);
        $this->actingAs($seller)->getJson('/api/seller/fulfillments/'.$id)->assertOk()->assertJsonPath('handoff.recipient_phone', '+6281234567890')->assertJsonMissingPath('handoff.recipient_email')->assertJsonMissingPath('buyer.id');
        $this->actingAs($other)->getJson('/api/seller/fulfillments/'.$id)->assertForbidden();
    }

    public function test_multi_seller_details_share_snapshot_without_sibling_items(): void
    {
        $sellerA = User::factory()->create();
        $sellerB = User::factory()->create();
        $buyer = User::factory()->create();
        $first = Product::factory()->for($sellerA, 'owner')->create(['name' => 'First']);
        $second = Product::factory()->for($sellerB, 'owner')->create(['name' => 'Second']);
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $first->id, 'quantity' => 1], ['id' => $second->id, 'quantity' => 1]]]))->assertCreated();
        $fulfillments = collect($created->json('order.fulfillments'));
        $a = $fulfillments->firstWhere('seller_id', $sellerA->id)['id'];
        $b = $fulfillments->firstWhere('seller_id', $sellerB->id)['id'];
        $this->actingAs($sellerA)->getJson('/api/seller/fulfillments/'.$a)->assertJsonPath('handoff.recipient_name', 'Test Recipient')->assertJsonPath('items.0.product_name', 'First')->assertJsonMissing(['product_name' => 'Second']);
        $this->actingAs($sellerB)->getJson('/api/seller/fulfillments/'.$b)->assertJsonPath('handoff.recipient_name', 'Test Recipient')->assertJsonPath('items.0.product_name', 'Second')->assertJsonMissing(['product_name' => 'First']);
    }

    public function test_lifecycle_and_rental_cancellation_preserve_handoff_snapshot(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $product = Product::factory()->for($seller, 'owner')->create();
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['handoff_note' => 'Gate C', 'items' => [['id' => $product->id, 'quantity' => 1]]]))->assertCreated();
        $fulfillment = $created->json('order.fulfillments.0.id');
        $this->actingAs($seller)->patchJson('/api/seller/fulfillments/'.$fulfillment.'/status', ['status' => 'accepted'])->assertOk();
        $this->actingAs($seller)->getJson('/api/seller/fulfillments/'.$fulfillment)->assertJsonPath('handoff.handoff_note', 'Gate C');
        $this->actingAs($buyer)->getJson('/api/orders/'.$created->json('order_id'))->assertJsonPath('handoff.handoff_note', 'Gate C');
    }

    public function test_legacy_order_remains_readable_with_null_handoff(): void
    {
        $buyer = User::factory()->create();
        $order = $buyer->orders()->create(['total_amount' => 100, 'status' => 'demo_confirmed']);
        $this->actingAs($buyer)->getJson('/api/orders/'.$order->id)->assertOk()->assertJsonPath('handoff.recipient_name', null)->assertJsonPath('handoff.address_line1', null);
    }
}
