import { useEffect, useState } from 'react'
import { Armchair, Plus, Save } from 'lucide-react'
import { Button, Card, PageHeader, Badge, EmptyState, Select } from '../../components/ui'
import { ImagePicker } from '../../components/ImagePicker'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { cn } from '../../lib/utils'

export default function MasterSeatPlan() {
  const { data: eventsData } = useApi(() => partnerApi.events(), [])
  const events = eventsData?.data || []
  const [eventId, setEventId] = useState(null)

  useEffect(() => {
    if (!eventId && events.length) setEventId(events[0].id)
  }, [events, eventId])

  const { data: seatData, reload } = useApi(
    () => (eventId ? partnerApi.seatPlan(eventId) : Promise.resolve({ data: null })),
    [eventId],
  )
  const seatPlan = seatData?.data
  const rows = seatPlan?.rows || []
  const [gambar, setGambar] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    setGambar(seatPlan?.gambar_url || '')
  }, [seatPlan])

  const total = rows.reduce((s, r) => s + (r.seats?.length || 0), 0)
  const terisi = rows.reduce((s, r) => s + (r.seats || []).filter((x) => x.status === 'terisi').length, 0)

  const simpan = async () => {
    if (!eventId) return
    setSaving(true)
    try {
      await partnerApi.saveSeatPlan(eventId, {
        nama_denah: seatPlan?.nama_denah || 'Layout Utama',
        gambar_url: gambar,
        rows,
      })
      reload()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="Seat Plan"
        description="Atur denah tempat duduk untuk event yang membutuhkan pemilihan kursi."
        action={<Button onClick={simpan} disabled={!eventId || saving}><Save size={16} /> {saving ? 'Menyimpan...' : 'Simpan'}</Button>}
      />

      {events.length > 1 && (
        <Card className="mb-4 p-4">
          <Select value={eventId || ''} onChange={(e) => setEventId(Number(e.target.value))} className="sm:w-72">
            {events.map((e) => <option key={e.id} value={e.id}>{e.nama_event}</option>)}
          </Select>
        </Card>
      )}

      {!seatPlan ? (
        <Card>
          <EmptyState
            icon={Armchair}
            title="Belum ada denah"
            description="Denah tempat duduk belum diatur untuk event ini."
            action={<Button onClick={simpan}><Plus size={16} /> Buat Denah</Button>}
          />
        </Card>
      ) : (
        <Card className="p-6">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <span className="grid h-10 w-10 place-items-center rounded-xl bg-brand-100 text-brand-700"><Armchair size={18} /></span>
              <div>
                <p className="font-bold text-slate-900">{seatPlan.nama_denah}</p>
                <p className="text-xs text-slate-500">{total} kursi · {terisi} terisi</p>
              </div>
            </div>
            <div className="flex gap-2">
              <Badge color="green">Tersedia {total - terisi}</Badge>
              <Badge color="slate">Terisi {terisi}</Badge>
            </div>
          </div>

          <div className="mb-5 rounded-2xl border border-slate-200 p-4">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm font-semibold text-slate-700">Gambar Denah (opsional)</p>
              <div className="flex items-center gap-2">
                <ImagePicker
                  value={gambar}
                  onChange={(url) => setGambar(url)}
                  folder="seat-plans"
                  label="Unggah"
                  previewClassName="hidden"
                />
                {gambar && (
                  <Button variant="ghost" size="sm" className="text-rose-600" onClick={() => setGambar('')}>Hapus</Button>
                )}
              </div>
            </div>
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
              {gambar ? (
                <img key={gambar} src={gambar} alt="Denah" className="h-52 w-full object-cover" onError={(ev) => { ev.currentTarget.style.display = 'none' }} />
              ) : (
                <div className="grid h-40 place-items-center text-sm text-slate-400">Belum ada gambar denah</div>
              )}
            </div>
          </div>

          <div className="overflow-x-auto rounded-2xl bg-slate-50 p-5">
            <div className="mb-4 text-center text-xs font-semibold uppercase tracking-widest text-slate-400">Panggung</div>
            {rows.map((row) => (
              <div key={row.baris} className="mb-3 flex items-center gap-3">
                <span className="w-6 text-sm font-bold text-slate-400">{row.baris}</span>
                <span className="w-16 text-xs text-slate-400">{row.kategori}</span>
                <div className="flex flex-wrap gap-1.5">
                  {row.seats.map((seat) => (
                    <button
                      key={seat.kode}
                      className={cn(
                        'grid h-8 w-8 place-items-center rounded-md text-[10px] font-semibold transition',
                        seat.status === 'terisi'
                          ? 'bg-slate-300 text-slate-500'
                          : 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-300 hover:ring-2',
                      )}
                    >
                      {seat.kode}
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  )
}
