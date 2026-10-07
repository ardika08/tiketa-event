<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice & E-Ticket</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:640px;margin:0 auto;padding:24px;">
        <div style="background:#4f46e5;color:#fff;border-radius:16px 16px 0 0;padding:24px;">
            <h1 style="margin:0;font-size:20px;">Nontix</h1>
            <p style="margin:4px 0 0;font-size:13px;opacity:.9;">Gak perlu ribet</p>
        </div>

        <div style="background:#fff;padding:24px;border-radius:0 0 16px 16px;">
            <h2 style="margin:0 0 4px;font-size:18px;">Pembayaran Berhasil 🎉</h2>
            <p style="margin:0 0 16px;font-size:14px;color:#475569;">
                Hai {{ $order->nama_pembeli }}, terima kasih! Pesananmu sudah lunas.
            </p>

            <table style="width:100%;font-size:14px;border-collapse:collapse;">
                <tr><td style="padding:4px 0;color:#64748b;">Kode Order</td><td style="text-align:right;font-weight:bold;">{{ $order->kode_order }}</td></tr>
                <tr><td style="padding:4px 0;color:#64748b;">Event</td><td style="text-align:right;">{{ $order->event->nama_event }}</td></tr>
                <tr><td style="padding:4px 0;color:#64748b;">Total Bayar</td><td style="text-align:right;font-weight:bold;color:#4f46e5;">Rp {{ number_format((float) $order->total_harga, 0, ',', '.') }}</td></tr>
            </table>

            @include('emails.partials.e-ticket')

            <div style="margin-top:20px;">
                <a href="{{ $frontendUrl }}/invoice?kode={{ urlencode($order->kode_order) }}" style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">Lihat Invoice</a>
                <a href="{{ $frontendUrl }}/tiket/saya?kode={{ urlencode($order->kode_order) }}" style="display:inline-block;margin-left:8px;color:#4f46e5;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;border:1px solid #c7d2fe;">Lihat E-Ticket</a>
            </div>

            <p style="margin:24px 0 0;font-size:12px;color:#94a3b8;">
                Email ini dibuat otomatis oleh sistem Nontix. Jangan balas email ini.
            </p>
        </div>
    </div>
</body>
</html>
