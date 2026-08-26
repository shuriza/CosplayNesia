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
- Keranjang dan checkout demo dengan transaksi database atomik untuk pembelian dan reservasi sewa
- Validasi stok dan snapshot harga dari database
- Kalender ketersediaan sewa dengan tanggal inklusif Asia/Jakarta (hari ini hingga maksimal 30 hari)
- Idempotency key checkout, pembatalan reservasi milik pembeli, dan riwayat tanggal sewa
- Form checkout menyimpan snapshot penerima, kontak, alamat, dan catatan penyerahan yang tidak berubah
- Riwayat pesanan per pengguna
- Detail pesanan pembeli dan detail handoff penjual dengan akses berbasis kepemilikan
- Inbox pesanan masuk penjual dengan fulfillment per penjual dan status diterima, siap diserahkan, selesai, atau dibatalkan
- Form tambah produk dengan kepemilikan berbasis akun
- Edit, aktifkan/nonaktifkan, dan hapus listing milik penjual
- Listing nonaktif disembunyikan dari katalog dan ditolak oleh invariant checkout
- Reservasi sewa yang tumpang tindih ditolak secara atomik; tanggal hari berikutnya tetap tersedia
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

Checkout membuat pesanan demo dan status induknya menjadi `processing` saat memiliki fulfillment penjual (atau tetap `demo_confirmed` untuk listing tanpa pemilik akun). Produk `Beli` mengurangi stok; produk `Sewa` menyimpan reservasi tanggal inklusif dengan zona waktu `Asia/Jakarta` dan tidak mengurangi kapasitas katalog. Checkout sewa mewajibkan tanggal hari ini atau setelahnya, dengan durasi maksimum 30 hari. `Idempotency-Key` opsional pada request checkout mengulang pesanan yang sama untuk payload identik dan menolak payload berbeda.

Item dari listing dengan pemilik akun dikelompokkan menjadi satu fulfillment per penjual. Penjual mengelola alur `received` → `accepted` → `ready` → `completed`, atau membatalkan dari `received`/`accepted`. Produk demo tanpa `seller_id` tetap dapat dibeli dan tampil di riwayat pembeli, tetapi tidak masuk inbox penjual. Status pesanan induk merupakan agregat fulfillment dan tiap penjual hanya melihat fulfillment miliknya.

Pembeli dapat membatalkan reservasi miliknya sebelum tanggal mulai; tanggal tersebut kembali tersedia. Riwayat pesanan menyimpan snapshot nama, tipe, harga, tanggal, dan data handoff sehingga tetap terbaca setelah listing dihapus. Data handoff checkout menormalisasi nomor Indonesia ke format `+62...`; daftar pesanan hanya memuat ringkasan, sedangkan detail pembeli memuat data lengkap miliknya. Detail fulfillment penjual hanya memuat penerima, telepon, alamat, catatan, dan item penjual tersebut; email penerima serta identitas akun pembeli tidak dibagikan.

Belum ada payment gateway atau layanan pengiriman. Seller transition dan pembatalan sewa pembeli mengunci Order → Fulfillment → OrderItems → Products → RentalReservations dengan urutan deterministik. SQLite dan retry transaksi tetap ditujukan untuk demo lokal, bukan beban tulis bersamaan; SQLite tidak dapat membuktikan contention produksi sehingga pengujian konkurensi harus menggunakan database terkelola dengan row locking.
