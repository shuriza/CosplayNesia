<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Mail\TransactionalEventMail;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\NotificationCatalog;
use App\Services\NotificationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_queues_one_projected_email_for_the_seller(): void
    {
        Mail::fake();
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['price' => 150000, 'stock' => 5]);

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 2]],
        ]))->assertCreated();

        $fulfillmentId = $created->json('order.fulfillments.0.id');
        $delivery = NotificationDelivery::query()->sole();

        $this->assertSame($seller->id, $delivery->recipient_id);
        $this->assertSame(NotificationCatalog::CHANNEL_EMAIL, $delivery->channel);
        $this->assertSame(NotificationCatalog::CATEGORY_ORDERS, $delivery->category);
        $this->assertSame("notify:fulfillment:{$fulfillmentId}:placed", $delivery->event_key);
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);
        // The projection carries what the message needs and nothing else.
        $this->assertSame(1, $delivery->payload['item_count']);
        $this->assertSame(300000, $delivery->payload['subtotal']);

        Mail::assertSent(TransactionalEventMail::class, 1);
        Mail::assertSent(TransactionalEventMail::class, fn (TransactionalEventMail $mail): bool => $mail->hasTo($seller->email)
            && $mail->eventType === 'order.placed');
    }

    public function test_projection_drops_payload_keys_that_are_not_allowlisted(): void
    {
        Mail::fake();
        $recipient = $this->user();

        app(NotificationRecorder::class)->record([
            'recipient_id' => $recipient->id,
            'type' => UserNotification::TYPE_MESSAGE_RECEIVED,
            'payload' => [
                'product_name' => 'Kostum Furina',
                'preview' => 'Halo, apakah masih tersedia?',
                'buyer_email' => 'rahasia@example.test',
                'internal_note' => 'jangan pernah keluar',
            ],
            'event_key' => 'notify:test:projection',
        ]);

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame('Kostum Furina', $delivery->payload['product_name']);
        $this->assertSame('Halo, apakah masih tersedia?', $delivery->payload['preview']);
        $this->assertArrayNotHasKey('buyer_email', $delivery->payload);
        $this->assertArrayNotHasKey('internal_note', $delivery->payload);
    }

    public function test_replayed_transition_does_not_queue_a_second_email(): void
    {
        Mail::fake();
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['price' => 100000, 'stock' => 4]);

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $fulfillmentId = $created->json('order.fulfillments.0.id');

        // Same-state replay: the fulfillment is already accepted, so the transition is a no-op for
        // the domain and must stay a no-op for the channel too.
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", [
            'status' => OrderFulfillment::STATUS_ACCEPTED,
        ])->assertOk();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", [
            'status' => OrderFulfillment::STATUS_ACCEPTED,
        ])->assertOk();

        $this->assertSame(1, NotificationDelivery::query()
            ->where('event_key', "notify:fulfillment:{$fulfillmentId}:accepted")
            ->count());
        Mail::assertSent(TransactionalEventMail::class, 2); // order placed + accepted
    }

    public function test_redelivering_the_job_for_a_sent_row_does_not_send_twice(): void
    {
        Mail::fake();
        $recipient = $this->user();

        app(NotificationRecorder::class)->record([
            'recipient_id' => $recipient->id,
            'type' => UserNotification::TYPE_REVIEW_RECEIVED,
            'payload' => ['product_name' => 'Wig Miku', 'rating' => 5],
            'event_key' => 'notify:test:retry',
        ]);

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);

        SendNotificationEmail::dispatchSync($delivery->id);
        SendNotificationEmail::dispatchSync($delivery->id);

        Mail::assertSent(TransactionalEventMail::class, 1);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_failed_send_is_recorded_then_recovered_by_the_retry_command(): void
    {
        // Swapping the facade root runs the job's real `Mail::to(...)->send(...)` call into a stub
        // that fails, which is what a dead SMTP host looks like to the job.
        $manager = Mail::getFacadeRoot();
        Mail::swap(new class
        {
            public function to(mixed $users): never
            {
                throw new RuntimeException('SMTP tidak dapat dihubungi.');
            }
        });

        $recipient = $this->user();
        app(NotificationRecorder::class)->record([
            'recipient_id' => $recipient->id,
            'type' => UserNotification::TYPE_REVIEW_REPLIED,
            'payload' => ['product_name' => 'Kostum Kafka', 'rating' => 4],
            'event_key' => 'notify:test:dead-letter',
        ]);

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('SMTP tidak dapat dihubungi.', $delivery->last_error);
        $this->assertNotNull($delivery->failed_at);

        Mail::swap($manager);
        Mail::fake();
        $this->artisan('notifications:retry')->assertSuccessful();

        $recovered = $delivery->fresh();
        $this->assertSame(NotificationDelivery::STATUS_SENT, $recovered->status);
        $this->assertNull($recovered->last_error);
        Mail::assertSent(TransactionalEventMail::class, 1);
    }

    public function test_muted_category_is_recorded_as_suppressed_without_sending(): void
    {
        Mail::fake();
        $recipient = $this->user();
        NotificationPreference::query()->create([
            'user_id' => $recipient->id,
            'channel' => NotificationCatalog::CHANNEL_EMAIL,
            'category' => NotificationCatalog::CATEGORY_MESSAGES,
            'enabled' => false,
        ]);

        app(NotificationRecorder::class)->record([
            'recipient_id' => $recipient->id,
            'type' => UserNotification::TYPE_MESSAGE_RECEIVED,
            'payload' => ['preview' => 'Halo'],
            'event_key' => 'notify:test:muted',
        ]);

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::STATUS_SUPPRESSED, $delivery->status);
        $this->assertNull($delivery->queued_at);
        Mail::assertNothingSent();
    }

    public function test_preference_endpoints_round_trip_and_refuse_the_security_category(): void
    {
        $user = $this->user();

        $this->actingAs($user)->getJson('/api/notification-preferences')
            ->assertOk()
            ->assertJsonPath('channel', 'email')
            ->assertJsonFragment(['category' => 'orders', 'enabled' => true, 'mandatory' => false])
            ->assertJsonFragment(['category' => 'security', 'enabled' => true, 'mandatory' => true]);

        $this->actingAs($user)->patchJson('/api/notification-preferences', [
            'channel' => 'email',
            'category' => 'reviews',
            'enabled' => false,
        ])->assertOk()->assertJsonFragment(['category' => 'reviews', 'enabled' => false]);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id, 'channel' => 'email', 'category' => 'reviews', 'enabled' => false,
        ]);

        // The security category is not even a legal request value, so no row can ever be written.
        $this->actingAs($user)->patchJson('/api/notification-preferences', [
            'channel' => 'email',
            'category' => 'security',
            'enabled' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->assertDatabaseMissing('notification_preferences', [
            'user_id' => $user->id, 'category' => 'security',
        ]);

        $this->flushSession()->actingAsGuest();
        $this->getJson('/api/notification-preferences')->assertUnauthorized();
        $this->patchJson('/api/notification-preferences', ['channel' => 'email', 'category' => 'orders', 'enabled' => false])
            ->assertUnauthorized();
    }

    public function test_security_email_is_sent_even_when_a_preference_row_exists(): void
    {
        Mail::fake();
        $user = $this->user(['password' => 'old-password']);
        // Written directly, bypassing the API, to prove the resolver refuses to consult it.
        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'channel' => NotificationCatalog::CHANNEL_EMAIL,
            'category' => NotificationCatalog::CATEGORY_SECURITY,
            'enabled' => false,
        ]);

        $this->actingAs($user)->patchJson('/api/me/password', [
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationCatalog::CATEGORY_SECURITY, $delivery->category);
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        Mail::assertSent(TransactionalEventMail::class, fn (TransactionalEventMail $mail): bool => $mail->hasTo($user->email)
            && $mail->unsubscribeUrl === null);
    }

    public function test_unsubscribe_link_disables_only_its_own_category(): void
    {
        $user = $this->user();

        $url = URL::temporarySignedRoute('notifications.unsubscribe', now()->addDay(), [
            'user' => $user->id,
            'category' => NotificationCatalog::CATEGORY_REVIEWS,
        ]);
        $this->getJson($url)->assertOk();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id, 'channel' => 'email', 'category' => 'reviews', 'enabled' => false,
        ]);
        $this->assertDatabaseMissing('notification_preferences', [
            'user_id' => $user->id, 'category' => 'orders',
        ]);

        // Unsigned and expired links are refused, and a signed link cannot be edited into a
        // different category because the category is part of the signature.
        $this->getJson("/api/notifications/unsubscribe/{$user->id}/orders")->assertForbidden();
        $this->getJson($url.'&category=orders')->assertForbidden();

        $mandatory = URL::temporarySignedRoute('notifications.unsubscribe', now()->addDay(), [
            'user' => $user->id,
            'category' => NotificationCatalog::CATEGORY_SECURITY,
        ]);
        $this->getJson($mandatory)->assertStatus(422);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
