<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    public function index(): Response
    {
        // Enumerate from the same query the public pages serve. A sitemap URL that 404s wastes
        // crawl budget and reads as a site-quality defect, so visibility cannot drift here.
        // distinct() runs on city alone: PostgreSQL has no equality operator for the products
        // table's json columns, so a table-wide DISTINCT would fail there.
        $cityLabels = Product::query()
            ->published()
            ->where(function ($query): void {
                $query->whereNull('seller_id')->orWhereHas('store', fn ($store) => $store->publiclyVisible());
            })
            ->whereNotNull('city')
            ->distinct()
            ->pluck('city');

        $urls = collect([
            ['loc' => url('/'), 'lastmod' => now()->toDateString()],
            ...collect(['Anime', 'Game', 'VTuber', 'Aksesoris'])->map(fn (string $category): array => [
                'loc' => url('/kategori/'.Str::slug($category)),
                'lastmod' => now()->toDateString(),
            ]),
            ...$cityLabels->map(fn (string $city): array => [
                'loc' => url('/kota/'.Str::slug($city)),
                'lastmod' => now()->toDateString(),
            ]),
            ...Store::query()->publiclyVisible()->get(['slug', 'updated_at'])->map(fn (Store $store): array => [
                'loc' => url('/toko/'.$store->slug),
                'lastmod' => $store->updated_at?->toDateString() ?? now()->toDateString(),
            ]),
        ])->unique('loc')->values()->all();

        return response()->view('sitemap', compact('urls'))->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
