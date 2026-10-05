import { useMemo, useState } from 'react'
import { Plus, Ticket as TicketIcon, Layers } from 'lucide-react'
import { Button, Card, PageHeader, ProgressBar, Badge, Modal, Field, Input, Select } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { normalizeEvent } from '../../lib/normalize'
import { ticketSessionsLabel, isBundleTicket } from '../../data/mock'
import { formatRupiah } from '../../lib/utils'

const EMPTY_FORM = { event_id: '', nama_tiket: '', harga: 0, kuota: 0, max_per_order: 4, session_ids: [] }

export default function MasterTickets() {
  const { data, loading, reload } = useApi(() => partnerApi.events(), [])
  const events = useMemo(() => (data?.data || []).map(normalizeEvent), [data])
  const rows = useMemo(
    () => events.flatMap((e) => e.tiket.map((t) => ({ ...t, event: e.nama_event, eventData: e }))),
    [events],
  )

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const selectedEvent = events.find((e) => String(e.id) === String(form.event_id))
  const sessions = selectedEvent?.sessions || []

  const openModal = () => {
    setForm({ ...EMPTY_FORM, event_id: events[0]?.id ?? '' })
    setError('')
    setOpen(true)
  }

  const toggleSession = (sessionId) => {
    setForm((f) => {
      const has = f.session_ids.includes(sessionId)
      return { ...f, session_ids: has ? f.session_ids.filter((s) => s !== sessionId) : [...f.session_ids, sessionId] }
    })
  }

  const submit = async () => {
    if (!form.event_id) return setError('Pilih event terlebih dahulu.')
    if (!form.nama_tiket.trim()) return setError('Nama tiket wajib diisi.')
    if (Number(form.kuota) < 1) return setError('Kuota minimal 1.')

    setSaving(true)
    setError('')
    try {
      await partnerApi.createTicketType({
        event_id: Number(form.event_id),
        nama_tiket: form.nama_tiket.trim(),
        harga: Number(form.harga) || 0,
        kuota: Number(form.kuota) || 0,
        max_per_order: Number(form.max_per_order) || 1,
        session_ids: form.session_ids,
      })
      setOpen(false)
      reload()
    } catch (err) {
      setError(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="Tiket"
        description="Semua jenis tiket dari event yang kamu kelola."
        action={
          <Button onClick={openModal} disabled={events.length === 0}>
            <Plus size={16} /> Tambah Jenis Tiket
          </Button>
        }
      />
      <Card>
        {loading ? (
          <div className="m-4 h-56 animate-pulse rounded-xl bg-slate-100" />
        ) : rows.length === 0 ? (
          <div className="p-8 text-center text-sm text-slate-500">
            Belum ada jenis tiket. {events.length === 0 ? 'Buat event terlebih dahulu.' : 'Klik "Tambah Jenis Tiket".'}
          </div>
        ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-5 py-3 font-semibold">Jenis Tiket</th>
                <th className="px-5 py-3 font-semibold">Event</th>
                <th className="px-5 py-3 font-semibold">Sesi</th>
                <th className="px-5 py-3 font-semibold">Harga</th>
                <th className="px-5 py-3 font-semibold">Penjualan (Lunas)</th>
                <th className="px-5 py-3 font-semibold">Sisa Kuota</th>
                <th className="px-5 py-3 font-semibold">Maks/Order</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((t) => {
                const terjual = t.terjual_lunas ?? 0
                const dipesan = Math.max(0, t.kuota - t.sisa_kuota - terjual)
                return (
                  <tr key={t.id} className="hover:bg-slate-50">
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-2.5">
                        <span className="grid h-10 w-14 shrink-0 place-items-center overflow-hidden rounded-lg bg-brand-100 text-brand-700">
                          {t.gambar_url ? (
                            <img src={t.gambar_url} alt={t.nama_tiket} className="h-full w-full object-cover" onError={(ev) => { ev.currentTarget.style.display = 'none' }} />
                          ) : (
                            <TicketIcon size={15} />
                          )}
                        </span>
                        <span className="font-semibold text-slate-800">{t.nama_tiket}</span>
                        {isBundleTicket(t) && <Badge color="brand"><Layers size={11} /> Bundle</Badge>}
                      </div>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{t.event}</td>
                    <td className="px-5 py-3">
                      {ticketSessionsLabel(t.eventData, t) ? (
                        <span className="inline-flex items-center gap-1.5 text-slate-600">
                          {isBundleTicket(t) && <Layers size={13} className="text-brand-600" />}
                          {ticketSessionsLabel(t.eventData, t)}
                        </span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-5 py-3 font-semibold text-slate-800">{formatRupiah(t.harga)}</td>
                    <td className="px-5 py-3 w-56">
                      <div className="mb-1 flex justify-between text-xs text-slate-500">
                        <span><span className="font-semibold text-slate-700">{terjual.toLocaleString('id-ID')}</span> / {t.kuota.toLocaleString('id-ID')}</span>
                        {t.sisa_kuota === 0 && <Badge color="red">Habis</Badge>}
                      </div>
                      <ProgressBar value={terjual} max={t.kuota} color="bg-emerald-500" />
                      {dipesan > 0 && <p className="mt-1 text-[11px] text-amber-600">{dipesan} dipesan belum bayar</p>}
                    </td>
                    <td className="px-5 py-3 font-medium text-slate-700">{t.sisa_kuota.toLocaleString('id-ID')}</td>
                    <td className="px-5 py-3 text-slate-600">{t.max_per_order} tiket</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        )}
      </Card>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Tambah Jenis Tiket"
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>Batal</Button>
            <Button onClick={submit} disabled={saving}>{saving ? 'Menyimpan...' : 'Simpan'}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="Event" required>
            <Select value={form.event_id} onChange={(e) => setForm({ ...form, event_id: e.target.value, session_ids: [] })}>
              <option value="">Pilih event</option>
              {events.map((e) => <option key={e.id} value={e.id}>{e.nama_event}</option>)}
            </Select>
          </Field>
          <Field label="Nama Tiket" required>
            <Input value={form.nama_tiket} onChange={(e) => setForm({ ...form, nama_tiket: e.target.value })} placeholder="Reguler / VIP / 2-Day Pass" />
          </Field>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Field label="Harga (Rp)" required>
              <Input type="number" value={form.harga} onChange={(e) => setForm({ ...form, harga: e.target.value })} />
            </Field>
            <Field label="Kuota" required>
              <Input type="number" value={form.kuota} onChange={(e) => setForm({ ...form, kuota: e.target.value })} />
            </Field>
            <Field label="Maks/Order">
              <Input type="number" value={form.max_per_order} onChange={(e) => setForm({ ...form, max_per_order: e.target.value })} />
            </Field>
          </div>

          {sessions.length > 0 && (
            <div>
              <label className="label text-xs">Berlaku untuk Sesi (pilih &gt;1 untuk bundle)</label>
              <div className="flex flex-wrap gap-2">
                {sessions.map((s) => {
                  const checked = form.session_ids.includes(s.id)
                  return (
                    <label key={s.id} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold ${checked ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600'}`}>
                      <input type="checkbox" checked={checked} onChange={() => toggleSession(s.id)} className="h-3.5 w-3.5 rounded border-slate-300 text-brand-600" />
                      {s.label}
                    </label>
                  )
                })}
              </div>
            </div>
          )}

          {error && <p className="text-sm font-medium text-rose-600">{error}</p>}
        </div>
      </Modal>
    </div>
  )
}
