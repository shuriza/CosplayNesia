# Roadmap CosplayNesia

Roadmap ini mengubah CosplayNesia dari marketplace demo lokal menjadi marketplace Indonesia yang dapat menjalankan transaksi beli dan sewa secara end-to-end. Pekerjaan dibagi berdasarkan ketergantungan domain, bukan berdasarkan layar.

## Baseline saat ini

Sudah tersedia dan terverifikasi:

- autentikasi session, profil, rotasi kata sandi, dan invalidasi session lama;
- katalog dengan FTS5, filter, sort, cursor pagination, favorit, serta CRUD listing;
- checkout atomik dan idempotent, snapshot pesanan, kapasitas sewa, serta blok jadwal;
- fulfillment multi-penjual, timeline immutable, ulasan terverifikasi, balasan penjual;
- notifikasi in-app dan percakapan per fulfillment;
- 108 feature tests / 1100 assertions, Pint, syntax check JavaScript, build Vite, dan browser smoke terakhir lulus.

Baseline ini tetap disebut **demo** karena belum mempunyai pembayaran nyata, pengiriman, lifecycle pengembalian sewa, refund/dispute, backoffice, maupun deployment produksi.

## Definisi “feature complete”

Versi pertama dianggap feature complete hanya jika seluruh kondisi berikut terpenuhi:

1. Pembeli dapat mendaftar, memverifikasi akun, menemukan produk, membayar, melacak pesanan, menerima atau mengembalikan barang, meminta pembatalan/refund, membuka sengketa, dan memberi ulasan.
2. Penjual dapat lolos onboarding, mengelola toko serta listing, memenuhi pesanan, mengirim atau menerima kembali barang sewa, menangani sengketa, dan menerima settlement yang dapat direkonsiliasi.
3. Admin dapat memoderasi akun/listing, menangani laporan dan sengketa, memeriksa transaksi/ledger, serta menjalankan tindakan sensitif dengan audit trail.
4. Setiap perubahan uang, stok, reservasi, fulfillment, pengiriman, refund, dan settlement bersifat idempotent, dapat diaudit, dan aman terhadap retry/webhook ganda.
5. Aplikasi berjalan di infrastruktur produksi dengan database concurrent-write, queue, object storage, email, monitoring, backup/restore, CI, dan prosedur rollback.
6. Alur kritis lulus feature test, integration test provider, browser smoke desktop/mobile, accessibility check, dan uji kegagalan pada boundary transaksi.
7. Tidak ada lagi copy, status, data seed, atau cabang kode yang menyebut transaksi sebagai simulasi/demo pada mode produksi.

## Aturan eksekusi setiap batch

Setiap batch wajib menghasilkan vertical slice yang dapat digunakan. Batch berikutnya baru dimulai setelah exit gate batch aktif terpenuhi.

Definition of Done universal:

- schema dan migrasi forward/rollback selesai;
- authorization, validation, idempotency, locking, dan audit event diterapkan pada mutation path;
- API dan UI desktop/mobile selesai tanpa placeholder;
- notifikasi dan timeline diperbarui jika ada perubahan state yang terlihat pengguna;
- feature/integration test hanya untuk kontrak dan invariant yang bernilai;
- browser smoke menjalankan alur nyata dari UI;
- dokumentasi setup, konfigurasi, operasi, dan batasan diperbarui;
- seluruh suite, formatter, dan production build lulus;
- fixture smoke dibersihkan sebelum batch ditutup.

Keputusan teknis umum:

- Pertahankan Laravel, Blade, dan JavaScript saat ini; jangan melakukan rewrite framework.
- Pecah `resources/js/app.js` dan drawer profil secara bertahap berdasarkan domain saat disentuh, bukan lewat refactor besar tanpa nilai pengguna.
- Satu provider nyata per kebutuhan pada v1. Jangan membuat abstraction multi-provider sebelum provider kedua benar-benar dibutuhkan.
- Uang disimpan sebagai integer rupiah. Perubahan saldo memakai double-entry ledger, bukan menghitung ulang dari status order.
- Webhook diperlakukan sebagai input tidak tepercaya: verifikasi signature, simpan event id provider, proses idempotent melalui queue, dan rekonsiliasi berkala.
- Data sensitif tidak boleh masuk notification payload, timeline metadata, log, atau analytics.

---

## Batch 0 — Baseline transaksi demo

**Status:** selesai.

Cakupan: katalog, akun, listing, favorit, cart lokal, checkout demo, reservasi dan kapasitas rental, fulfillment, timeline, ulasan, notifikasi, percakapan, serta pengelolaan blok tanggal.

**Exit gate:** baseline hijau sebagaimana tercatat di atas. Fitur berikutnya tidak boleh merusak invariant kapasitas, isolasi buyer/seller, snapshot pesanan, cursor pagination, atau append-only evidence.

## Batch 1 — Fondasi produksi dan delivery pipeline

**Tujuan:** menghilangkan risiko teknis yang akan membuat pembayaran dan fulfillment nyata tidak aman.

Cakupan:

- tetapkan PostgreSQL sebagai database runtime produksi dan CI integration;
- migrasikan pencarian FTS5/triggers ke mekanisme pencarian PostgreSQL dan pertahankan cursor deterministik;
- aktifkan queue worker dan scheduler dengan failed-job handling serta retry policy;
- gunakan object storage privat/publik terpisah untuk aset;
- tambah environment validation, health/readiness endpoint, structured logging, correlation id, dan redaction;
- buat CI untuk install, migration, test, Pint, build, dan pemeriksaan dependency/security;
- buat deployment staging repeatable, backup otomatis, restore drill, dan rollback migration/release;
- pecah bootstrap frontend menjadi modul domain tanpa mengubah behavior.

**Exit gate:** staging dapat dibuat dari nol oleh pipeline; checkout paralel pada PostgreSQL tidak oversell; queue retry tidak menduplikasi side effect; backup staging berhasil direstore; pencarian dan pagination setara dengan baseline.

## Batch 2 — Identitas, keamanan akun, dan legal consent

**Tujuan:** akun layak dipakai untuk transaksi bernilai uang.

Cakupan:

- verifikasi email dan resend dengan throttling;
- forgot/reset password dengan token sekali pakai dan invalidasi session yang tepat;
- daftar perangkat/session, revoke per-device, dan revoke-all;
- konfirmasi perubahan email sebelum alamat baru aktif;
- account deactivation/deletion dengan retensi data transaksi yang legal dan anonymization terkontrol;
- acceptance/versioning Terms, Privacy Policy, dan kebijakan rental pada registrasi/checkout;
- security events untuk login, reset, perubahan identitas, dan tindakan sensitif;
- CAPTCHA atau risk challenge hanya pada endpoint yang terbukti disalahgunakan, bukan pada seluruh UX.

**Exit gate:** akun belum terverifikasi tidak bisa checkout atau menjual; reset token tidak dapat dipakai ulang; perubahan email tidak mengambil alih akun sebelum verifikasi; penghapusan akun tidak merusak order evidence atau laporan keuangan.

## Batch 3 — Onboarding penjual dan storefront

**Tujuan:** memisahkan identitas akun dari identitas toko dan memastikan hanya penjual yang disetujui dapat menerima order.

Cakupan:

- entitas toko: slug, nama, deskripsi, logo/banner, lokasi asal, jam operasional, status;
- onboarding penjual dengan draft → submitted → approved/rejected/suspended;
- data legal/KYC minimum dan consent; dokumen disimpan privat dengan akses audit;
- integrasi onboarding/sub-account provider pembayaran sesuai provider terpilih;
- role/capability buyer, seller, support, moderator, finance, admin;
- halaman storefront publik dengan produk, rating, kebijakan, dan status toko;
- admin review onboarding dan seller suspension tanpa menghapus riwayat transaksi.

**Exit gate:** akun biasa tidak bisa menerbitkan listing; penjual approved dapat membuka storefront; seller suspended tidak menerima checkout baru tetapi tetap dapat menyelesaikan kewajiban existing; dokumen privat tidak pernah bocor lewat API publik.

## Batch 4 — Katalog, media, variasi, dan kualitas listing

**Tujuan:** listing merepresentasikan barang nyata, bukan satu record sederhana dengan URL gambar.

Cakupan:

- upload gambar langsung ke object storage, gallery, urutan gambar, thumbnail, validasi MIME/ukuran, dan penghapusan aman;
- draft/published/paused/rejected/archived lifecycle;
- variasi SKU untuk ukuran/warna/kondisi dengan stok atau kapasitas masing-masing;
- atribut kategori, kelengkapan kostum, kondisi barang, ukuran detail, dan aturan perawatan;
- harga jual, harga sewa per durasi yang jelas, deposit, serta minimum/maksimum durasi;
- preview listing dan checklist kelengkapan sebelum publish;
- moderation queue dan alasan penolakan; perubahan material pada listing published dapat direview ulang;
- migrasi snapshot order agar nama variasi dan atribut penting tetap immutable.

**Exit gate:** pembeli memilih SKU yang tepat; stok/capacity dilock per SKU; file non-gambar atau oversize ditolak; arsip listing tidak menghilangkan snapshot order/review; katalog tidak menampilkan draft/rejected/suspended store.

## Batch 5 — Cart persisten, alamat, ongkir, dan janji layanan

**Tujuan:** checkout mempunyai biaya dan tujuan pengiriman yang benar sebelum pembayaran dibuat.

Cakupan:

- cart server-side per akun dengan merge saat login dan validasi ulang harga/stok;
- address book buyer dengan label, alamat default, normalisasi, dan snapshot ke order;
- origin address/store pickup configuration milik penjual;
- integrasi satu aggregator kurir nyata untuk service, ongkir, dan estimasi;
- split shipment per seller, pilihan kurir per fulfillment, berat/dimensi, dan cache quote berumur pendek;
- komponen biaya immutable: item subtotal, rental charge, deposit, shipping, discount, platform fee, total;
- quote expiry dan requote tanpa membuat order yatim;
- pickup/meet-up hanya jika penjual mengaktifkan dan syarat handoff jelas.

**Exit gate:** total checkout dapat dijelaskan sampai komponen terkecil; quote expired tidak bisa dibayar; order multi-seller menghasilkan fulfillment dan shipment quote terisolasi; seller tidak melihat email atau data buyer yang tidak dibutuhkan.

## Batch 6 — Pembayaran nyata dan finalisasi order

**Tujuan:** order hanya dikonfirmasi oleh hasil pembayaran yang terverifikasi.

Cakupan:

- state machine payment: initiated/pending/paid/expired/failed/cancelled/refunded/partially_refunded;
- create payment session/token melalui provider terpilih;
- webhook signature verification, event inbox unik, queue processing, replay protection, dan manual reconcile;
- inventory/reservation hold dengan expiry; release otomatis jika payment gagal/kedaluwarsa;
- checkout idempotency mencakup cart version, quote, amount, dan payment attempt;
- halaman status pembayaran serta recovery setelah redirect, refresh, atau callback terlambat;
- invoice/receipt dan notifikasi paid/failed/expired;
- hilangkan status `demo_confirmed` dan semua copy checkout demo dari mode produksi.

**Exit gate:** nominal paid harus sama dengan order payable; webhook duplikat/out-of-order tidak menggandakan order atau mengubah stok dua kali; hold expired dilepas; payment sukses setelah timeout direkonsiliasi; refund tidak dapat melebihi captured amount.

## Batch 7 — Pengiriman dan handoff fisik

**Tujuan:** barang dapat bergerak dari penjual ke pembeli dengan bukti dan tracking.

Cakupan:

- shipment entity per fulfillment, label/waybill, tracking number, carrier status, dan timeline;
- seller pack/ship deadline, ready-for-pickup, pickup confirmation, dan buyer receipt confirmation;
- webhook/polling kurir idempotent dengan mapping status internal;
- bukti serah-terima yang aman: waktu, aktor, kode/OTP, dan foto bila kebijakan mengharuskan;
- lost/damaged/delayed exception path;
- reminder otomatis dan escalation tanpa cron logic tersebar;
- fulfillment completion berasal dari delivery/handoff evidence, bukan tombol seller sepihak.

**Exit gate:** order kirim dan pickup dapat selesai end-to-end; seller tidak dapat menandai delivered sendiri; event kurir out-of-order tidak memundurkan state; tracking dan exception terlihat oleh kedua pihak tanpa bocor data provider.

## Batch 8 — Lifecycle rental lengkap

**Tujuan:** rental tidak berhenti saat fulfillment “completed”, tetapi berakhir setelah barang kembali dan diperiksa.

Cakupan:

- lifecycle outbound → delivered/picked_up → in_use → return_due → return_in_transit → returned → inspected → closed;
- buffer persiapan/pembersihan sebelum dan sesudah rental dalam kalkulasi kapasitas;
- return shipment/pickup dan label balik;
- checklist kondisi sebelum/akhir, foto evidence, aksesori/komponen, dan acknowledgement kedua pihak;
- reminder jatuh tempo, grace period, keterlambatan, perpanjangan dengan cek kapasitas;
- deposit hold/capture/release sesuai kemampuan provider dan kebijakan;
- biaya keterlambatan/kerusakan masuk ledger dan selalu membutuhkan evidence serta jalur dispute;
- listing tidak kembali available sebelum return/inspection memenuhi policy.

**Exit gate:** rental dapat ditutup hanya setelah pengembalian dan inspeksi; extension tidak menabrak booking berikutnya; retry tidak menggandakan biaya; kapasitas harian memasukkan buffer dan rental overdue; deposit memiliki jejak audit lengkap.

## Batch 9 — Pembatalan, retur, refund, dan sengketa

**Tujuan:** exception transaksi mempunyai kebijakan eksplisit, bukti, SLA, dan dampak keuangan yang konsisten.

Cakupan:

- policy engine sederhana berdasarkan tipe produk, actor, status, deadline, dan alasan;
- cancel request buyer/seller, approval bila perlu, serta automatic outcome yang transparan;
- retur barang beli dan refund penuh/sebagian;
- dispute case dengan kategori, evidence privat, percakapan kasus, SLA, dan assignment admin;
- refund provider idempotent serta restock/reservation release yang tepat;
- damage/loss/late fee review untuk rental;
- appeal dan immutable decision log;
- notifikasi setiap perubahan penting tanpa mengungkap evidence privat.

**Exit gate:** setiap status aktif mempunyai jalur sukses, cancel, timeout, dan dispute; refund cocok dengan ledger dan provider; outcome sengketa tidak dapat diedit tanpa event koreksi; stok tidak direstock sebelum syarat fisik terpenuhi.

## Batch 10 — Promosi, discovery, dan retensi

**Tujuan:** membuat marketplace dapat ditemukan dan digunakan ulang tanpa merusak integritas harga.

Cakupan:

- halaman kategori/series/kota/store yang crawlable, metadata SEO, sitemap, canonical URL;
- filter tanggal ketersediaan, ukuran, lokasi, rentang harga, rating, tipe, dan kondisi;
- recent views, saved search, restock/availability alert;
- voucher/campaign dengan scope, budget, quota, minimum spend, periode, dan anti-abuse;
- wishlist/favorite notification dan seller follow bila memang dipakai pada UI;
- ranking berbasis sinyal yang dapat dijelaskan; jangan menambah ML sebelum data cukup;
- analytics funnel dengan consent dan tanpa PII pada event payload.

**Exit gate:** filter rental hanya menampilkan SKU yang tersedia pada tanggal/qty diminta; discount dihitung server-side dan tersnapshot; quota tidak oversubscribe; halaman publik mempunyai URL stabil dan metadata valid.

## Batch 11 — Komunikasi multi-channel

**Tujuan:** pengguna menerima informasi penting tanpa harus membuka drawer notifikasi.

Cakupan:

- email transactional melalui queue untuk verification, payment, shipment, rental due, refund, dan dispute;
- realtime in-app update melalui broadcast untuk badge, message, dan perubahan status;
- notification preference per channel dan kategori, dengan event wajib keamanan tidak dapat dimatikan;
- retry/dead-letter, provider message id, delivery status, dan suppression/bounce handling;
- attachment chat hanya jika kebutuhan operasional terbukti; file harus private, scanned, expiring URL;
- anti-spam/rate limit pada message dan notification fan-out.

**Exit gate:** event domain menghasilkan maksimal satu notifikasi per channel/event key; retry aman; unsubscribe dihormati; email tidak memuat data lebih sensitif daripada yang diperlukan; UI tetap benar saat realtime terputus dan kembali polling/fetch.

## Batch 12 — Admin, moderasi, dan trust & safety

**Tujuan:** platform dapat dioperasikan tanpa akses database manual.

Cakupan:

- backoffice terpisah dengan RBAC ketat dan step-up authentication untuk tindakan sensitif;
- pencarian akun, toko, listing, order, payment, shipment, refund, dispute, dan notification delivery;
- moderation report untuk listing/user/message/review dengan evidence dan resolution;
- suspend/reactivate, takedown listing, freeze payout, dan risk flag;
- correction actions harus membuat compensating event, tidak mengedit history;
- immutable admin audit log: actor, reason, before/after aman, correlation id, timestamp;
- data export/privacy request serta retention jobs;
- support notes privat yang tidak bercampur dengan chat buyer-seller.

**Exit gate:** support dapat menyelesaikan skenario operasional utama tanpa SQL; permission negatif diuji per role; admin tidak dapat menghapus audit log; tindakan keuangan memerlukan reason dan approval policy yang ditetapkan.

## Batch 13 — Ledger, fee, settlement, dan rekonsiliasi

**Tujuan:** setiap rupiah mempunyai sumber, tujuan, dan saldo yang dapat dibuktikan.

Cakupan:

- double-entry ledger untuk payment, shipping, platform fee, discount subsidy, deposit, refund, adjustment, seller payable, dan payout;
- fee policy yang di-versioning dan tersnapshot saat order;
- seller balance: pending, available, reserved, paid;
- settlement setelah delivery/rental close serta hold saat dispute;
- payout provider, payout failure/retry, dan statement penjual;
- invoice/receipt/credit note sesuai kebutuhan legal yang telah dikonfirmasi;
- daily reconciliation antara provider transaction, bank/payout, order, refund, dan ledger;
- finance dashboard dan exception queue; koreksi selalu via compensating entry.

**Exit gate:** ledger selalu balance; seller tidak dapat menarik saldo pending/frozen; payout retry tidak membayar dua kali; laporan harian menunjukkan selisih nol atau exception eksplisit; sampel transaksi dapat ditelusuri dari webhook sampai settlement.

## Batch 14 — Hardening, launch, dan operasi produksi

**Tujuan:** menutup gap non-fitur yang menentukan apakah produk aman diluncurkan.

Cakupan:

- threat model untuk auth, upload, checkout, webhook, chat, admin, dan privacy;
- security headers, cookie policy, CSRF/session review, rate limit, secret rotation, dependency scanning, dan penetration test;
- performance/load test pada browse, search, checkout contention, webhook burst, dan inbox besar;
- index/query review dengan data volume representatif; queue capacity dan backpressure;
- accessibility WCAG 2.2 AA pada alur kritis, responsive browser/device matrix, dan degraded network states;
- metrics, traces, alert, error budget, dashboard bisnis/teknis, on-call runbook, incident process;
- backup encryption, restore drill, disaster recovery objective, maintenance mode, dan rollback drill;
- legal copy final, data retention, cookie/analytics consent, support channel, status page;
- canary/internal pilot → limited beta → general availability dengan kill switch untuk payment/checkout.

**Exit gate:** tidak ada critical/high security finding terbuka; performance SLO tercapai pada beban target yang didokumentasikan; restore dan rollback drill berhasil; alert terbukti berbunyi; seluruh journey buyer/seller/admin lolos di staging production-like; README tidak lagi menyebut batasan demo yang sudah diselesaikan.

---

## Urutan prioritas

Urutan default bersifat ketat:

`1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 → 13 → 14`

Pengecualian yang aman:

- desain ledger dan fee dari Batch 13 harus dibuat sebelum coding Batch 6, tetapi UI payout tetap dikerjakan pada Batch 13;
- skeleton backoffice minimal untuk melihat webhook/payment exception boleh dibuat di Batch 6, lalu diselesaikan di Batch 12;
- email verification pada Batch 2 membutuhkan queue/email foundation Batch 1;
- fitur promosi Batch 10 tidak boleh mendahului pembayaran, refund, dan snapshot harga;
- realtime Batch 11 tidak boleh menjadi sumber kebenaran; API/database tetap authoritative.

## Yang sengaja bukan target v1

Agar “complete” mempunyai batas yang objektif, hal berikut ditunda setelah general availability:

- aplikasi native iOS/Android;
- transaksi internasional, multi-currency, dan multi-language;
- lelang, live shopping, social feed, dan gamification;
- rekomendasi machine learning;
- lebih dari satu payment/shipping provider aktif;
- warehouse/fulfillment center milik platform;
- seller team multi-user dan enterprise API.

Item tersebut hanya masuk roadmap jika data penggunaan atau kebutuhan bisnis membuktikan nilainya.

## Cara menjalankan roadmap

Untuk setiap batch:

1. tulis ADR singkat untuk keputusan yang irreversible atau vendor-bound;
2. petakan state machine dan invariant sebelum schema/API;
3. implementasikan backend, UI, event, audit, dan failure path sebagai satu vertical slice;
4. migrasikan seluruh caller lalu hapus path lama—tanpa alias atau mode ganda permanen;
5. jalankan test terfokus, full suite, build, dan browser smoke;
6. deploy ke staging, jalankan acceptance scenario, lalu tutup batch hanya ketika exit gate terbukti.

Roadmap selesai ketika Batch 14 lulus, bukan ketika seluruh endpoint sudah dibuat.