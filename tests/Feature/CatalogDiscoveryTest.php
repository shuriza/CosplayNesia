<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\RentalReservation;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_filters_by_type_size_city_price_rating_and_condition(): void
    {
        $matching = Product::factory()->create([
            'name' => 'Furina Filtered',
            'type' => Product::TYPE_RENTAL,
            'size' => 'M',
            'city' => 'Bandung',
            'price' => 150000,
        ]);
        $matching->variants()->sole()->update(['condition' => 'like_new', 'size' => 'M']);
        Product::factory()->create([
            'name' => 'Anya Unfiltered',
            'type' => Product::TYPE_SALE,
            'size' => 'S',
            'city' => 'Surabaya',
            'price' => 50000,
        ]);

        $this->getJson('/api/products?type=Sewa')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Furina Filtered');
        $this->getJson('/api/products?size=M')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
        $this->getJson('/api/products?city=bandung')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
        $this->getJson('/api/products?min_price=100000')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
        $this->getJson('/api/products?condition=like_new')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);

        // Ratings are derived from stored reviews, so an unreviewed listing is never "highly rated"
        // just because the product row still carries the legacy default.
        $this->getJson('/api/products?min_rating=4.5')->assertOk()->assertJsonPath('data', []);

        $buyer = User::factory()->create();
        $order = $buyer->orders()->create(['total_amount' => 150000, 'status' => 'processing']);
        $item = $order->items()->create([
            'product_id' => $matching->id,
            'product_name' => $matching->name,
            'product_type' => Product::TYPE_RENTAL,
            'unit_price' => 150000,
            'quantity' => 1,
        ]);
        ProductReview::create([
            'order_item_id' => $item->id,
            'product_id' => $matching->id,
            'seller_id' => $matching->seller_id,
            'user_id' => $buyer->id,
            'rating' => 5,
        ]);

        $this->getJson('/api/products?min_rating=4.5')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_availability_filter_shows_only_products_with_a_free_sku(): void
    {
        $available = Product::factory()->create(['type' => Product::TYPE_RENTAL, 'stock' => 2, 'name' => 'Masih Ada']);
        $rented = Product::factory()->create(['type' => Product::TYPE_RENTAL, 'stock' => 1, 'name' => 'Sudah Penuh']);
        $sale = Product::factory()->create(['type' => Product::TYPE_SALE, 'name' => 'Kostum Jual']);

        $buyer = User::factory()->create();
        $dates = [
            'start_date' => Carbon::today(config('app.timezone'))->addDays(2)->toDateString(),
            'end_date' => Carbon::today(config('app.timezone'))->addDays(3)->toDateString(),
        ];
        $rentedVariant = $rented->variants()->sole();
        $order = $buyer->orders()->create(['total_amount' => 100000, 'status' => 'processing']);
        $item = $order->items()->create([
            'product_id' => $rented->id,
            'product_variant_id' => $rentedVariant->id,
            'product_name' => $rented->name,
            'product_type' => Product::TYPE_RENTAL,
            'unit_price' => 100000,
            'quantity' => 1,
        ]);
        RentalReservation::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $rented->id,
            'product_variant_id' => $rentedVariant->id,
            'quantity' => 1,
            ...$dates,
            'status' => RentalReservation::STATUS_RESERVED,
        ]);

        $this->getJson('/api/products?'.http_build_query([...$dates, 'quantity' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->id);
        $this->getJson('/api/products?'.http_build_query([...$dates, 'quantity' => 3]))
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/products?'.http_build_query([...$dates, 'type' => Product::TYPE_SALE]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sale->id);

        $this->getJson('/api/products?start_date='.$dates['start_date'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->getJson('/api/products?'.http_build_query([
            'start_date' => Carbon::today(config('app.timezone'))->subDay()->toDateString(),
            'end_date' => $dates['end_date'],
        ]))->assertUnprocessable();
        $this->getJson('/api/products?'.http_build_query([
            'start_date' => $dates['start_date'],
            'end_date' => Carbon::parse($dates['start_date'])->addDays(30)->toDateString(),
        ]))->assertUnprocessable();
    }

    public function test_seo_pages_render_metadata_and_canonical_urls(): void
    {
        $store = Store::factory()->create(['slug' => 'kitsune-wardrobe', 'name' => 'Kitsune Wardrobe', 'city' => 'Bandung']);
        Product::factory()->for($store->owner, 'owner')->create([
            'name' => 'Furina Fontaine',
            'category' => 'Game',
            'city' => 'Bandung',
            'store_id' => $store->id,
        ]);

        $this->withoutVite();
        $this->get('/kategori/game')->assertOk()->assertSee('Furina Fontaine')->assertSee('rel="canonical"', false)->assertSee(url('/kategori/game'), false);
        $this->get('/kota/bandung')->assertOk()->assertSee('Kostum cosplay di Bandung')->assertSee('Furina Fontaine', false);
        $this->get('/toko/kitsune-wardrobe')->assertOk()->assertSee('Kitsune Wardrobe')->assertSee('Furina Fontaine', false);
        $this->get('/kategori/manga')->assertNotFound();
        $this->get('/kota/nowhere')->assertNotFound();

        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->assertStringContainsString(url('/kategori/game'), $sitemap->getContent());
        $this->assertStringContainsString(url('/kota/bandung'), $sitemap->getContent());
        $this->assertStringContainsString(url('/toko/kitsune-wardrobe'), $sitemap->getContent());
    }

    public function test_seo_pages_never_expose_unpublished_or_suspended_listings(): void
    {
        $store = Store::factory()->create(['slug' => 'astral-cosrent', 'name' => 'Astral Cosrent', 'city' => 'Jakarta']);
        Product::factory()->for($store->owner, 'owner')->create([
            'name' => 'Hidden Draft',
            'status' => Product::STATUS_DRAFT,
            'city' => 'Jakarta',
            'store_id' => $store->id,
        ]);
        Product::factory()->for($store->owner, 'owner')->create([
            'name' => 'Visible Costume',
            'city' => 'Jakarta',
            'store_id' => $store->id,
        ]);

        $this->withoutVite();
        $this->get('/toko/astral-cosrent')->assertOk()->assertSee('Visible Costume')->assertDontSee('Hidden Draft');

        $store->update(['status' => Store::STATUS_SUSPENDED]);
        $this->get('/toko/astral-cosrent')->assertNotFound();
    }

    /**
     * Regression: size + dates must be judged on the same SKU. A product whose M SKU is fully
     * reserved must not appear for `size=M` just because its L SKU happens to be free.
     */
    public function test_size_and_date_filter_is_judged_on_the_matching_sku(): void
    {
        $product = Product::factory()->create(['type' => Product::TYPE_RENTAL, 'size' => 'M', 'stock' => 1, 'name' => 'Two Size Costume']);
        $medium = $product->variants()->sole();
        $medium->update(['size' => 'M']);
        $product->variants()->create([
            'sku' => 'TWO-SIZE-L',
            'label' => 'Large',
            'size' => 'L',
            'condition' => 'good',
            'stock' => 2,
            'position' => 1,
            'is_default' => false,
        ]);
        $product->forceFill(['stock' => 3])->save();

        $buyer = User::factory()->create();
        $dates = [
            'start_date' => Carbon::today(config('app.timezone'))->addDays(5)->toDateString(),
            'end_date' => Carbon::today(config('app.timezone'))->addDays(6)->toDateString(),
        ];
        $order = $buyer->orders()->create(['total_amount' => 100000, 'status' => 'processing']);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $medium->id,
            'product_name' => $product->name,
            'product_type' => Product::TYPE_RENTAL,
            'unit_price' => 100000,
            'quantity' => 1,
        ]);
        RentalReservation::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'product_variant_id' => $medium->id,
            'quantity' => 1,
            ...$dates,
            'status' => RentalReservation::STATUS_RESERVED,
        ]);

        // M is fully reserved: the product must disappear from an M-scoped rental search even
        // though L has capacity, and must stay visible without the size constraint.
        $this->getJson('/api/products?'.http_build_query(['size' => 'M', ...$dates]))
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/products?'.http_build_query($dates))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id);
        $this->getJson('/api/products?'.http_build_query(['size' => 'L', ...$dates]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id);
    }

    /**
     * Regression: the sitemap must not advertise a city page that 404s. A city whose only
     * listings belong to a suspended store has no public page, so it must be absent from the
     * sitemap too — both sides read from the same visibility definition.
     */
    public function test_sitemap_omits_cities_whose_public_page_is_unavailable(): void
    {
        $suspended = Store::factory()->create(['slug' => 'suspended-shop', 'status' => Store::STATUS_SUSPENDED, 'city' => 'Kota Rahasia']);
        Product::factory()->for($suspended->owner, 'owner')->create([
            'name' => 'Hidden Listing',
            'city' => 'Kota Rahasia',
            'store_id' => $suspended->id,
        ]);
        $approved = Store::factory()->create(['slug' => 'approved-shop', 'status' => Store::STATUS_APPROVED, 'city' => 'Kota Terbuka']);
        Product::factory()->for($approved->owner, 'owner')->create([
            'name' => 'Visible Listing',
            'city' => 'Kota Terbuka',
            'store_id' => $approved->id,
        ]);

        $this->withoutVite();
        $this->get('/kota/kota-terbuka')->assertOk();
        $this->get('/kota/kota-rahasia')->assertNotFound();

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/kota/kota-terbuka', $sitemap);
        $this->assertStringNotContainsString('/kota/kota-rahasia', $sitemap);
    }

    public function test_seo_pages_paginate_with_stable_urls(): void
    {
        $store = Store::factory()->create(['slug' => 'paged-shop', 'status' => Store::STATUS_APPROVED, 'city' => 'Bandung']);
        Product::factory()->count(30)->for($store->owner, 'owner')->create([
            'city' => 'Bandung',
            'store_id' => $store->id,
        ]);

        $this->withoutVite();
        $first = $this->get('/toko/paged-shop')->assertOk();
        $this->assertCount(24, $first->viewData('products'));
        $nextUrl = url('/toko/paged-shop').'?cursor='.urlencode($first->viewData('nextCursor'));

        $second = $this->get($nextUrl)->assertOk();
        $this->assertCount(6, $second->viewData('products'));
        $this->assertStringContainsString($nextUrl, $first->getContent());
        $this->assertNull($second->viewData('nextCursor'));

        // Laravel treats an invalid cursor as page one rather than an error; assert it degrades
        // safely instead of 500-ing or leaking a different scope's listings.
        $garbage = $this->get('/toko/paged-shop?cursor='.urlencode('garbage'))->assertOk();
        $this->assertCount(24, $garbage->viewData('products'));
    }
}
