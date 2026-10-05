import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus, Search, CalendarDays, MoreVertical, Pencil, Trash2, Eye } from 'lucide-react'
import { Button, Card, PageHeader, StatusBadge, Input, EmptyState } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { normalizeEvent } from '../../lib/normalize'
import { formatRupiah, formatTanggal } from '../../lib/utils'

export default function MasterEvents() {
  const [q, setQ] = useState('')
  const [menu, setMenu] = useState(null)
  const { data, loading, reload } = useApi(() => partnerApi.events(), [])
  const all = useMemo(() => (data?.data || []).map(normalizeEvent), [data])
  const myEvents = useMemo(
    () => all.filter((e) => e.nama_event.toLowerCase().includes(q.toLowerCase())),
    [all, q],
  )

  const hapus = async (id) => {
    if (!window.confirm('Hapus event ini?')) return
    try {
      await partnerApi.deleteEvent(id)
      setMenu(null)
      reload()
    } catch (err) {
      window.alert(err.message)
    }
  }

  return (
    <div>
      <PageHeader
        title="Event"
        description="Kelola acara yang kamu selenggarakan."
        action={
          <Link to="/partner/master/event/baru">
            <Button><Plus size={16} /> Buat Event</Button>
          </Link>
        }
      />

      <div className="mb-4 max-w-md">
        <div className="relative">
          <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari event..." className="pl-10" />
        </div>
      </div>

      {loading ? (
        <div className="grid gap-4 lg:grid-cols-2">
          {[1, 2].map((i) => <div key={i} className="h-32 animate-pulse rounded-2xl bg-slate-100" />)}
        </div>
      ) : myEvents.length === 0 ? (
        <Card>
          <EmptyState
            icon={CalendarDays}
            title="Belum ada event"
            description="Buat event pertamamu dan mulai jual tiket."
            action={<Link to="/partner/master/event/baru"><Button><Plus size={16} /> Buat Event</Button></Link>}
          />
        </Card>
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {myEvents.map((e) => {
            const total = e.tiket.reduce((s, t) => s + t.kuota, 0)
            const sisa = e.total_sisa_kuota ?? e.tiket.reduce((s, t) => s + t.sisa_kuota, 0)
            const terjual = e.tiket.reduce((s, t) => s + (t.terjual_lunas ?? 0), 0)
            const dipesan = Math.max(0, total - sisa - terjual)
            return (
              <Card key={e.id} className="flex gap-4 p-4">
                <div className="h-24 w-24 shrink-0 overflow-hidden rounded-xl bg-gradient-to-br from-brand-600 to-accent-500">
                  <img key={e.hero_image_url} src={e.hero_image_url} alt={e.nama_event} className="h-full w-full object-cover" onError={(ev) => { ev.currentTarget.style.display = 'none' }} />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <h3 className="truncate font-bold text-slate-900">{e.nama_event}</h3>
                      <p className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                        <CalendarDays size={13} /> {formatTanggal(e.tanggal_mulai, { withDay: true })}
                      </p>
                    </div>
                    <StatusBadge status={e.status} />
                  </div>
                  <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                    <span>{e.tiket.length} jenis tiket</span>
                    <span className="font-medium text-emerald-700">{terjual.toLocaleString('id-ID')} terjual (lunas)</span>
                    {dipesan > 0 && <span className="text-amber-600">{dipesan} dipesan (pending)</span>}
                    <span>mulai {formatRupiah(Math.min(...e.tiket.map((t) => t.harga)))}</span>
                    {e.sembunyikan_sisa_kuota && <span className="text-slate-400">· sisa disembunyikan</span>}
                  </div>
                  <div className="mt-3 flex items-center gap-2">
                    <Link to={`/partner/master/event/${e.id}`}>
                      <Button size="sm" variant="secondary"><Pencil size={14} /> Kelola</Button>
                    </Link>
                    <Link to={`/event/${e.slug}`}>
                      <Button size="sm" variant="ghost"><Eye size={14} /> Pratinjau</Button>
                    </Link>
                    <div className="relative ml-auto">
                      <Button size="sm" variant="ghost" onClick={() => setMenu(menu === e.id ? null : e.id)}>
                        <MoreVertical size={15} />
                      </Button>
                      {menu === e.id && (
                        <div className="absolute right-0 top-full z-10 mt-1 w-40 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                          <Link to={`/partner/master/event/${e.id}`} className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                            <Pencil size={14} /> Ubah
                          </Link>
                          <button onClick={() => hapus(e.id)} className="flex w-full items-center gap-2 px-3 py-2 text-sm text-rose-600 hover:bg-rose-50">
                            <Trash2 size={14} /> Hapus
                          </button>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </Card>
            )
          })}
        </div>
      )}
    </div>
  )
}
