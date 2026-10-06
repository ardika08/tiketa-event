import { useEffect, useRef } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import QRCode from 'qrcode'
import { Ticket as TicketIcon, CalendarDays, Download, RefreshCw, Info, Layers } from 'lucide-react'
import { Button, Card, Badge, EmptyState } from '../../components/ui'
import { formatTanggal } from '../../lib/utils'
import { useOrder } from '../../context/OrderContext'

/**
 * QR ASLI (scannable) — bukan placeholder. Digambar 3x ukuran tampilan
 * di canvas supaya tajam di layar retina.
 */
function QrCanvas({ value, size = 180 }) {
  const ref = useRef(null)

  useEffect(() => {
    if (!ref.current || !value) return
    QRCode.toCanvas(ref.current, value, {
      width: size * 3,
      margin: 1,
      errorCorrectionLevel: 'M',
      color: { dark: '#0f172a', light: '#ffffff' },
    }).catch(() => {})
  }, [value, size])

  return (
    <div className="inline-block rounded-2xl border-2 border-slate-200 bg-white p-3 shadow-sm">
      <canvas ref={ref} style={{ width: size, height: size }} className="block rounded-md" />
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

  useEffect(() => {
    const kode = searchParams.get('kode')
    if (kode) {
      refreshOrder(kode).catch(() => {})
    } else if (!order) {
      refreshOrder().catch(() => {})
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

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
    <div className="container-page max-w-2xl py-8">
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">E-Ticket</h1>
          <p className="text-sm text-slate-500">Tunjukkan tiket ini di pintu masuk acara</p>
        </div>
        <Link to="/tiket/kirim-ulang">
          <Button variant="secondary" size="sm"><RefreshCw size={15} /> Kirim Ulang</Button>
        </Link>
      </div>

      <div className="space-y-6">
        {order.tickets.map((t, idx) => {
          const passes = t.passes?.length
            ? t.passes
            : [{ kode_qr: t.kode_tiket, session_label: t.session_label, session_name: t.session_name, tanggal_mulai: null, lokasi: null, status: 'belum_hadir' }]

          return (
            <Card key={t.kode_tiket} className="overflow-hidden">
              {/* 1. Header banner ala boarding pass */}
              <div className="bg-gradient-to-r from-brand-700 to-accent-500 px-5 py-4 text-white">
                <div className="mb-2 flex items-center justify-between gap-2">
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-widest">
                    <TicketIcon size={11} /> Nontix Pass
                  </span>
                  <span className="font-mono text-xs font-semibold opacity-90">{order.kode_order}</span>
                </div>
                <h2 className="text-lg font-extrabold leading-snug">{order.event.nama_event}</h2>
                <div className="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-white/85">
                  <span className="flex items-center gap-1.5">
                    <CalendarDays size={13} /> {formatTanggal(order.event.tanggal_mulai, { withTime: true })}
                  </span>
                  {order.tickets.length > 1 && (
                    <span className="rounded-full bg-white/15 px-2 py-0.5 font-semibold">Tiket {idx + 1}/{order.tickets.length}</span>
                  )}
                </div>
              </div>

              {/* 2. Pemegang tiket */}
              <div className="flex items-end justify-between gap-3 border-b border-slate-200 px-5 pb-3 pt-4">
                <div className="min-w-0">
                  <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Pemegang Tiket</p>
                  <p className="truncate text-lg font-extrabold text-slate-900">{t.nama_pemegang}</p>
                </div>
                <span className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1 text-xs font-bold text-brand-700">
                  {t.is_bundle && <Layers size={12} />} {t.nama_tiket}
                </span>
              </div>

              {/* 3. Info penting 3 kolom */}
              <div className="px-5 py-4">
                <div className="grid grid-cols-3 divide-x divide-slate-200 rounded-xl border border-slate-200 bg-slate-50 text-center">
                  <div className="min-w-0 px-2 py-2.5">
                    <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Jam Masuk</p>
                    <p className="truncate text-sm font-bold text-slate-800">
                      {t.jam_masuk_mulai
                        ? `${t.jam_masuk_mulai.slice(0, 5)}${t.jam_masuk_selesai ? `–${t.jam_masuk_selesai.slice(0, 5)}` : ''} WIB`
                        : 'Sesuai jadwal'}
                    </p>
                  </div>
                  <div className="min-w-0 px-2 py-2.5">
                    <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Lokasi</p>
                    <p className="truncate text-sm font-bold text-slate-800" title={order.event.lokasi}>
                      {order.event.lokasi || '—'}
                    </p>
                  </div>
                  <div className="min-w-0 px-2 py-2.5">
                    <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Hari Event</p>
                    <p className="truncate text-sm font-bold text-slate-800">
                      {passes.length > 1 ? `${passes.length} Hari` : '1 Hari'}
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
                    <div className="flex flex-col items-center gap-2 px-5 pb-5 pt-2 text-center">
                      <div className="flex flex-wrap items-center justify-center gap-2">
                        <p className="text-[11px] font-bold uppercase tracking-widest text-brand-700">
                          {pass.session_label ? `Check-in · ${pass.session_label}` : 'Pindai untuk Check-in'}
                        </p>
                        <Badge color={hadir ? 'green' : 'slate'}>
                          {hadir ? 'Sudah check-in' : 'Belum check-in'}
                        </Badge>
                      </div>
                      {pass.session_name && (
                        <p className="-mt-1 text-sm font-semibold text-slate-800">{pass.session_name}</p>
                      )}
                      {pass.tanggal_mulai && (
                        <p className="-mt-1 flex items-center gap-1 text-xs text-slate-500">
                          <CalendarDays size={12} /> {formatTanggal(pass.tanggal_mulai, { withTime: true })}
                        </p>
                      )}

                      <QrCanvas value={pass.kode_qr} />

                      <p className="break-all rounded-lg border border-slate-200 bg-slate-100 px-3 py-1 font-mono text-sm font-bold tracking-widest text-slate-800">
                        {pass.kode_qr}
                      </p>
                      <p className="max-w-xs text-xs text-slate-500">
                        Tunjukkan QR ini ke petugas scanner saat memasuki venue
                      </p>
                      <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => downloadQrPng(pass.kode_qr, pass.session_label)}
                      >
                        <Download size={15} /> Unduh PNG
                      </Button>
                    </div>
                  </div>
                )
              })}

              {/* 5. Footer */}
              <div className="border-t border-slate-200 bg-slate-50 px-5 py-3 text-center text-[11px] text-slate-500">
                Tiket resmi diterbitkan oleh Nontix · Tunjukkan tiket ini bersama kartu identitas yang sah
              </div>
            </Card>
          )
        })}
      </div>

      <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        Jangan bagikan kode tiket ini kepada siapa pun. Petugas akan memindai kode untuk menandai kehadiranmu.
      </div>
    </div>
  )
}
