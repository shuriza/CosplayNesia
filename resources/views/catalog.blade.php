<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="robots" content="index,follow">
    <meta name="theme-color" content="#5145cd">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='18' fill='%235145cd'/%3E%3Cpath d='M43 22c-3-4-7-6-12-6-9 0-15 7-15 16s6 16 15 16c5 0 9-2 12-6l-6-5c-2 2-4 3-6 3-4 0-7-3-7-8s3-8 7-8c2 0 4 1 6 3z' fill='white'/%3E%3C/svg%3E">
    @vite('resources/css/app.css')
    <title>{{ $title }} | CosplayNesia</title>
</head>
<body>
<header class="site-header">
    <div class="container header-main">
        <a class="brand" href="{{ url('/') }}" aria-label="CosplayNesia beranda"><span class="brand__mark" aria-hidden="true"><span>C</span></span><span class="brand__name">CosplayNesia</span></a>
        <a class="button button--primary" href="{{ url('/#produk') }}">Jelajahi semua kostum</a>
    </div>
</header>
<main>
    <section class="seo-hero container" aria-labelledby="catalog-title">
        <span class="eyebrow"><span class="pulse"></span> Koleksi publik CosplayNesia</span>
        <h1 id="catalog-title">{{ $title }}</h1>
        <p>{{ $description }}</p>
        @if ($store)
            <div class="seo-store-meta"><strong>{{ $store->name }}</strong><span>{{ $store->city }}</span></div>
        @endif
    </section>
    <section class="section section--products" aria-labelledby="products-title">
        <div class="container">
            <div class="section-heading"><div><span class="section-kicker">Listing terbit</span><h2 id="products-title">Koleksi pilihan</h2></div><span id="result-count">{{ count($products) }} produk</span></div>
            @if (count($products) === 0)
                <div class="empty-state"><span aria-hidden="true">⌕</span><h3>Belum ada kostum di halaman ini</h3><p>Kembali ke koleksi utama untuk melihat listing lainnya.</p><a class="button button--primary" href="{{ url('/#produk') }}">Lihat semua produk</a></div>
            @else
                <div class="product-grid">
                    @foreach ($products as $product)
                        @php($variant = $product->variants->first())
                        <article class="product-card seo-product-card">
                            <div class="product-card__image"><img src="{{ $product->image ?: config('cosplaynesia.default_product_image') }}" alt="{{ $product->name }}" loading="lazy"><span class="badge {{ strtolower((string) $product->badge) === 'baru' ? 'badge--new' : '' }}">{{ $product->badge ?: $product->type }}</span></div>
                            <div class="product-card__body">
                                <span class="product-card__series">{{ $product->series }}</span>
                                <h3>{{ $product->name }}</h3>
                                <div class="product-card__meta"><span class="meta-pill">{{ $product->type }}</span><span class="meta-pill">{{ $variant?->size ?: $product->size }}</span><span>★ {{ $product->rating ? number_format((float) $product->rating, 1) : 'Baru' }}</span></div>
                                <div class="seller"><span class="seller__avatar">{{ strtoupper(mb_substr($product->seller, 0, 1)) }}</span><span>{{ $product->seller }}</span><span class="verified" aria-label="Terverifikasi">✓</span></div>
                                <div class="product-card__price"><span><strong>Rp{{ number_format((int) $product->price, 0, ',', '.') }}</strong><small>{{ $product->type === 'Sewa' ? ' / hari' : 'Harga jual' }}</small></span></div>
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($nextCursor)
                    @php($nextUrl = $canonical.'?cursor='.urlencode($nextCursor))
                    <a class="button button--outline load-more" href="{{ $nextUrl }}" rel="next">Halaman berikutnya</a>
                    <link rel="next" href="{{ $nextUrl }}">
                @endif
            @endif
        </div>
    </section>
</main>
<footer class="footer"><div class="container footer__bottom"><p>© {{ now()->year }} CosplayNesia. Marketplace kostum cosplay Indonesia.</p><a href="{{ url('/sitemap.xml') }}">Peta situs</a></div></footer>
</body>
</html>
