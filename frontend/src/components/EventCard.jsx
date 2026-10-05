import { Link } from 'react-router-dom'
import { CalendarDays, MapPin, Ticket as TicketIcon } from 'lucide-react'
import { Badge } from './ui'
import { formatRupiah, formatTanggal, cn } from '../lib/utils'

export function EventImage({ src, alt, className }) {
  return (
    <div className={cn('relative overflow-hidden bg-gradient-to-br from-brand-600 to-accent-500', className)}>
      <img
        src={src}
        alt={alt}
        loading="lazy"
        className="h-full w-full object-cover"
        onError={(e) => {
          e.currentTarget.style.display = 'none'
        }}
      />
    </div>
  )
}

export default function EventCard({ event }) {
  const tiket = event.tiket || []
  const sisa = event.total_sisa_kuota ?? tiket.reduce((sum, t) => sum + (t.sisa_kuota || 0), 0)
  const habis = sisa <= 0
  const hargaMulai = tiket.length ? Math.min(...tiket.map((t) => t.harga)) : 0
  const sembunyi = !!event.sembunyikan_sisa_kuota

  return (
    <Link
      to={`/event/${event.slug}`}
      className="group card card-hover overflow-hidden"
    >
      <div className="relative">
        <EventImage src={event.hero_image_url} alt={event.nama_event} className="aspect-video w-full" />
        <div className="absolute left-3 top-3 flex gap-2">
          <Badge color="brand">{event.kategori}</Badge>
          {habis && <Badge color="red">Kuota Habis</Badge>}
        </div>
      </div>
      <div className="p-4">
        <h3 className="line-clamp-2 text-base font-semibold text-slate-900 group-hover:text-brand-700">
          {event.nama_event}
        </h3>
        <div className="mt-3 space-y-1.5 text-sm text-slate-500">
          <p className="flex items-center gap-2">
            <CalendarDays size={15} className="shrink-0 text-brand-500" />
            {formatTanggal(event.tanggal_mulai, { withDay: true, withTime: true })}
          </p>
          <p className="flex items-center gap-2">
            <MapPin size={15} className="shrink-0 text-brand-500" />
            <span className="truncate">{event.lokasi}</span>
          </p>
        </div>
        <div className="mt-4 flex items-end justify-between border-t border-slate-100 pt-3">
          <div>
            <p className="text-xs text-slate-400">Mulai dari</p>
            <p className="text-lg font-bold text-brand-700">{formatRupiah(hargaMulai)}</p>
          </div>
          <div className="text-right">
            <p className="flex items-center justify-end gap-1 text-xs font-medium text-slate-500">
              <TicketIcon size={13} /> {sembunyi ? 'Status tiket' : 'Sisa kuota'}
            </p>
            <p className={cn('text-sm font-bold', habis ? 'text-rose-600' : 'text-slate-800')}>
              {habis ? 'Habis' : sembunyi ? 'Tersedia' : `${sisa.toLocaleString('id-ID')} tiket`}
            </p>
          </div>
        </div>
      </div>
    </Link>
  )
}
