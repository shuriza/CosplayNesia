<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $destination }}</title>
</head>
<body style="margin:0;padding:24px;background:#f7f7fb;color:#18182a;font-family:'DM Sans',Arial,sans-serif;">
    <div style="max-width:520px;margin:0 auto;padding:28px;background:#ffffff;border:1px solid #e8e7ef;border-radius:18px;">
        <p style="margin:0 0 18px;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#5145cd;">CosplayNesia</p>

        @if ($lines !== [])
            <ul style="margin:0 0 20px;padding-left:18px;font-size:14px;line-height:1.7;">
                @foreach ($lines as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        @endif

        <p style="margin:0 0 20px;font-size:14px;line-height:1.7;">
            Buka CosplayNesia untuk melihat detail lengkapnya.
        </p>

        <p style="margin:0;font-size:11px;line-height:1.6;color:#6c6c7d;">
            Email ini dikirim otomatis karena ada aktivitas pada akun CosplayNesia kamu.
            @if ($unsubscribeUrl)
                <a href="{{ $unsubscribeUrl }}" style="color:#6c6c7d;text-decoration:underline;">Berhenti berlangganan kategori ini</a>.
            @endif
        </p>
    </div>
</body>
</html>
