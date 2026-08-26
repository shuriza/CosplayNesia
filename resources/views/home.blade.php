<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="CosplayNesia, marketplace sewa dan beli kostum cosplay dari kreator lokal Indonesia.">
    <meta name="theme-color" content="#5145cd">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='18' fill='%235145cd'/%3E%3Cpath d='M43 22c-3-4-7-6-12-6-9 0-15 7-15 16s6 16 15 16c5 0 9-2 12-6l-6-5c-2 2-4 3-6 3-4 0-7-3-7-8s3-8 7-8c2 0 4 1 6 3z' fill='white'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Plus+Jakarta+Sans:wght@600;700;800&amp;display=swap" rel="stylesheet">
    <title>CosplayNesia | Sewa &amp; Beli Kostum Cosplay</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="announcement">
        <div class="container announcement__inner">
            <p><strong>Gratis ongkir</strong> hingga Rp25.000 untuk transaksi pertamamu</p>
            <a href="#cara-kerja">Lihat caranya <span aria-hidden="true">→</span></a>
        </div>
    </div>

    <header class="site-header">
        <div class="container header-main">
            <a class="brand" href="#top" aria-label="CosplayNesia beranda">
                <span class="brand__mark" aria-hidden="true"><span>C</span></span>
                <span class="brand__name">CosplayNesia</span>
            </a>
            <form class="search" id="search-form" role="search">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"></path></svg>
                <input id="search-input" type="search" placeholder="Cari kostum, karakter, atau series..." autocomplete="off" aria-label="Cari produk">
                <button type="submit">Cari</button>
            </form>
            <nav class="header-actions" aria-label="Akun dan keranjang">
                <button type="button" class="icon-button" id="cart-button" aria-label="Buka keranjang, 0 produk">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 3h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 7H6m4 13h.01M18 20h.01"></path></svg>
                    <span class="cart-count" id="cart-count" aria-hidden="true">0</span>
                </button>
                <button type="button" class="button button--ghost auth-action" data-auth-mode="login">Masuk</button>
                <button type="button" class="button button--primary auth-action" data-auth-mode="register">Daftar</button>
                <button type="button" class="button button--ghost profile-action" hidden>Profil</button>
            </nav>
        </div>
        <div class="container mobile-search-wrap">
            <form class="search search--mobile" id="mobile-search-form" role="search">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"></path></svg>
                <input id="mobile-search-input" type="search" placeholder="Cari kostum atau karakter..." autocomplete="off" aria-label="Cari produk">
                <button type="submit" class="sr-only">Cari</button>
            </form>
        </div>
        <nav class="category-nav" aria-label="Navigasi kategori">
            <div class="container category-nav__scroll">
                <button type="button" class="nav-link active" data-filter="Semua">Semua</button>
                <button type="button" class="nav-link" data-filter="Terbaru">Terbaru</button>
                <button type="button" class="nav-link" data-filter="Terlaris">Terlaris</button>
                <button type="button" class="nav-link" data-filter="Anime">Anime</button>
                <button type="button" class="nav-link" data-filter="Game">Game</button>
                <button type="button" class="nav-link" data-filter="VTuber">VTuber</button>
                <button type="button" class="nav-link" data-filter="Aksesoris">Aksesoris</button>
                <a class="nav-link nav-link--seller" href="#seller">Mulai Jualan <span aria-hidden="true">↗</span></a>
            </div>
        </nav>
    </header>

    <main id="top">
        <section class="hero container" aria-labelledby="hero-title">
            <div class="hero__copy">
                <span class="eyebrow"><span class="pulse"></span> Marketplace cosplay terpercaya</span>
                <h1 id="hero-title">Jadi karakter favoritmu, <em>tanpa ribet.</em></h1>
                <p>Temukan kostum berkualitas dari ratusan cosplayer dan rental terpercaya di seluruh Indonesia.</p>
                <div class="hero__actions">
                    <a href="#produk" class="button button--primary button--large">Jelajahi kostum</a>
                    <a href="#cara-kerja" class="button button--soft button--large"><span class="play" aria-hidden="true">▶</span> Cara kerja</a>
                </div>
                <div class="hero__proof">
                    <div class="avatars" aria-hidden="true">
                        <img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&amp;fit=crop&amp;w=80&amp;q=80" alt="">
                        <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&amp;fit=crop&amp;w=80&amp;q=80" alt="">
                        <img src="https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&amp;fit=crop&amp;w=80&amp;q=80" alt="">
                    </div>
                    <p><strong>4.9/5</strong> dari 2.000+ transaksi</p>
                </div>
            </div>
            <div class="hero__visual" role="img" aria-label="Koleksi kostum pilihan">
                <div class="hero-card hero-card--main"><img src="https://images.unsplash.com/photo-1578632767115-351597cf2477?auto=format&amp;fit=crop&amp;w=900&amp;q=85" alt="Koleksi karakter anime"><span class="hero-card__tag">Pilihan minggu ini</span></div>
                <div class="hero-card hero-card--small"><img src="https://images.unsplash.com/photo-1614583225154-5fcdda07019e?auto=format&amp;fit=crop&amp;w=500&amp;q=85" alt="Aksesoris karakter"></div>
                <div class="floating-card floating-card--top"><span class="floating-icon floating-icon--green">✓</span><span><strong>Checkout praktis</strong><small>Alur transaksi untuk kebutuhan demo</small></span></div>
                <div class="floating-card floating-card--bottom"><span class="floating-icon">✦</span><span><strong>1.200+</strong><small>Kostum tersedia</small></span></div>
            </div>
        </section>

        <section class="trust-bar" aria-label="Keunggulan CosplayNesia">
            <div class="container trust-grid">
                <article><span class="trust-icon" aria-hidden="true">✓</span><span><strong>Transaksi transparan</strong><small>Harga dan stok diverifikasi saat checkout demo</small></span></article>
                <article><span class="trust-icon" aria-hidden="true">◇</span><span><strong>Pengiriman terintegrasi</strong><small>Lacak paket langsung dari satu tempat</small></span></article>
                <article><span class="trust-icon" aria-hidden="true">↻</span><span><strong>Fleksibel sewa &amp; beli</strong><small>Pilih durasi sesuai jadwal eventmu</small></span></article>
            </div>
        </section>

        <section class="section container" aria-labelledby="kategori-title">
            <div class="section-heading"><div><span class="section-kicker">Cari berdasarkan dunia</span><h2 id="kategori-title">Kategori populer</h2></div><button type="button" class="text-link" data-filter-trigger="Semua">Lihat semua <span aria-hidden="true">→</span></button></div>
            <div class="category-grid">
                <button type="button" class="category-card" data-filter-trigger="Game"><img src="https://images.unsplash.com/photo-1511512578047-dfb367046420?auto=format&amp;fit=crop&amp;w=500&amp;q=80" alt=""><span><strong>Genshin Impact</strong><small>Koleksi kostum game</small></span></button>
                <button type="button" class="category-card" data-filter-trigger="Anime"><img src="https://images.unsplash.com/photo-1578632767115-351597cf2477?auto=format&amp;fit=crop&amp;w=500&amp;q=80" alt=""><span><strong>Anime</strong><small>Koleksi anime populer</small></span></button>
                <button type="button" class="category-card" data-filter-trigger="Game"><img src="https://images.unsplash.com/photo-1542751371-adc38448a05e?auto=format&amp;fit=crop&amp;w=500&amp;q=80" alt=""><span><strong>Honkai: Star Rail</strong><small>Koleksi trailblazer</small></span></button>
                <button type="button" class="category-card" data-filter-trigger="VTuber"><img src="https://images.unsplash.com/photo-1637858868799-7f26a0640eb6?auto=format&amp;fit=crop&amp;w=500&amp;q=80" alt=""><span><strong>VTuber</strong><small>Koleksi virtual talent</small></span></button>
                <button type="button" class="category-card" data-filter-trigger="Aksesoris"><span class="category-card__more" aria-hidden="true">＋</span><span><strong>Lainnya</strong><small>Semua kategori</small></span></button>
            </div>
        </section>

        <section class="section section--products" id="produk" aria-labelledby="produk-title">
            <div class="container">
                <div class="section-heading product-heading">
                    <div><span class="section-kicker">Koleksi terkurasi</span><h2 id="produk-title">Lagi banyak dicari</h2></div>
                    <div class="product-controls"><span id="result-count" aria-live="polite">Memuat produk…</span><label class="sort-select"><span>Urutkan:</span><select id="sort-select" aria-label="Urutkan produk"><option value="popular">Terpopuler</option><option value="newest">Terbaru</option><option value="low">Harga terendah</option><option value="high">Harga tertinggi</option></select></label></div>
                </div>
                <div class="product-grid" id="product-grid" aria-busy="true"></div>
                <div class="empty-state" id="empty-state" hidden><span aria-hidden="true">⌕</span><h3>Kostum belum ditemukan</h3><p>Coba kata kunci lain atau tampilkan semua koleksi.</p><button type="button" class="button button--primary" id="reset-search">Tampilkan semua</button></div>
                <button type="button" class="button button--outline load-more" id="load-more" hidden>Tampilkan lebih banyak</button>
            </div>
        </section>

        <section class="container editorial-banner" id="seller">
            <div class="editorial-banner__copy"><span class="eyebrow eyebrow--light">Untuk kreator &amp; rental</span><h2>Kostummu bisa jadi awal cerita orang lain.</h2><p>Buka toko gratis, atur kalender sewa, dan jangkau komunitas cosplay di seluruh Indonesia.</p><button type="button" class="button button--white seller-action">Buka toko sekarang <span aria-hidden="true">→</span></button></div>
            <div class="seller-stats" role="group" aria-label="Statistik penjual"><div><strong>350+</strong><span>Penjual aktif</span></div><div><strong>50+</strong><span>Kota terjangkau</span></div><div><strong>0%</strong><span>Biaya buka toko</span></div></div>
        </section>

        <section class="section container how" id="cara-kerja" aria-labelledby="how-title">
            <div class="section-heading section-heading--center"><div><span class="section-kicker">Mudah dan terlindungi</span><h2 id="how-title">Tiga langkah jadi karakter impian</h2></div></div>
            <div class="steps"><article><span>01</span><h3>Pilih kostum</h3><p>Cari berdasarkan karakter, ukuran, lokasi, dan tanggal eventmu.</p></article><article><span>02</span><h3>Buat pesanan demo</h3><p>Konfirmasi keranjang untuk melihat simulasi checkout dan pengurangan stok.</p></article><article><span>03</span><h3>Siap tampil</h3><p>Lihat riwayat pesananmu dan lanjutkan persiapan event berikutnya.</p></article></div>
        </section>
    </main>

    <footer class="footer">
        <div class="container footer__main">
            <div class="footer__brand"><a class="brand brand--light" href="#top"><span class="brand__mark"><span>C</span></span><span class="brand__name">CosplayNesia</span></a><p>Marketplace aman untuk sewa dan beli kostum cosplay dari komunitas Indonesia.</p></div>
            <div class="footer__links"><h3>CosplayNesia</h3><a href="#cara-kerja">Tentang kami</a><a href="#seller">Jadi penjual</a><a href="#produk">Koleksi</a></div>
            <div class="footer__links"><h3>Bantuan</h3><a href="#cara-kerja">Cara menyewa</a><a href="#cara-kerja">Keamanan</a></div>
            <div class="footer__links"><h3>Legal</h3><a href="#top">Syarat &amp; ketentuan</a><a href="#top">Kebijakan privasi</a></div>
        </div>
        <div class="container footer__bottom"><p>© 2026 CosplayNesia. Dibuat untuk komunitas cosplay Indonesia.</p><p>Indonesia · IDR</p></div>
    </footer>

    <nav class="mobile-tabbar" aria-label="Navigasi mobile">
        <a class="active" href="#top"><span aria-hidden="true">⌂</span><span>Beranda</span></a>
        <a href="#kategori-title"><span aria-hidden="true">▦</span><span>Kategori</span></a>
        <button type="button" id="mobile-favorites-button"><span aria-hidden="true">♡</span><span>Favorit</span></button>
        <button type="button" id="mobile-cart-button"><span aria-hidden="true">🛒</span><span>Keranjang</span><b id="mobile-cart-count" aria-hidden="true">0</b></button>
        <button type="button" class="mobile-account-action"><span aria-hidden="true">♙</span><span class="mobile-account-label">Akun</span></button>
    </nav>

    <div class="overlay" id="overlay" hidden></div>

    <aside class="cart-drawer" id="cart-drawer" role="dialog" aria-modal="true" aria-labelledby="cart-title" aria-hidden="true" inert>
        <div class="drawer-header"><div><span class="section-kicker">Pesananmu</span><h2 id="cart-title">Keranjang</h2></div><button type="button" class="close-button" data-close-layer aria-label="Tutup keranjang">×</button></div>
        <div class="cart-items" id="cart-items"></div>
        <div class="cart-empty" id="cart-empty"><span aria-hidden="true">🛍</span><h3>Keranjangmu masih kosong</h3><p>Yuk, temukan kostum untuk event berikutnya.</p><button type="button" class="button button--primary" id="shop-now">Mulai belanja</button></div>
        <div class="cart-summary" id="cart-summary" hidden><div><span>Subtotal</span><strong id="cart-subtotal">Rp0</strong></div><small>Untuk sewa, tanggal mulai dan selesai bersifat inklusif (maksimal 30 hari). Ketersediaan dikonfirmasi saat checkout.</small><form class="checkout-handoff-form" id="checkout-handoff-form" novalidate><fieldset><legend>Data penyerahan</legend><label>Nama penerima<input id="checkout-recipient-name" name="recipient_name" type="text" autocomplete="name" minlength="2" maxlength="80" required></label><div class="form-row"><label>Nomor WhatsApp<input id="checkout-recipient-phone" name="recipient_phone" type="tel" inputmode="tel" autocomplete="tel" minlength="8" maxlength="24" required placeholder="0812 3456 7890"></label><label>Email<input id="checkout-recipient-email" name="recipient_email" type="email" autocomplete="email" maxlength="255" required></label></div><label>Alamat<input name="address_line1" type="text" autocomplete="street-address" minlength="5" maxlength="255" required></label><label>Detail alamat (opsional)<input name="address_line2" type="text" maxlength="255"></label><div class="form-row"><label>Kota<input name="city" type="text" autocomplete="address-level2" minlength="2" maxlength="80" required></label><label>Provinsi<input name="province" type="text" autocomplete="address-level1" minlength="2" maxlength="80" required></label></div><label>Kode pos<input name="postal_code" type="text" inputmode="numeric" pattern="[0-9]{5}" minlength="5" maxlength="5" autocomplete="postal-code" required></label><label>Catatan penyerahan (opsional)<textarea name="handoff_note" maxlength="500" rows="3"></textarea></label><p class="form-error" id="checkout-handoff-error" role="alert" hidden></p></fieldset></form><button type="button" class="button button--primary button--full" id="checkout-button">Lanjut checkout</button></div>
    </aside>

    <section class="modal" id="product-modal" role="dialog" aria-modal="true" aria-labelledby="modal-product-title" aria-hidden="true" inert>
        <button type="button" class="close-button modal__close" data-close-layer aria-label="Tutup detail produk">×</button>
        <div id="modal-content"></div>
    </section>

    <section class="modal auth-modal" id="auth-modal" role="dialog" aria-modal="true" aria-labelledby="auth-title" aria-describedby="auth-description" aria-hidden="true" inert>
        <button type="button" class="close-button modal__close" data-close-layer aria-label="Tutup autentikasi">×</button>
        <span class="brand__mark auth-mark" aria-hidden="true"><span>C</span></span><span class="section-kicker">Selamat datang</span>
        <h2 id="auth-title">Masuk ke CosplayNesia</h2><p id="auth-description">Simpan favorit, kelola pesanan, dan mulai berjualan dalam satu akun.</p>
        <div class="auth-switch" role="group" aria-label="Pilih autentikasi"><button type="button" data-switch-auth="login" aria-pressed="true">Masuk</button><button type="button" data-switch-auth="register" aria-pressed="false">Daftar</button></div>
        <form id="auth-form" novalidate>
            <label id="name-field" hidden>Nama lengkap<input id="auth-name" name="name" type="text" autocomplete="name" minlength="2" placeholder="Nama kamu"></label>
            <label>Email<input id="auth-email" name="email" type="email" required autocomplete="email" placeholder="nama@email.com"></label>
            <label>Kata sandi<input id="auth-password" name="password" type="password" required minlength="8" autocomplete="current-password" placeholder="Minimal 8 karakter"></label>
            <label id="password-confirmation-field" hidden>Konfirmasi kata sandi<input id="auth-password-confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" placeholder="Ulangi kata sandi"></label>
            <p class="form-error" id="auth-error" role="alert" hidden></p>
            <button class="button button--primary button--full" type="submit">Masuk</button>
        </form>
        <small>Dengan melanjutkan, kamu menyetujui syarat dan kebijakan privasi CosplayNesia.</small>
    </section>

    <aside class="cart-drawer profile-drawer" id="profile-drawer" role="dialog" aria-modal="true" aria-labelledby="profile-title" aria-hidden="true" inert>
        <div class="drawer-header"><div><span class="section-kicker">Akunmu</span><h2 id="profile-title">Profil Saya</h2></div><button type="button" class="close-button" data-close-layer aria-label="Tutup profil">×</button></div>
        <div class="profile-scroll">
            <div class="profile-info"><div class="profile-avatar" aria-hidden="true">C</div><div><strong id="profile-name">Cosplayer</strong><span id="profile-email"></span></div></div>
            <div class="profile-actions"><button type="button" class="button button--primary" id="add-product-button">Tambah produk</button><button type="button" class="button button--outline" id="logout-button">Keluar</button></div>
            <section class="profile-section" aria-labelledby="my-products-title"><div class="profile-section__heading"><h3 id="my-products-title">Toko Saya</h3><button type="button" class="text-link" id="refresh-profile">Muat ulang</button></div><div class="profile-list" id="my-products-list" aria-live="polite"></div></section>
            <section class="profile-section" aria-labelledby="incoming-orders-title"><div class="profile-section__heading"><h3 id="incoming-orders-title">Pesanan Masuk</h3></div><div class="profile-list" id="incoming-orders-list" aria-live="polite"></div></section>
            <section class="profile-section" aria-labelledby="orders-title"><h3 id="orders-title">Riwayat Pesanan</h3><div class="profile-list" id="orders-list" aria-live="polite"></div></section>
        </div>
    </aside>

    <aside class="cart-drawer" id="add-product-drawer" role="dialog" aria-modal="true" aria-labelledby="add-product-title" aria-hidden="true" inert>
        <div class="drawer-header"><div><span class="section-kicker" id="product-form-kicker">Mulai berjualan</span><h2 id="add-product-title">Tambah Produk</h2></div><button type="button" class="close-button" data-close-layer aria-label="Tutup formulir produk">×</button></div>
        <div class="drawer-form-wrap">
            <form class="stack-form" id="add-product-form">
                <label>Nama produk<input name="name" type="text" required maxlength="120"></label>
                <div class="form-row"><label>Harga (Rp)<input name="price" type="number" min="1" step="1" required></label><label>Stok<input name="stock" type="number" min="0" step="1" value="1" required></label></div>
                <label>Kategori<select name="category" required><option value="Anime">Anime</option><option value="Game">Game</option><option value="VTuber">VTuber</option><option value="Aksesoris">Aksesoris</option></select></label>
                <label>Series<input name="series" type="text" maxlength="100" placeholder="Contoh: Genshin Impact"></label>
                <div class="form-row"><label>Tipe<select name="type"><option value="Sewa">Sewa</option><option value="Beli">Beli</option></select></label><label>Ukuran<input name="size" type="text" maxlength="30" placeholder="S–XL"></label></div>
                <label>Kota<input name="city" type="text" maxlength="80" placeholder="Contoh: Bandung"></label>
                <label>URL gambar<input name="image" type="url" placeholder="https://…"></label>
                <label class="status-control" id="product-active-field" hidden><input name="is_active" type="checkbox" value="1"><span><strong>Listing aktif</strong><small>Produk aktif dapat ditemukan dan dipesan pembeli.</small></span></label>
                <p class="form-error" id="product-form-error" role="alert" hidden></p>
                <button type="submit" class="button button--primary button--full" id="product-submit-button">Simpan produk</button>
            </form>
        </div>
    </aside>

    <div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true"></div>
</body>
</html>
