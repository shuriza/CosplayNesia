<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#5145cd">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='18' fill='%235145cd'/%3E%3Cpath d='M43 22c-3-4-7-6-12-6-9 0-15 7-15 16s6 16 15 16c5 0 9-2 12-6l-6-5c-2 2-4 3-6 3-4 0-7-3-7-8s3-8 7-8c2 0 4 1 6 3z' fill='white'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Plus+Jakarta+Sans:wght@600;700;800&amp;display=swap" rel="stylesheet">
    <title>Konsol Operasional | CosplayNesia</title>
    @vite(['resources/css/app.css', 'resources/js/backoffice.js'])
</head>
<body class="console-body">
    <header class="console-topbar">
        <div class="console-topbar__inner">
            <a class="brand" href="/" aria-label="Kembali ke beranda CosplayNesia">
                <span class="brand__mark" aria-hidden="true"><span>C</span></span>
                <span class="brand__name">CosplayNesia</span>
            </a>
            <span class="console-badge">Konsol Operasional</span>
            <div class="console-topbar__right">
                <span id="console-actor" class="console-actor" aria-live="polite"></span>
                <button type="button" class="button button--outline" id="console-stepup">Konfirmasi kata sandi</button>
            </div>
        </div>
    </header>

    <main class="console-shell">
        <div class="console-gate" id="console-gate" hidden>
            <h1>Akses terbatas</h1>
            <p id="console-gate-message">Konsol ini hanya untuk akun dengan kapabilitas backoffice.</p>
            <a class="button button--primary" href="/">Kembali ke beranda</a>
        </div>

        <div class="console-main" id="console-main" hidden>
            <nav class="console-tabs" aria-label="Bagian konsol">
                <button type="button" class="console-tab active" data-console-tab="reports" aria-pressed="true">Laporan</button>
                <button type="button" class="console-tab" data-console-tab="stores" aria-pressed="false">Toko</button>
                <button type="button" class="console-tab" data-console-tab="users" aria-pressed="false">Akun</button>
                <button type="button" class="console-tab" data-console-tab="orders" aria-pressed="false">Pesanan</button>
                <button type="button" class="console-tab" data-console-tab="ledger" aria-pressed="false">Ledger</button>
                <button type="button" class="console-tab" data-console-tab="audit" aria-pressed="false">Audit</button>
            </nav>

            <p class="console-note" id="console-stepup-note" role="status"></p>

            <section class="console-panel" id="panel-reports" aria-labelledby="reports-title">
                <div class="console-panel__head">
                    <div><h2 id="reports-title">Laporan moderasi</h2><p>Konten yang dilaporkan pengguna, dengan evidence berbatas.</p></div>
                    <button type="button" class="button button--outline" data-console-load="reports">Muat ulang</button>
                </div>
                <div class="console-list" id="reports-list" aria-live="polite" aria-busy="false"></div>
                <button type="button" class="button button--outline console-load-more" id="load-more-reports" hidden>Muat laporan lain</button>
            </section>

            <section class="console-panel" id="panel-stores" aria-labelledby="console-stores-title" hidden>
                <div class="console-panel__head">
                    <div><h2 id="console-stores-title">Toko</h2><p>Suspend, aktifkan kembali, atau bekukan pencairan dana.</p></div>
                    <button type="button" class="button button--outline" data-console-load="stores">Muat ulang</button>
                </div>
                <form class="console-filter" id="stores-filter">
                    <label>Cari<input name="q" type="search" placeholder="Nama atau slug toko" autocomplete="off"></label>
                    <label>Status<select name="status"><option value="">Semua</option><option value="draft">Draft</option><option value="submitted">Diajukan</option><option value="approved">Disetujui</option><option value="rejected">Ditolak</option><option value="suspended">Suspended</option></select></label>
                    <button type="submit" class="button button--outline">Terapkan</button>
                </form>
                <div class="console-list" id="stores-list" aria-live="polite" aria-busy="false"></div>
                <button type="button" class="button button--outline console-load-more" id="load-more-stores" hidden>Muat toko lain</button>
            </section>

            <section class="console-panel" id="panel-users" aria-labelledby="console-users-title" hidden>
                <div class="console-panel__head">
                    <div><h2 id="console-users-title">Akun</h2><p>Email disamarkan pada daftar; detail hanya setelah konfirmasi kata sandi.</p></div>
                    <button type="button" class="button button--outline" data-console-load="users">Muat ulang</button>
                </div>
                <form class="console-filter" id="users-filter">
                    <label>Cari<input name="q" type="search" placeholder="Nama atau email" autocomplete="off"></label>
                    <label>Peran<select name="role"><option value="">Semua</option><option value="buyer">Buyer</option><option value="seller">Seller</option><option value="support">Support</option><option value="moderator">Moderator</option><option value="finance">Finance</option><option value="admin">Admin</option></select></label>
                    <button type="submit" class="button button--outline">Terapkan</button>
                </form>
                <div class="console-list" id="users-list" aria-live="polite" aria-busy="false"></div>
                <button type="button" class="button button--outline console-load-more" id="load-more-users" hidden>Muat akun lain</button>
                <section class="privacy-export" id="privacy-export" aria-labelledby="privacy-export-title" hidden>
                    <div class="console-panel__head">
                        <div><h3 id="privacy-export-title">Export privasi</h3><p id="privacy-export-subject"></p></div>
                        <div class="privacy-export__actions">
                            <button type="button" class="button button--outline" id="privacy-export-download">Unduh JSON</button>
                            <button type="button" class="button button--outline" id="privacy-export-close">Tutup</button>
                        </div>
                    </div>
                    <p class="privacy-export__note">Isi export dibatasi pada allowlist resmi: tanpa hash kata sandi, token, sidik jari sesi, atau data pihak lain. Pembacaan ini tercatat di log audit.</p>
                    <pre class="privacy-export__body" id="privacy-export-body" tabindex="0"></pre>
                </section>
            </section>

            <section class="console-panel" id="panel-orders" aria-labelledby="console-orders-title" hidden>
                <div class="console-panel__head">
                    <div><h2 id="console-orders-title">Pesanan</h2><p>Jejak fulfillment dan status settlement per pesanan.</p></div>
                    <button type="button" class="button button--outline" data-console-load="orders">Muat ulang</button>
                </div>
                <div class="console-list" id="orders-list" aria-live="polite" aria-busy="false"></div>
                <button type="button" class="button button--outline console-load-more" id="load-more-orders" hidden>Muat pesanan lain</button>
            </section>

            <section class="console-panel" id="panel-ledger" aria-labelledby="console-ledger-title" hidden>
                <div class="console-panel__head">
                    <div><h2 id="console-ledger-title">Ledger &amp; rekonsiliasi</h2><p>Saldo penjual dan integritas double-entry. Koreksi selalu transaksi baru.</p></div>
                    <button type="button" class="button button--outline" data-console-load="ledger">Muat ulang</button>
                </div>
                <div class="console-summary" id="ledger-summary" aria-live="polite"></div>
                <div class="console-list" id="ledger-list" aria-live="polite" aria-busy="false"></div>
                <button type="button" class="button button--outline console-load-more" id="load-more-ledger" hidden>Muat transaksi lain</button>
            </section>

            <section class="console-panel" id="panel-audit" aria-labelledby="console-audit-title" hidden>
                <div class="console-panel__head">
                    <div><h2 id="console-audit-title">Log audit</h2><p>Append-only: tidak ada endpoint untuk mengubah atau menghapus baris.</p></div>
                    <button type="button" class="button button--outline" data-console-load="audit">Muat ulang</button>
                </div>
                <div class="console-list" id="audit-list" aria-live="polite" aria-busy="false"></div>
                <button type="button" class="button button--outline console-load-more" id="load-more-audit" hidden>Muat entri lain</button>
            </section>
        </div>
    </main>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <section class="modal console-modal" id="console-action-modal" role="dialog" aria-modal="true" aria-labelledby="console-action-title" aria-hidden="true" inert>
        <button type="button" class="close-button modal__close" id="console-action-close" aria-label="Tutup">×</button>
        <span class="section-kicker" id="console-action-kicker">Tindakan operator</span>
        <h2 id="console-action-title">Konfirmasi tindakan</h2>
        <p id="console-action-body"></p>
        <form id="console-action-form" novalidate>
            <label>Alasan<input name="reason" type="text" required minlength="10" maxlength="500" autocomplete="off"></label>
            <p class="form-error" id="console-action-error" role="alert" hidden></p>
            <button type="submit" class="button button--primary button--full">Jalankan</button>
        </form>
    </section>

    <div class="overlay" id="console-overlay" hidden></div>
</body>
</html>
