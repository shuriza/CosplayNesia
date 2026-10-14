# ADR 0003 — Kanal email transaksional lewat outbox idempoten

- **Status:** Accepted (implementasi awal Batch 11)
- **Tanggal:** 2026-10-14
- **Konteks batch:** ROADMAP.md Batch 11; melanjutkan pola idempotensi ADR 0001/0002.

## Konteks

Notifikasi in-app sudah punya jalur tulis tunggal (`NotificationRecorder`) dengan `event_key`
unik yang membuat fan-out idempotent. Pengguna, bagaimanapun, hanya melihat notifikasi kalau mereka
membuka drawer. Batch 11 meminta email transaksional untuk event yang sudah ada sekarang —
verifikasi, pesanan, perubahan status fulfillment, pembatalan sewa, ulasan, dan pesan — tanpa
menunggu payment gateway.

Tiga masalah harus dijawab sebelum menulis kode:

1. **Duplikasi.** Queue bersifat *at-least-once*. Retry, redelivery, dan transaksi domain yang
   di-replay dapat memicu pengiriman berkali-kali. Email duplikat lebih terlihat daripada notifikasi
   in-app duplikat.
2. **Kebocoran data.** Payload notifikasi internal boleh memuat apa saja; email keluar dari batas
   kepercayaan platform dan tidak boleh.
3. **Preferensi vs. keamanan.** Pengguna harus bisa mematikan kategori yang berisik, tetapi tidak
   boleh bisa mematikan peringatan bahwa akunnya sedang diambil alih.

## Keputusan

### 1. Outbox per (channel, event_key) sebagai jaminan, bukan konvensi

`notification_deliveries` menyimpan satu baris per `(channel, event_key)` dengan unique index.
Baris ditulis **di dalam transaksi domain yang sama** dengan notifikasi in-app, sehingga checkout
yang rollback tidak meninggalkan email yatim. Job hanya di-dispatch lewat `DB::afterCommit`, jadi
worker tidak pernah mengambil delivery yang baris domainnya belum commit.

### 2. Retry aman lewat klaim, bukan lewat kehati-hatian

`SendNotificationEmail` memindahkan baris `pending -> processing` dengan `UPDATE` bersyarat.
Redelivery apa pun menemukan tidak ada yang bisa diklaim dan keluar. Baris `sent` tidak pernah
diklaim lagi. Ini membuat "retry tidak mengirim dua kali" tetap benar meski queue sendiri
at-least-once. Kegagalan permanen berakhir di status `failed` (dead-letter) dan dipulihkan eksplisit
via `notifications:retry`.

### 3. Proyeksi allowlist, bukan dump payload

`NotificationChannel::project()` memotong payload ke daftar kunci tetap sebelum menyimpannya ke
outbox. Email tidak dapat tumbuh sebuah field hanya karena pemanggil menambahkannya ke payload
notifikasi. Proyeksi dibekukan saat enqueue, sehingga pesan yang akhirnya terkirim adalah pesan
yang disetujui.

### 4. Kategori wajib tidak pernah dibaca dari tabel preferensi

`NotificationPreferences::allows()` mengembalikan `true` untuk kategori `security` **sebelum**
menyentuh tabel. Tidak ada baris preferensi yang bisa mematikannya, dan bug di jalur tulis pun tidak
dapat membuka kembali lubangnya. Endpoint preferensi juga menolak `security` sebagai nilai input.

### 5. Unsubscribe bertanda tangan dan terbatas kategori

Tautan unsubscribe di footer email adalah signed URL berumur 30 hari yang mengikat `user` dan
`category`. Kategori adalah bagian dari tanda tangan, jadi tautan tidak dapat diedit menjadi
permintaan kategori lain, dan kategori wajib tidak pernah menghasilkan tautan.

## Konsekuensi

- Menambah tipe notifikasi baru cukup menambah entri di `NotificationCatalog`; tidak perlu tabel,
  endpoint, atau kelas mail baru.
- `provider_message_id` disimpan untuk rekonsiliasi bounce/komplen di masa depan, meski belum ada
  yang membacanya.
- Pada driver queue `sync` (dev/test) kegagalan kirim dicatat lalu ditelan, karena melempar akan
  menampilkan masalah email sebagai checkout yang gagal. Pada queue nyata exception dilempar ulang
  agar worker retry dengan backoff.
- Email belum memuat lampiran, template kaya, atau unsubscribe global lintas kategori.
