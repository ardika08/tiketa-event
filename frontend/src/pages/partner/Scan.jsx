import { Suspense, lazy, useCallback, useEffect, useState } from 'react'
import { QrCode, ScanLine, CheckCircle2, XCircle, AlertTriangle, RotateCcw, CalendarDays, Camera, Loader2 } from 'lucide-react'
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
  unpaid: { bg: 'from-amber-500 to-orange-600', icon: AlertTriangle, label: 'Belum Lunas' },
  invalid: { bg: 'from-rose-500 to-red-600', icon: XCircle, label: 'Gagal' },
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
  const [activeSessionId, setActiveSessionId] = useState(null)

  useEffect(() => {
    setActiveSessionId(sessions[0]?.id ?? null)
  }, [sessions])

  const [code, setCode] = useState('')
  const [result, setResult] = useState(null)
  const [history, setHistory] = useState([])
  const [scanning, setScanning] = useState(false)
  const [cameraOpen, setCameraOpen] = useState(false)

  const activeSession = sessions.find((s) => s.id === activeSessionId)
  const hadirSesi = activeSession?.total_hadir ?? 0
  const totalSesi = activeSession?.total_pass ?? 0

  /**
   * Kirim kode QR ke backend. Mengembalikan objek hasil agar bisa
   * ditampilkan sebagai overlay di dalam modal kamera.
   */
  const submitScan = useCallback(async (rawCode) => {
    const kode = String(rawCode || '').trim().toUpperCase()
    if (!kode) return null
    if (!eventId || !activeSessionId) {
      const res = { status: 'invalid', message: 'Pilih event dan sesi terlebih dahulu.', ticket: null }
      setResult(res)
      return res
    }

    setScanning(true)
    try {
      const res = await partnerApi.scan({ event_id: eventId, session_id: activeSessionId, kode_qr: kode })
      setResult(res)
      if (res.status === 'valid') {
        setHistory((h) => [{ kode, ...res, session_label: activeSession?.label, waktu: new Date().toISOString() }, ...h])
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
  }, [eventId, activeSessionId, activeSession, reload])

  const handleManualScan = () => {
    const value = code
    setCode('')
    submitScan(value)
  }

  const openCamera = () => {
    if (!activeSessionId) return
    setResult(null)
    setCameraOpen(true)
  }

  const reset = () => setResult(null)

  return (
    <div>
      <PageHeader title="Scan Tiket" description="Pilih sesi lalu pindai QR tiket untuk menandai kehadiran per hari." />

      <Card className="mb-6 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-2">
            <CalendarDays size={18} className="text-brand-600" />
            <div>
              <p className="text-sm font-bold text-slate-900">Sesi Aktif</p>
              <p className="text-xs text-slate-500">QR tiket hanya berlaku pada sesi yang dipilih.</p>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {events.length > 1 && (
              <Select value={eventId || ''} onChange={(e) => setEventId(Number(e.target.value))} className="sm:w-56">
                {events.map((e) => <option key={e.id} value={e.id}>{e.nama_event}</option>)}
              </Select>
            )}
            {sessions.map((s) => (
              <button
                key={s.id}
                onClick={() => { setActiveSessionId(s.id); setResult(null) }}
                className={cn(
                  'rounded-xl border px-4 py-2 text-left transition',
                  activeSessionId === s.id
                    ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-100'
                    : 'border-slate-200 bg-white hover:border-brand-300',
                )}
              >
                <span className={cn('text-xs font-bold uppercase tracking-wide', activeSessionId === s.id ? 'text-brand-700' : 'text-slate-500')}>{s.label}</span>
                <span className="block text-sm font-semibold text-slate-900">{s.nama_session}</span>
              </button>
            ))}
            {sessions.length === 0 && <p className="text-sm text-slate-400">Belum ada sesi untuk event ini.</p>}
          </div>
        </div>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="overflow-hidden">
          <div className={cn('flex flex-col items-center justify-center bg-gradient-to-br p-8 text-white', result ? RESULT_STYLE[result.status]?.bg || 'from-slate-700 to-slate-900' : 'from-slate-700 to-slate-900')}>
            {result ? (
              (() => {
                const Icon = RESULT_STYLE[result.status]?.icon || XCircle
                return (
                  <>
                    <div className="grid h-20 w-20 place-items-center rounded-full bg-white/20">
                      <Icon size={44} />
                    </div>
                    <p className="mt-4 text-xl font-extrabold">{RESULT_STYLE[result.status]?.label || 'Hasil'}</p>
                    <p className="mt-1 text-center text-white/90">{result.message}</p>
                    {result.ticket && (
                      <div className="mt-4 w-full rounded-xl bg-white/10 p-3 text-center text-sm">
                        <p className="font-mono font-bold">{result.ticket.kode_tiket}</p>
                        <p className="mt-1">{result.ticket.nama_pemegang} · {result.ticket.nama_tiket}</p>
                      </div>
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
                <p className="text-sm text-white/60">{activeSession ? `${activeSession.label} · ${activeSession.nama_session}` : 'Pilih sesi dulu'}</p>
              </>
            )}
          </div>
          <div className="p-5">
            <Button
              size="lg"
              className="w-full"
              onClick={openCamera}
              disabled={!activeSessionId}
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
                disabled={!activeSessionId}
              />
              <Button onClick={handleManualScan} disabled={scanning || !activeSessionId}><QrCode size={16} /> Scan</Button>
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
          footerInfo={activeSession ? `Sesi aktif: ${activeSession.label} · ${activeSession.nama_session}` : 'Pilih sesi terlebih dahulu.'}
        />
      </Suspense>
    </div>
  )
}
