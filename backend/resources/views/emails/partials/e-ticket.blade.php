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
