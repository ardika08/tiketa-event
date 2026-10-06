<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kirim Ulang E-Ticket</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:640px;margin:0 auto;padding:24px;">
        <div style="background:#4f46e5;color:#fff;border-radius:16px 16px 0 0;padding:24px;">
            <h1 style="margin:0;font-size:20px;">Nontix</h1>
            <p style="margin:4px 0 0;font-size:13px;opacity:.9;">Kirim ulang e-ticket</p>
        </div>

        <div style="background:#fff;padding:24px;border-radius:0 0 16px 16px;">
            <h2 style="margin:0 0 4px;font-size:18px;">E-Ticket Kamu</h2>
            <p style="margin:0 0 16px;font-size:14px;color:#475569;">
                Berikut e-ticket untuk pesanan <strong>{{ $order->kode_order }}</strong> ({{ $order->event->nama_event }}).
            </p>

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
                <a href="{{ $frontendUrl }}/tiket/saya" style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">Lihat E-Ticket</a>
            </div>

            <p style="margin:24px 0 0;font-size:12px;color:#94a3b8;">
                Email ini dibuat otomatis oleh sistem Nontix. Jangan balas email ini.
            </p>
        </div>
    </div>
</body>
</html>
