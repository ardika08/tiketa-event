// Helper unduh CSV di sisi klien.
//
// Sebelumnya tombol "Ekspor" (Keuangan) dan "Unduh Laporan" (Kehadiran) hanya
// menampilkan pesan sukses tanpa menghasilkan file apa pun. Fungsi ini membuat
// file CSV asli dari data yang sudah tampil di halaman, jadi apa yang
// dijanjikan tombolnya benar-benar terjadi.
//
// DELIMITER: TAB, bukan koma atau titik-koma.
//
// Excel Indonesia memakai pemisah list `;`, Excel US/UK dan LibreOffice
// memakai `,`. Memilih salah satunya bikin file rusak di tempat lain — ini
// yang jadi keluhan user "CSV isinya jadi satu kolom". Tab bekerja di SEMUA
// locale: Excel (ID/US/UK/…), LibreOffice, Google Sheets, Numbers membaca
// tab tanpa menampilkan Text Import Wizard. Ekstensi tetap `.csv` supaya
// double-click tetap membuka Excel.

function escapeCell(value) {
  const s = value === null || value === undefined ? '' : String(value)
  // Excel menjalankan teks yang dimulai =, +, -, @ sebagai formula. Pembeli
  // bisa saja punya nama/instagram/catatan yang kebetulan diawali itu — tanpa
  // prefix kutip tunggal, file terbuka dengan peringatan keamanan atau baris
  // rusak. Tab dan newline di dalam sel mengacaukan parsing, jadi diganti.
  const aman = s
    .replace(/^[=+\-@\t\r]/, (m) => `'${m}`)
    .replace(/\t/g, ' ')
    .replace(/[\r\n]+/g, ' ')
  return /["]/.test(aman) ? `"${aman.replace(/"/g, '""')}"` : aman
}

/**
 * @param {string} filename nama file, mis. "laporan-keuangan-20261007.csv"
 * @param {Array<Array<string|number>>} rows baris-baris CSV (baris kosong = [])
 */
export function downloadCsv(filename, rows) {
  // BOM UTF-8 supaya Excel membaca karakter beraksen (é, ñ, dst.) dengan benar.
  const isi = rows.map((row) => row.map(escapeCell).join('\t')).join('\r\n')
  const blob = new Blob([`\uFEFF${isi}`], { type: 'text/plain;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

/** Nama file dengan tanggal-jam supaya unduhan berulang tidak saling menimpa. */
export function namaFileCsv(prefix) {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${prefix}-${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}.csv`
}

/**
 * Nama berkas CSV mengikuti pola PDF dari server:
 * `Nontix_Laporan-Keuangan_<Organizer>_<YYYY-MM>.csv`
 *
 * Tujuannya supaya CSV dan PDF untuk periode yang sama tersimpan sebagai satu
 * keluarga berkas — mudah dicari dan tidak tertukar dengan unduhan lain.
 * Karakter haram di Windows dibuang, sama seperti di sisi server.
 */
export function namaFileLaporan(jenis, organizer, ekstensi = 'csv') {
  const bulan = new Date().toISOString().slice(0, 7)
  const bersih = String(organizer || 'Nontix')
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[\/:*?"<>|]/g, '')
    .trim()
    .replace(/\s+/g, '-')
    .slice(0, 60) || 'Nontix'
  return `Nontix_Laporan-${jenis}_${bersih}_${bulan}.${ekstensi}`
}
