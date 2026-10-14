# Langkah Berikutnya

Ringkasan operasional dari ROADMAP.md. Dokumen ini menjawab satu pertanyaan: **apa yang dikerjakan sekarang, sementara VPS dan akun provider belum ada.**

## Blocker aktif

| Blocker | Memblokir | Pemilik |
|---|---|---|
| VPS + DNS + TLS | `Deploy staging`, gate pra-Batch 6 | pemilik proyek |
| Akun payment provider (v1) | Batch 3 onboarding, Batch 6 pembayaran | pemilik proyek |
| Akun aggregator kurir (v1) | sisa Batch 5, Batch 7 pengiriman | pemilik proyek |
| Docker belum terpasang di workstation | menjalankan `compose.staging.yml` lokal | pengembang |

Ini keputusan bisnis/aset, bukan pekerjaan kode. Jangan tahan pekerjaan lain menunggu; kerjakan jalur paralel di bawah.

## Yang dikerjakan sekarang (tanpa host, tanpa provider)

1. **Ledger (Batch 13) — fondasi baca selesai.** ADR `docs/adr/0001-double-entry-ledger.md` dan `docs/adr/0002-seller-balance-projection.md`; schema (`ledger_accounts`, `ledger_transactions`, `ledger_entries`, `fee_policies`) dengan `LedgerService` sebagai jalur tulis tunggal. Sudah ada: `SellerBalance` (proyeksi `pending`/`available`/`reserved`/`paid` + `unclassified`), kolom klasifikasi `order_fulfillments.settlement_status`, snapshot `orders.fee_policy_version` saat checkout, dan laporan read-only `LedgerReconciliation` + command `ledger:reconcile` (exit code gagal bila ada exception). **Berikutnya (write-side, menyusul Batch 6):** transisi `settlement_status`, posting kredit `seller_payable` saat pembayaran, dan payout.
2. **Backoffice (Batch 12) — baca-saja dan tindakan operator selesai.** Route `/api/backoffice/*` dengan RBAC capability (`backoffice`, `backoffice_finance`, `backoffice_queue`, `review_listings`, `review_stores`) dan step-up password. Baca: users, stores, orders (dengan jejak fulfillment + settlement), ledger (transaksi/akun/saldo penjual/rekonsiliasi), queue health, audit log append-only (`admin_audit_logs`). Tulis: takedown/restore listing, suspend/reactivate toko, catatan support privat, laporan moderasi dengan evidence, export privasi — semua dengan alasan wajib, capability diperiksa sebelum step-up, dan audit before/after teredaksi. **Berikutnya (sisa Batch 12):** backoffice UI, freeze payout, correction action via compensating event, retention job.
3. **Email transaksional (Batch 11) — kanal selesai, provider belum.** ADR `docs/adr/0003-transactional-email-outbox.md`; outbox `notification_deliveries` dengan jaminan satu email per `(channel, event_key)`, `NotificationCatalog`, job `SendNotificationEmail` dengan klaim bersyarat dan dead-letter, preferensi per channel/kategori, serta unsubscribe bertanda tangan. Sudah terkirim untuk: pesanan masuk, transisi fulfillment, pembatalan sewa, ulasan, balasan ulasan, pesan, dan event keamanan akun. **Berikutnya:** kredensial provider SMTP/API, verifikasi pengiriman nyata, `provider_message_id` dari vendor, suppression/bounce handling, dan broadcast realtime in-app.
4. **Discovery/SEO (Batch 10).** Halaman kategori/kota/store, sitemap, filter ketersediaan tanggal — selama tidak mendahului snapshot harga.
5. **Hardening awal (Batch 14).** Threat model, security headers, dependency scanning, review index/query.

## Gate sebelum Batch 6

Batch 6 tetap blocked sampai **semua** terpenuhi:

- workflow `Deploy staging` sukses untuk commit kandidat pada host nyata;
- DNS/TLS aktif dan `https://<host>/ready` hijau;
- rollback database/release terbukti pada environment tersebut.

Job `container` di CI kini menyalakan topology staging penuh dan memverifikasi `/ready`, tetapi itu membuktikan topology, **bukan** pengganti deployment live.

## Opsi host tanpa VPS berbayar

1. Oracle Cloud Always Free / Fly.io / Render — paling dekat dengan definisi "VPS nyata".
2. Docker Desktop lokal + Cloudflare Tunnel — validasi workflow end-to-end, tapi host-nya PC sendiri.
3. CI-only — topology terbukti di runner GitHub; deployment live tetap dinyatakan belum ada.

## Keputusan yang masih dibutuhkan

- Payment provider v1: Midtrans atau Xendit?
- Aggregator kurir v1: RajaOngkir atau Biteship?
