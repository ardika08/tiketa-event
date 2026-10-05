import { useEffect } from 'react'
import { Link } from 'react-router-dom'
import { Ticket as TicketIcon, MapPin, CalendarDays, Download, RefreshCw, Info, Layers } from 'lucide-react'
import { Button, Card, Badge, EmptyState } from '../../components/ui'
import { formatTanggal } from '../../lib/utils'
import { useOrder } from '../../context/OrderContext'

function QrPlaceholder({ value, size = 120 }) {
  let hash = 0
  for (let i = 0; i < value.length; i += 1) hash = (hash * 31 + value.charCodeAt(i)) >>> 0
  const cells = 13
  const bits = []
  for (let i = 0; i < cells * cells; i += 1) {
    hash = (hash * 1103515245 + 12345) >>> 0
    bits.push((hash >> (i % 16)) & 1)
  }
  return (
    <div className="grid gap-px rounded-lg bg-white p-2 shadow-sm" style={{ width: size, height: size, gridTemplateColumns: `repeat(${cells}, 1fr)` }}>
      {bits.map((b, i) => (
        <span key={i} className={b ? 'bg-slate-900' : 'bg-white'} />
      ))}
    </div>
  )
}

export default function Ticket() {
  const { order, refreshOrder } = useOrder()

  useEffect(() => {
    if (!order) refreshOrder().catch(() => {})
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
        <div className="flex gap-2">
          <Button variant="secondary" size="sm" onClick={() => window.print()}><Download size={15} /> Simpan</Button>
          <Link to="/tiket/kirim-ulang"><Button variant="secondary" size="sm"><RefreshCw size={15} /> Kirim Ulang</Button></Link>
        </div>
      </div>

      <div className="space-y-4">
        {order.tickets.map((t, idx) => (
          <Card key={t.kode_tiket} className="overflow-hidden">
            <div className="flex items-center justify-between bg-gradient-to-r from-brand-700 to-accent-500 px-5 py-3 text-white">
              <div className="flex items-center gap-2">
                <TicketIcon size={18} />
                <span className="font-bold">{order.event.nama_event}</span>
              </div>
              <Badge color="green" className="bg-white/20 text-white ring-white/30">Tiket {idx + 1}/{order.tickets.length}</Badge>
            </div>
            <div className="p-5">
              <div className="mb-4 flex items-center gap-3 rounded-xl bg-slate-50 p-3">
                <div className="h-14 w-20 shrink-0 overflow-hidden rounded-lg bg-gradient-to-br from-brand-500 to-accent-500">
                  {t.gambar_url ? (
                    <img key={t.gambar_url} src={t.gambar_url} alt={t.nama_tiket} className="h-full w-full object-cover" loading="lazy" onError={(ev) => { ev.currentTarget.style.display = 'none' }} />
                  ) : (
                    <div className="grid h-full w-full place-items-center text-white/80"><TicketIcon size={20} /></div>
                  )}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="text-xs uppercase text-slate-400">Pemegang Tiket</p>
                  <p className="truncate font-semibold text-slate-800">{t.nama_pemegang}</p>
                </div>
                <div className="min-w-0 text-right">
                  <p className="text-xs uppercase text-slate-400">Jenis Tiket</p>
                  <p className="flex items-center justify-end gap-1.5 font-semibold text-brand-700">
                    {t.is_bundle && <Layers size={13} />} <span className="line-clamp-2">{t.nama_tiket}</span>
                  </p>
                </div>
              </div>

              <div className="mt-4 space-y-3">
                {(t.passes?.length ? t.passes : [{ kode_qr: t.kode_tiket, session_label: t.session_label, session_name: t.session_name, tanggal_mulai: null, lokasi: null, status: 'belum_hadir' }]).map((pass, pIdx) => (
                  <div key={pass.kode_qr || pIdx} className="flex flex-col gap-4 rounded-2xl border border-slate-200 p-4 sm:flex-row sm:items-center">
                    <div className="flex flex-col items-center gap-2 self-center">
                      <div className="max-w-full overflow-hidden">
                        <QrPlaceholder value={pass.kode_qr} size={Math.min(120, 110)} />
                      </div>
                      <p className="max-w-[140px] break-all text-center font-mono text-xs font-bold tracking-wider text-slate-700">{pass.kode_qr}</p>
                    </div>
                    <div className="flex-1 space-y-2 text-sm">
                      <div className="flex items-center justify-between gap-2">
                        <p className="text-xs uppercase text-slate-400">
                          {pass.session_label ? `Hari Event · ${pass.session_label}` : 'Tiket Masuk'}
                        </p>
                        <Badge color={pass.status === 'hadir' ? 'green' : 'slate'}>
                          {pass.status === 'hadir' ? 'Sudah check-in' : 'Belum check-in'}
                        </Badge>
                      </div>
                      {pass.session_name && <p className="font-semibold text-slate-800">{pass.session_name}</p>}
                      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                        <span className="flex items-center gap-1.5"><CalendarDays size={13} /> {formatTanggal(pass.tanggal_mulai || order.event.tanggal_mulai, { withTime: true })}</span>
                        <span className="flex items-center gap-1.5"><MapPin size={13} /> {pass.lokasi || order.event.lokasi}</span>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
            <div className="border-t border-dashed border-slate-200 bg-slate-50 px-5 py-3 text-center text-xs text-slate-500">
              Setiap hari punya QR sendiri. Satu QR hanya berlaku untuk satu kali masuk di hari tersebut.
            </div>
          </Card>
        ))}
      </div>

      <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        Jangan bagikan kode tiket ini kepada siapa pun. Petugas akan memindai kode untuk menandai kehadiranmu.
      </div>
    </div>
  )
}
