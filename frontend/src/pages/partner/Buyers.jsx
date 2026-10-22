import { Fragment, useMemo, useState } from 'react'
import { Search, Download, Send, Loader2, CheckCircle2, AlertCircle, Users, ChevronLeft, ChevronRight, ChevronDown, Mail, History, Plus } from 'lucide-react'
import { Button, Card, Input, PageHeader, Select, StatusBadge, EmptyState, Modal, Field } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah, formatTanggal } from '../../lib/utils'
import { downloadCsv, namaFileCsv } from '../../lib/csv'

const PER_PAGE = 6

// Label & warna untuk riwayat pengiriman email (email_logs).
const LABEL_JENIS_EMAIL = {
  invoice: 'Invoice otomatis',
  resend: 'Kirim ulang',
  manual_alternative: 'Manual ke email lain',
  manual_order: 'Pembelian manual',
}

const WARNA_STATUS_EMAIL = {
  terkirim: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  proses: 'bg-amber-50 text-amber-700 ring-amber-200',
  gagal: 'bg-rose-50 text-rose-700 ring-rose-200',
}

const METODE_MANUAL = [
  { value: 'tunai', label: 'Tunai' },
  { value: 'transfer', label: 'Transfer' },
  { value: 'bayar_di_lokasi', label: 'Bayar di lokasi (OTS)' },
]

const LABEL_METODE = Object.fromEntries(METODE_MANUAL.map((m) => [m.value, m.label]))

const FORM_MANUAL_KOSONG = {
  event_id: '',
  ticket_type_id: '',
  jumlah: 1,
  nama: '',
  email: '',
  whatsapp: '',
  instagram: '',
  metode_bayar: 'tunai',
  catatan: '',
}

/** Panel jawaban formulir custom untuk satu order (mendukung format baru & lama). */
function FormDataPanel({ order }) {
  const fd = order?.form_data
  if (!fd || typeof fd !== 'object') {
    return <p className="text-sm text-slate-400">Tidak ada jawaban formulir untuk pesanan ini.</p>
  }

  const sections = []
  if (fd.order && typeof fd.order === 'object' && Object.keys(fd.order).length) {
    sections.push({ title: 'Data pembeli', entries: Object.entries(fd.order) })
  }
  if (fd.tickets && typeof fd.tickets === 'object') {
    for (const [tid, answers] of Object.entries(fd.tickets)) {
      if (answers && typeof answers === 'object' && Object.keys(answers).length) {
        sections.push({ title: order.ticket_names?.[tid] || `Jenis tiket #${tid}`, entries: Object.entries(answers) })
      }
    }
  }
  // Format lama (sebelum restrukturisasi): kunci flat di level atas.
  const reserved = ['order', 'tickets']
  const legacy = Object.entries(fd).filter(
    ([k, v]) => !reserved.includes(k) && v !== null && typeof v !== 'object' && String(v).trim() !== '',
  )
  if (legacy.length) sections.push({ title: 'Lainnya', entries: legacy })

  if (!sections.length) {
    return <p className="text-sm text-slate-400">Tidak ada jawaban formulir untuk pesanan ini.</p>
  }

  return (
    <div className="space-y-3">
      {sections.map((s) => (
        <div key={s.title}>
          <p className="text-xs font-bold uppercase tracking-wide text-slate-400">{s.title}</p>
          <dl className="mt-1.5 space-y-1">
            {s.entries.map(([label, value]) => (
              <div key={label} className="flex gap-2 text-sm">
                <dt className="w-48 shrink-0 text-slate-500">{label}</dt>
                <dd className="min-w-0 font-medium text-slate-700">{String(value) || '—'}</dd>
              </div>
            ))}
          </dl>
        </div>
      ))}
    </div>
  )
}

export default function Buyers() {
  const [q, setQ] = useState('')
  const [eventName, setEventName] = useState('all')
  const [status, setStatus] = useState('all')
  const [expanded, setExpanded] = useState(null)
  const [page, setPage] = useState(1)
  const [sending, setSending] = useState(null)
  const [notif, setNotif] = useState(null)

  // Kirim manual ke alamat lain (kasus email pembeli tidak sampai).
  const [emailModal, setEmailModal] = useState(null)
  const [emailTujuan, setEmailTujuan] = useState('')
  const [emailKonfirmasi, setEmailKonfirmasi] = useState(false)
  const [emailError, setEmailError] = useState('')
  const [emailSending, setEmailSending] = useState(false)

  // Riwayat pengiriman email satu pesanan.
  const [riwayatModal, setRiwayatModal] = useState(null)
  const [riwayat, setRiwayat] = useState(null)
  const [riwayatLoading, setRiwayatLoading] = useState(false)

  // Penjualan manual (OTS/tunai/transfer).
  const [manualOpen, setManualOpen] = useState(false)
  const [manualForm, setManualForm] = useState(FORM_MANUAL_KOSONG)
  const [manualError, setManualError] = useState('')
  const [manualSaving, setManualSaving] = useState(false)
  const [manualHasil, setManualHasil] = useState(null)

  const { data, loading, reload } = useApi(() => partnerApi.buyers(), [])
  const { data: eventsData } = useApi(() => partnerApi.events(), [])
  const buyers = data?.data || []
  const events = eventsData?.data || []

  const filtered = useMemo(() => {
    return buyers.filter((b) => {
      const matchQ = b.nama.toLowerCase().includes(q.toLowerCase()) || b.email.toLowerCase().includes(q.toLowerCase())
      const matchE = eventName === 'all' || b.event === eventName
      // "Manual" adalah opsi di dropdown status: menampilkan semua pesanan yang
      // diinput partner (OTS/tunai/transfer), apa pun status bayarnya.
      // Order lama (sebelum kolom sumber ada) dianggap online — itu jalur aslinya.
      const matchS = status === 'all'
        ? true
        : status === 'manual'
          ? (b.sumber || 'online') === 'manual'
          : b.status === status
      return matchQ && matchE && matchS
    })
  }, [buyers, q, eventName, status])

  const jumlahManual = useMemo(
    () => buyers.filter((b) => (b.sumber || 'online') === 'manual').length,
    [buyers],
  )

  const jumlahLunas = useMemo(() => buyers.filter((b) => b.status === 'lunas').length, [buyers])

  const totalPages = Math.max(1, Math.ceil(filtered.length / PER_PAGE))
  const current = Math.min(page, totalPages)
  const rows = filtered.slice((current - 1) * PER_PAGE, current * PER_PAGE)

  // Kirim ulang e-ticket ke alamat pembeli yang terdaftar. Backend yang
  // menentukan tujuan pengiriman, jadi alamat tidak bisa dipalsukan dari sini.
  async function kirimUlang(order) {
    setSending(order.id)
    setNotif(null)
    try {
      const res = await partnerApi.resendTicket(order.id)
      setNotif({ ok: true, text: res?.message || `E-ticket dikirim ulang ke ${order.email}.` })
    } catch (e) {
      setNotif({ ok: false, text: e?.message || 'Gagal mengirim ulang e-ticket.' })
    } finally {
      setSending(null)
    }
  }

  function bukaEmailLain(order) {
    setEmailModal(order)
    setEmailTujuan('')
    setEmailKonfirmasi(false)
    setEmailError('')
  }

  async function kirimEmailLain(e) {
    e?.preventDefault()
    if (!emailModal) return
    if (!emailTujuan.trim()) {
      setEmailError('Email tujuan wajib diisi.')
      return
    }
    if (!emailKonfirmasi) {
      setEmailError('Centang dulu bahwa email ini sudah diverifikasi dengan pembeli.')
      return
    }

    setEmailSending(true)
    setEmailError('')
    try {
      const res = await partnerApi.kirimEmailAlternatif(emailModal.id, {
        email: emailTujuan.trim(),
        konfirmasi: true,
      })
      setEmailModal(null)
      setNotif({ ok: true, text: res?.message || 'E-ticket dikirim ke email alternatif.' })
    } catch (err) {
      const pesanField = err.errors ? Object.values(err.errors)[0]?.[0] : null
      setEmailError(pesanField || err.message || 'Gagal mengirim e-ticket.')
    } finally {
      setEmailSending(false)
    }
  }

  async function bukaRiwayat(order) {
    setRiwayatModal(order)
    setRiwayat(null)
    setRiwayatLoading(true)
    try {
      const res = await partnerApi.riwayatEmail(order.id)
      setRiwayat(res)
    } catch (err) {
      setRiwayat({ data: [], error: err.message })
    } finally {
      setRiwayatLoading(false)
    }
  }

  // ---- Penjualan manual (OTS / tunai / transfer) ----
  const eventManual = events.find((e) => String(e.id) === String(manualForm.event_id)) || null
  const kategoriManual = eventManual?.tiket || []
  const kategoriTerpilih = kategoriManual.find((t) => String(t.id) === String(manualForm.ticket_type_id)) || null
  const totalManual = kategoriTerpilih ? Number(kategoriTerpilih.harga) * Number(manualForm.jumlah || 0) : 0

  function bukaManual() {
    setManualForm(FORM_MANUAL_KOSONG)
    setManualError('')
    setManualHasil(null)
    setManualOpen(true)
  }

  function ubahManual(patch) {
    setManualForm((f) => ({ ...f, ...patch }))
  }

  async function simpanManual(e) {
    e?.preventDefault()
    if (!manualForm.event_id) return setManualError('Pilih event dulu.')
    if (!manualForm.ticket_type_id) return setManualError('Pilih kategori tiket.')
    if (!manualForm.nama.trim()) return setManualError('Nama pembeli wajib diisi.')
    if (!manualForm.email.trim()) return setManualError('Email wajib diisi — e-ticket dan QR dikirim ke sini.')
    if (!manualForm.whatsapp.trim()) return setManualError('Nomor WhatsApp wajib diisi.')

    setManualSaving(true)
    setManualError('')
    try {
      const res = await partnerApi.buatOrderManual({
        event_id: Number(manualForm.event_id),
        nama: manualForm.nama.trim(),
        email: manualForm.email.trim(),
        whatsapp: manualForm.whatsapp.trim(),
        instagram: manualForm.instagram.trim() || null,
        metode_bayar: manualForm.metode_bayar,
        catatan: manualForm.catatan.trim() || null,
        items: [{
          ticket_type_id: Number(manualForm.ticket_type_id),
          jumlah: Number(manualForm.jumlah),
        }],
      })
      setManualHasil(res?.data || null)
      setNotif({ ok: true, text: res?.message || 'Tiket manual dibuat.' })
      reload()
    } catch (err) {
      const pesanField = err.errors ? Object.values(err.errors)[0]?.[0] : null
      setManualError(pesanField || err.message || 'Gagal membuat tiket manual.')
    } finally {
      setManualSaving(false)
    }
  }

  // Ekspor CSV dari baris yang sedang tampil (ikut filter & pencarian),
  // supaya isi berkasnya sama dengan yang dilihat partner di layar.
  function ekspor() {
    const header = ['Kode Pesanan', 'Nama Pembeli', 'Email', 'WhatsApp', 'Instagram', 'TikTok', 'Threads', 'Event', 'Tiket', 'Jumlah', 'Total', 'Status', 'Sumber', 'Metode Bayar', 'Diinput Oleh', 'Tanggal']
    const body = filtered.map((b) => [
      b.kode_order, b.nama, b.email, b.whatsapp, b.instagram, b.tiktok, b.threads,
      b.event, b.tiket, b.jumlah, b.total, b.status,
      b.sumber === 'manual' ? 'Manual' : 'Online',
      LABEL_METODE[b.metode_bayar] || '', b.dibuat_oleh || '',
      formatTanggal(b.tanggal, { withTime: true }),
    ])
    downloadCsv(namaFileCsv('data-pembeli'), [header, ...body])
  }

  return (
    <div>
      <PageHeader
        title="Data Pembeli"
        description={`Semua pesanan tiket beserta kontaknya. ${jumlahLunas} dari ${buyers.length} sudah lunas${jumlahManual > 0 ? `, ${jumlahManual} di antaranya penjualan manual` : ''}. Klik baris untuk melihat jawaban formulir.`}
        action={
          <>
            <Button onClick={bukaManual}>
              <Plus size={16} /> Jual Tiket Manual
            </Button>
            <Button variant="secondary" onClick={ekspor} disabled={filtered.length === 0}>
              <Download size={16} /> Ekspor
            </Button>
          </>
        }
      />

      {notif && (
        <div className={`mb-4 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ${notif.ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
          {notif.ok
            ? <CheckCircle2 size={17} className="mt-0.5 shrink-0" />
            : <AlertCircle size={17} className="mt-0.5 shrink-0" />}
          <span>{notif.text}</span>
        </div>
      )}

      <Card className="mb-4 p-4">
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="relative flex-1">
            <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <Input value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} placeholder="Cari nama atau email..." className="pl-10" />
          </div>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} className="sm:w-48">
            <option value="all">Semua Status</option>
            <option value="lunas">Lunas</option>
            <option value="manual">Manual</option>
            <option value="pending">Menunggu Pembayaran</option>
            <option value="dibatalkan">Dibatalkan</option>
            <option value="kadaluarsa">Kadaluarsa</option>
          </Select>
          <Select value={eventName} onChange={(e) => { setEventName(e.target.value); setPage(1) }} className="sm:w-64">
            <option value="all">Semua Event</option>
            {events.map((e) => <option key={e.id} value={e.nama_event}>{e.nama_event}</option>)}
          </Select>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="m-4 h-56 animate-pulse rounded-xl bg-slate-100" />
        ) : rows.length === 0 ? (
          <EmptyState
            icon={Users}
            title="Tidak ada pembeli"
            description={status === 'manual'
              ? 'Belum ada penjualan manual. Pakai tombol "Jual Tiket Manual" untuk mencatat penjualan di luar website.'
              : 'Coba ubah kata kunci atau filter event.'}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Pembeli</th>
                  <th className="px-5 py-3 font-semibold">Kontak</th>
                  <th className="px-5 py-3 font-semibold">Tiket</th>
                  <th className="px-5 py-3 font-semibold">Total</th>
                  <th className="px-5 py-3 font-semibold">Status</th>
                  <th className="px-5 py-3 font-semibold">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((b) => (
                  <Fragment key={b.id}>
                    <tr
                      className={`cursor-pointer hover:bg-slate-50 ${expanded === b.id ? 'bg-slate-50/60' : ''}`}
                      onClick={() => setExpanded((cur) => (cur === b.id ? null : b.id))}
                    >
                      <td className="px-5 py-3">
                        <p className="font-semibold text-slate-800">{b.nama}</p>
                        <p className="font-mono text-xs text-slate-400">{b.kode_order}</p>
                      </td>
                      <td className="px-5 py-3">
                        <p className="text-slate-600">{b.email}</p>
                        <p className="text-xs text-slate-400">{b.whatsapp}</p>
                      </td>
                      <td className="px-5 py-3">
                        <p className="text-slate-700">{b.tiket}</p>
                        <p className="text-xs text-slate-400">{b.jumlah} tiket · {b.event}</p>
                      </td>
                      <td className="px-5 py-3 font-semibold text-slate-800">{formatRupiah(b.total)}</td>
                      <td className="px-5 py-3">
                        <div className="flex items-center gap-1.5">
                          <StatusBadge status={b.status} />
                          {b.sumber === 'manual' && (
                            <span
                              className="ml-1 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200"
                              title={`Penjualan manual${b.dibuat_oleh ? ` · diinput ${b.dibuat_oleh}` : ''}`}
                            >
                              Manual
                            </span>
                          )}
                          <ChevronDown
                            size={15}
                            className={`shrink-0 text-slate-400 transition-transform ${expanded === b.id ? 'rotate-180' : ''}`}
                          />
                        </div>
                        <p className="mt-1 text-xs text-slate-400">{formatTanggal(b.tanggal)}</p>
                        {b.status === 'pending' && b.batas_bayar && (
                          <p className="mt-0.5 text-xs text-amber-600">Bayar s/d {formatTanggal(b.batas_bayar, { withTime: true })}</p>
                        )}
                      </td>
                      <td className="px-5 py-3">
                        {b.status === 'lunas' ? (
                          <div className="flex flex-col items-start gap-1">
                            <Button
                              variant="ghost"
                              size="sm"
                              disabled={sending === b.id}
                              onClick={(e) => { e.stopPropagation(); kirimUlang(b) }}
                            >
                              {sending === b.id
                                ? <Loader2 size={15} className="animate-spin" />
                                : <Send size={15} />}
                              Kirim ulang
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={(e) => { e.stopPropagation(); bukaEmailLain(b) }}
                            >
                              <Mail size={15} className="text-amber-600" /> Email lain
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={(e) => { e.stopPropagation(); bukaRiwayat(b) }}
                            >
                              <History size={15} /> Riwayat
                            </Button>
                          </div>
                        ) : (
                          <span className="text-xs text-slate-300">—</span>
                        )}
                      </td>
                    </tr>
                    {expanded === b.id && (
                      <tr>
                        <td colSpan={6} className="bg-brand-50/40 px-5 py-4">
                          <FormDataPanel order={b} />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {filtered.length > 0 && (
          <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3.5">
            <p className="text-sm text-slate-500">
              Menampilkan <span className="font-medium text-slate-700">{rows.length}</span> dari {filtered.length} pembeli
            </p>
            <div className="flex items-center gap-1">
              <Button variant="ghost" size="sm" disabled={current <= 1} onClick={() => setPage(current - 1)}>
                <ChevronLeft size={16} />
              </Button>
              <span className="px-2 text-sm font-medium text-slate-600">{current} / {totalPages}</span>
              <Button variant="ghost" size="sm" disabled={current >= totalPages} onClick={() => setPage(current + 1)}>
                <ChevronRight size={16} />
              </Button>
            </div>
          </div>
        )}
      </Card>

      {/* Kirim manual ke alamat lain — HANYA untuk kasus email pembeli tidak
          sampai. Email pesanan tidak diubah, dan setiap pemakaian tercatat. */}
      <Modal
        open={!!emailModal}
        onClose={() => setEmailModal(null)}
        title="Kirim e-ticket ke email lain"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEmailModal(null)} disabled={emailSending}>Batal</Button>
            <Button onClick={kirimEmailLain} disabled={emailSending || !emailKonfirmasi}>
              {emailSending ? 'Mengirim…' : 'Kirim'}
            </Button>
          </>
        }
      >
        <form onSubmit={kirimEmailLain} className="space-y-4">
          <div className="rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
            Pesanan <strong>{emailModal?.kode_order}</strong> · {emailModal?.nama}
            <br />
            Email pesanan: <strong>{emailModal?.email}</strong>
          </div>

          {emailError && (
            <div className="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
              <AlertCircle size={16} className="mt-0.5 shrink-0" />
              <span>{emailError}</span>
            </div>
          )}

          <Field
            label="Email tujuan"
            required
            hint="Untuk kasus email pembeli tidak sampai (mis. diblokir penyedia email)."
          >
            <Input
              type="email"
              value={emailTujuan}
              onChange={(e) => setEmailTujuan(e.target.value)}
              placeholder="nama@email.com"
              autoFocus
            />
          </Field>

          <label className="flex items-start gap-2 text-sm text-slate-600">
            <input
              type="checkbox"
              className="mt-1"
              checked={emailKonfirmasi}
              onChange={(e) => setEmailKonfirmasi(e.target.checked)}
            />
            <span>Saya sudah memverifikasi email ini dengan pembeli (via WhatsApp).</span>
          </label>

          <p className="text-xs text-slate-500">
            Email pesanan <strong>tidak diubah</strong> — tiket tetap milik pembeli asli.
            Ini hanya salinan ke alamat lain, dan tercatat di riwayat pengiriman.
          </p>
        </form>
      </Modal>

      {/* Riwayat pengiriman email pesanan: bukti saat pembeli bilang "belum terima". */}
      <Modal
        open={!!riwayatModal}
        onClose={() => setRiwayatModal(null)}
        title={`Riwayat email ${riwayatModal?.kode_order || ''}`}
        size="lg"
        footer={<Button variant="secondary" onClick={() => setRiwayatModal(null)}>Tutup</Button>}
      >
        {riwayatLoading ? (
          <p className="py-6 text-center text-sm text-slate-500">Memuat riwayat…</p>
        ) : (riwayat?.data || []).length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-500">
            {riwayat?.error || 'Belum ada email yang tercatat untuk pesanan ini.'}
          </p>
        ) : (
          <div className="space-y-2">
            <p className="mb-2 text-xs text-slate-500">
              Email pesanan saat ini: <strong>{riwayat?.email_pesanan}</strong>
            </p>
            {riwayat.data.map((e) => (
              <div key={e.id} className="rounded-xl border border-slate-200 p-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-800">{e.tujuan}</p>
                    <p className="text-xs text-slate-500">
                      {LABEL_JENIS_EMAIL[e.jenis] || e.jenis}
                      {' · '}
                      {formatTanggal(e.waktu, { withTime: true })}
                      {e.oleh ? ` · oleh ${e.oleh}` : ''}
                    </p>
                  </div>
                  <span
                    className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ${WARNA_STATUS_EMAIL[e.status] || 'bg-slate-50 text-slate-600 ring-slate-200'}`}
                  >
                    {e.status}
                  </span>
                </div>
                {e.status === 'gagal' && e.error && (
                  <p className="mt-1 text-xs text-rose-600">{e.error}</p>
                )}
                {e.message_id && (
                  <p className="mt-1 truncate font-mono text-[11px] text-slate-400">{e.message_id}</p>
                )}
              </div>
            ))}
            <p className="pt-2 text-xs text-slate-400">
              Status "terkirim" berarti email sudah diterima antrean pengirim, bukan jaminan masuk inbox.
            </p>
          </div>
        )}
      </Modal>

      {/* Penjualan manual: OTS / tunai / transfer. Langsung lunas, QR dibuat,
          e-ticket dikirim. Tidak lewat gateway — jadi tidak ada verifikasi uang. */}
      <Modal
        open={manualOpen}
        onClose={() => setManualOpen(false)}
        title="Jual Tiket Manual"
        size="lg"
        footer={manualHasil ? (
          <>
            <Button
              variant="secondary"
              onClick={() => { setManualForm(FORM_MANUAL_KOSONG); setManualHasil(null) }}
            >
              Input lagi
            </Button>
            <Button onClick={() => setManualOpen(false)}>Selesai</Button>
          </>
        ) : (
          <>
            <Button variant="secondary" onClick={() => setManualOpen(false)} disabled={manualSaving}>Batal</Button>
            <Button onClick={simpanManual} disabled={manualSaving}>
              {manualSaving ? 'Menyimpan…' : 'Simpan & kirim e-ticket'}
            </Button>
          </>
        )}
      >
        {manualHasil ? (
          <div className="space-y-4">
            <div className="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
              <CheckCircle2 size={20} className="mt-0.5 shrink-0 text-emerald-600" />
              <div className="text-sm text-emerald-800">
                <p className="font-semibold">Tiket berhasil dibuat</p>
                <p className="mt-1">
                  <strong>{manualHasil.kode_order}</strong> · {manualHasil.nama} ·{' '}
                  {manualHasil.jumlah_tiket} tiket · {formatRupiah(manualHasil.total_harga)}
                </p>
                <p className="mt-1">E-ticket dikirim ke <strong>{manualHasil.email}</strong>.</p>
              </div>
            </div>
            <p className="text-xs text-slate-500">
              Pesanan ini sudah tampil di tabel pembeli sebagai <strong>lunas</strong> dan
              langsung bisa di-scan di gerbang.
            </p>
          </div>
        ) : (
          <form onSubmit={simpanManual} className="space-y-4">
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
              Tiket langsung aktif dan e-ticket dikirim ke email pembeli.
              Penjualan manual tetap dikenakan biaya layanan platform sesuai tarif per tiket.
            </div>

            {manualError && (
              <div className="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                <AlertCircle size={16} className="mt-0.5 shrink-0" />
                <span>{manualError}</span>
              </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Event" required>
                <Select
                  value={manualForm.event_id}
                  onChange={(e) => ubahManual({ event_id: e.target.value, ticket_type_id: '' })}
                >
                  <option value="">Pilih event</option>
                  {events.map((e) => (
                    <option key={e.id} value={e.id}>{e.nama_event}</option>
                  ))}
                </Select>
              </Field>

              <Field
                label="Kategori tiket"
                required
                hint={kategoriTerpilih
                  ? `Sisa kuota ${kategoriTerpilih.sisa_kuota} · ${formatRupiah(kategoriTerpilih.harga)}/tiket`
                  : undefined}
              >
                <Select
                  value={manualForm.ticket_type_id}
                  onChange={(e) => ubahManual({ ticket_type_id: e.target.value })}
                  disabled={!manualForm.event_id}
                >
                  <option value="">{manualForm.event_id ? 'Pilih kategori' : 'Pilih event dulu'}</option>
                  {kategoriManual.map((t) => (
                    <option key={t.id} value={t.id} disabled={Number(t.sisa_kuota) < 1}>
                      {t.nama_tiket} — {formatRupiah(t.harga)} · sisa {t.sisa_kuota}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <Field label="Jumlah tiket" required>
                <Input
                  type="number"
                  min={1}
                  max={kategoriTerpilih ? Math.min(50, Number(kategoriTerpilih.sisa_kuota)) : 50}
                  value={manualForm.jumlah}
                  onChange={(e) => ubahManual({ jumlah: e.target.value })}
                />
              </Field>
              <Field label="Metode bayar" required>
                <Select
                  value={manualForm.metode_bayar}
                  onChange={(e) => ubahManual({ metode_bayar: e.target.value })}
                >
                  {METODE_MANUAL.map((m) => (
                    <option key={m.value} value={m.value}>{m.label}</option>
                  ))}
                </Select>
              </Field>
              <div className="flex items-end">
                <div className="w-full rounded-xl bg-slate-50 px-3 py-2.5">
                  <p className="text-xs text-slate-500">Total dibayar pembeli</p>
                  <p className="text-base font-semibold text-slate-900">{formatRupiah(totalManual)}</p>
                </div>
              </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Nama pembeli" required>
                <Input
                  value={manualForm.nama}
                  onChange={(e) => ubahManual({ nama: e.target.value })}
                  placeholder="Nama sesuai KTP"
                />
              </Field>
              <Field label="Email" required hint="E-ticket dan QR dikirim ke alamat ini.">
                <Input
                  type="email"
                  value={manualForm.email}
                  onChange={(e) => ubahManual({ email: e.target.value })}
                  placeholder="nama@email.com"
                />
              </Field>
              <Field label="WhatsApp" required>
                <Input
                  value={manualForm.whatsapp}
                  onChange={(e) => ubahManual({ whatsapp: e.target.value })}
                  placeholder="08xxxxxxxxxx"
                />
              </Field>
              <Field label="Instagram" hint="Opsional.">
                <Input
                  value={manualForm.instagram}
                  onChange={(e) => ubahManual({ instagram: e.target.value })}
                  placeholder="@username"
                />
              </Field>
            </div>

            <Field label="Catatan internal" hint="Opsional — mis. nama penjual atau nomor referensi transfer.">
              <textarea
                className="input min-h-[72px]"
                value={manualForm.catatan}
                onChange={(e) => ubahManual({ catatan: e.target.value })}
                placeholder="cth: dijual oleh Bu Rina, transfer BCA 21/10"
              />
            </Field>

            <p className="text-xs text-slate-500">
              Sistem tidak bisa memverifikasi uang masuk di jalur ini. Pastikan pembayaran
              benar-benar diterima sebelum menyimpan — nama penginput ikut tercatat.
            </p>
          </form>
        )}
      </Modal>
    </div>
  )
}
