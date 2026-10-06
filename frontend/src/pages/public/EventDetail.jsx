import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  CalendarDays, MapPin, Clock, ArrowLeft, ShieldCheck, Trash2,
  Ticket as TicketIcon, Info, Minus, Plus, ShoppingBag, LayoutGrid, Layers,
} from 'lucide-react'
import { Badge, Button, Card, EmptyState } from '../../components/ui'
import { EventImage } from '../../components/EventCard'
import { isBundleTicket, ticketCoversSession, ticketSessionsLabel } from '../../data/mock'
import { publicApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { normalizeEvent } from '../../lib/normalize'
import { formatRupiah, formatTanggal, cn } from '../../lib/utils'
import { useOrder } from '../../context/OrderContext'

function useCountdown(target) {
  const [now, setNow] = useState(Date.now())
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(t)
  }, [])
  const diff = Math.max(new Date(target).getTime() - now, 0)
  return {
    hari: Math.floor(diff / 86400000),
    jam: Math.floor((diff % 86400000) / 3600000),
    menit: Math.floor((diff % 3600000) / 60000),
    detik: Math.floor((diff % 60000) / 1000),
  }
}

function TicketImage({ src, alt, className }) {
  if (!src) {
    return (
      <div className={cn('grid place-items-center bg-gradient-to-br from-brand-500 to-accent-500 text-white/80', className)}>
        <TicketIcon size={34} />
      </div>
    )
  }
  return <EventImage src={src} alt={alt} className={className} />
}

const TABS = [
  { id: 'snk', label: 'Syarat & Ketentuan' },
  { id: 'tiket', label: 'Tiket' },
  { id: 'denah', label: 'Denah' },
]

export default function EventDetail() {
  const { slug } = useParams()
  const navigate = useNavigate()
  const { startDraft } = useOrder()
  const { data, loading } = useApi(() => publicApi.event(slug), [slug])
  const event = useMemo(() => normalizeEvent(data?.data), [data])
  const [tab, setTab] = useState('snk')
  const [qty, setQty] = useState({})
  const sessions = useMemo(
    () => [...(event?.sessions || [])].filter((session) => session.status === 'aktif').sort((a, b) => a.urutan - b.urutan),
    [event],
  )
  const [activeSessionId, setActiveSessionId] = useState(null)

  useEffect(() => {
    setActiveSessionId(sessions[0]?.id ?? null)
    setQty({})
  }, [event?.id, sessions])

  const activeSession = sessions.find((session) => session.id === activeSessionId)
  const bundleTickets = event?.tiket.filter((ticket) => isBundleTicket(ticket)) || []
  const visibleTickets = event?.tiket.filter((ticket) =>
    !sessions.length || (!isBundleTicket(ticket) && ticketCoversSession(ticket, activeSessionId)),
  ) || []
  const countdown = useCountdown(activeSession?.tanggal_mulai || event?.tanggal_mulai || Date.now())

  const selected = useMemo(() => {
    if (!event) return []
    return event.tiket
      .filter((t) => (qty[t.id] || 0) > 0)
      .map((t) => ({ ...t, ticketId: t.id, jumlah: qty[t.id] }))
  }, [event, qty])

  const subtotal = selected.reduce((s, t) => s + t.harga * t.jumlah, 0)
  const totalTiket = selected.reduce((s, t) => s + t.jumlah, 0)

  if (loading) {
    return (
      <div className="container-page py-20">
        <div className="h-72 animate-pulse rounded-2xl bg-slate-100" />
      </div>
    )
  }

  if (!event) {
    return (
      <div className="container-page py-20">
        <Card>
          <EmptyState icon={Info} title="Event tidak ditemukan" description="Acara yang kamu cari tidak tersedia." action={<Link to="/"><Button>Kembali ke Beranda</Button></Link>} />
        </Card>
      </div>
    )
  }

  const setTicketQty = (ticket, value) => {
    const max = Math.min(ticket.max_per_order, ticket.sisa_kuota)
    setQty((q) => ({ ...q, [ticket.id]: Math.max(0, Math.min(max, value)) }))
  }

  const selectSession = (sessionId) => {
    if (sessionId === activeSessionId) return
    setActiveSessionId(sessionId)
    setTab('tiket')
  }

  const goCheckout = (items) => {
    startDraft(event)
    navigate('/checkout', { state: { items, event } })
  }

  const handleBeli = () => {
    if (totalTiket === 0) return
    goCheckout(selected)
  }

  const renderTicketCard = (ticket) => {
    const habis = ticket.sisa_kuota <= 0
    const value = qty[ticket.id] || 0
    const max = Math.min(ticket.max_per_order, ticket.sisa_kuota)
    const coverage = ticketSessionsLabel(event, ticket)
    const sembunyi = !!event.sembunyikan_sisa_kuota
    return (
      <div key={ticket.id} className="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white">
        <div className="relative">
          <TicketImage src={ticket.gambar_url} alt={ticket.nama_tiket} className="aspect-video w-full" />
          {habis ? (
            <span className="absolute left-2 top-2"><Badge color="red">Kuota Habis</Badge></span>
          ) : (
            isBundleTicket(ticket) && (
              <span className="absolute left-2 top-2"><Badge color="brand">Bundle</Badge></span>
            )
          )}
        </div>
        <div className="flex flex-1 flex-col p-4">
          <h4 className="font-bold text-slate-900">{ticket.nama_tiket}</h4>
          <p className="mt-1 text-lg font-extrabold text-brand-700">{formatRupiah(ticket.harga)}</p>
          {ticket.jam_masuk_mulai && (
            <p className="mt-1 flex items-center gap-1 text-xs font-semibold text-brand-600">
              <Clock size={12} /> Jam masuk: {ticket.jam_masuk_mulai}{ticket.jam_masuk_selesai ? `–${ticket.jam_masuk_selesai}` : ''} WIB
            </p>
          )}
          {coverage && isBundleTicket(ticket) && (
            <p className="mt-1 flex items-center gap-1 text-xs font-semibold text-brand-600">
              <Layers size={12} /> Berlaku: {coverage}
            </p>
          )}
          <p className="mt-0.5 text-xs text-slate-500">
            {habis
              ? 'Tiket tidak tersedia'
              : sembunyi
                ? 'Tersedia'
                : `Sisa ${ticket.sisa_kuota.toLocaleString('id-ID')} · maks ${ticket.max_per_order}/pesanan`}
          </p>

          <div className="mt-4">
            {habis ? (
              <span className="text-xs font-semibold text-rose-600">Tiket tidak tersedia</span>
            ) : (
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-medium text-slate-500">Jumlah</span>
                <div className="inline-flex items-center rounded-xl border border-slate-300">
                  <button
                    onClick={() => setTicketQty(ticket, value - 1)}
                    disabled={value <= 0}
                    className="grid h-9 w-9 place-items-center rounded-l-xl text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                  >
                    <Minus size={14} />
                  </button>
                  <span className="w-9 text-center text-sm font-bold">{value}</span>
                  <button
                    onClick={() => setTicketQty(ticket, value + 1)}
                    disabled={value >= max}
                    className="grid h-9 w-9 place-items-center rounded-r-xl text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                  >
                    <Plus size={14} />
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div>
      <div className="relative isolate overflow-hidden bg-slate-950">
        <div className="relative min-h-[320px] w-full sm:min-h-0" style={{ aspectRatio: '16 / 9', maxHeight: '520px' }}>
          <EventImage src={event.hero_image_url} alt={event.nama_event} className="absolute inset-0 h-full w-full" />
          <div className="absolute inset-0 bg-gradient-to-t from-slate-900/85 via-slate-900/40 to-slate-900/20" />
          <div className="container-page absolute inset-x-0 bottom-0 z-10 flex flex-col justify-end pb-5 text-white sm:pb-6">
            <Link to="/" className="mb-3 inline-flex w-fit items-center gap-1.5 text-sm text-white/80 hover:text-white">
              <ArrowLeft size={16} /> Kembali
            </Link>
            <Badge color="brand" className="w-fit">{event.kategori}</Badge>
            <h1 className="mt-2 line-clamp-3 max-w-3xl text-xl font-extrabold leading-tight text-white sm:text-4xl">{event.nama_event}</h1>
            <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-white/90 sm:text-sm">
              <span className="flex items-center gap-2"><CalendarDays size={15} className="shrink-0" /> {formatTanggal(event.tanggal_mulai, { withDay: true })}</span>
              <span className="flex items-center gap-2"><MapPin size={15} className="shrink-0" /> <span className="line-clamp-1">{event.lokasi}</span></span>
              <span className="flex items-center gap-2"><ShieldCheck size={15} className="shrink-0" /> {event.organizer}</span>
            </div>
          </div>
        </div>
      </div>

      <div className="container-page grid gap-8 py-8 lg:grid-cols-3">
        <div className="min-w-0 space-y-8 lg:col-span-2">
          {sessions.length > 0 && (
            <Card className="p-5 sm:p-6">
              <div className="mb-4">
                <h2 className="text-lg font-semibold text-slate-900">Pilih Hari Event</h2>
                <p className="mt-1 text-sm text-slate-500">Kamu dapat memilih tiket dari beberapa hari dalam satu pesanan.</p>
              </div>
              <div className="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-2">
                {sessions.map((session) => (
                  <button
                    key={session.id}
                    type="button"
                    onClick={() => selectSession(session.id)}
                    aria-pressed={activeSessionId === session.id}
                    className={cn(
                      'min-w-[190px] snap-start rounded-[var(--radius-control)] border p-4 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                      activeSessionId === session.id
                        ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-100'
                        : 'border-slate-200 bg-white hover:border-brand-300 hover:bg-slate-50',
                    )}
                  >
                    <span className={cn('text-xs font-bold uppercase tracking-wide', activeSessionId === session.id ? 'text-brand-700' : 'text-slate-500')}>{session.label}</span>
                    <span className="mt-1 block text-sm font-semibold text-slate-900">{session.nama_session}</span>
                    <span className="mt-2 flex items-center gap-1.5 text-xs text-slate-500"><CalendarDays size={13} /> {formatTanggal(session.tanggal_mulai, { withDay: true, withTime: true })}</span>
                  </button>
                ))}
              </div>
            </Card>
          )}

          <Card className="p-6">
            <h2 className="mb-4 text-lg font-bold text-slate-900">Menuju Acara</h2>
            <div className="grid grid-cols-4 gap-3">
              {[
                ['Hari', countdown.hari],
                ['Jam', countdown.jam],
                ['Menit', countdown.menit],
                ['Detik', countdown.detik],
              ].map(([label, value]) => (
                <div key={label} className="rounded-2xl bg-brand-50 p-3 text-center">
                  <p className="text-2xl font-extrabold text-brand-700">{String(value).padStart(2, '0')}</p>
                  <p className="text-xs font-medium text-slate-500">{label}</p>
                </div>
              ))}
            </div>
          </Card>

          <Card className="p-6">
            <h2 className="mb-3 text-lg font-bold text-slate-900">Deskripsi Acara</h2>
            <p className="whitespace-pre-line leading-relaxed text-slate-600">{event.deskripsi}</p>
            <div className="mt-5 grid gap-3 sm:grid-cols-2">
              <div className="rounded-xl bg-slate-50 p-4">
                <p className="flex items-center gap-2 text-xs font-semibold uppercase text-slate-400"><Clock size={14} /> Waktu</p>
                <p className="mt-1 text-sm font-medium text-slate-700">{formatTanggal(activeSession?.tanggal_mulai || event.tanggal_mulai, { withTime: true })}</p>
                <p className="text-sm text-slate-500">s/d {formatTanggal(activeSession?.tanggal_selesai || event.tanggal_selesai, { withTime: true })}</p>
              </div>
              <div className="rounded-xl bg-slate-50 p-4">
                <p className="flex items-center gap-2 text-xs font-semibold uppercase text-slate-400"><MapPin size={14} /> Lokasi</p>
                <p className="mt-1 text-sm font-medium text-slate-700">{activeSession?.lokasi || event.lokasi}</p>
              </div>
            </div>
          </Card>

          <Card className="overflow-hidden">
            <div className="flex gap-1 overflow-x-auto border-b border-slate-200 px-3 pt-3">
              {TABS.map((t) => (
                <button
                  key={t.id}
                  onClick={() => setTab(t.id)}
                  className={cn(
                    'whitespace-nowrap rounded-t-xl border-b-2 px-4 py-2.5 text-sm font-semibold transition',
                    tab === t.id
                      ? 'border-brand-600 text-brand-700'
                      : 'border-transparent text-slate-500 hover:text-slate-700',
                  )}
                >
                  {t.label}
                </button>
              ))}
            </div>

            <div className="p-6">
              {tab === 'snk' && (
                <div>
                  <h3 className="mb-3 text-base font-bold text-slate-900">Syarat & Ketentuan</h3>
                  <p className="whitespace-pre-line leading-relaxed text-slate-600">{event.syarat_ketentuan}</p>
                </div>
              )}

              {tab === 'tiket' && (
                <div className="space-y-8">
                  {bundleTickets.length > 0 && (
                    <div>
                      <div className="mb-4 flex items-center gap-2">
                        <Layers size={18} className="text-brand-600" />
                        <div>
                          <h3 className="text-base font-bold text-slate-900">Paket Multi-Hari</h3>
                          <p className="text-xs text-slate-500">Satu tiket untuk beberapa hari sekaligus, lebih hemat.</p>
                        </div>
                      </div>
                      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {bundleTickets.map((ticket) => renderTicketCard(ticket))}
                      </div>
                    </div>
                  )}

                  <div>
                    <div className="mb-4 flex items-center gap-2">
                      <TicketIcon size={18} className="text-brand-600" />
                      <div>
                        <h3 className="text-base font-bold text-slate-900">
                          {bundleTickets.length > 0 ? 'Tiket Harian' : 'Pilih Jenis Tiket'}
                        </h3>
                        {activeSession && <p className="text-xs text-slate-500">{activeSession.label} · {activeSession.nama_session}</p>}
                      </div>
                    </div>
                    {visibleTickets.length > 0 ? (
                      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {visibleTickets.map((ticket) => renderTicketCard(ticket))}
                      </div>
                    ) : (
                      <p className="rounded-xl border-2 border-dashed border-slate-200 bg-slate-50 p-5 text-center text-sm text-slate-500">
                        Tidak ada tiket harian untuk sesi ini. Paket multi-hari di atas tetap bisa dipilih.
                      </p>
                    )}
                  </div>
                </div>
              )}

              {tab === 'denah' && (
                <div>
                  <div className="mb-4 flex items-center gap-2">
                    <LayoutGrid size={18} className="text-brand-600" />
                    <h3 className="text-base font-bold text-slate-900">Denah Lokasi & Tempat Duduk</h3>
                  </div>

                  {event.seatPlan?.gambar_url ? (
                    <div className="overflow-hidden rounded-2xl border border-slate-200">
                      <EventImage src={event.seatPlan.gambar_url} alt={event.seatPlan.nama_denah} className="h-56 w-full sm:h-72" />
                    </div>
                  ) : (
                    <div className="grid h-48 place-items-center rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 text-sm text-slate-400">
                      Gambar denah belum ditambahkan oleh penyelenggara.
                    </div>
                  )}

                  <p className="mt-4 mb-3 text-sm text-slate-500">{event.seatPlan?.nama_denah}</p>
                  <div className="space-y-2 overflow-x-auto rounded-xl bg-slate-50 p-4">
                    {(event.seatPlan?.rows || []).map((row) => (
                      <div key={row.baris} className="flex items-center gap-3">
                        <span className="w-6 shrink-0 text-sm font-bold text-slate-400">{row.baris}</span>
                        <div className="flex flex-wrap gap-1.5">
                          {row.seats.map((seat) => (
                            <span
                              key={seat.kode}
                              title={`${seat.kode} · ${row.kategori} · ${seat.status}`}
                              className={cn(
                                'grid h-7 w-7 place-items-center rounded-md text-[10px] font-semibold',
                                seat.status === 'terisi'
                                  ? 'bg-slate-300 text-slate-500'
                                  : 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-300',
                              )}
                            >
                              {seat.kode.replace(row.baris, '')}
                            </span>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div>
                  <div className="mt-3 flex gap-4 text-xs text-slate-500">
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-emerald-100 ring-1 ring-emerald-300" /> Tersedia</span>
                    <span className="flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-slate-300" /> Terisi</span>
                  </div>
                </div>
              )}
            </div>
          </Card>
        </div>

        <div className="min-w-0">
          <Card className="p-6 lg:sticky lg:top-24">
            <div className="mb-4 flex items-center gap-2">
              <ShoppingBag size={18} className="text-brand-600" />
              <h2 className="text-lg font-bold text-slate-900">Ringkasan Pesanan</h2>
            </div>

            {activeSession && (
              <div className="mb-4 rounded-[var(--radius-control)] bg-brand-50 p-3 ring-1 ring-brand-100">
                <p className="text-xs font-bold uppercase tracking-wide text-brand-700">{activeSession.label}</p>
                <p className="mt-0.5 text-sm font-semibold text-slate-800">{activeSession.nama_session}</p>
                <p className="mt-1 text-xs text-slate-500">{formatTanggal(activeSession.tanggal_mulai, { withTime: true })}</p>
              </div>
            )}

            {selected.length === 0 ? (
              <div className="rounded-xl border-2 border-dashed border-slate-200 bg-slate-50 p-5 text-center">
                <TicketIcon size={24} className="mx-auto text-slate-300" />
                <p className="mt-2 text-sm text-slate-500">Belum ada tiket dipilih.</p>
                <Button variant="secondary" size="sm" className="mt-3" onClick={() => setTab('tiket')}>
                  Pilih Tiket
                </Button>
              </div>
            ) : (
              <div className="space-y-3">
                {selected.map((t) => (
                  <div key={t.id} className="flex items-start justify-between gap-3 text-sm">
                    <div className="min-w-0">
                      <p className="truncate font-medium text-slate-700">{t.nama_tiket}</p>
                      {sessions.length > 0 && (
                        <p className="text-xs font-medium text-brand-600">{ticketSessionsLabel(event, t)}</p>
                      )}
                      <p className="text-xs text-slate-500">{formatRupiah(t.harga)} × {t.jumlah}</p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                      <span className="font-semibold text-slate-800">{formatRupiah(t.harga * t.jumlah)}</span>
                      <button
                        onClick={() => setTicketQty(t, 0)}
                        title="Hapus dari pesanan"
                        className="rounded-md p-1 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                      >
                        <Trash2 size={15} />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}

            <div className="mt-5 border-t border-slate-100 pt-4">
              <div className="flex items-center justify-between text-sm">
                <span className="text-slate-500">Total tiket</span>
                <span className="font-semibold text-slate-800">{totalTiket} tiket</span>
              </div>
              <div className="mt-1 flex items-center justify-between">
                <span className="text-sm text-slate-500">Subtotal</span>
                <span className="text-lg font-bold text-brand-700">{formatRupiah(subtotal)}</span>
              </div>
              <Button className="mt-4 w-full" size="lg" disabled={totalTiket === 0} onClick={handleBeli}>
                Lanjut Checkout
              </Button>
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
