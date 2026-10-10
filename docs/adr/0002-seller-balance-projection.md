# ADR 0002 — Saldo penjual sebagai proyeksi, bukan tabel mutabel

- **Status:** Accepted (implementasi awal Batch 13)
- **Tanggal:** 2026-10-06
- **Konteks batch:** ROADMAP.md Batch 13; melanjutkan ADR 0001.

## Konteks

ADR 0001 menetapkan bahwa perubahan uang memakai double-entry ledger dan bahwa saldo penjual
`pending/available/reserved/paid` adalah **proyeksi**, bukan kolom yang di-`UPDATE`. Batch 6
(pembayaran) belum boleh dimulai, tetapi fondasi klasifikasi saldo dibutuhkan lebih dulu supaya
pembayaran nanti menulis ke kosakata yang sudah pasti.

Pertanyaan yang harus dijawab sekarang, tanpa provider pembayaran: bagaimana saldo penjual
dihitung dari ledger + status fulfillment, dan bagaimana ketidakcocokan terlihat sebagai exception
alih-alih tersembunyi.

## Keputusan

### 1. Sumber kebenaran tunggal tetap ledger

Total kewajiban platform ke penjual adalah saldo akun liabilitas `seller_payable:{id}` (kredit
dikurangi debit). `LedgerService::balance()` adalah satu-satunya cara membacanya. Tidak ada tabel
saldo yang di-`UPDATE`.

### 2. Status settlement pada fulfillment sebagai klasifikasi

`order_fulfillments.settlement_status` (`pending`/`available`/`reserved`/`paid`) adalah
**klasifikasi domain**, bukan saldo. Nilainya hanya memindahkan sebagian kewajiban ledger ke
bucket yang berbeda:

| bucket | arti |
|---|---|
| `pending` | dana sudah masuk ledger, belum layak dibayarkan ke penjual |
| `available` | layak disettlekan (mengikuti keputusan Batch 8/9) |
| `reserved` | ditahan karena dispute/klaim |
| `paid` | sudah dipindahkan ke penjual (payout Batch 13) |

Nilai bucket dihitung sebagai jumlah `order_items.unit_price * quantity` pada fulfillment terminal
(`completed`/`cancelled`) milik penjual. Komponen biaya lain (ongkir, deposit, platform fee) adalah
akun ledger terpisah dan belum masuk hitungan ini; ia masuk saat kredit `seller_payable` diposting
di Batch 6. Kolom ini sengaja **hanya** dipakai membaca; alur pembayaran Batch 6 yang akan
menulisnya.

### 3. `unclassified` membuat ketidakcocokan terlihat

`SellerBalance::for($sellerId)` mengembalikan:

- `liability` — saldo akun `seller_payable:{id}`;
- `pending/available/reserved/paid` — hasil klasifikasi;
- `classified` — jumlah keempat bucket;
- `unclassified = liability - classified` — selisih;
- `balanced = liability === classified`.

`unclassified` non-nol berarti ada rupiah yang mencapai ledger tanpa fulfillment yang
mengklasifikasikannya (atau sebaliknya). Itu selalu sinyal yang harus diperiksa, bukan angka yang
dibulatkan diam-diam. Saat Batch 6 berjalan, invariant yang benar adalah `unclassified === 0`.

### 4. Rekonsiliasi adalah laporan read-only

`LedgerReconciliation::report()` dan command `ledger:reconcile` hanya membaca dan menampilkan:

- setiap transaksi balance (total debit = total kredit);
- transaksi yang (seharusnya tidak pernah) tidak balance;
- penjual dengan `unclassified` non-nol;
- backlog settlement `pending` (jumlah fulfillment dan nominal).

Command mengembalikan exit code gagal bila ada exception, sehingga bisa dipakai sebagai gate di CI
atau operasi. Rekonsiliasi terhadap provider/bank dilaporkan sebagai
`unavailable_until_payment_provider` sampai Batch 6 menyediakan sumber eksternal.

### 5. Snapshot versi fee policy

`orders.fee_policy_version` menyimpan versi kebijakan biaya yang berlaku saat order dibuat. Order
tidak boleh menghitung ulang terhadap kebijakan terkini. Kolom diisi dari `FeePolicy::activeFor()`
saat checkout.

## Konsekuensi

- Batch 6 hanya perlu memindahkan `settlement_status` dan memposting kredit `seller_payable`;
  bentuk bacaannya sudah ada.
- Tidak ada saldo yang bisa "benar" hanya karena status fulfillment berubah; ledger dan klasifikasi
  harus konsisten dan dapat direkonsiliasi.
- `unclassified` menjadi metrik kesehatan, bukan galat fatal: ia memisahkan "belum ada uang" dari
  "ada uang yang belum terklasifikasi".

## Yang belum diputuskan

- Aturan transisi `pending → available` (saat delivery vs rental close) mengikuti Batch 8.
- Kebijakan `reserved` saat dispute mengikuti Batch 9.
- Bentuk payout provider mengikuti Batch 13.
