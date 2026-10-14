import { Suspense, lazy, useCallback, useEffect, useState } from 'react'
import { QrCode, ScanLine, CheckCircle2, XCircle, AlertTriangle, RotateCcw, CalendarDays, Camera, Loader2, Clock, CalendarX, Timer } from 'lucide-react'
import { Button, Card, Input, PageHeader, Badge, Select } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatTanggal, cn } from '../../lib/utils'

// html5-qrcode (±340KB) hanya diunduh saat petugas benar-benar membuka scanner.
const QrScannerModal = lazy(() => import('../../components/QrScannerModal'))

const RESULT_STYLE = {
  valid: { bg: 'from-emerald-500 to-teal-600', icon: CheckCircle2, label: 'Berhasil' },
  used: { bg: 'from-amber-500 to-orange-600', icon: AlertTriangle, label: 'Sudah Dipakai' },
  wrong_session: { bg: 'from-amber-500 to-orange-600', icon: AlertTriangle, label: 'Sesi Tidak Cocok' },
  not_open: { bg: 'from-sky-500 to-blue-600', icon: Clock, label: 'Belum Dibuka' },
  closed: { bg: 'from-amber-500 to-orange-600', icon: CalendarX, label: 'Sudah Ditutup' },
  unpaid: { bg: 'from-amber-500 to-orange-600', icon: AlertTriangle, label: 'Belum Lunas' },
  invalid: { bg: 'from-rose-500 to-red-600', icon: XCircle, label: 'Gagal' },
}

// Status gelombang tiap kategori tiket di panel jadwal.
const WAVE_STYLE = {
  dibuka: { dot: 'bg-emerald-500', teks: 'text-emerald-700', label: 'Sedang dibuka' },
  belum_dibuka: { dot: 'bg-slate-300', teks: 'text-slate-500', label: 'Belum dibuka' },
  sudah_ditutup: { dot: 'bg-rose-400', teks: 'text-rose-600', label: 'Sudah tutup' },
  tanpa_jadwal: { dot: 'bg-slate-300', teks: 'text-slate-400', label: 'Tanpa jam masuk' },
}

const LABEL_SESI = {
  berjalan: 'Sedang berjalan',
  akan_datang: 'Akan datang',
  sudah_lewat: 'Sudah lewat',
  tanpa_jadwal: 'Tanpa jadwal',
}

/** "135" → "2 jam 15 menit" */
function labelMenuju(menit) {
  if (menit === null || menit === undefined) return null
  if (menit <= 0) return 'sebentar lagi'
  const jam = Math.floor(menit / 60)
  const sisa = menit % 60
  if (!jam) return `${sisa} menit`
  if (!sisa) return `${jam} jam`
  return `${jam} jam ${sisa} menit`
}

/** Selisih dua waktu ISO → "2 hari 3 jam". Keduanya waktu absolut, jadi aman lintas zona. */
function labelSelisih(isoTujuan, isoSekarang) {
  if (!isoTujuan || !isoSekarang) return null
  const beda = Math.round((new Date(isoTujuan) - new Date(isoSekarang)) / 60000)
  if (beda <= 0) return null
  const hari = Math.floor(beda / 1440)
  const jam = Math.floor((beda % 1440) / 60)
  const menit = beda % 60
  const bagian = []
  if (hari) bagian.push(`${hari} hari`)
  if (jam) bagian.push(`${jam} jam`)
  if (!hari && menit) bagian.push(`${menit} menit`)
  return bagian.join(' ') || 'sebentar lagi'
}

export default function Scan() {
  const { data: eventsData } = useApi(() => partnerApi.events(), [])
  const events = eventsData?.data || []
  const [eventId, setEventId] = useState(null)

  useEffect(() => {
    if (!eventId && events.length) setEventId(events[0].id)
  }, [events, eventId])

  const { data: sessionsData, reload } = useApi(
    () => (eventId ? partnerApi.scanSessions(eventId) : Promise.resolve({ data: [] })),
    [eventId],
  )

  const sessions = sessionsData?.data || []
  const kategori = sessionsData?.kategori || []
  const sesiAktifId = sessionsData?.sesi_aktif_id ?? null
  const adaJadwalSesi = sessionsData?.ada_jadwal_sesi ?? false
  const sekarangIso = sessionsData?.sekarang

  // Sesi TIDAK dipilih petugas: backend sudah menentukan sesi yang sedang
  // berjalan dari jam server (sesi_aktif_id).
  const activeSession = sessions.find((s) => s.id === sesiAktifId) || null
  const sesiBerjalan = sessions.find((s) => s.status_waktu === 'berjalan') || null
  const sesiBerikutnya =
    sessions
      .filter((s) => s.status_waktu === 'akan_datang')
      .sort((a, b) => new Date(a.tanggal_mulai) - new Date(b.tanggal_mulai))[0] || null

  // Kalau tanggal sesi belum diisi (adaJadwalSesi = false), perilakunya sama
  // seperti sebelumnya: scanner selalu bisa dipakai.
  const bolehScan = Boolean(eventId) && (!adaJadwalSesi || Boolean(sesiBerjalan))

  const hadirSesi = activeSession?.total_hadir ?? 0
  const totalSesi = activeSession?.total_pass ?? 0

  const [code, setCode] = useState('')
  const [result, setResult] = useState(null)
  const [history, setHistory] = useState([])
  const [scanning, setScanning] = useState(false)
  const [cameraOpen, setCameraOpen] = useState(false)

  /**
   * Kirim kode QR ke backend. Sesi tidak dikirim sebagai penentu — backend
   * memakai sesi milik QR itu sendiri; session_id hanya cadangan untuk event
   * yang tanggal sesinya belum diisi.
   */
  const submitScan = useCallback(async (rawCode) => {
    const kode = String(rawCode || '').trim().toUpperCase()
    if (!kode) return null
    if (!eventId) {
      const res = { status: 'invalid', message: 'Pilih event terlebih dahulu.', ticket: null }
      setResult(res)
      return res
    }

    setScanning(true)
    try {
      const res = await partnerApi.scan({
        event_id: eventId,
        ...(sesiAktifId ? { session_id: sesiAktifId } : {}),
        kode_qr: kode,
      })
      setResult(res)
      if (res.status === 'valid') {
        setHistory((h) => [
          { kode, ...res, session_label: res.ticket?.sesi_label || activeSession?.label, waktu: new Date().toISOString() },
          ...h,
        ])
        reload()
      }
      return res
    } catch (err) {
      const res = { status: 'invalid', message: err.message, ticket: null }
      setResult(res)
      return res
    } finally {
      setScanning(false)
    }
  }, [eventId, sesiAktifId, activeSession, reload])

  const handleManualScan = () => {
    const value = code
    setCode('')
    submitScan(value)
  }

  const openCamera = () => {
    if (!bolehScan) return
    setResult(null)
    setCameraOpen(true)
  }

  const reset = () => setResult(null)

  const gayaHasil = RESULT_STYLE[result?.status] || null

  return (
    <div>
      <PageHeader
        title="Scan Tiket"
        description="Pindai QR tiket. Gerbang mengikuti jadwal jam masuk tiap kategori — petugas tidak perlu memilih sesi."
      />

      <Card className="mb-6 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-2">
            <CalendarDays size={18} className="text-brand-600" />
            <div>
              <p className="text-sm font-bold text-slate-900">
                Sesi Aktif: {activeSession ? `${activeSession.label} · ${activeSession.nama_session}` : '—'}
              </p>
              <p className="text-xs text-slate-500">
                Dipilih otomatis dari jam server. Sesi tiap tiket diambil dari QR-nya sendiri.
              </p>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {activeSession?.status_waktu && (
              <Badge color={activeSession.status_waktu === 'berjalan' ? 'green' : 'slate'}>
                {LABEL_SESI[activeSession.status_waktu] || activeSession.status_waktu}
              </Badge>
            )}
            {events.length > 1 && (
              <Select value={eventId || ''} onChange={(e) => setEventId(Number(e.target.value))} className="sm:w-56">
                {events.map((e) => <option key={e.id} value={e.id}>{e.nama_event}</option>)}
              </Select>
            )}
          </div>
        </div>

        {!bolehScan && sesiBerikutnya && (
          <div className="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            <Clock size={16} className="mt-0.5 shrink-0" />
            <p>
              Belum masuk jam sesi. <strong>{sesiBerikutnya.label} · {sesiBerikutnya.nama_session}</strong> dibuka{' '}
              {formatTanggal(sesiBerikutnya.tanggal_mulai, { withTime: true })} WIB
              {labelSelisih(sesiBerikutnya.tanggal_mulai, sekarangIso)
                ? ` (dalam ${labelSelisih(sesiBerikutnya.tanggal_mulai, sekarangIso)})`
                : ''}
              . Scanner terbuka sendiri begitu jamnya tiba.
            </p>
          </div>
        )}

        {kategori.length > 0 && (
          <div className="mt-4 border-t border-slate-100 pt-4">
            <div className="flex flex-wrap items-center gap-2">
              <Timer size={16} className="text-brand-600" />
              <p className="text-sm font-bold text-slate-900">Jadwal Masuk per Kategori</p>
              <span className="text-xs text-slate-400">
                {kategori.length} kategori · 1 gerbang · jam server WIB
              </span>
            </div>
            <div className="mt-3 space-y-2">
              {kategori.map((k) => {
                const gaya = WAVE_STYLE[k.status] || WAVE_STYLE.tanpa_jadwal
                const dibuka = k.status === 'dibuka'
                const jadwal = k.jam_masuk_mulai && k.jam_masuk_selesai
                  ? `${k.jam_masuk_mulai}–${k.jam_masuk_selesai} WIB`
                  : k.jam_masuk_mulai
                    ? `mulai ${k.jam_masuk_mulai} WIB`
                    : k.jam_masuk_selesai
                      ? `sampai ${k.jam_masuk_selesai} WIB`
                      : 'Tanpa batas jam'
                return (
                  <div
                    key={k.id}
                    className={cn(
                      'flex items-center gap-3 rounded-xl border p-3',
                      dibuka ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-white',
                    )}
                  >
                    <span className={cn('h-2.5 w-2.5 shrink-0 rounded-full', gaya.dot)} />
                    <div className="min-w-0 flex-1">
                      <p className={cn('truncate text-sm font-semibold', dibuka ? 'text-emerald-800' : 'text-slate-700')}>
                        {k.nama_tiket}
                      </p>
                      <p className="text-xs text-slate-500">
                        {jadwal}
                        {/* Hitung mundur hanya kalau tinggal <= 24 jam. Di luar
                            hari-H, 15 baris "dibuka dalam 51 hari" cuma bising —
                            banner di atas sudah membawa hitungan besarnya. */}
                        {k.status === 'belum_dibuka' && k.menit_ke_buka !== null && k.menit_ke_buka <= 1440
                          ? ` · dibuka dalam ${labelMenuju(k.menit_ke_buka)}`
                          : ''}
                      </p>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className={cn('text-xs font-bold uppercase tracking-wide', gaya.teks)}>{gaya.label}</p>
                      <p className="text-xs text-slate-400">{k.total_hadir}/{k.total_pass} hadir</p>
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        )}

        {sessions.length === 0 && (
          <p className="mt-4 text-sm text-slate-400">Belum ada sesi untuk event ini.</p>
        )}
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="overflow-hidden">
          <div className={cn('flex flex-col items-center justify-center bg-gradient-to-br p-8 text-white', gayaHasil?.bg || 'from-slate-700 to-slate-900')}>
            {result ? (
              (() => {
                const Icon = gayaHasil?.icon || XCircle
                return (
                  <>
                    <div className="grid h-20 w-20 place-items-center rounded-full bg-white/20">
                      <Icon size={44} />
                    </div>
                    <p className="mt-4 text-xl font-extrabold">{gayaHasil?.label || 'Hasil'}</p>
                    <p className="mt-1 text-center text-white/90">{result.message}</p>
                    {result.ticket && (
                      <div className="mt-4 w-full rounded-xl bg-white/10 p-3 text-center text-sm">
                        <p className="font-mono font-bold">{result.ticket.kode_tiket}</p>
                        <p className="mt-1">{result.ticket.nama_pemegang} · {result.ticket.nama_tiket}</p>
                        {(result.ticket.sesi_label || result.ticket.label_jam) && (
                          <p className="mt-1 text-xs text-white/80">
                            {result.ticket.sesi_label ? `Sesi ${result.ticket.sesi_label}` : ''}
                            {result.ticket.sesi_label && result.ticket.label_jam ? ' · ' : ''}
                            {result.ticket.label_jam ? `Jam masuk ${result.ticket.label_jam} WIB` : ''}
                          </p>
                        )}
                      </div>
                    )}
                    {result.pesan_pengunjung && (
                      <p className="mt-3 rounded-xl bg-white/10 px-3 py-2 text-center text-xs text-white/90">
                        Untuk pengunjung: “{result.pesan_pengunjung}”
                      </p>
                    )}
                  </>
                )
              })()
            ) : (
              <>
                <div className="grid h-20 w-20 place-items-center rounded-full bg-white/10">
                  <ScanLine size={44} />
                </div>
                <p className="mt-4 text-lg font-semibold">Arahkan kamera ke QR tiket</p>
                <p className="text-sm text-white/60">
                  {activeSession ? `${activeSession.label} · ${activeSession.nama_session}` : 'Belum ada sesi aktif'}
                </p>
              </>
            )}
          </div>
          <div className="p-5">
            <Button
              size="lg"
              className="w-full"
              onClick={openCamera}
              disabled={!bolehScan}
            >
              <Camera size={18} /> Buka Scanner Kamera
            </Button>
            <p className="mt-2 text-center text-xs text-slate-400">
              Bekerja di HP, tablet, maupun webcam laptop/PC. Beberapa petugas bisa memindai bersamaan.
            </p>

            <div className="my-4 flex items-center gap-3">
              <span className="h-px flex-1 bg-slate-200" />
              <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">atau input manual</span>
              <span className="h-px flex-1 bg-slate-200" />
            </div>

            <div className="flex gap-2">
              <Input
                value={code}
                onChange={(e) => setCode(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && handleManualScan()}
                placeholder="Masukkan kode QR..."
                className="font-mono"
                disabled={!bolehScan}
              />
              <Button onClick={handleManualScan} disabled={scanning || !bolehScan}><QrCode size={16} /> Scan</Button>
            </div>
            <p className="mt-3 text-xs text-slate-400">Masukkan kode QR dari e-ticket pembeli (format: Q7K2-9PLM-3XQ8).</p>
            {result && <Button variant="secondary" className="mt-4 w-full" onClick={reset}><RotateCcw size={15} /> Scan Lagi</Button>}
          </div>
        </Card>

        <Card className="p-5">
          <div className="flex items-center justify-between">
            <h2 className="font-bold text-slate-900">Hasil Scan · {activeSession?.label || '-'}</h2>
            <Badge color="green">{hadirSesi}/{totalSesi} check-in</Badge>
          </div>
          {history.length === 0 ? (
            <p className="mt-2 text-sm text-slate-500">Belum ada tiket yang berhasil dipindai pada sesi ini.</p>
          ) : (
            <div className="mt-4 space-y-2">
              {history.map((h, i) => (
                <div key={i} className="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                  <CheckCircle2 size={18} className="shrink-0 text-emerald-600" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-slate-800">{h.ticket?.nama_pemegang || h.kode}</p>
                    <p className="font-mono text-xs text-slate-500">{h.ticket?.kode_tiket || h.kode}</p>
                  </div>
                  <div className="shrink-0 text-right">
                    <Badge color="slate">{h.session_label}</Badge>
                    <p className="mt-1 text-xs text-slate-400">{formatTanggal(h.waktu, { withTime: true })}</p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      <Suspense
        fallback={cameraOpen ? (
          <div className="fixed inset-0 z-[60] grid place-items-center bg-slate-950 text-white">
            <div className="flex flex-col items-center gap-3">
              <Loader2 size={30} className="animate-spin" />
              <p className="text-sm font-medium">Menyiapkan scanner…</p>
            </div>
          </div>
        ) : null}
      >
        <QrScannerModal
          open={cameraOpen}
          onClose={() => setCameraOpen(false)}
          onDecoded={submitScan}
          subtitle="Arahkan kamera ke QR code tiket"
          counter={`${hadirSesi}/${totalSesi} check-in`}
          footerInfo={activeSession ? `Sesi aktif: ${activeSession.label} · ${activeSession.nama_session}` : 'Belum ada sesi aktif.'}
        />
      </Suspense>
    </div>
  )
}
