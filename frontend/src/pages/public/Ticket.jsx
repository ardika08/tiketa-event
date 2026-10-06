import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import QRCode from 'qrcode'
import { toPng } from 'html-to-image'
import { Ticket as TicketIcon, CalendarDays, Download, RefreshCw, Info, Layers, Loader2 } from 'lucide-react'
import { Button, Card, Badge, EmptyState } from '../../components/ui'
import { formatTanggal } from '../../lib/utils'
import { useOrder } from '../../context/OrderContext'

/**
 * QR ASLI (scannable) — bukan placeholder. Digambar 3x ukuran tampilan
 * di canvas supaya tajam di layar retina.
 */
function QrCanvas({ value, size = 160 }) {
  const ref = useRef(null)

  useEffect(() => {
    if (!ref.current || !value) return
    QRCode.toCanvas(ref.current, value, {
      width: size * 3,
      margin: 1,
      errorCorrectionLevel: 'M',
      color: { dark: '#0f172a', light: '#ffffff' },
    })
      .then(() => {
        if (ref.current) {
          ref.current.style.width = `${size}px`
          ref.current.style.height = `${size}px`
        }
      })
      .catch(() => {})
  }, [value, size])

  return (
    <div className="inline-block rounded-2xl border border-slate-200 bg-white p-3 shadow-xs">
      <canvas
        ref={ref}
        style={{ width: size, height: size, maxWidth: size, maxHeight: size }}
        className="block rounded-lg"
      />
    </div>
  )
}

/** Unduh QR sebagai PNG 800px (lossless, tajam, siap disimpan ke galeri). */
async function downloadQrPng(kode, label) {
  const canvas = await QRCode.toCanvas(kode, {
    width: 800,
    margin: 2,
    errorCorrectionLevel: 'M',
    color: { dark: '#0f172a', light: '#ffffff' },
  })
  const url = canvas.toDataURL('image/png')
  const a = document.createElement('a')
  a.href = url
  a.download = `nontix-qr-${String(label || kode).replace(/[^a-zA-Z0-9-_]/g, '-')}.png`
  document.body.appendChild(a)
  a.click()
  a.remove()
}

/** Garis perforasi khas boarding pass dengan lekukan notch di kiri-kanan. */
function Perforation() {
  return (
    <div className="relative my-1 flex items-center" aria-hidden="true">
      <span className="absolute -left-3 h-6 w-6 rounded-full bg-slate-50" />
      <span className="absolute -right-3 h-6 w-6 rounded-full bg-slate-50" />
      <div className="mx-5 flex-1 border-t-2 border-dashed border-slate-200" />
    </div>
  )
}

export default function Ticket() {
  const { order, refreshOrder } = useOrder()
  const [searchParams] = useSearchParams()
  const [downloadingId, setDownloadingId] = useState(null)

  useEffect(() => {
    const kode = searchParams.get('kode')
    if (kode) {
      refreshOrder(kode).catch(() => {})
    } else if (!order) {
      refreshOrder().catch(() => {})
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const handleDownloadFullTicket = async (kodeTiket, filename) => {
    const cardId = `ticket-card-${kodeTiket}`
    const node = document.getElementById(cardId)
    if (!node) return
    setDownloadingId(kodeTiket)
    try {
      const dataUrl = await toPng(node, {
        pixelRatio: 2,
        backgroundColor: '#ffffff',
        cacheBust: true,
        filter: (child) => !child?.classList?.contains('no-export'),
      })
      const a = document.createElement('a')
      a.href = dataUrl
      a.download = `nontix-ticket-${String(filename || kodeTiket).replace(/[^a-zA-Z0-9-_]/g, '-')}.png`
      document.body.appendChild(a)
      a.click()
      a.remove()
    } catch (err) {
      console.error('Gagal mengunduh kartu tiket:', err)
    } finally {
      setDownloadingId(null)
    }
  }

  if (!order) {
    return (
      <div className="container-page py-20">
        <Card>
          <EmptyState
            icon={Info}
            title="Belum ada tiket"
            description="Tiket akan muncul di sini setelah kamu menyelesaikan pembelian."
            action={<Link to="/"><Button>Jelajahi Event</Button></Link>}
          />
        </Card>
      </div>
    )
  }

  return (
    <div className="container-page max-w-md py-6 sm:py-8">
      <div className="mb-4 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">E-Ticket</h1>
          <p className="text-xs text-slate-500">Tunjukkan tiket ini di pintu masuk acara</p>
        </div>
        <Link to="/tiket/kirim-ulang">
          <Button variant="secondary" size="sm" className="h-8 text-xs shrink-0"><RefreshCw size={13} /> Kirim Ulang</Button>
        </Link>
      </div>

      <div className="space-y-5">
        {order.tickets.map((t, idx) => {
          const passes = t.passes?.length
            ? t.passes
            : [{ kode_qr: t.kode_tiket, session_label: t.session_label, session_name: t.session_name, tanggal_mulai: null, lokasi: null, status: 'belum_hadir' }]

          return (
            <Card key={t.kode_tiket} id={`ticket-card-${t.kode_tiket}`} className="overflow-hidden shadow-sm">
              {/* 1. Header banner ala boarding pass */}
              <div className="bg-gradient-to-r from-brand-700 to-accent-500 px-4 py-3.5 text-white sm:px-5 sm:py-4">
                <div className="mb-1.5 flex items-center justify-between gap-2">
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2 py-0.5 text-[9px] font-extrabold uppercase tracking-widest sm:text-[10px]">
                    <TicketIcon size={11} /> Nontix Pass
                  </span>
                  <span className="font-mono text-xs font-semibold opacity-90">{order.kode_order}</span>
                </div>
                <h2 className="text-base font-extrabold leading-snug sm:text-lg">{order.event.nama_event}</h2>
                <div className="mt-1 flex flex-wrap items-center justify-between gap-2 text-[11px] text-white/85 sm:text-xs">
                  <span className="flex items-center gap-1.5">
                    <CalendarDays size={12} /> {formatTanggal(order.event.tanggal_mulai, { withTime: true })}
                  </span>
                  {order.tickets.length > 1 && (
                    <span className="rounded-full bg-white/15 px-2 py-0.5 font-semibold">Tiket {idx + 1}/{order.tickets.length}</span>
                  )}
                </div>
              </div>

              {/* 2. Pemegang tiket & Kategori */}
              <div className="border-b border-slate-200 px-4 pb-3 pt-3 sm:px-5 sm:pb-3.5 sm:pt-3.5">
                <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Pemegang Tiket</p>
                <p className="text-base font-extrabold text-slate-900 sm:text-lg">{t.nama_pemegang}</p>
                <div className="mt-1.5">
                  <span className="inline-flex items-center gap-1 rounded-md border border-brand-200 bg-brand-50 px-2 py-0.5 text-xs font-bold text-brand-700">
                    {t.is_bundle && <Layers size={11} />} {t.nama_tiket}
                  </span>
                </div>
              </div>

              {/* 3. Info penting ringkas */}
              <div className="px-4 py-3 sm:px-5">
                <div className="rounded-xl border border-slate-200 bg-slate-50 p-2.5 text-xs">
                  <div className="flex items-center justify-between border-b border-slate-200/80 pb-2">
                    <div>
                      <p className="text-[9px] font-bold uppercase tracking-wider text-slate-400">Jam Masuk</p>
                      <p className="font-bold text-slate-800">
                        {t.jam_masuk_mulai
                          ? `${t.jam_masuk_mulai.slice(0, 5)}${t.jam_masuk_selesai ? `–${t.jam_masuk_selesai.slice(0, 5)}` : ''} WIB`
                          : 'Sesuai jadwal'}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="text-[9px] font-bold uppercase tracking-wider text-slate-400">Hari Event</p>
                      <p className="font-bold text-slate-800">
                        {passes.length > 1 ? `${passes.length} Hari` : '1 Hari'}
                      </p>
                    </div>
                  </div>
                  <div className="pt-2">
                    <p className="text-[9px] font-bold uppercase tracking-wider text-slate-400">Lokasi</p>
                    <p className="font-semibold text-slate-800 leading-snug">
                      {order.event.lokasi || '—'}
                    </p>
                  </div>
                </div>
              </div>

              {/* 4. QR code per pass — fokus di tengah */}
              {passes.map((pass, pIdx) => {
                const hadir = pass.status === 'hadir'
                return (
                  <div key={pass.kode_qr || pIdx}>
                    <Perforation />
                    <div className="flex flex-col items-center gap-1.5 px-4 pb-4 pt-1.5 text-center sm:px-6 sm:pb-5">
                      <div className="flex flex-wrap items-center justify-center gap-1.5">
                        <p className="text-[10px] font-bold uppercase tracking-widest text-brand-700 sm:text-[11px]">
                          {pass.session_label ? `Check-in · ${pass.session_label}` : 'Pindai untuk Check-in'}
                        </p>
                        <Badge color={hadir ? 'green' : 'slate'}>
                          {hadir ? 'Sudah check-in' : 'Belum check-in'}
                        </Badge>
                      </div>
                      {pass.session_name && (
                        <p className="-mt-0.5 text-xs font-semibold text-slate-800 sm:text-sm">{pass.session_name}</p>
                      )}
                      {pass.tanggal_mulai && (
                        <p className="-mt-0.5 flex items-center gap-1 text-[11px] text-slate-500 sm:text-xs">
                          <CalendarDays size={11} /> {formatTanggal(pass.tanggal_mulai, { withTime: true })}
                        </p>
                      )}

                      <div className="my-1.5">
                        <QrCanvas value={pass.kode_qr} size={150} />
                      </div>

                      <p className="break-all rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-0.5 font-mono text-xs font-bold tracking-widest text-slate-800">
                        {pass.kode_qr}
                      </p>
                      <p className="max-w-xs text-[11px] text-slate-500">
                        Tunjukkan QR ini ke petugas scanner saat memasuki venue
                      </p>
                      <Button
                        variant="secondary"
                        size="sm"
                        className="no-export h-8 text-xs font-semibold"
                        disabled={downloadingId === t.kode_tiket}
                        onClick={() => handleDownloadFullTicket(t.kode_tiket, `${order.kode_order}-${t.nama_pemegang}`)}
                      >
                        {downloadingId === t.kode_tiket ? (
                          <>
                            <Loader2 size={13} className="animate-spin" /> Menyiapkan Tiket...
                          </>
                        ) : (
                          <>
                            <Download size={13} /> Unduh E-Tiket (PNG)
                          </>
                        )}
                      </Button>
                      <button
                        type="button"
                        className="no-export text-[10px] text-slate-400 hover:text-slate-600 underline"
                        onClick={() => downloadQrPng(pass.kode_qr, pass.session_label)}
                      >
                        Atau unduh QR saja
                      </button>
                    </div>
                  </div>
                )
              })}

              {/* 5. Footer */}
              <div className="border-t border-slate-200 bg-slate-50 px-4 py-2.5 text-center text-[10px] text-slate-500 sm:px-5 sm:py-3 sm:text-[11px]">
                Tiket resmi diterbitkan oleh Nontix · Tunjukkan tiket ini bersama kartu identitas yang sah
              </div>
            </Card>
          )
        })}
      </div>

      <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-center text-xs text-amber-800">
        Jangan bagikan kode tiket ini kepada siapa pun. Petugas akan memindai kode untuk menandai kehadiranmu.
      </div>
    </div>
  )
}
