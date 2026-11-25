<!doctype html>
<html lang="id">
<body>
    <h1>Pembatalan tiket</h1>
    <p>QR berikut untuk {{ $notice['event'] }} (pesanan {{ $notice['kode_order'] }}) telah dibatalkan dan tidak bisa dipakai untuk masuk:</p>
    <ul>
        @foreach ($notice['tickets'] as $ticket)
            <li>{{ $ticket['nama_tiket'] }} — {{ implode(', ', $ticket['sesi']) }}</li>
        @endforeach
    </ul>
    <p>Alasan: {{ $notice['reason'] }}</p>
    <p>Tiket lain dalam pesanan ini tidak terpengaruh oleh pembatalan ini.</p>
</body>
</html>
