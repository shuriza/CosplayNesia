<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PublicCatalogController extends Controller
{
    private const CATEGORIES = [
        'anime' => 'Anime',
        'game' => 'Game',
        'vtuber' => 'VTuber',
        'aksesoris' => 'Aksesoris',
    ];

    public function category(string $category): Response
    {
        $label = self::CATEGORIES[strtolower($category)] ?? abort(404);

        return $this->catalogPage(
            request(),
            $this->visibleProducts()->where('category', $label),
            "Kostum cosplay {$label}",
            "Temukan kostum {$label} untuk sewa dan beli dari toko cosplay terverifikasi di Indonesia.",
            url('/kategori/'.Str::slug($label)),
        );
    }

    public function city(string $city): Response
    {
        $products = $this->visibleProducts()->whereRaw("replace(lower(city), ' ', '-') = ?", [strtolower($city)]);
        $label = $products->clone()->value('city');
        abort_if($label === null, 404);

        return $this->catalogPage(
            request(),
            $products,
            "Kostum cosplay di {$label}",
            "Sewa dan beli kostum cosplay dari seller di {$label}, dengan pilihan karakter, ukuran, dan tipe produk yang beragam.",
            url('/kota/'.Str::slug($label)),
        );
    }

    public function store(Store $store): Response
    {
        abort_unless($store->isApproved(), 404);

        return $this->catalogPage(
            request(),
            $this->visibleProducts()->where('store_id', $store->id),
            $store->name.' — Toko cosplay di '.$store->city,
            $store->description ?: "Lihat koleksi kostum cosplay dari {$store->name} di {$store->city}.",
            url('/toko/'.$store->slug),
            $store,
        );
    }

    /**
     * The single definition of "a publicly visible listing": published, and either ownerless demo
     * data or owned by an approved store. The sitemap must enumerate exactly the set of listings
     * that the crawlable pages can serve, so the sitemap shares this builder.
     *
     * @return Builder
     */
    public static function visibleProducts()
    {
        return Product::query()
            ->published()
            ->where(function ($query): void {
                $query->whereNull('seller_id')->orWhereHas('store', fn ($store) => $store->publiclyVisible());
            })
            ->with(['images', 'variants', 'store:id,slug,name,status'])
            ->withReviewSummary()
            ->orderByDesc('popular')
            ->orderByDesc('id');
    }

    private function catalogPage(Request $request, $products, string $title, string $description, string $canonical, ?Store $store = null): Response
    {
        $page = $products->cursorPaginate(24, cursorName: 'cursor');

        return response()->view('catalog', [
            'products' => $page->items(),
            'nextCursor' => $page->nextCursor()?->encode(),
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'store' => $store,
        ]);
    }
}
