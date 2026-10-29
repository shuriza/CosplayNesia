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

## Yang sudah selesai pada jalur tanpa host/provider

1. **Batch 10 — Discovery & SEO:** halaman kategori/kota/store, metadata/canonical, `sitemap.xml` selaras visibilitas, filter katalog, pagination halaman SEO, saved search + alert ketersediaan (`searches:scan` 08.00 & 18.00).
2. **Batch 12 — konsol operator:** UI `/backoffice`, tindakan moderasi, freeze payout, koreksi ledger, retention job, dan **UI export privasi** (tombol di tab Akun, unduh JSON).
3. **Batch 14 baseline:** security headers + CSP, cookie secure default produksi, `HardeningBaselineTest`, composer audit bersih.

## Yang dikerjakan berikutnya tanpa host/provider

1. **Sisa Batch 10:** halaman series, recent views, restock alert otomatis, analytics funnel ber-consent. Voucher/campaign tetap menunggu Batch 6.
2. **Sisa Batch 14:** threat model tertulis, review index/query pada volume data besar, a11y audit alur kritis.

## Pekerjaan yang memerlukan provider

- **Batch 11 email:** kredensial SMTP/API, kirim nyata, `provider_message_id`, suppression/bounce. Outbox dan preferensi sudah siap; realtime in-app adalah pekerjaan terpisah.
- **Batch 5/7 kurir:** akun aggregator (RajaOngkir atau Biteship).
- **Batch 6 pembayaran + Batch 13 write-side:** akun payment provider (Midtrans atau Xendit) DAN deployment staging live (gate keras).

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
