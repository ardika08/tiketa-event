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

            <h3 style="margin:24px 0 8px;font-size:15px;">Rincian Tiket</h3>
            <table style="width:100%;font-size:13px;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;color:#64748b;">
                        <th style="padding:6px 0;border-bottom:1px solid #e2e8f0;">Tiket</th>
                        <th style="padding:6px 0;border-bottom:1px solid #e2e8f0;text-align:center;">Qty</th>
                        <th style="padding:6px 0;border-bottom:1px solid #e2e8f0;text-align:right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->items as $item)
                        <tr>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;">{{ $item->ticketType->nama_tiket }}</td>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;text-align:center;">{{ $item->jumlah }}</td>
                            <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;text-align:right;">Rp {{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <h3 style="margin:24px 0 8px;font-size:15px;">E-Ticket (QR per Hari)</h3>
            @foreach ($order->tickets as $ticket)
                <div style="border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:10px;">
                    <p style="margin:0;font-size:13px;font-weight:bold;">{{ $ticket->ticketType->nama_tiket }}</p>
                    @if ($ticket->ticketType?->jam_masuk_mulai)
                        @php $jamSelesai = $ticket->ticketType->jam_masuk_selesai; @endphp
                        <p style="margin:2px 0 0;font-size:12px;color:#4f46e5;font-weight:bold;">
                            Jam masuk: {{ substr($ticket->ticketType->jam_masuk_mulai, 0, 5) }}{{ $jamSelesai ? '–'.substr($jamSelesai, 0, 5) : '' }} WIB
                        </p>
                    @endif
                    <p style="margin:2px 0 8px;font-size:12px;color:#64748b;">Pemegang: {{ $ticket->nama_pemegang }} · Kode: {{ $ticket->kode_tiket }}</p>
                    <table style="width:100%;font-size:12px;border-collapse:collapse;">
                        @foreach ($ticket->passes as $pass)
                            <tr>
                                <td style="padding:4px 0;color:#64748b;">
                                    {{ $pass->session?->label ?? 'Tiket Masuk' }}
                                    @if ($pass->session) · {{ $pass->session->nama_session }} @endif
                                </td>
                                <td style="padding:4px 0;text-align:right;font-family:monospace;font-weight:bold;">{{ $pass->kode_qr }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endforeach

            <div style="margin-top:20px;">
                <a href="{{ $frontendUrl }}/invoice?kode={{ urlencode($order->kode_order) }}" style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">Lihat Invoice</a>
                {{-- Tombol e-ticket disembunyikan sementara (QR diperbaiki) --}}
                {{-- <a href="{{ $frontendUrl }}/tiket/saya?kode={{ urlencode($order->kode_order) }}" style="display:inline-block;margin-left:8px;color:#4f46e5;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;border:1px solid #c7d2fe;">Lihat E-Ticket</a> --}}
            </div>

            <p style="margin:24px 0 0;font-size:12px;color:#94a3b8;">
                Email ini dibuat otomatis oleh sistem Nontix. Jangan balas email ini.
            </p>
        </div>
    </div>
</body>
</html>
