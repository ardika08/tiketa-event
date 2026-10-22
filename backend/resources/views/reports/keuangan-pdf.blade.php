<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Keuangan — {{ $namaOrganizer ?: 'Nontix' }}</title>
    <style>
        /* DomPDF hanya mendukung CSS 2.1 — tanpa flexbox/grid, tabel untuk layout. */
        @page { margin: 26px 30px 64px 30px; }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            color: #1e293b;
            margin: 0;
        }

        .kop { width: 100%; border-bottom: 2px solid #6d28d9; padding-bottom: 8px; }
        .kop td { vertical-align: bottom; }
        .merek { font-size: 15px; font-weight: bold; color: #6d28d9; letter-spacing: .4px; }
        .merek-sub { font-size: 8px; color: #64748b; margin-top: 2px; }
        .judul { font-size: 13px; font-weight: bold; color: #0f172a; }
        .periode { font-size: 8px; color: #64748b; margin-top: 3px; }

        .ringkas { width: 100%; margin: 12px 0 14px 0; border-collapse: separate; border-spacing: 6px 0; }
        .ringkas td {
            width: 20%;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-top: 3px solid #6d28d9;
            padding: 7px 8px;
            vertical-align: top;
        }
        .ringkas .label { font-size: 7.5px; color: #64748b; text-transform: uppercase; letter-spacing: .3px; }
        .ringkas .nilai { font-size: 11px; font-weight: bold; margin-top: 3px; color: #0f172a; }

        h3 {
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #475569;
            margin: 14px 0 5px 0;
        }

        table.data { width: 100%; border-collapse: collapse; }
        table.data thead { display: table-header-group; }
        table.data th {
            background: #6d28d9;
            color: #ffffff;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: .3px;
            padding: 5px 5px;
            text-align: left;
            border: 1px solid #5b21b6;
        }
        table.data td {
            padding: 4px 5px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 8px;
            vertical-align: top;
        }
        table.data tbody tr:nth-child(even) td { background: #f8fafc; }
        table.data tr.total td {
            background: #ede9fe !important;
            font-weight: bold;
            border-top: 1.5px solid #6d28d9;
            border-bottom: 1.5px solid #6d28d9;
        }
        .num { text-align: right; white-space: nowrap; }
        .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 7.5px; }
        .kanal { white-space: nowrap; }
        /* Footer hak cipta: digambar DomPDF di setiap halaman lewat script,
           bukan elemen HTML, supaya tidak hanya muncul di halaman terakhir. */
        .footer-cipta { font-size: 7.2px; color: #94a3b8; }
        .masuk { color: #047857; font-weight: bold; }
        .keluar { color: #b91c1c; font-weight: bold; }
        .biaya { color: #b45309; font-weight: bold; }
        .netral { color: #94a3b8; }

        .kosong { padding: 10px; background: #f8fafc; border: 1px dashed #cbd5e1; color: #64748b; font-size: 8px; }

        .catatan {
            margin-top: 14px;
            padding: 8px 10px;
            background: #f8fafc;
            border-left: 3px solid #6d28d9;
            font-size: 7.5px;
            color: #475569;
            line-height: 1.5;
        }
        .catatan b { color: #0f172a; }

        .ttd { margin-top: 22px; width: 100%; }
        .ttd td { width: 50%; font-size: 8px; color: #475569; vertical-align: bottom; }
        .ttd .garis { border-bottom: 1px solid #94a3b8; height: 30px; }
    </style>
</head>
<body>

<table class="kop">
    <tr>
        <td>
            <div class="merek">NONTIX</div>
            <div class="merek-sub">Laporan Keuangan Penyelenggara</div>
        </td>
        <td style="text-align:right;">
            <div class="judul">{{ $namaOrganizer }}</div>
            <div class="periode">Digenerate {{ $dibuatPada }} WIB</div>
        </td>
    </tr>
</table>

<table class="ringkas">
    <tr>
        <td>
            <div class="label">Tiket Terjual</div>
            <div class="nilai">{{ number_format($summary['tiket'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="label">Penjualan Bruto</div>
            <div class="nilai">Rp {{ number_format($summary['kotor'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="label">Biaya Layanan</div>
            <div class="nilai">Rp {{ number_format($summary['biaya'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="label">Pendapatan Netto</div>
            <div class="nilai">Rp {{ number_format($summary['bersih'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="label">Saldo Tersedia</div>
            <div class="nilai">Rp {{ number_format($ringkasan['saldo_tersedia'] ?? 0, 0, ',', '.') }}</div>
        </td>
    </tr>
</table>

@if ($namaOrganizer === '')
    <p class="kosong">Nama penyelenggara belum diisi di menu Profil, jadi kop laporan ini kosong.</p>
@endif

{{-- ============ 1. MUTASI ============ --}}
<h3>Mutasi Keuangan</h3>

@if (count($mutasi) === 0)
    <p class="kosong">Belum ada transaksi lunas pada rentang ini, sehingga tidak ada mutasi yang bisa ditampilkan.</p>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width:11%;">Tanggal</th>
                <th style="width:14%;">Jenis Transaksi</th>
                <th style="width:12%;">Referensi</th>
                <th style="width:28%;">Uraian</th>
                <th style="width:8%;">Tipe</th>
                <th style="width:11%;">Kanal</th>
                <th style="width:8%;" class="num">Nominal</th>
                <th style="width:8%;" class="num">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($mutasi as $m)
                <tr>
                    <td class="mono">{{ $m['tanggal'] }}</td>
                    <td>{{ $m['jenis'] }}</td>
                    <td class="mono">{{ $m['referensi'] }}</td>
                    <td>{{ $m['uraian'] }}</td>
                    <td class="{{ $m['kelas'] }}">{{ $m['tipe'] }}</td>
                    <td class="kanal">{{ $m['kanal'] }}</td>
                    <td class="num {{ $m['kelas'] }}">Rp {{ $m['nominal'] < 0 ? '-' : '' }}{{ number_format(abs($m['nominal']), 0, ',', '.') }}</td>
                    <td class="num">Rp {{ $m['saldo'] < 0 ? '-' : '' }}{{ number_format(abs($m['saldo']), 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="6">SALDO AKHIR MUTASI</td>
                <td class="num">-</td>
                <td class="num">Rp {{ $saldoAkhir < 0 ? '-' : '' }}{{ number_format(abs($saldoAkhir), 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
@endif

{{-- ============ 2. RINGKASAN PER EVENT ============ --}}
<h3>Ringkasan Per Event</h3>

@if (count($perEvent) === 0)
    <p class="kosong">Belum ada event dengan penjualan lunas.</p>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width:40%;">Event</th>
                <th style="width:12%;" class="num">Tiket</th>
                <th style="width:16%;" class="num">Bruto</th>
                <th style="width:16%;" class="num">Biaya Layanan</th>
                <th style="width:16%;" class="num">Netto</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($perEvent as $e)
                <tr>
                    <td>{{ $e['event'] }}</td>
                    <td class="num">{{ number_format($e['tiket'], 0, ',', '.') }}</td>
                    <td class="num">Rp {{ number_format($e['kotor'], 0, ',', '.') }}</td>
                    <td class="num">Rp {{ number_format($e['biaya'], 0, ',', '.') }}</td>
                    <td class="num">Rp {{ number_format($e['bersih'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>TOTAL</td>
                <td class="num">{{ number_format($summary['tiket'], 0, ',', '.') }}</td>
                <td class="num">Rp {{ number_format($summary['kotor'], 0, ',', '.') }}</td>
                <td class="num">Rp {{ number_format($summary['biaya'], 0, ',', '.') }}</td>
                <td class="num">Rp {{ number_format($summary['bersih'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
@endif

{{-- ============ 3. PENCAIRAN ============ --}}
<h3>Riwayat Pencairan Dana</h3>

@if (count($payout) === 0)
    <p class="kosong">Belum ada pengajuan pencairan dana.</p>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width:10%;">Ref</th>
                <th style="width:14%;">Diajukan</th>
                <th style="width:14%;">Selesai</th>
                <th style="width:24%;">Rekening Tujuan</th>
                <th style="width:12%;" class="num">Jumlah</th>
                <th style="width:12%;">Status</th>
                <th style="width:14%;">Catatan Admin</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payout as $p)
                <tr>
                    <td class="mono">PAYOUT-{{ $p['id'] }}</td>
                    <td class="mono">{{ $p['diajukan'] }}</td>
                    <td class="mono">{{ $p['selesai'] ?: '—' }}</td>
                    <td>{{ $p['bank'] }} {{ $p['norek'] }}<br>a/n {{ $p['atas_nama'] }}</td>
                    <td class="num">Rp {{ number_format($p['jumlah'], 0, ',', '.') }}</td>
                    <td>{{ $p['status'] }}</td>
                    <td>{{ $p['catatan_admin'] ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="data" style="margin-top:6px;">
        <tr class="total">
            <td style="width:70%;">TOTAL SUDAH DICAIRKAN</td>
            <td class="num" style="width:30%;">Rp {{ number_format($ringkasan['sudah_dicairkan'] ?? 0, 0, ',', '.') }}</td>
        </tr>
        <tr class="total">
            <td>SEDANG DIPROSES</td>
            <td class="num">Rp {{ number_format($ringkasan['sedang_diproses'] ?? 0, 0, ',', '.') }}</td>
        </tr>
    </table>
@endif

<div class="catatan">
    <b>Cara membaca laporan ini.</b>
    Laporan hanya memuat pesanan berstatus <b>lunas</b> — pesanan menunggu pembayaran atau dibatalkan tidak dihitung.
    Setiap penjualan muncul sebagai dua baris: uang masuk sebesar harga tiket, lalu biaya layanan platform
    Rp {{ number_format($biayaLayanan, 0, ',', '.') }} per tiket. Saldo yang ditampilkan adalah
    <b>pendapatan netto milik penyelenggara</b> setelah biaya layanan, bukan saldo rekening bank.
    Pencairan berstatus ditolak tidak mengurangi saldo karena dananya tidak pernah keluar.
    Penjualan manual yang dibayar tunai/transfer langsung ke panitia tetap tercatat sebagai pendapatan,
    namun dananya tidak berada di rekening platform.
</div>

<table class="ttd">
    <tr>
        <td>
            <div class="garis"></div>
            <div>Disiapkan oleh, {{ $namaOrganizer ?: 'Penyelenggara' }}</div>
        </td>
        <td style="text-align:right;">
            <div class="garis" style="margin-left:auto; width:60%;"></div>
            <div>Tanggal: {{ $dibuatPada }}</div>
        </td>
    </tr>
</table>

</body>
</html>
