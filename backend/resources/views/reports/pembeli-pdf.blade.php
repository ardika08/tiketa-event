<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Data Pembeli — {{ $namaOrganizer ?: 'Nontix' }}</title>
    <style>
        /* DomPDF hanya mendukung CSS 2.1 — tanpa flexbox/grid, tabel untuk layout.
           Gaya sengaja kembar dengan keuangan-pdf.blade.php supaya kedua laporan
           terlihat satu keluarga. */
        @page { margin: 26px 30px 64px 30px; }

        /* Lebar isi = 595.28 - 60 = 535.28pt. Jangan ada elemen yang melebihi. */

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            color: #1e293b;
            margin: 0;
        }

        .kop { width: 530pt; border-collapse: collapse; table-layout: fixed; padding-bottom: 8px; }
        .kop-garis { border-top: 2px solid #6d28d9; height: 2px; margin-top: 6px; }
        .kop td { vertical-align: bottom; }
        .merek { font-size: 15px; font-weight: bold; color: #6d28d9; letter-spacing: .4px; }
        .merek-sub { font-size: 8px; color: #64748b; margin-top: 2px; }
        .judul { font-size: 13px; font-weight: bold; color: #0f172a; word-wrap: break-word; overflow-wrap: break-word; }
        /* Nama penyelenggara panjang tidak boleh mendorong kop keluar halaman. */
        .kop .kanan { width: 195pt; }
        .kop .kiri { width: 330pt; }
        .periode { font-size: 8px; color: #64748b; margin-top: 3px; }

        .ringkas { width: 530pt; margin: 12px 0 14px 0; border-collapse: separate; border-spacing: 6px 0; }
        .ringkas td {
            width: 25%;
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

        table.data { width: 530pt; border-collapse: collapse; table-layout: fixed; }
        table.data thead { display: table-header-group; }
        table.data th {
            background: #6d28d9;
            color: #ffffff;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: .3px;
            padding: 5px 4px;
            text-align: left;
            border: 1px solid #5b21b6;
        }
        table.data td {
            padding: 4px 4px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 8px;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        table.data tbody tr:nth-child(even) td { background: #f8fafc; }
        table.data tr.total td {
            background: #ede9fe !important;
            font-weight: bold;
            border-top: 1.5px solid #6d28d9;
            border-bottom: 1.5px solid #6d28d9;
        }
        /* tr.ulang dipakai di baris pertama setiap event: memberi batas visual
           supaya pembaca bisa memindai kelompok pesanan per event. */
        table.data tr.ulang td { border-top: 1.5px solid #c4b5fd; }

        .num { text-align: right; white-space: nowrap; }
        /* Nilai rupiah panjang: cegah sel melebar melewati margin. */
        table.data .num { max-width: 110pt; }
        .tengah { text-align: center; white-space: nowrap; }
        .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 7.5px; white-space: nowrap; }
        .kontak { font-size: 7.5px; color: #475569; line-height: 1.35; }
        .nama { font-weight: bold; color: #0f172a; }
        .kanal { white-space: nowrap; }
        .masuk { color: #047857; font-weight: bold; }
        .netral { color: #94a3b8; }

        .kosong { padding: 10px; background: #f8fafc; border: 1px dashed #cbd5e1; color: #64748b; font-size: 8px; }

        .peringatan {
            margin-top: 10px;
            padding: 7px 10px;
            background: #fffbeb;
            border-left: 3px solid #d97706;
            font-size: 7.5px;
            color: #78350f;
            line-height: 1.5;
        }

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

        .ttd { margin-top: 22px; width: 530pt; border-collapse: collapse; }
        .ttd .kanan { width: 240pt; }
        .ttd td { width: 255pt; font-size: 8px; color: #475569; vertical-align: bottom; }
        .ttd .garis { border-bottom: 1px solid #94a3b8; height: 30px; }
    </style>
</head>
<body>

<table class="kop">
    <tr>
        <td class="kiri">
            <div class="merek">NONTIX</div>
            <div class="merek-sub">Laporan Data Pembeli Penyelenggara</div>
        </td>
        <td class="kanan" style="text-align:right;">
            <div class="judul">{{ $namaOrganizer }}</div>
            <div class="periode">Digenerate {{ $dibuatPada }} WIB</div>
        </td>
    </tr>
    <tr><td colspan="2" style="padding:0;"><div class="kop-garis"></div></td></tr>
</table>

<table class="ringkas">
    <tr>
        <td>
            <div class="label">Pesanan Lunas</div>
            <div class="nilai">{{ number_format($ringkasan['pesanan'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="label">Tiket Terjual</div>
            <div class="nilai">{{ number_format($ringkasan['tiket'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="label">Total Nilai</div>
            <div class="nilai">Rp {{ number_format($ringkasan['nilai'], 0, ',', '.') }}</div>
        </td>
        <td>
            <div class="label">Rata-rata / Pesanan</div>
            <div class="nilai">Rp {{ number_format($ringkasan['pesanan'] > 0 ? $ringkasan['nilai'] / $ringkasan['pesanan'] : 0, 0, ',', '.') }}</div>
        </td>
    </tr>
</table>

@if ($namaOrganizer === '')
    <p class="kosong">Nama penyelenggara belum diisi di menu Profil, jadi kop laporan ini kosong.</p>
@endif

{{-- ============ 1. DAFTAR PEMBELI ============ --}}
<h3>Daftar Pembeli</h3>

@if (count($baris) === 0)
    <p class="kosong">Belum ada pesanan lunas. Laporan ini hanya memuat pesanan yang sudah dibayar.</p>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width:50pt;">Tanggal</th>
                <th style="width:60pt;">Kode</th>
                <th style="width:86pt;">Pembeli</th>
                <th style="width:97pt;">Kontak</th>
                <th style="width:76pt;">Tiket</th>
                <th style="width:22pt;" class="tengah">Jml</th>
                <th style="width:50pt;" class="num">Total</th>
                <th style="width:68pt;">Sumber</th>
            </tr>
        </thead>
        <tbody>
            @php $eventSebelumnya = null; @endphp
            @foreach ($baris as $b)
                @php
                    // Baris pertama tiap event diberi garis atas lebih tebal.
                    $gantiEvent = $eventSebelumnya !== null && $eventSebelumnya !== $b['event'];
                    $eventSebelumnya = $b['event'];
                @endphp
                <tr class="{{ $gantiEvent ? 'ulang' : '' }}">
                    <td>{{ $b['tanggal'] }}</td>
                    <td class="mono">{{ $b['kode'] }}</td>
                    <td><span class="nama">{{ $b['nama'] }}</span><br><span class="netral">{{ $b['event'] }}</span></td>
                    <td class="kontak">{!! nl2br(e($b['kontak'])) !!}</td>
                    <td>{{ $b['tiket'] }}</td>
                    <td class="tengah">{{ $b['jumlah'] > 0 ? $b['jumlah'] : '—' }}</td>
                    <td class="num">{{ number_format($b['total'], 0, ',', '.') }}</td>
                    <td class="kanal">{{ $b['sumber'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="5">TOTAL {{ number_format($ringkasan['pesanan'], 0, ',', '.') }} PESANAN</td>
                <td class="tengah">{{ number_format($ringkasan['tiket'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($ringkasan['nilai'], 0, ',', '.') }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
@endif

@if ($terpotong > 0)
    <div class="peringatan">
        <b>Laporan dipotong.</b> PDF ini memuat {{ number_format($maksBaris, 0, ',', '.') }} pesanan terbaru
        dari {{ number_format($ringkasan['pesanan'], 0, ',', '.') }} pesanan lunas.
        {{ number_format($terpotong, 0, ',', '.') }} pesanan lainnya tidak ikut —
        gunakan ekspor CSV untuk daftar lengkap.
    </div>
@endif

{{-- ============ 2. RINGKASAN PER EVENT ============ --}}
<h3>Ringkasan per Event</h3>

@if (count($perEvent) === 0)
    <p class="kosong">Belum ada data untuk diringkas.</p>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width:230pt;">Event</th>
                <th style="width:84pt;" class="tengah">Pesanan</th>
                <th style="width:84pt;" class="tengah">Tiket</th>
                <th style="width:116pt;" class="num">Nilai</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($perEvent as $e)
                <tr>
                    <td>{{ $e['event'] }}</td>
                    <td class="tengah">{{ number_format($e['pesanan'], 0, ',', '.') }}</td>
                    <td class="tengah">{{ number_format($e['tiket'], 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($e['nilai'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>TOTAL</td>
                <td class="tengah">{{ number_format($ringkasan['pesanan'], 0, ',', '.') }}</td>
                <td class="tengah">{{ number_format($ringkasan['tiket'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($ringkasan['nilai'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
@endif

{{-- ============ 3. RINGKASAN PER SUMBER ============ --}}
<h3>Ringkasan per Sumber Penjualan</h3>

<table class="data">
    <thead>
        <tr>
            <th style="width:230pt;">Sumber</th>
            <th style="width:84pt;" class="tengah">Pesanan</th>
            <th style="width:84pt;" class="tengah">Tiket</th>
            <th style="width:116pt;" class="num">Nilai</th>
        </tr>
    </thead>
    <tbody>
        @foreach (['Online', 'Manual'] as $sumber)
            @php $row = $perSumber[$sumber] ?? null; @endphp
            <tr>
                <td>{{ $sumber }}{{ $sumber === 'Online' ? ' — dibayar lewat gateway' : ' — diinput partner' }}</td>
                <td class="tengah">{{ number_format($row['pesanan'] ?? 0, 0, ',', '.') }}</td>
                <td class="tengah">{{ number_format($row['tiket'] ?? 0, 0, ',', '.') }}</td>
                <td class="num">{{ number_format($row['nilai'] ?? 0, 0, ',', '.') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- ============ CATATAN ============ --}}
<div class="catatan">
    <b>Catatan penyusunan laporan</b><br>
    • Laporan ini memuat <b>hanya pesanan lunas</b>. Pesanan batal dan kedaluwarsa tidak dihitung sebagai pembeli.<br>
    • Periode: <b>seluruh data</b> yang tercatat pada akun penyelenggara, bukan satu bulan tertentu.<br>
    @if ($jumlahBelumBayar > 0 || $jumlahBatal > 0)
        • Tidak ikut dalam laporan:
        @if ($jumlahBelumBayar > 0) <b>{{ number_format($jumlahBelumBayar, 0, ',', '.') }} pesanan belum dibayar</b>@endif
        @if ($jumlahBelumBayar > 0 && $jumlahBatal > 0) dan @endif
        @if ($jumlahBatal > 0) <b>{{ number_format($jumlahBatal, 0, ',', '.') }} pesanan batal/kedaluwarsa</b>@endif
        .<br>
    @endif
    • Nilai adalah harga yang dibayar pembeli, <b>termasuk</b> biaya layanan. Angka pendapatan bersih ada di Laporan Keuangan.<br>
    • Data kontak berasal dari isian pembeli saat memesan. Gunakan hanya untuk keperluan event ini.
</div>

<table class="ttd">
    <tr>
        <td>
            <div class="garis"></div>
            <div style="margin-top:4px;">Dibuat oleh,</div>
            <div style="font-weight:bold; color:#0f172a; margin-top:2px;">{{ $namaOrganizer ?: 'Penyelenggara' }}</div>
        </td>
        <td class="kanan" style="text-align:right;">
            <div class="garis"></div>
            <div style="margin-top:4px;">Dicetak {{ $dibuatPada }} WIB</div>
            <div style="margin-top:2px;">Dokumen dihasilkan otomatis<br>oleh Nontix</div>
        </td>
    </tr>
</table>

</body>
</html>
