# ADR 0001 — Double-entry ledger untuk uang

- **Status:** Accepted (desain; implementasi penuh menyusul pada Batch 6/13)
- **Tanggal:** 2026-10-04
- **Konteks batch:** ROADMAP.md mewajibkan desain ledger dan fee policy dibuat **sebelum** koding Batch 6, dengan UI payout tetap dikerjakan pada Batch 13.

## Konteks

Saat ini nilai uang hanya tersimpan sebagai snapshot di `orders` (`total_amount`, `merchandise_subtotal`, `shipping_fee`, `platform_fee`, `rental_deposit`, `grand_total`) dan tidak ada sumber kebenaran tunggal untuk pergerakan uang. Menghitung ulang saldo penjual dari status order tidak dapat menjawab pertanyaan dasar finance:

- berapa saldo penjual yang masih *pending* vs *available* vs *reserved* vs *paid*;
- bagaimana refund sebagian, biaya keterlambatan rental, atau subsidi diskon masuk ke angka;
- apakah payout, webhook, dan refund yang di-retry menggandakan uang.

Roadmap sudah menetapkan prinsip: **uang disimpan sebagai integer rupiah** dan **perubahan saldo memakai double-entry ledger, bukan menghitung ulang dari status order**.

## Keputusan

### 1. Double-entry, append-only

Setiap pergerakan uang adalah satu `ledger_transactions` (header) dengan dua atau lebih `ledger_entries` (baris debit/kredit) yang **wajib balance**: total debit = total kredit. Baris ledger bersifat **append-only** — tidak boleh di-update atau di-delete (dipaksa di level model, sama seperti `order_activities` dan `fulfillment_messages`). Koreksi dilakukan lewat transaksi *compensating* baru, bukan dengan mengedit history.

### 2. Idempotency di level transaksi

`ledger_transactions.event_key` unik. Retry webhook, replay transaksi, atau job yang dijalankan ulang memakai `insertOrIgnore`/first-or-create terhadap `event_key` sehingga tidak pernah menggandakan uang. Ini sejalan dengan `NotificationRecorder` dan `OrderActivity`.

### 3. Chart of accounts

Akun disimpan di `ledger_accounts` dengan `code` unik. Akun sistem memakai kode tetap; akun milik pihak tertentu (mis. payable per penjual) memakai kode turunan `seller_payable:{id}` dan menyimpan `owner_type`/`owner_id` untuk query.

Akun awal (seeded oleh migrasi):

| code | nama | tipe | owner |
|---|---|---|---|
| `1000` | Platform cash | asset | platform |
| `2000` | Seller payable | liability | seller (per penjual) |
| `2100` | Rental deposit held | liability | platform |
| `4000` | Platform fee revenue | revenue | platform |
| `4100` | Shipping revenue | revenue | platform |
| `5000` | Discount subsidy | expense | platform |
| `5100` | Payment gateway fee | expense | platform |

### 4. Fee policy ter-versioning

`fee_policies` menyimpan versi kebijakan biaya (`version` unik, `platform_fee_bps`, `platform_fee_flat`, periode efektif). Order harus **menyimpan versi yang berlaku saat order dibuat**, bukan merujuk kebijakan terkini — supaya perubahan kebijakan tidak mengubah arti order lama.

> **Tindak lanjut Batch 6:** tambahkan kolom nullable `orders.fee_policy_version` dan isi dari kebijakan aktif saat checkout. Kolom ini sengaja **belum** dibuat sekarang agar tidak ada kolom menggantung yang tidak ditulis siapa pun (roadmap: tanpa mode ganda permanen).

### 5. Saldo penjual adalah proyeksi, bukan tabel mutabel

`pending`/`available`/`reserved`/`paid` dihitung dari akun `seller_payable` (saldo kredit = kewajiban platform ke penjual) dikombinasikan dengan status settlement/dispute. Tidak ada kolom saldo yang di-`UPDATE` langsung; bila nanti dibutuhkan performa, tambahkan cache saldo yang **selalu** dapat direkonstruksi dari ledger.

### 6. Rekonsiliasi harian

Total debit per akun vs mutasi provider/bank harus sama. Exception disimpan eksplisit (bukan disembunyikan), dan koreksi selalu berupa compensating entry.

## Konsekuensi

- Semua alur uang Batch 6–13 menulis lewat satu jalur (`LedgerService`), sehingga invariant balance dan idempotency hanya ditegakkan di satu tempat.
- Refund sebagian, deposit hold/capture/release, biaya keterlambatan, dan payout memakai kosakata yang sama.
- Tidak ada saldo yang "benar" hanya karena status order berubah; status order dan ledger harus konsisten dan dapat direkonsiliasi.
- Ledger tidak menambah beban UX apa pun pada v1; ia infrastruktur finance.

## Yang belum diputuskan (butuh Batch 6/13)

- Provider pembayaran dan payout v1 belum dipilih → bentuk akun `payment_gateway_fee` dan event webhook belum final.
- Aturan kapan saldo berpindah `pending → available` (saat delivery vs saat rental close) mengikuti keputusan Batch 8.
- Kebijakan hold saat dispute mengikuti keputusan Batch 9.
