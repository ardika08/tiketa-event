import { useState } from 'react'
import { Button, Card, Input, Select } from './ui'
import { partnerApi } from '../lib/api'

const wib = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', dateStyle: 'medium', timeStyle: 'short' })
const timeLabel = (value) => value ? `${wib.format(new Date(value))} WIB` : '—'

export default function CheckinSchedule({ eventId, sessions, schedules, reload }) {
  const [sessionId, setSessionId] = useState('')
  const [ticketId, setTicketId] = useState('')
  const [edit, setEdit] = useState(null)
  const [saving, setSaving] = useState(false)
  const [status, setStatus] = useState(null)
  const session = sessions.find((s) => String(s.id) === sessionId) ?? sessions[0]
  const tickets = schedules.filter((row) => Number(row.session_id) === Number(session?.id))
  const ticket = tickets.find((row) => String(row.ticket_type_id) === ticketId) ?? tickets[0]
  const persisted = { manual: Boolean(ticket?.checkin_mulai && ticket?.checkin_selesai), start: ticket?.checkin_mulai ?? '', end: ticket?.checkin_selesai ?? '' }
  const draft = edit ?? persisted

  const choose = (setter, value) => { setter(value); setEdit(null); setStatus(null) }
  const update = (patch) => { setEdit({ ...draft, ...patch }); setStatus(null) }

  const save = async (e) => {
    e.preventDefault()
    if (!session || !ticket || saving) return
    if (draft.manual && (!draft.start || !draft.end || draft.end <= draft.start)) {
      setStatus({ error: true, text: 'Isi waktu buka dan tutup. Waktu tutup harus setelah waktu buka.' })
      return
    }
    setSaving(true)
    setStatus(null)
    try {
      await partnerApi.updateCheckinSchedule(eventId, {
        session_id: session.id, ticket_type_id: ticket.ticket_type_id,
        checkin_mulai: draft.manual ? draft.start : null, checkin_selesai: draft.manual ? draft.end : null,
      })
      const refreshed = await reload()
      if (refreshed) setEdit(null)
      setStatus({ error: !refreshed, text: refreshed ? 'Jadwal check-in tersimpan.' : 'Jadwal tersimpan, tapi tampilan belum diperbarui. Muat ulang halaman.' })
    } catch (err) {
      setStatus({ error: true, text: err.message || 'Jadwal belum tersimpan. Coba lagi.' })
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card className="mb-6 p-4 sm:p-5">
      <details>
        <summary className="cursor-pointer rounded text-base font-bold text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500">Jadwal Check-in</summary>
        <form className="mt-4 space-y-4" onSubmit={save}>
          <fieldset disabled={saving} className="min-w-0 space-y-4">
            <legend className="sr-only">Pengaturan jadwal tiap jenis tiket</legend>
            {session ? (
              <label className="grid gap-1.5 text-sm font-semibold text-slate-700">
                Sesi
                <Select aria-label="Sesi" value={session.id} onChange={(e) => { choose(setSessionId, e.target.value); setTicketId('') }}>
                  {sessions.map((s) => <option key={s.id} value={s.id}>{s.label} · {s.nama_session}</option>)}
                </Select>
              </label>
            ) : <p className="text-sm text-slate-600">Tambahkan sesi di pengaturan event terlebih dahulu.</p>}
            {ticket ? (
              <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid min-w-0 gap-1.5 text-sm font-semibold text-slate-700">
                  Jenis tiket
                  <Select aria-label="Jenis tiket" value={ticket.ticket_type_id} onChange={(e) => choose(setTicketId, e.target.value)}>
                    {tickets.map((t) => <option key={t.ticket_type_id} value={t.ticket_type_id}>{t.nama_tiket}</option>)}
                  </Select>
                </label>
                <label className="grid min-w-0 gap-1.5 text-sm font-semibold text-slate-700">
                  Aturan check-in
                  <Select aria-label="Aturan check-in" value={draft.manual ? 'manual' : 'default'} onChange={(e) => update({ manual: e.target.value === 'manual' })}>
                    <option value="default">Ikuti jadwal bawaan</option>
                    <option value="manual">Jadwal manual</option>
                  </Select>
                </label>
              </div>
            ) : session && <p className="text-sm text-slate-600">Belum ada jenis tiket untuk sesi ini.</p>}
            {session && <p className="rounded-lg bg-brand-50 p-3 text-sm text-slate-700">Jadwal acara: <strong>{timeLabel(session.tanggal_mulai)} – {timeLabel(session.tanggal_selesai)}</strong>. Pengaturan ini tidak mengubah jadwal acara atau penjualan tiket.</p>}
            {ticket && draft.manual && (
              <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid min-w-0 gap-1.5 text-sm font-semibold text-slate-700">
                  Buka Check-in (WIB)
                  <Input className="min-w-0" type="datetime-local" required value={draft.start} onChange={(e) => update({ start: e.target.value })} />
                </label>
                <label className="grid min-w-0 gap-1.5 text-sm font-semibold text-slate-700">
                  Tutup Check-in (WIB)
                  <Input className="min-w-0" type="datetime-local" required value={draft.end} onChange={(e) => update({ end: e.target.value })} />
                </label>
              </div>
            )}
            {ticket && <Button type="submit" disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan jadwal'}</Button>}
          </fieldset>
          <p role={status?.error ? 'alert' : 'status'} className={status?.error ? 'text-sm text-rose-700' : 'text-sm text-emerald-700'}>{status?.text}</p>
        </form>
      </details>
    </Card>
  )
}
