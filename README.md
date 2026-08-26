# CosplayNesia

Marketplace demo untuk sewa dan beli kostum cosplay Indonesia. Aplikasi menggunakan Laravel, Blade, session authentication, SQLite, dan JavaScript tanpa framework frontend.

## Persyaratan

- PHP 8.3 atau lebih baru
- Composer 2
- Node.js 22 atau lebih baru
- Ekstensi PHP `pdo_sqlite` dan `sqlite3`

## Menjalankan Lokal

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Path database/database.sqlite -Force
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Buka `http://127.0.0.1:8000`.

Untuk menjalankan server Laravel dan Vite secara bersamaan saat mengembangkan:

```powershell
composer run dev
```

## Pengujian

```powershell
php artisan test
vendor\bin\pint --test
npm run build
```

Reset database demo dan muat kembali 12 produk awal:

```powershell
php artisan migrate:fresh --seed
```

## Fitur

- Katalog responsif dengan pencarian, kategori, sorting, dan load more
- Register, login, logout, serta session authentication Laravel
- Favorit persisten per pengguna
- Keranjang dan checkout demo dengan transaksi database atomik
- Validasi stok dan snapshot harga dari database
- Riwayat pesanan per pengguna
- Form tambah produk dengan kepemilikan berbasis akun
- Edit, aktifkan/nonaktifkan, dan hapus listing milik penjual
- Listing nonaktif disembunyikan dari katalog dan ditolak oleh invariant checkout
- Feature tests untuk autentikasi, katalog, favorit, checkout, dan isolasi data

## Struktur Utama

- `app/Http/Controllers` menangani endpoint JSON dan autentikasi
- `app/Http/Requests` memvalidasi seluruh input mutasi
- `app/Services/CheckoutService.php` menangani checkout atomik
- `app/Models` berisi model dan relasi Eloquent
- `database/migrations` mendefinisikan schema
- `database/seeders/ProductSeeder.php` memuat katalog demo
- `resources/views/home.blade.php` berisi halaman utama
- `resources/js/app.js` menangani interaksi frontend
- `resources/css` berisi visual system CosplayNesia

## Batasan Demo

Checkout membuat pesanan berstatus `demo_confirmed` dan mengurangi stok, tetapi belum menghubungi payment gateway, layanan pengiriman, atau kalender reservasi sewa. SQLite dan retry transaksi ditujukan untuk demo lokal, bukan beban tulis bersamaan. Gunakan database terkelola dan integrasi pembayaran terverifikasi sebelum deployment production.
