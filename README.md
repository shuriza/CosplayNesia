# CosplayNesia

Marketplace demo untuk menyewa dan membeli kostum cosplay Indonesia. Aplikasi menggunakan Laravel 13, Blade, session authentication, SQLite, dan JavaScript tanpa framework frontend. Fokus implementasi berada pada konsistensi transaksi, isolasi data pembeli/penjual, dan riwayat pesanan yang tetap utuh saat listing berubah atau dihapus.

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

## Roadmap

Rencana pengembangan dari baseline demo hingga marketplace production-ready dibagi menjadi batch berurutan di [ROADMAP.md](ROADMAP.md). Setiap batch memiliki tujuan, cakupan, dependency, dan exit gate yang wajib terpenuhi sebelum batch berikutnya dimulai.

## Staging container

Image produksi dan topology staging tersedia melalui `Dockerfile` dan `compose.staging.yml`. Siapkan DNS publik `STAGING_HOST` ke host staging, buka port 80/443, salin `.env.staging.example` menjadi `.env.staging`, lalu isi seluruh secret:

```powershell
docker compose --env-file .env.staging -f compose.staging.yml build
docker compose --env-file .env.staging -f compose.staging.yml up -d
docker compose --env-file .env.staging -f compose.staging.yml exec web php artisan app:validate-runtime
```

Service `migrate` memvalidasi konfigurasi produksi sebelum migrasi. Service `web`, `worker`, dan `scheduler` memakai image yang sama; PostgreSQL 16 dan object storage S3-compatible disediakan untuk staging. Caddy menerbitkan sertifikat TLS otomatis untuk `STAGING_HOST`; Apache tidak diekspos langsung. Endpoint `/up` adalah liveness, sedangkan `/ready` memeriksa konfigurasi, database, dan cache.

## Fitur

### Katalog dan akun

- Katalog responsif dengan pencarian SQLite FTS5, filter kategori, sorting deterministik, favorit, dan cursor-based load more
- Register, login, logout, pembaruan profil, dan rotasi kata sandi dengan verifikasi kata sandi saat ini
- Session authentication Laravel yang mengeluarkan device lain setelah kata sandi dirotasi tanpa mengeluarkan device yang melakukan perubahan
- Favorit persisten dan listing milik pengguna dengan akses berbasis kepemilikan
- Tambah, edit, aktifkan/nonaktifkan, dan hapus listing; listing nonaktif tidak dapat di-checkout

### Checkout dan rental

- Keranjang dan checkout atomik dengan harga, stok, tipe produk, penjual, serta data handoff yang diambil dari database
- Perlindungan self-checkout; basket campuran dibatalkan seluruhnya bila memuat produk milik pembeli
- Idempotency key untuk replay payload identik dan penolakan payload berbeda
- Kalender ketersediaan sewa inklusif pada zona waktu `Asia/Jakarta`, mulai hari ini hingga maksimal 30 hari
- Reservasi tumpang tindih divalidasi terhadap kapasitas stok; perubahan stok tidak boleh turun di bawah puncak reservasi aktif
- Produk rental dengan reservasi aktif tidak dapat diubah menjadi produk jual, tetapi tetap dapat dinonaktifkan
- Pembeli dapat membatalkan rental sebelum tanggal mulai sehingga kapasitas kembali tersedia
- Kalender kapasitas harian menampilkan jumlah unit yang direservasi, diblokir, dan masih tersedia pada rentang tanggal inklusif
- Penjual dapat memblokir sebagian stok untuk jadwal pemeliharaan, perbaikan, atau pemakaian pribadi tanpa menonaktifkan listing
- Blok tanggal tidak pernah mengambil kapasitas reservasi yang sudah masuk dan dapat dibatalkan untuk mengembalikan kapasitas

### Pesanan, fulfillment, dan ulasan

- Riwayat pesanan pembeli dan inbox fulfillment penjual memakai cursor pagination tanpa query count tak terbatas
- Satu fulfillment per penjual dengan transisi `received` → `accepted` → `ready` → `completed`, atau pembatalan dari state yang diizinkan
- Rental hanya dapat diselesaikan setelah tanggal akhir inklusif tercapai
- Timeline aktivitas checkout, fulfillment, dan rental bersifat immutable dan dibatasi sesuai pemilik data
- Snapshot nama produk, harga, tipe, penjual, tanggal rental, penerima, kontak, alamat, dan catatan tetap terbaca setelah listing berubah atau dihapus
- Rating katalog hanya berasal dari ulasan pembeli terverifikasi pada item pesanan yang benar-benar selesai
- Ulasan dibatasi satu per item; rental yang dibatalkan tidak dapat diulas dan ulasan selesai tetap tersimpan setelah produk dihapus
- Pembeli dapat menulis ulasan teks opsional maksimal 500 karakter; teks kosong disimpan sebagai `null`
- Detail produk menampilkan feed ulasan publik dengan cursor pagination, distribusi bintang 1–5, dan identitas pengulas yang dimask menjadi nama depan plus inisial
- Feed ulasan hanya tersedia untuk listing aktif; listing nonaktif dan produk terhapus mengembalikan 404 tanpa menghilangkan ulasan yang sudah tersimpan
- Penjual memiliki inbox ulasan tersendiri dengan cursor pagination dan filter "belum dibalas"
- Penjual dapat menulis, memperbarui, dan menghapus satu balasan per ulasan tanpa mengubah rating atau teks pembeli
- Balasan penjual tampil pada feed publik dan pada riwayat pesanan pembeli

### Notifikasi

- Inbox notifikasi per akun dengan lonceng, badge jumlah belum dibaca, filter "hanya belum dibaca", dan cursor pagination
- Fan-out otomatis dari checkout, setiap transisi fulfillment, pembatalan sewa, ulasan baru, dan balasan penjual
- Pelaku aksi tidak pernah menerima notifikasi atas aksinya sendiri; hanya pihak lawan yang diberi tahu
- Setiap notifikasi idempotent lewat `event_key` unik sehingga retry transaksi dan replay status sama tidak menduplikasi baris
- Tandai satu notifikasi atau semua sekaligus; menandai ulang tidak mengubah waktu baca pertama

### Percakapan pesanan

- Thread pesan per fulfillment antara pembeli dan penjual yang terikat pada pesanan tersebut
- Hanya dua pihak itu yang dapat membaca dan menulis; penjual lain pada pesanan yang sama tetap 403
- Pesan bersifat append-only sehingga tidak ada pihak yang dapat mengubah atau menghapus isi percakapan
- Penanda baca per sisi memakai id pesan, bukan timestamp, sehingga pesan dalam detik yang sama tidak terlewat
- Badge jumlah pesan belum dibaca muncul pada riwayat pesanan pembeli dan inbox penjual
- Setiap pesan memicu notifikasi ke pihak lawan saja; pesanan yang dibatalkan menutup percakapan tanpa menghapus riwayat

### Kualitas

- Index khusus untuk cursor pagination dan pencarian katalog
- Query list dijaga bounded terhadap ukuran halaman
- Feature tests mencakup autentikasi, katalog, pagination, favorit, checkout, rental, blok jadwal sewa, fulfillment, timeline, handoff, ulasan, feed ulasan publik, dan isolasi data

## Struktur Utama

- `app/Http/Controllers` menangani endpoint JSON dan autentikasi
- `app/Http/Controllers/ProductReviewFeedController.php` melayani feed ulasan publik per produk
- `app/Http/Controllers/SellerReviewController.php` melayani inbox ulasan penjual dan mutasi balasan
- `app/Http/Controllers/NotificationController.php` melayani inbox notifikasi dan status baca
- `app/Http/Controllers/FulfillmentMessageController.php` melayani percakapan pesanan
- `app/Services/NotificationRecorder.php` satu-satunya jalur tulis notifikasi, idempotent dan menolak self-notify
- `app/Services/MessageThread.php` memutuskan peran peserta, mengunci thread, dan mengirim fan-out pesan
- `app/Services/RentalCapacity.php` satu-satunya perhitungan kapasitas sewa: puncak gabungan reservasi dan blok tanggal
- `app/Http/Controllers/RentalBlockController.php` melayani kalender kapasitas dan mutasi blok tanggal penjual
- `app/Http/Requests` memvalidasi seluruh input mutasi
- `app/Services/CheckoutService.php` menangani checkout, rental, fulfillment, dan pencatatan timeline secara atomik
- `app/Models` berisi model dan relasi Eloquent
- `database/migrations` mendefinisikan schema
- `database/seeders/ProductSeeder.php` memuat katalog demo
- `resources/views/home.blade.php` berisi halaman utama
- `resources/js/app.js` menangani interaksi frontend
- `resources/css` berisi visual system CosplayNesia

## Batasan Demo

Checkout membuat pesanan demo dan status induknya menjadi `processing` saat memiliki fulfillment penjual (atau tetap `demo_confirmed` untuk listing tanpa pemilik akun). Produk `Beli` mengurangi stok; produk `Sewa` menyimpan reservasi tanggal inklusif dengan zona waktu `Asia/Jakarta`. Checkout sewa mewajibkan tanggal hari ini atau setelahnya, dengan durasi maksimum 30 hari. Header `Idempotency-Key` opsional mengulang pesanan yang sama untuk payload identik dan menolak payload berbeda.

Item dari listing dengan pemilik akun dikelompokkan menjadi satu fulfillment per penjual. Penjual mengelola alur `received` → `accepted` → `ready` → `completed`, atau membatalkan dari `received`/`accepted`. Produk demo tanpa `seller_id` tetap dapat dibeli dan tampil di riwayat pembeli, tetapi tidak masuk inbox penjual. Status pesanan induk merupakan agregat fulfillment dan tiap penjual hanya melihat fulfillment miliknya.

Pembeli dapat membatalkan reservasi miliknya sebelum tanggal mulai; tanggal tersebut kembali tersedia. Riwayat pesanan menyimpan snapshot nama, tipe, harga, tanggal, dan data handoff sehingga tetap terbaca setelah listing dihapus. Data handoff checkout menormalisasi nomor Indonesia ke format `+62...`; daftar pesanan hanya memuat ringkasan, sedangkan detail pembeli memuat data lengkap miliknya. Detail fulfillment penjual hanya memuat penerima, telepon, alamat, catatan, dan item penjual tersebut; email penerima serta identitas akun pembeli tidak dibagikan.

Feed ulasan publik `GET /api/products/{product}/reviews` tidak membutuhkan autentikasi dan hanya melayani listing aktif. Feed memuat rating, teks ulasan, tanggal, serta label pengulas berupa nama depan dan inisial nama terakhir sehingga identitas penuh pembeli tidak tersebar; email dan akun pembeli tidak pernah disertakan. Ringkasan feed berisi rata-rata rating, jumlah ulasan, dan distribusi lengkap bintang 1–5 yang dihitung dalam satu query grouped.

Inbox ulasan penjual `GET /api/seller/reviews` hanya memuat ulasan pada produk milik penjual tersebut, memakai snapshot `seller_id` yang diambil saat ulasan dibuat sehingga akses tetap ada setelah listing dihapus. Balasan ditulis lewat `PATCH /api/seller/reviews/{review}/reply` dan dihapus lewat `DELETE`; kepemilikan diperiksa ulang terhadap baris yang sudah dilock agar transfer listing bersamaan tidak meloloskan penjual lama. Balasan tidak pernah mengubah rating atau teks pembeli, dan label pengulas pada inbox tetap dimask seperti pada feed publik.

Notifikasi ditulis pada tabel `user_notifications` yang sengaja dipisah dari tabel `notifications` milik Laravel agar tidak menimpa relasi trait `Notifiable`. Semua penulisan lewat `NotificationRecorder` di dalam transaksi mutasi yang sama, memakai `insertOrIgnore` terhadap `event_key` unik sehingga aman terhadap retry. Balasan ulasan hanya memberi tahu pembeli saat balasan pertama muncul; penyuntingan berikutnya tidak mengirim notifikasi lagi. Endpoint `GET /api/notifications` mengembalikan `unread_count` bersama halaman cursor, sedangkan `PATCH /api/notifications/{id}/read` dan `PATCH /api/notifications/read-all` mengubah status baca tanpa menyentuh isi notifikasi.

Percakapan pesanan memakai satu thread per fulfillment pada `GET|POST /api/fulfillments/{fulfillment}/messages`. Peran peserta diputuskan per fulfillment, bukan per prefix URL: penjual pemilik fulfillment dan pembeli pemilik pesanan induk. Peserta lain menerima 403, termasuk penjual pada fulfillment lain di pesanan yang sama. `sender_role` disimpan sebagai snapshot agar percakapan tetap terbaca setelah akun pengirim dihapus, dan label pihak lawan selalu berupa peran, bukan nama akun. Penanda baca disimpan sebagai id pesan terakhir yang dibaca tiap sisi karena `created_at` hanya berpresisi detik. Mengirim pesan otomatis menandai thread terbaca bagi pengirim. Fulfillment yang dibatalkan menolak pesan baru dengan 409 tetapi tetap menampilkan riwayat.

Jadwal sewa penjual memakai `GET|POST /api/products/{product}/rental-blocks` dan `DELETE /api/products/{product}/rental-blocks/{block}`; kalender hanya dapat dibuka pemilik listing dan hanya untuk produk Sewa. Blok menyimpan rentang tanggal inklusif, jumlah unit, dan catatan privat yang hanya muncul di endpoint pemilik—tidak pernah pada ketersediaan publik. Kapasitas menghitung **puncak gabungan** reservasi dan blok per hari, bukan jumlah total pada rentang: reservasi yang berlangsung pada hari berbeda tidak mengurangi kapasitas secara bersamaan. Penurunan stok dan perubahan tipe menjadi produk jual ditolak 422 selama puncak tersebut masih membutuhkan stok lebih besar, sedangkan penonaktifan listing tetap diizinkan. Pembatalan blok bersifat idempotent, dan blok yang dibatalkan atau sudah lewat tidak lagi menahan kapasitas.

Belum ada payment gateway, layanan pengiriman, atau deployment produksi. Notifikasi bersifat in-app saja; belum ada pengiriman email, push, maupun realtime broadcast. Seller transition, pembatalan rental, ulasan, dan mutasi stok memakai transaksi serta penguncian berurutan untuk menjaga invariant. SQLite dan retry transaksi ditujukan untuk demo lokal, bukan beban tulis bersamaan; validasi contention produksi harus menggunakan database terkelola yang mendukung row locking.

## Pemilik Proyek

Dikembangkan dan dikelola oleh [shuriza](https://github.com/shuriza).
