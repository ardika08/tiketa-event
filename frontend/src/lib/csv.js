// Helper unduh CSV di sisi klien.
//
// Sebelumnya tombol "Ekspor" (Keuangan) dan "Unduh Laporan" (Kehadiran) hanya
// menampilkan pesan sukses tanpa menghasilkan file apa pun. Fungsi ini membuat
// file CSV asli dari data yang sudah tampil di halaman, jadi apa yang
// dijanjikan tombolnya benar-benar terjadi.

function escapeCell(value) {
  const s = value === null || value === undefined ? '' : String(value)
  return /[";\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
}

/**
 * @param {string} filename nama file, mis. "laporan-keuangan-20261007.csv"
 * @param {Array<Array<string|number>>} rows baris-baris CSV (baris kosong = [])
 */
export function downloadCsv(filename, rows) {
  // Titik-koma sebagai pemisah + BOM UTF-8 supaya Excel versi Indonesia
  // langsung memisahkan kolom dengan benar dan tidak merusak karakter aksen.
  const isi = rows.map((row) => row.map(escapeCell).join(';')).join('\r\n')
  const blob = new Blob([`\uFEFF${isi}`], { type: 'text/csv;charset=utf-8;' })
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
