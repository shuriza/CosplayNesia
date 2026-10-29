# Plan CosplayNesia — Status Terkini

Dokumen ini adalah ringkasan status plan berdasarkan **pemeriksaan langsung ke kode**, diperbarui setelah vertical slice Discovery & SEO.

## Bukti verifikasi terbaru

| Pemeriksaan | Hasil |
|---|---|
| `php artisan test` | **231 passed, 4 skipped, 1872 assertions** (4 skipped = integration test PostgreSQL) |
| `php vendor/bin/pint --test` | lulus |
| `node --check` + `npx vite build` | lulus |
| `composer audit --locked` | bersih: 0 advisory, 0 abandoned |
| Browser smoke (22 Okt) | halaman SEO, sitemap, filter katalog, validasi tanggal, mobile 390 px tanpa overflow |
| Status demo di kode | **masih ada** — `Order::STATUS_DEMO_CONFIRMED`, teks/copy checkout demo; pembersihan menunggu Batch 6 |
| Catatan | Batch 6 tetap blocked oleh deployment staging live dan credential provider |
---

## Peta status per batch

| Batch | Status | Catatan bukti |
|---|---|---|
| 0 — Baseline transaksi demo | **Selesai** | katalog, akun, checkout, rental, fulfillment, ulasan, notifikasi |
| 1 — Fondasi produksi & pipeline | **Selesai bersyarat** | kode + CI hijau; deployment live **belum** (lihat blocker) |
| 2 — Identitas & keamanan akun | **Selesai** | `AccountSecurityController`, `AccountSession`, session revoke, deaktivasi + anonimisasi (`anonymized_at`), versi Terms/Privacy tersimpan |
| 3 — Onboarding penjual & storefront | **Selesai lokal** | `Store`, `StoreLifecycle`, review admin, dokumen KYC privat. **Sub-account provider pembayaran ditunda** |
| 4 — Katalog, media, variasi | **Selesai** | `ProductImage`, `ProductVariant`, lifecycle `draft→published→…`, moderasi, snapshot variasi di order |
| 5 — Cart, alamat, ongkir | **Selesai sebagian** | cart persisten + merge, address book, komponen biaya (`shipping_fee`, `platform_fee`, `merchandise_subtotal`), pickup. **Aggregator kurir & quote expiry diblokir** |
| 6 — Pembayaran nyata | **Belum dimulai — BLOCKED** | tidak ada tabel payment; `shipping_fee` masih `null` saat checkout |
| 7 — Pengiriman & handoff | **Belum dimulai** | `OrderFulfillment` hanya punya `received/accepted/ready/completed` + `handoff`; belum ada shipment/tracking |
| 8 — Lifecycle rental | **Belum dimulai** | rental berhenti di `completed`; tidak ada `in_use/returned/inspected` |
| 9 — Batal, retur, refund, sengketa | **Belum dimulai** | yang ada baru moderasi laporan (`ModerationReport`), bukan dispute keuangan |
| 10 — Promosi & discovery | **Sebagian besar selesai — 29 Okt 2026** | halaman kategori/kota/toko + canonical, sitemap selaras visibilitas, filter katalog, pagination halaman SEO, saved search + alert ketersediaan (command `searches:scan` dua kali sehari). **Belum:** halaman series, recent views, restock alert otomatis per SKU, voucher/campaign (menunggu Batch 6), analytics funnel |
| 11 — Komunikasi multi-channel | **Sebagian** | outbox email `notification_deliveries` + preferensi + unsubscribe selesai; **provider nyata, `provider_message_id`, bounce, broadcast realtime belum ada** |
| 12 — Admin & trust & safety | **Selesai lokal — 29 Okt 2026** | seluruh fitur konsol API + UI, freeze payout, koreksi ledger, retention job, dan UI export privasi (tombol di tab Akun, unduh JSON, step-up tetap di server). Acceptance di staging menyusul deploy live |
| 13 — Ledger, fee, settlement | **Fondasi baca selesai** | `LedgerService`, `SellerBalance`, `LedgerReconciliation`, `fee_policy_version`; **write-side (posting saat pembayaran, payout) menunggu Batch 6** |
| 14 — Hardening & launch | **Baseline selesai — 29 Okt 2026** | security headers + CSP di semua response, cookie secure default produksi, composer audit bersih (4 advisory diperbaiki: laravel/framework, league/commonmark, symfony/http-foundation, symfony/mime), tes `HardeningBaselineTest`. **Belum:** threat model penuh, load test, a11y audit, restore/rollback drill, monitoring |

---

## Blocker aktif (keputusan bisnis/aset, bukan pekerjaan kode)

| Blocker | Memblokir | Pemilik |
|---|---|---|
| VPS + DNS + TLS | `Deploy staging` (workflow sudah ada, secrets belum), gate pra-Batch 6 | pemilik proyek |
| Akun payment provider v1 | Batch 3 onboarding sub-account, Batch 6 pembayaran | pemilik proyek |
| Akun aggregator kurir v1 | sisa Batch 5, Batch 7 pengiriman | pemilik proyek |
| Docker belum terpasang di workstation | menjalankan `compose.staging.yml` lokal | pengembang |

Keputusan yang masih dibutuhkan pemilik proyek:
- Payment provider v1: **Midtrans atau Xendit?**
- Aggregator kurir v1: **RajaOngkir atau Biteship?**

---

## Urutan pengerjaan selanjutnya

### Jalur A — tanpa host, tanpa provider (bisa dikerjakan sekarang)

1. **Sisa Batch 12 — Backoffice UI + freeze payout + correction action — SELESAI 16 Oktober 2026**
   - UI konsol `/backoffice`: tab laporan, toko, akun, pesanan, ledger, audit; cursor pagination; filter toko/akun; konfirmasi kata sandi dengan replay sekali saat 423.
   - `POST /api/backoffice/stores/{store}/freeze-payout` dan `unfreeze-payout` — kapabilitas `finance`, wajib alasan, step-up, diaudit. Freeze **tidak menyentuh ledger**: hanya menahan pencairan.
   - `POST /api/backoffice/ledger/transactions/{transaction}/reverse` — koreksi lewat transaksi cermin (`reverses_transaction_id` unik), entri asli tidak pernah diubah, idempoten.
   - `data:prune` — retensi data transien (token reset, sesi, security event, outbox terkirim) terdaftar harian di scheduler. Order, ledger, ulasan, pesan, laporan, dan audit **tidak pernah** disentuh.
   **Hasil:** 214 test / 1731 assertion lulus; Pint lulus; build Vite lulus; browser smoke 10 langkah lulus tanpa overflow di 390 px.

2. **Batch 10 — Discovery & SEO — sudah diimplementasikan, belum memenuhi seluruh acceptance**
   - halaman kategori/kota/store yang crawlable + metadata + canonical;
   - `sitemap.xml`;
   - filter tipe, ukuran, kota, rentang harga, rating, kondisi, dan ketersediaan tanggal rental;
   - panel filter di beranda plus validasi klien.
   **Hasil:** 218 test / 1786 assertion lulus; Pint lulus; build Vite lulus; browser smoke filter + mobile 390 px tanpa overflow lulus.
   **Gap terkonfirmasi pada pemeriksaan lanjutan:** filter `size=M` dengan tanggal masih mengembalikan produk ketika SKU M penuh tetapi SKU L tersedia; sitemap memuat kota yang hanya memiliki listing toko suspended walaupun halaman kotanya 404. Halaman SEO juga masih memakai batas 24 produk tanpa pagination. Hasil tes di atas belum mencakup kasus-kasus ini.
   > Voucher/campaign **ditahan** sampai Batch 6 selesai, karena diskon harus dihitung server-side dan tersnapshot bersama pembayaran.

3. **Sisa Batch 11 — penuntasan kanal email (memerlukan provider)**
   - kredensial SMTP/API dan verifikasi kirim nyata belum tersedia; pekerjaan ini bukan jalur bebas provider;
   - `provider_message_id` dan suppression/bounce handling mengikuti provider terpilih;
   - broadcast realtime in-app (API/database tetap authoritative).

4. **Batch 14 — hardening awal**
   Threat model, security headers, dependency scanning, review index/query.

### Jalur B — setelah blocker terlepas

5. **Deploy staging ke host nyata** → tutup gate pra-Batch 6 (workflow sukses, DNS/TLS aktif, `/ready` hijau, rollback DB + release terbukti).
6. **Batch 6 — pembayaran nyata.** Setelah ini: hapus `STATUS_DEMO_CONFIRMED` dan seluruh copy demo dari mode produksi.
7. **Batch 13 write-side** (posting `seller_payable`, transisi `settlement_status`, payout) — menyusul Batch 6.
8. **Batch 7 → 8 → 9** (pengiriman, lifecycle rental, refund/dispute) sesuai urutan roadmap.
9. **Batch 14 penuh → GA.**

---

## Definisi selesai

Proyek dinyatakan feature complete ketika seluruh kondisi ini terpenuhi:

1. Pembeli: daftar → verifikasi → temukan produk → bayar → lacak → terima/kembalikan → batal/refund → sengketa → ulasan.
2. Penjual: onboarding → kelola toko & listing → fulfill → kirim/terima kembali → tangani sengketa → settlement yang dapat direkonsiliasi.
3. Admin: moderasi akun/listing, tangani laporan & sengketa, periksa transaksi/ledger, tindakan sensitif ber-audit.
4. Setiap perubahan uang, stok, reservasi, fulfillment, pengiriman, refund, settlement bersifat idempotent, dapat diaudit, aman terhadap retry/webhook ganda.
5. Berjalan di infrastruktur produksi: database concurrent-write, queue, object storage, email, monitoring, backup/restore, CI, rollback.
6. Alur kritis lulus feature test, integration test provider, browser smoke desktop/mobile, accessibility check, uji kegagalan boundary transaksi.
7. **Tidak ada lagi status, copy, atau seed yang menyebut transaksi sebagai demo/simulasi pada mode produksi.**

Poin 7 belum terpenuhi: `demo_confirmed` dan copy "checkout demo" masih hidup di kode, UI, dan README. Pembersihan ini menjadi bagian dari Batch 6, bukan pekerjaan mandiri — menghapusnya sebelum pembayaran nyata ada akan membuat checkout tidak berstatus apa-apa.

## Yang sengaja bukan target v1

Aplikasi native; transaksi internasional/multi-currency/multi-language; lelang, live shopping, social feed, gamification; rekomendasi ML; lebih dari satu provider payment/shipping aktif; warehouse milik platform; seller team multi-user & enterprise API.
