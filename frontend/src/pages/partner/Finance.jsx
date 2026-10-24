import { useMemo, useState } from 'react'
import { Wallet, TrendingDown, PiggyBank, Download, Info, Landmark, Plus } from 'lucide-react'
import { Button, Card, PageHeader, Stat, Modal, Badge, Field, Input, Textarea, EmptyState, StatusBadge } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah, formatTanggal } from '../../lib/utils'
import { BIAYA_LAYANAN } from '../../lib/constants'
import { downloadCsv, namaFileLaporan } from '../../lib/csv'

export default function Finance() {
  const [exportOpen, setExportOpen] = useState(false)
  const [done, setDone] = useState(false)
  const [unduhPdf, setUnduhPdf] = useState(false)
  const [galatUnduh, setGalatUnduh] = useState('')
  const [payoutOpen, setPayoutOpen] = useState(false)
  const [payoutForm, setPayoutForm] = useState({ jumlah: '', catatan: '' })
  const [payoutError, setPayoutError] = useState('')
  const [saving, setSaving] = useState(false)

  const { data, loading, reload } = useApi(() => partnerApi.finance(), [])
  // Nama penyelenggara untuk judul di file ekspor — bukan data keuangan, tapi
  // file laporan tanpa identitas pemilik sulit diarsipkan/diaudit.
  const { data: profilData } = useApi(() => partnerApi.profile(), [])
  const namaOrganizer = profilData?.data?.nama_penyelenggara || profilData?.data?.nama || ''
  const summaryRaw = data?.summary || {}
  const transaksi = data?.transaksi || []
  const payout = data?.payout || {}
  const ringkasan = payout.ringkasan || {}
  const riwayatPayout = payout.data || []

  const summary = {
    tiket: summaryRaw.tiket_terjual || 0,
    kotor: summaryRaw.pendapatan_kotor || 0,
    biaya: summaryRaw.biaya_layanan || 0,
    bersih: summaryRaw.pendapatan_bersih || 0,
    pending: summaryRaw.order_pending || 0,
    nilaiPending: summaryRaw.nilai_pending || 0,
  }

  const perEvent = useMemo(() => {
    const map = new Map()
    transaksi.forEach((t) => {
      const key = t.event || 'Tanpa Event'
      const cur = map.get(key) || { event: key, tiket: 0, kotor: 0, biaya: 0, bersih: 0 }
      cur.tiket += t.jumlah_tiket || 0
      cur.kotor += t.bruto || 0
      cur.biaya += t.biaya_layanan || 0
      cur.bersih += t.netto || 0
      map.set(key, cur)
    })
    return Array.from(map.values())
  }, [transaksi])

  const openPayout = () => {
    setPayoutForm({ jumlah: String(Math.round(ringkasan.saldo_tersedia || 0)), catatan: '' })
    setPayoutError('')
    setPayoutOpen(true)
  }

  const submitPayout = async () => {
    setSaving(true)
    setPayoutError('')
    try {
      await partnerApi.createPayout({
        jumlah: Number(payoutForm.jumlah),
        catatan: payoutForm.catatan || null,
      })
      setPayoutOpen(false)
      reload()
    } catch (err) {
      setPayoutError(err.message)
    } finally {
      setSaving(false)
    }
  }

  const cancelPayout = async (id) => {
    if (!window.confirm('Batalkan pengajuan pencairan ini?')) return
    try {
      await partnerApi.cancelPayout(id)
      reload()
    } catch (err) {
      window.alert(err.message)
    }
  }

  // Ekspor CSV berbentuk MUTASI (satu baris = satu kejadian), bukan tabel lebar.
  //
  // Alasannya: laporan keuangan dibaca sebagai aliran uang — masuk, keluar,
  // sisa. Format tabel lebar memaksa orang menjumlah sendiri kolomnya.
  // PDF dibuat di server (DomPDF): kop, ringkasan, mutasi, riwayat pencairan,
  // dan nomor halaman. Angkanya bersumber dari endpoint yang sama dengan CSV.
  const unduhPdfLaporan = async () => {
    setUnduhPdf(true)
    setGalatUnduh('')
    try {
      // Nama dari server diutamakan. Cadangannya tetap nama yang masuk akal,
      // bukan 'unduhan', supaya berkas tidak pernah tersimpan tanpa keterangan
      // kalau header tidak terbaca (mis. proxy membuang Content-Disposition).
      const { blob, namaFile } = await partnerApi.financePdf()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = namaFile && namaFile !== 'unduhan'
        ? namaFile
        : `Nontix_Laporan-Keuangan_${new Date().toISOString().slice(0, 7)}.pdf`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
      setDone(true)
    } catch (err) {
      setGalatUnduh(err?.message || 'Gagal membuat PDF. Coba lagi.')
    } finally {
      setUnduhPdf(false)
    }
  }

  // Ekspor CSV disusun sebagai BLOK, meniru halaman PDF.
  //
  // Sebelumnya semua blok ditumpuk tanpa kolom yang seragam: baris ringkasan
  // hanya 2 kolom sementara tabel mutasi 8 kolom, sehingga di Excel kolomnya
  // bergeser dan tabel tidak terbaca. Sekarang setiap tabel punya header
  // sendiri dan jumlah kolomnya konsisten di dalam satu blok.
  const unduhLaporan = () => {
    const angka = (n) => Number(n || 0)
    const tgl = (iso) => (iso ? formatTanggal(iso, { withTime: true }) : '')
    const rp = (n) => Math.round(angka(n))
    const kanal = (t) => {
      if (t.sumber === 'manual' || t.jenis === 'penjualan_manual') {
        const m = t.metode_bayar
        if (m === 'tunai') return 'TUNAI'
        if (m === 'transfer') return 'TRANSFER'
        if (m === 'bayar_di_lokasi') return 'BAYAR DI LOKASI'
        return 'MANUAL'
      }
      return 'MAYAR'
    }

    // Urutkan kronologis (lama → baru) supaya saldo berjalan bisa dihitung.
    const kronologis = [...transaksi].sort(
      (a, b) => new Date(a.tanggal || 0) - new Date(b.tanggal || 0),
    )

    const mutasi = []
    let saldo = 0
    let totalBruto = 0
    let totalBiaya = 0
    let totalPayout = 0
    let totalTiket = 0

    for (const t of kronologis) {
      const bruto = angka(t.bruto)
      const biaya = angka(t.biaya_layanan)
      const netto = angka(t.netto)
      const pembeli = t.nama_pembeli || '—'

      // 1) Uang masuk: nilai tiket yang dibayar pembeli.
      saldo += bruto
      totalBruto += bruto
      totalTiket += angka(t.jumlah_tiket)
      mutasi.push([
        tgl(t.tanggal), 'PENJUALAN TIKET', t.kode_order,
        `${pembeli} — ${t.event || 'Tanpa Event'}, ${t.jumlah_tiket} tiket`,
        'MASUK', kanal(t), rp(bruto), rp(saldo),
      ])

      // 2) Biaya layanan: potongan platform per tiket.
      if (biaya > 0) {
        saldo -= biaya
        totalBiaya += biaya
        mutasi.push([
          tgl(t.tanggal), 'BIAYA LAYANAN', t.kode_order,
          `Biaya platform ${t.jumlah_tiket} tiket`,
          'BIAYA', kanal(t), rp(-biaya), rp(saldo),
        ])
      }

      // Jejak selisih netto: memudahkan rekonsiliasi satu pesanan.
      if (Math.round(netto) !== Math.round(bruto - biaya)) {
        mutasi.push([
          tgl(t.tanggal), 'SELISIH NETTO', t.kode_order,
          'Netto tidak sama dengan bruto dikurangi biaya — periksa pesanan ini',
          'CATATAN', kanal(t), 0, rp(saldo),
        ])
      }
    }

    // 3) Pencairan: uang keluar dari saldo platform ke rekening partner.
    const payoutSah = riwayatPayout.filter((p) => p.status !== 'ditolak')
    for (const p of payoutSah) {
      const jumlah = angka(p.jumlah)
      saldo -= jumlah
      totalPayout += jumlah
      mutasi.push([
        tgl(p.diajukan_at || p.selesai_at),
        'PENCAIRAN DANA',
        `PAYOUT-${p.id}`,
        `Transfer ke ${p.nama_bank || '—'} ${p.nomor_rekening || ''} a/n ${p.nama_rekening || '—'}`,
        'KELUAR',
        'TRANSFER BANK',
        rp(-jumlah),
        rp(saldo),
      ])
    }

    // Setiap tabel diberi header sendiri. Baris judul dan keterangan cukup
    // satu sel — di Excel isinya tetap terbaca karena tidak ada kolom yang
    // bergeser di dalam satu blok tabel.
    const baris = []
    const judul = (teks) => baris.push([teks])
    const kosong = () => baris.push([])
    const tabel = (header, isi) => {
      baris.push(header)
      isi.forEach((r) => baris.push(r))
    }

    // ── Kop ──
    judul('LAPORAN KEUANGAN')
    judul(namaOrganizer || 'Nontix')
    judul(`Dibuat ${formatTanggal(new Date().toISOString(), { withTime: true })} WIB`)
    kosong()

    // ── Ringkasan ──
    judul('RINGKASAN')
    tabel(['Keterangan', 'Nilai', 'Satuan'], [
      ['Tiket terjual', totalTiket, 'tiket'],
      ['Penjualan bruto', rp(totalBruto), 'Rp'],
      ['Biaya layanan', rp(-totalBiaya), 'Rp'],
      ['Pencairan dana', rp(-totalPayout), 'Rp'],
      ['Saldo akhir (netto tersedia)', rp(totalBruto - totalBiaya - totalPayout), 'Rp'],
    ])
    kosong()

    // ── Mutasi ──
    judul('MUTASI KEUANGAN')
    tabel(
      ['Tanggal & Waktu', 'Jenis Transaksi', 'Referensi', 'Uraian', 'Tipe Baris', 'Kanal', 'Nominal (Rp)', 'Saldo (Rp)'],
      mutasi,
    )
    kosong()

    // ── Rekap per event ──
    judul('RINGKASAN PER EVENT')
    tabel(['Event', 'Tiket Terjual', 'Penjualan Bruto (Rp)', 'Biaya Layanan (Rp)', 'Netto (Rp)'], [
      ...perEvent.map((e) => [e.event, e.tiket, rp(e.kotor), rp(e.biaya), rp(e.bersih)]),
      ['TOTAL', summary.tiket, rp(summary.kotor), rp(summary.biaya), rp(summary.bersih)],
    ])
    kosong()

    // ── Riwayat pencairan ──
    judul('RIWAYAT PENCAIRAN')
    if (riwayatPayout.length === 0) {
      baris.push(['Belum ada pencairan dana.'])
    } else {
      tabel(['Tanggal Pengajuan', 'Referensi', 'Bank', 'Nomor Rekening', 'Atas Nama', 'Jumlah (Rp)', 'Status'], [
        ...riwayatPayout.map((p) => [
          tgl(p.diajukan_at),
          `PAYOUT-${p.id}`,
          p.nama_bank || '—',
          p.nomor_rekening || '—',
          p.nama_rekening || '—',
          rp(p.jumlah),
          p.status === 'selesai' ? 'SELESAI' : p.status === 'ditolak' ? 'DITOLAK' : 'DIPROSES',
        ]),
        [
          'TOTAL CAIR', '', '', '', '',
          rp(payoutSah.reduce((n, p) => n + angka(p.jumlah), 0)), '',
        ],
      ])
    }
    kosong()

    // ── Catatan ──
    judul('CATATAN')
    judul('Hanya pesanan lunas yang dihitung. Pencairan berstatus DITOLAK tidak mengurangi saldo.')
    judul(`Biaya layanan ${formatRupiah(BIAYA_LAYANAN)} per tiket. Nilai dalam Rupiah.`)

    downloadCsv(namaFileLaporan('Keuangan', namaOrganizer), baris)
    setDone(true)
  }

  return (
    <div>
      <PageHeader
        title="Keuangan"
        description="Laporan pendapatan bersih dan pencairan dana ke rekeningmu."
        action={<Button variant="secondary" onClick={() => setExportOpen(true)}><Download size={16} /> Ekspor</Button>}
      />

      <div className="mb-6 flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        <Info size={16} className="mt-0.5 shrink-0" />
        <p>
          Nontix memotong biaya layanan <span className="font-semibold">{formatRupiah(BIAYA_LAYANAN)}/tiket</span> dari setiap tiket yang terjual.
          Sisanya (<span className="font-semibold">pendapatan bersih</span>) adalah milikmu dan bisa dicairkan ke rekening bank
          yang terdaftar di menu Profil. Hanya pesanan <span className="font-semibold">lunas</span> yang dihitung.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Pendapatan Kotor" value={formatRupiah(summary.kotor)} icon={Wallet} color="blue" />
        <Stat label="Biaya Layanan" value={formatRupiah(summary.biaya)} icon={TrendingDown} color="amber" hint={`${summary.tiket} tiket × ${formatRupiah(BIAYA_LAYANAN)}`} />
        <Stat label="Pemasukan Bersih" value={formatRupiah(summary.bersih)} icon={PiggyBank} color="green" />
        <Stat
          label="Saldo Bisa Dicairkan"
          value={formatRupiah(ringkasan.saldo_tersedia || 0)}
          icon={Landmark}
          color="brand"
          hint={`Min. ${formatRupiah(ringkasan.min_payout || 0)}`}
        />
      </div>

      {summary.pending > 0 && (
        <div className="mt-4 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Badge color="amber">Menunggu Pembayaran</Badge>
          {summary.pending} pesanan senilai {formatRupiah(summary.nilaiPending)} belum dibayar — tidak dihitung sebagai pendapatan.
        </div>
      )}

      <Card className="mt-6">
        <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="font-bold text-slate-900">Pencairan Dana</h2>
            <p className="text-sm text-slate-500">
              Ajukan pencairan saldo bersih, lalu ditransfer manual oleh tim Nontix.
            </p>
          </div>
          <Button onClick={openPayout} disabled={(ringkasan.saldo_tersedia || 0) < (ringkasan.min_payout || 0) || saving}>
            <Plus size={16} /> Ajukan Pencairan
          </Button>
        </div>

        <div className="grid gap-4 p-5 sm:grid-cols-3">
          <div className="rounded-xl bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase text-slate-400">Sedang Diproses</p>
            <p className="mt-1 text-lg font-bold text-slate-800">{formatRupiah(ringkasan.sedang_diproses || 0)}</p>
          </div>
          <div className="rounded-xl bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase text-slate-400">Sudah Dicairkan</p>
            <p className="mt-1 text-lg font-bold text-slate-800">{formatRupiah(ringkasan.sudah_dicairkan || 0)}</p>
          </div>
          <div className="rounded-xl bg-brand-50 p-4">
            <p className="text-xs font-semibold uppercase text-brand-600">Saldo Tersedia</p>
            <p className="mt-1 text-lg font-bold text-brand-700">{formatRupiah(ringkasan.saldo_tersedia || 0)}</p>
          </div>
        </div>

        {riwayatPayout.length === 0 ? (
          <EmptyState icon={Landmark} title="Belum ada pencairan" description="Riwayat pengajuan pencairan akan tampil di sini." />
        ) : (
          <div className="overflow-x-auto border-t border-slate-100">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Tanggal</th>
                  <th className="px-5 py-3 font-semibold">Jumlah</th>
                  <th className="px-5 py-3 font-semibold">Rekening</th>
                  <th className="px-5 py-3 font-semibold">Status</th>
                  <th className="px-5 py-3 font-semibold">Catatan</th>
                  <th className="px-5 py-3 font-semibold"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {riwayatPayout.map((p) => (
                  <tr key={p.id} className="hover:bg-slate-50">
                    <td className="px-5 py-3 text-slate-600">{formatTanggal(p.diajukan_at, { withTime: true })}</td>
                    <td className="px-5 py-3 font-semibold text-slate-800">{formatRupiah(p.jumlah)}</td>
                    <td className="px-5 py-3 text-slate-600">
                      {p.nama_bank} · {p.nomor_rekening}
                      <p className="text-xs text-slate-400">{p.nama_rekening}</p>
                    </td>
                    <td className="px-5 py-3">
                      <StatusBadge status={p.status} />
                      {p.bukti_transfer_url && (
                        <a href={p.bukti_transfer_url} target="_blank" rel="noreferrer" className="mt-1 block text-xs font-medium text-brand-600 hover:underline">
                          Lihat bukti transfer
                        </a>
                      )}
                    </td>
                    <td className="px-5 py-3 text-xs text-slate-500">{p.catatan_admin || p.catatan || '—'}</td>
                    <td className="px-5 py-3 text-right">
                      {p.status === 'diajukan' && (
                        <Button variant="ghost" size="sm" className="text-rose-600" onClick={() => cancelPayout(p.id)}>
                          Batalkan
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card className="mt-6">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <h2 className="font-bold text-slate-900">Rincian per Event</h2>
          <Badge color="brand">Hanya pesanan lunas</Badge>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-5 py-3 font-semibold">Event</th>
                <th className="px-5 py-3 font-semibold">Tiket Lunas</th>
                <th className="px-5 py-3 font-semibold">Pendapatan Kotor</th>
                <th className="px-5 py-3 font-semibold">Biaya Layanan</th>
                <th className="px-5 py-3 font-semibold">Bersih</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                <tr><td colSpan={5} className="px-5 py-8"><div className="h-24 animate-pulse rounded-xl bg-slate-100" /></td></tr>
              ) : perEvent.length === 0 ? (
                <tr><td colSpan={5} className="px-5 py-8 text-center text-sm text-slate-400">Belum ada transaksi lunas.</td></tr>
              ) : (
                perEvent.map((r) => (
                  <tr key={r.event} className="hover:bg-slate-50">
                    <td className="px-5 py-3 font-medium text-slate-800">{r.event}</td>
                    <td className="px-5 py-3 text-slate-600">{r.tiket.toLocaleString('id-ID')}</td>
                    <td className="px-5 py-3 text-slate-600">{formatRupiah(r.kotor)}</td>
                    <td className="px-5 py-3 text-amber-600">-{formatRupiah(r.biaya)}</td>
                    <td className="px-5 py-3 font-semibold text-emerald-600">{formatRupiah(r.bersih)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Card>

      <Card className="mt-6">
        <div className="border-b border-slate-100 px-5 py-4">
          <h2 className="font-bold text-slate-900">Riwayat Transaksi</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-5 py-3 font-semibold">Kode Order</th>
                <th className="px-5 py-3 font-semibold">Event</th>
                <th className="px-5 py-3 font-semibold">Tanggal Lunas</th>
                <th className="px-5 py-3 font-semibold">Tiket</th>
                <th className="px-5 py-3 font-semibold">Bruto</th>
                <th className="px-5 py-3 font-semibold">Netto</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {transaksi.slice(0, 20).map((t) => (
                <tr key={t.kode_order} className="hover:bg-slate-50">
                  <td className="px-5 py-3 font-mono text-xs text-slate-600">{t.kode_order}</td>
                  <td className="px-5 py-3 text-slate-600">{t.event}</td>
                  <td className="px-5 py-3 text-slate-500">{formatTanggal(t.tanggal, { withTime: true })}</td>
                  <td className="px-5 py-3 text-slate-600">{t.jumlah_tiket}</td>
                  <td className="px-5 py-3 text-slate-600">{formatRupiah(t.bruto)}</td>
                  <td className="px-5 py-3 font-semibold text-slate-800">{formatRupiah(t.netto)}</td>
                </tr>
              ))}
              {transaksi.length === 0 && (
                <tr><td colSpan={6} className="px-5 py-8 text-center text-sm text-slate-400">Belum ada transaksi.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </Card>

      <Modal
        open={payoutOpen}
        onClose={() => setPayoutOpen(false)}
        title="Ajukan Pencairan"
        footer={
          <>
            <Button variant="secondary" onClick={() => setPayoutOpen(false)} disabled={saving}>Batal</Button>
            <Button onClick={submitPayout} disabled={saving}>{saving ? 'Mengirim...' : 'Ajukan'}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="rounded-xl bg-slate-50 p-4 text-sm">
            <div className="flex justify-between">
              <span className="text-slate-500">Saldo tersedia</span>
              <span className="font-bold text-brand-700">{formatRupiah(ringkasan.saldo_tersedia || 0)}</span>
            </div>
            <div className="mt-1 flex justify-between">
              <span className="text-slate-500">Minimal pencairan</span>
              <span className="font-medium text-slate-700">{formatRupiah(ringkasan.min_payout || 0)}</span>
            </div>
          </div>

          <Field label="Jumlah Pencairan (Rp)" required>
            <Input type="number" value={payoutForm.jumlah} onChange={(e) => setPayoutForm({ ...payoutForm, jumlah: e.target.value })} />
          </Field>

          <Field label="Catatan (opsional)">
            <Textarea value={payoutForm.catatan} onChange={(e) => setPayoutForm({ ...payoutForm, catatan: e.target.value })} placeholder="Catatan untuk admin..." className="min-h-[80px]" />
          </Field>

          <p className="flex items-start gap-2 text-xs text-slate-500">
            <Landmark size={14} className="mt-0.5 shrink-0" />
            Dana akan ditransfer ke rekening yang tersimpan di menu Profil. Pastikan data rekening sudah benar.
          </p>

          {payoutError && <p className="text-sm font-medium text-rose-600">{payoutError}</p>}
        </div>
      </Modal>

      <Modal
        open={exportOpen}
        onClose={() => { setExportOpen(false); setDone(false); setGalatUnduh("") }}
        title="Ekspor Laporan Keuangan"
        footer={
          done ? <Button onClick={() => { setExportOpen(false); setDone(false) }}>Selesai</Button> : (
            <>
              <Button variant="secondary" onClick={() => setExportOpen(false)}>Batal</Button>
              <Button variant="secondary" onClick={unduhLaporan}>Unduh CSV</Button>
              <Button onClick={unduhPdfLaporan} disabled={unduhPdf}>
                {unduhPdf ? 'Menyiapkan PDF…' : 'Unduh PDF'}
              </Button>
            </>
          )
        }
      >
        {done ? (
          <div className="py-6 text-center">
            <p className="text-2xl">✅</p>
            <p className="mt-2 font-semibold text-slate-800">File berhasil diunduh</p>
            <p className="text-sm text-slate-500">Cek folder Unduhan di perangkatmu.</p>
          </div>
        ) : (
          <div className="space-y-3">
            <p className="text-sm text-slate-600">
              Kedua format berisi data yang sama: <span className="font-semibold">mutasi keuangan</span> dengan
              saldo berjalan, dari {transaksi.length} penjualan lunas dan {riwayatPayout.length} pencairan.
            </p>
            <div className="grid gap-2 sm:grid-cols-2">
              <div className="rounded-xl border border-slate-200 px-3 py-2">
                <p className="text-sm font-semibold text-slate-800">CSV</p>
                <p className="text-xs text-slate-500">Untuk Excel atau Google Sheets. Bisa diolah dan dijumlah ulang sendiri.</p>
              </div>
              <div className="rounded-xl border border-slate-200 px-3 py-2">
                <p className="text-sm font-semibold text-slate-800">PDF</p>
                <p className="text-xs text-slate-500">Siap cetak atau dikirim: berkop, ringkasan, dan nomor halaman.</p>
              </div>
            </div>
            <ul className="space-y-1 rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500">
              <li>• <span className="font-semibold">Blok terpisah</span> — kop, ringkasan, mutasi, rekap per event, dan riwayat pencairan. Tiap tabel punya baris judul kolom sendiri, jadi tidak perlu dirapikan manual di Excel.</li>
              <li>• <span className="font-semibold">Mutasi</span> — tanggal, jenis transaksi, referensi, uraian, tipe baris, kanal, nominal, dan saldo berjalan. Tiap penjualan jadi dua baris: uang masuk dan biaya layanan.</li>
              <li>• <span className="font-semibold">Ringkasan</span> — total tiket, penjualan bruto, biaya layanan, pencairan, dan saldo akhir.</li>
              <li>• <span className="font-semibold">Riwayat pencairan</span> — semua pengajuan beserta statusnya; pencairan yang ditolak tidak mengurangi saldo.</li>
              <li>• Nomor disimpan sebagai angka, bukan teks berformat, supaya bisa langsung dijumlah di Excel.</li>
            </ul>
            {galatUnduh && <p className="text-sm font-medium text-rose-600">{galatUnduh}</p>}
          </div>
        )}
      </Modal>
    </div>
  )
}