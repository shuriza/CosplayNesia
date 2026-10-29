<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RentalReservation;
use App\Models\SavedSearch;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\NotificationCatalog;
use App\Services\SavedSearchScanner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_saves_updates_and_deletes_a_search_with_validated_filters(): void
    {
        $user = $this->user();

        $created = $this->actingAs($user)->postJson('/api/saved-searches', [
            'name' => 'Kostum Game Bandung',
            'filters' => [
                'category' => 'Game',
                'city' => 'Bandung',
                'type' => Product::TYPE_RENTAL,
                'start_date' => Carbon::today(config('app.timezone'))->addDays(3)->toDateString(),
                'end_date' => Carbon::today(config('app.timezone'))->addDays(4)->toDateString(),
            ],
        ])->assertCreated()
            ->assertJsonPath('alert_enabled', true)
            ->assertJsonPath('filters.category', 'Game');

        $searchId = $created->json('id');

        // A filter the catalog endpoint rejects cannot enter the alert system: stored payloads are
        // input, not trusted configuration.
        $this->actingAs($user)->postJson('/api/saved-searches', [
            'name' => 'Ilegal',
            'filters' => ['category' => 'Horor'],
        ])->assertUnprocessable()->assertJsonValidationErrors('filters.category');

        $this->actingAs($user)->patchJson("/api/saved-searches/{$searchId}", [
            'alert_enabled' => false,
        ])->assertOk()->assertJsonPath('alert_enabled', false);

        $this->actingAs($user)->patchJson("/api/saved-searches/{$searchId}", [
            'name' => 'Kostum Game Bandung v2',
        ])->assertOk()->assertJsonPath('name', 'Kostum Game Bandung v2');

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/saved-searches/{$searchId}")
            ->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/saved-searches/{$searchId}")->assertNoContent();
        $this->assertDatabaseMissing('saved_searches', ['id' => $searchId]);
    }

    public function test_scanner_notifies_new_matches_once_and_advances_the_cursor(): void
    {
        Bus::fake();
        $user = $this->user();
        $search = SavedSearch::create([
            'user_id' => $user->id,
            'name' => 'Kostum Game',
            'filters' => ['category' => 'Game', 'type' => Product::TYPE_RENTAL],
            'alert_enabled' => true,
        ]);

        $gameRental = Product::factory()->create(['category' => 'Game', 'type' => Product::TYPE_RENTAL, 'popular' => 10]);
        $animeRental = Product::factory()->create(['category' => 'Anime', 'type' => Product::TYPE_RENTAL, 'popular' => 99]);
        $draftGame = Product::factory()->create(['category' => 'Game', 'type' => Product::TYPE_RENTAL, 'status' => Product::STATUS_DRAFT]);

        $summary = app(SavedSearchScanner::class)->scanOne($search->fresh());

        $this->assertSame(1, $summary['matched']);
        $this->assertSame(1, $summary['notified']);
        $this->assertDatabaseHas('user_notifications', [
            'recipient_id' => $user->id,
            'type' => UserNotification::TYPE_NEW_MATCH,
        ]);
        $this->assertSame((int) $gameRental->id, (int) $search->fresh()->last_matched_product_id);

        // A re-scan must not re-announce: the cursor is the contract that an alert is sent once.
        $second = app(SavedSearchScanner::class)->scanOne($search->fresh());
        $this->assertSame(0, $second['matched']);
        $this->assertSame(0, $second['notified']);

        // A listing that becomes visible later with a higher id is announced on the next scan.
        $later = Product::factory()->create(['category' => 'Game', 'type' => Product::TYPE_RENTAL, 'popular' => 1]);
        $third = app(SavedSearchScanner::class)->scanOne($search->fresh());
        $this->assertSame(1, $third['matched']);
        $this->assertSame($later->id, UserNotification::query()
            ->where('type', UserNotification::TYPE_NEW_MATCH)
            ->latest('id')
            ->first()
            ->payload['product_id'] ?? null);

        // Exactly one announcement per matching product, and only for products the filter allows.
        $announcedIds = UserNotification::query()
            ->where('type', UserNotification::TYPE_NEW_MATCH)
            ->get()
            ->map(fn (UserNotification $notification) => (int) ($notification->payload['product_id'] ?? 0))
            ->all();
        $this->assertSame([$gameRental->id, $later->id], $announcedIds);
        $this->assertNull(UserNotification::query()->where('payload->product_id', $animeRental->id)->first());
        $this->assertNull(UserNotification::query()->where('payload->product_id', $draftGame->id)->first());
    }

    public function test_availability_alert_fires_only_when_a_matching_sku_is_free(): void
    {
        Bus::fake();
        $user = $this->user();
        $dates = [
            'start_date' => Carbon::today(config('app.timezone'))->addDays(5)->toDateString(),
            'end_date' => Carbon::today(config('app.timezone'))->addDays(6)->toDateString(),
        ];
        $search = SavedSearch::create([
            'user_id' => $user->id,
            'name' => 'Sewa Weekend',
            'filters' => ['type' => Product::TYPE_RENTAL, ...$dates],
            'alert_enabled' => true,
        ]);

        $free = Product::factory()->create(['type' => Product::TYPE_RENTAL, 'stock' => 2]);
        $busy = Product::factory()->create(['type' => Product::TYPE_RENTAL, 'stock' => 1]);
        $buyer = $this->user();
        $order = $buyer->orders()->create(['total_amount' => 100000, 'status' => 'processing']);
        $item = $order->items()->create([
            'product_id' => $busy->id,
            'product_variant_id' => $busy->variants()->sole()->id,
            'product_name' => $busy->name,
            'product_type' => Product::TYPE_RENTAL,
            'unit_price' => 100000,
            'quantity' => 1,
        ]);
        RentalReservation::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $busy->id,
            'product_variant_id' => $busy->variants()->sole()->id,
            'quantity' => 1,
            ...$dates,
            'status' => RentalReservation::STATUS_RESERVED,
        ]);

        $summary = app(SavedSearchScanner::class)->scanOne($search->fresh());

        $this->assertSame(1, $summary['notified']);
        $payloads = UserNotification::query()
            ->where('type', UserNotification::TYPE_AVAILABILITY_ALERT)
            ->get()
            ->map(fn (UserNotification $notification) => (int) ($notification->payload['product_id'] ?? 0))
            ->all();
        $this->assertSame([$free->id], $payloads);
    }

    public function test_notification_routes_into_the_discovery_category_and_respects_mutes(): void
    {
        Bus::fake();
        $user = $this->user();
        // Discovery is mailable and NOT mandatory, so a muted user must get a suppressed outbox
        // row instead of an email — the same contract as every other opt-out category.
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id,
            'channel' => NotificationCatalog::CHANNEL_EMAIL,
            'category' => NotificationCatalog::CATEGORY_DISCOVERY,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $search = SavedSearch::create([
            'user_id' => $user->id,
            'name' => 'Pantau VTuber',
            'filters' => ['category' => 'VTuber'],
            'alert_enabled' => true,
        ]);
        Product::factory()->create(['category' => 'VTuber']);

        app(SavedSearchScanner::class)->scanOne($search->fresh());

        $this->assertDatabaseHas('notification_deliveries', [
            'recipient_id' => $user->id,
            'category' => NotificationCatalog::CATEGORY_DISCOVERY,
            'status' => 'suppressed',
        ]);
    }

    public function test_command_scans_every_enabled_search_and_reports_errors(): void
    {
        Bus::fake();
        $user = $this->user();
        SavedSearch::create([
            'user_id' => $user->id,
            'name' => 'Aktif',
            'filters' => ['category' => 'Game'],
            'alert_enabled' => true,
        ]);
        SavedSearch::create([
            'user_id' => $user->id,
            'name' => 'Dimatikan',
            'filters' => ['category' => 'Game'],
            'alert_enabled' => false,
        ]);
        Product::factory()->create(['category' => 'Game']);

        $this->artisan('searches:scan')->assertSuccessful();

        $this->assertSame(1, UserNotification::query()->where('type', UserNotification::TYPE_NEW_MATCH)->count(), 'Command scan harus menghasilkan tepat satu notifikasi untuk search aktif.');
        // The disabled search must not have produced anything at all.
        $disabled = SavedSearch::query()->where('alert_enabled', false)->sole();
        $this->assertNull(UserNotification::query()->where('event_key', 'like', "saved-search:{$disabled->id}:%")->first());
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
