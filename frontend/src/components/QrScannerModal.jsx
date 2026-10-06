import { useCallback, useEffect, useRef, useState } from 'react'
import { Html5Qrcode } from 'html5-qrcode'
import {
  X, Camera, RefreshCw, CheckCircle2, AlertTriangle, XCircle,
  Loader2, SwitchCamera, VideoOff, ScanLine,
} from 'lucide-react'
import { cn } from '../lib/utils'

const READER_ID = 'nontix-qr-reader'

/* ---------- Umpan balik sensorik ---------- */

function beep(ok = true) {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext
    if (!Ctx) return
    const ctx = new Ctx()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.type = 'sine'
    osc.frequency.value = ok ? 1180 : 340
    const dur = ok ? 0.16 : 0.3
    gain.gain.setValueAtTime(0.0001, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.28, ctx.currentTime + 0.012)
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + dur)
    osc.start(ctx.currentTime)
    osc.stop(ctx.currentTime + dur + 0.02)
    window.setTimeout(() => { try { ctx.close() } catch { /* noop */ } }, 700)
  } catch { /* audio tidak tersedia — abaikan */ }
}

function vibrate(ok = true) {
  try { navigator.vibrate?.(ok ? 90 : [70, 60, 70]) } catch { /* noop */ }
}

/* ---------- Pesan error kamera yang ramah petugas ---------- */

function friendlyCameraError(err) {
  const name = err?.name || err?.code || ''
  const raw = String(err?.message || err || '')
  if (/NotAllowedError|PermissionDeniedError/i.test(name) || /permission|denied/i.test(raw)) {
    return 'Izin kamera ditolak. Buka pengaturan browser → izinkan akses kamera untuk situs ini, lalu coba lagi.'
  }
  if (/NotFoundError|DevicesNotFoundError/i.test(name) || /no.*camera|not found/i.test(raw)) {
    return 'Tidak ada kamera yang terdeteksi di perangkat ini. Gunakan input kode manual sebagai gantinya.'
  }
  if (/NotReadableError|TrackStartError/i.test(name) || /could not start|in use|readable/i.test(raw)) {
    return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi kamera/Zoom/Meet, lalu tekan "Ganti Kamera" untuk mencoba lagi.'
  }
  if (/OverconstrainedError|ConstraintNotSatisfied/i.test(name)) {
    return 'Kamera yang dipilih tidak tersedia. Pilih kamera lain dari daftar di bawah.'
  }
  if (/SecurityError/i.test(name) || !window.isSecureContext) {
    return 'Akses kamera hanya bisa lewat koneksi aman (HTTPS). Pastikan alamat situs diawali https://'
  }
  return 'Gagal menyalakan kamera. Coba pilih kamera lain atau gunakan input kode manual.'
}

/* Pilih kamera default: utamakan kamera belakang */
function pickDefaultCamera(list) {
  if (!list?.length) return null
  const back = list.find((c) => /back|rear|belakang|environment|world/i.test(c.label || ''))
  if (back) return back.id
  const front = list.find((c) => /front|depan|user|facetime|integrated/i.test(c.label || ''))
  if (front) return front.id
  return list[list.length - 1].id
}

const FLASH_STYLE = {
  valid: { wrap: 'bg-emerald-500/95', icon: CheckCircle2, title: 'BERHASIL' },
  used: { wrap: 'bg-amber-500/95', icon: AlertTriangle, title: 'SUDAH DIPAKAI' },
  wrong_session: { wrap: 'bg-amber-500/95', icon: AlertTriangle, title: 'SESI TIDAK COCOK' },
  unpaid: { wrap: 'bg-amber-500/95', icon: AlertTriangle, title: 'BELUM LUNAS' },
  invalid: { wrap: 'bg-rose-500/95', icon: XCircle, title: 'GAGAL' },
  pending: { wrap: 'bg-slate-800/90', icon: Loader2, title: 'MEMERIKSA…' },
}

/**
 * Modal scanner QR berbasis kamera (html5-qrcode).
 * Mendukung iPhone/iPad (Safari), Android (Chrome), dan webcam desktop.
 * Semua track kamera dimatikan otomatis saat modal ditutup.
 */
export default function QrScannerModal({
  open,
  onClose,
  onDecoded,
  subtitle = 'Arahkan kamera ke QR code tiket',
  footerInfo = null,
  counter = null,
}) {
  const scannerRef = useRef(null)
  const decodedRef = useRef(onDecoded)
  const lastRef = useRef(null)
  const busyRef = useRef(false)
  const genRef = useRef(0)

  const [cameras, setCameras] = useState([])
  const [activeId, setActiveId] = useState(null)
  const [starting, setStarting] = useState(false)
  const [error, setError] = useState(null)
  const [flash, setFlash] = useState(null)

  useEffect(() => { decodedRef.current = onDecoded }, [onDecoded])

  /* --- Satu QR terdeteksi --- */
  const handleDecoded = useCallback(async (text) => {
    const kode = String(text || '').trim()
    if (!kode || busyRef.current) return
    // QR yang sama masih di depan kamera → jangan tampilkan ulang (cegah beep beruntun).
    // Di-reset hanya saat QR keluar dari frame (lihat handleMiss).
    if (kode === lastRef.current) return
    lastRef.current = kode
    busyRef.current = true

    setFlash({ status: 'pending', kode, message: 'Memeriksa tiket…' })

    let res = null
    try {
      res = await decodedRef.current?.(kode)
    } catch (err) {
      res = { status: 'invalid', message: err?.message || 'Gagal memeriksa tiket.' }
    }

    const status = res?.status || 'invalid'
    const ok = status === 'valid'
    beep(ok)
    vibrate(ok)
    setFlash({ status, kode, message: res?.message, ticket: res?.ticket })

    // Petugas butuh waktu membaca detail pemegang tiket → tahan 3 detik.
    window.setTimeout(() => {
      setFlash(null)
      busyRef.current = false
    }, ok ? 3000 : 2400)
  }, [])

  /* --- Tidak ada QR di frame → siap memindai kode berikutnya --- */
  const handleMiss = useCallback(() => {
    if (busyRef.current) return
    lastRef.current = null
  }, [])

  /* --- Nyalakan kamera --- */
  const startWith = useCallback(async (cameraId) => {
    const scanner = scannerRef.current
    if (!scanner) return false
    setStarting(true)
    setError(null)
    try {
      await scanner.start(
        cameraId ? { deviceId: { exact: cameraId } } : { facingMode: { ideal: 'environment' } },
        {
          fps: 10,
          // Kotak pindai = 70% sisi terpendek, dihitung ulang saat layar berputar
          qrbox: (vw, vh) => {
            const side = Math.max(140, Math.floor(Math.min(vw, vh) * 0.7))
            return { width: side, height: side }
          },
          aspectRatio: 1.0,
          disableFlip: false,
          experimentalFeatures: { useBarCodeDetectorIfSupported: true },
        },
        handleDecoded,
        handleMiss,
      )
      return true
    } catch (err) {
      setError(friendlyCameraError(err))
      return false
    } finally {
      setStarting(false)
    }
  }, [handleDecoded, handleMiss])

  /* --- Hidup/mati mengikuti status modal --- */
  useEffect(() => {
    if (!open) return undefined
    let cancelled = false
    busyRef.current = false
    lastRef.current = null
    setError(null)
    setFlash(null)
    setCameras([])
    setActiveId(null)

    const scanner = new Html5Qrcode(READER_ID, { verbose: false })
    scannerRef.current = scanner
    const gen = ++genRef.current

    ;(async () => {
      let list = []
      let listErr = null
      try {
        list = await Html5Qrcode.getCameras()
      } catch (err) {
        // Beberapa browser menolak enumerateDevices() tapi tetap mengizinkan
        // getUserMedia(). Jangan langsung tampilkan error — coba nyalakan dulu.
        listErr = err
      }
      if (cancelled) return
      setCameras(list || [])
      const id = pickDefaultCamera(list)
      setActiveId(id)
      const ok = await startWith(id)
      // Kamera benar-benar gagal dinyalakan → tampilkan penyebab dari izin/enumerasi.
      if (!ok && !cancelled && listErr) setError(friendlyCameraError(listErr))
    })()

    return () => {
      cancelled = true
      const s = scannerRef.current
      scannerRef.current = null
      if (!s) return
      // WAJIB: matikan seluruh track kamera agar lampu indikator & baterai tidak boros.
      // clear() hanya dijalankan bila tidak ada instance scanner yang lebih baru,
      // supaya render ulang (mis. React StrictMode) tidak menghapus elemen video
      // milik scanner yang sedang aktif.
      Promise.resolve()
        .then(() => s.stop())
        .catch(() => { /* kamera memang belum jalan */ })
        .finally(() => {
          if (genRef.current !== gen) return
          try { s.clear() } catch { /* noop */ }
        })
    }
  }, [open, startWith])

  /* --- Ganti kamera (belakang ⇄ depan ⇄ webcam lain) --- */
  const switchCamera = useCallback(async () => {
    if (cameras.length < 2 || starting) return
    const idx = cameras.findIndex((c) => c.id === activeId)
    const next = cameras[(idx + 1) % cameras.length]
    setActiveId(next.id)
    setFlash(null)
    busyRef.current = false
    const s = scannerRef.current
    if (s) { try { await s.stop() } catch { /* noop */ } }
    await startWith(next.id)
  }, [cameras, activeId, starting, startWith])

  const retry = useCallback(async () => {
    const s = scannerRef.current
    if (s) { try { await s.stop() } catch { /* noop */ } }
    await startWith(activeId)
  }, [activeId, startWith])

  const chooseCamera = useCallback(async (id) => {
    if (!id || id === activeId) return
    setActiveId(id)
    const s = scannerRef.current
    if (s) { try { await s.stop() } catch { /* noop */ } }
    await startWith(id)
  }, [activeId, startWith])

  if (!open) return null

  const Flash = flash ? FLASH_STYLE[flash.status] || FLASH_STYLE.invalid : null
  const FlashIcon = Flash?.icon

  return (
    <div className="fixed inset-0 z-[60] flex flex-col bg-slate-950 sm:items-center sm:justify-center sm:bg-slate-950/70 sm:p-4 sm:backdrop-blur-sm">
      <div className="flex h-full w-full flex-col overflow-y-auto overflow-x-hidden bg-slate-900 sm:h-[min(92vh,760px)] sm:max-w-md sm:overflow-hidden sm:rounded-3xl sm:shadow-2xl">
        {/* Header */}
        <div className="flex shrink-0 items-start justify-between gap-3 rounded-none bg-brand-600 px-5 py-4 text-white sm:rounded-t-3xl">
          <div className="min-w-0">
            <h3 className="flex items-center gap-2 text-lg font-bold leading-tight">
              <ScanLine size={20} className="shrink-0" /> Scan QR Code
            </h3>
            <p className="mt-0.5 text-sm text-white/80">{subtitle}</p>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Tutup scanner"
            className="shrink-0 rounded-xl p-1.5 text-white/90 transition hover:bg-white/15 active:bg-white/25"
          >
            <X size={22} />
          </button>
        </div>

        {/* Viewfinder */}
        <div className="relative w-full min-h-[180px] flex-1 overflow-hidden bg-black">
          <div id={READER_ID} className="absolute inset-0 [&_video]:h-full [&_video]:w-full [&_video]:object-cover [&_canvas]:hidden [&_#qr-shaded-region]:border-0" />

          {/* Reticle: bracket sudut + garis pindai */}
          <div className="pointer-events-none absolute inset-0 z-10 grid place-items-center">
            <div className="relative aspect-square w-[70%] max-h-[85%] max-w-[280px]">
              {['-top-0.5 -left-0.5 border-t-4 border-l-4 rounded-tl-lg',
                '-top-0.5 -right-0.5 border-t-4 border-r-4 rounded-tr-lg',
                '-bottom-0.5 -left-0.5 border-b-4 border-l-4 rounded-bl-lg',
                '-bottom-0.5 -right-0.5 border-b-4 border-r-4 rounded-br-lg'].map((cls) => (
                <span key={cls} className={cn('absolute h-9 w-9 border-white drop-shadow-[0_0_6px_rgba(0,0,0,0.6)]', cls)} />
              ))}
              {!flash && !error && !starting && (
                <span className="absolute inset-x-2 h-0.5 animate-[scanline_2.2s_ease-in-out_infinite] bg-gradient-to-r from-transparent via-emerald-400 to-transparent shadow-[0_0_10px_rgba(52,211,153,0.9)]" />
              )}
            </div>
          </div>

          {/* Status: memuat / error */}
          {starting && !error && (
            <div className="absolute inset-0 z-20 grid place-items-center bg-slate-950/70 text-white">
              <div className="flex flex-col items-center gap-2">
                <Loader2 size={30} className="animate-spin" />
                <p className="text-sm font-medium">Menyalakan kamera…</p>
              </div>
            </div>
          )}

          {error && (
            <div className="absolute inset-0 z-20 grid place-items-center bg-slate-950/85 px-6 text-center text-white">
              <div className="flex flex-col items-center gap-3">
                <VideoOff size={32} className="text-rose-400" />
                <p className="text-sm leading-relaxed text-white/90">{error}</p>
                <div className="mt-1 flex flex-wrap justify-center gap-2">
                  <button
                    type="button"
                    onClick={retry}
                    className="inline-flex items-center gap-1.5 rounded-xl bg-white/15 px-3.5 py-2 text-sm font-semibold transition hover:bg-white/25"
                  >
                    <RefreshCw size={15} /> Coba Lagi
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Overlay hasil pindai */}
          {flash && Flash && (
            <div className={cn('absolute inset-0 z-30 flex flex-col items-center justify-center px-6 text-center text-white', Flash.wrap)}>
              {FlashIcon && (
                <FlashIcon size={44} className={cn('drop-shadow', flash.status === 'pending' && 'animate-spin')} />
              )}
              <p className="mt-2 text-lg font-extrabold tracking-wide">{Flash.title}</p>
              {flash.message && <p className="mt-1 text-sm text-white/95">{flash.message}</p>}
              {flash.ticket && (
                <div className="mt-3 w-full max-w-xs rounded-xl bg-black/20 px-3 py-2 text-sm">
                  <p className="font-mono font-bold">{flash.ticket.kode_tiket}</p>
                  <p className="mt-0.5">{flash.ticket.nama_pemegang}</p>
                  {flash.ticket.nama_tiket && <p className="text-xs text-white/80">{flash.ticket.nama_tiket}</p>}
                </div>
              )}
            </div>
          )}

          {/* Counter check-in */}
          {counter && !flash && (
            <div className="pointer-events-none absolute left-3 top-3 z-20 rounded-full bg-black/55 px-3 py-1 text-xs font-semibold text-white backdrop-blur-sm">
              {counter}
            </div>
          )}
        </div>

        {/* Kontrol kamera */}
        <div className="shrink-0 space-y-3 border-t border-slate-800 bg-slate-900 px-5 py-4 sm:rounded-b-3xl">
          <button
            type="button"
            onClick={switchCamera}
            disabled={cameras.length < 2 || starting}
            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-3 text-sm font-bold text-white shadow-lg transition hover:bg-brand-700 active:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-40"
          >
            <SwitchCamera size={18} /> Ganti Kamera
          </button>

          <div className="relative">
            <Camera size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
            <select
              value={activeId || ''}
              onChange={(e) => chooseCamera(e.target.value)}
              disabled={starting || cameras.length === 0}
              className="w-full appearance-none rounded-xl border border-slate-700 bg-slate-800 py-2.5 pl-10 pr-9 text-sm font-medium text-slate-100 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 disabled:opacity-50"
            >
              {cameras.length === 0 && <option value="">Pilih Kamera</option>}
              {cameras.map((c, i) => (
                <option key={c.id} value={c.id}>{c.label || `Kamera ${i + 1}`}</option>
              ))}
            </select>
            <span className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400">▾</span>
          </div>

          {footerInfo && <p className="text-center text-xs leading-relaxed text-slate-400">{footerInfo}</p>}
        </div>
      </div>
    </div>
  )
}
