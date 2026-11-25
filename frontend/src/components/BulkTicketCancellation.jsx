import { useEffect, useRef, useState } from 'react'
import { Button, Input, Modal, Select } from './ui'
import { partnerApi } from '../lib/api'

const DEFAULT_REASON = 'Tiket dibatalkan oleh penyelenggara.'
const emailStatus = { pending: 'Menunggu pengiriman', queued: 'Menunggu pengiriman', sending: 'Sedang dikirim', sent: 'Diterima penyedia email', failed: 'Gagal dikirim', uncertain: 'Perlu cek di Mailketing' }
const ticketStatus = { cancelled: 'Dibatalkan', checked_in: 'Sudah check-in', ineligible: 'Tidak memenuhi syarat pembatalan' }
const sessionText = (value) => Array.isArray(value) ? value.join(', ') : value

export default function BulkTicketCancellation({ events, onClose }) {
  const [eventId, setEventId] = useState('')
  const [query, setQuery] = useState('')
  const [data, setData] = useState(null)
  const [selected, setSelected] = useState({})
  const [mode, setMode] = useState('default')
  const [custom, setCustom] = useState('')
  const [internal, setInternal] = useState('')
  const [step, setStep] = useState('select')
  const [results, setResults] = useState([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const active = useRef(false)
  const requestId = useRef(null)
  const sequence = useRef(0)
  const reason = (mode === 'default' ? DEFAULT_REASON : custom).trim()
  const picks = Object.values(selected)
  const orders = Object.values(picks.reduce((groups, { order, ticket }) => {
    groups[order.id] ??= { order, tickets: [] }
    groups[order.id].tickets.push(ticket)
    return groups
  }, {}))
  const eventName = events.find((event) => String(event.id) === eventId)?.nama_event
  const tooMany = orders.length > 25 || picks.length > 250 || orders.some(({ tickets }) => tickets.length > 100)

  async function load(id, text) {
    if (!id) return
    const token = ++sequence.current
    try {
      const value = await partnerApi.ticketCancellationList(id, text.trim())
      if (token === sequence.current) setData(value)
    } catch (err) {
      if (token === sequence.current) setError(err.message)
    }
  }

  useEffect(() => {
    if (!eventId) return
    const timer = setTimeout(() => { void load(eventId, query) }, 250)
    return () => { clearTimeout(timer); sequence.current++ }
  }, [eventId, query])

  const setEvent = (value) => {
    sequence.current++
    setEventId(value); setQuery(''); setData(null); setSelected({}); setResults([]); setError('')
  }
  const toggle = (order, ticket) => setSelected((state) => {
    const next = { ...state }
    if (next[ticket.id]) delete next[ticket.id]
    else next[ticket.id] = { order, ticket }
    return next
  })

  async function execute() {
    if (active.current || !picks.length || !reason || !requestId.current || tooMany) return
    active.current = true; setBusy(true); setError('')
    try {
      const res = await partnerApi.cancelTickets(eventId, {
        orders: orders.map(({ order, tickets }) => ({ order_id: order.id, ticket_ids: tickets.map(({ id }) => id) })),
        reason, internal_note: internal.trim() || null, request_id: requestId.current,
      })
      setResults(res.results ?? [])
      if (res.results?.some((result) => result.status === 'failed')) {
        setError('Sebagian hasil belum bisa dipastikan. Ulangi konfirmasi untuk memeriksa pesanan yang sama tanpa membatalkan tiket dua kali.')
      } else { setStep('result'); setSelected({}) }
      await load(eventId, query)
    } catch (err) {
      setError(err.message)
    } finally { active.current = false; setBusy(false) }
  }

  async function retry(id) {
    if (active.current) return
    active.current = true; setBusy(true); setError('')
    try {
      await partnerApi.retryCancellationEmail(eventId, id)
      await load(eventId, query)
    } catch (err) { setError(err.message) }
    finally { active.current = false; setBusy(false) }
  }

  const notifications = data?.notifications ?? []
  return <Modal open onClose={() => { if (!active.current) onClose() }} title="Batalkan tiket" size="xl" footer={<>
    <Button variant="secondary" disabled={busy} onClick={() => {
      if (step !== 'select') { setStep('select'); setError('') }
      else onClose()
    }}>{step === 'select' ? 'Tutup' : 'Kembali'}</Button>
    {step === 'select' && <Button disabled={!picks.length || !reason || busy || tooMany} onClick={() => {
      requestId.current = crypto.randomUUID(); setStep('preview'); setError('')
    }}>Cek pembatalan</Button>}
    {step === 'preview' && <Button variant="danger" disabled={busy} onClick={execute}>{busy ? 'Memproses…' : 'Konfirmasi pembatalan'}</Button>}
  </>}>
    <div className="space-y-4">
      {error && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}
      {step === 'select' && <>
        <label className="grid gap-1 text-sm font-semibold">Event<Select value={eventId} onChange={(e) => setEvent(e.target.value)}><option value="">Pilih event</option>{events.map((event) => <option key={event.id} value={event.id}>{event.nama_event}</option>)}</Select></label>
        {eventId && <>
          <label className="grid gap-1 text-sm font-semibold">Cari pesanan<Input value={query} maxLength={120} onChange={(e) => { sequence.current++; setQuery(e.target.value); setData(null); setError('') }} placeholder="Kode pesanan, nama, atau email" /></label>
          <p className="text-sm text-slate-600" role="status">{orders.length} pesanan · {picks.length} tiket dipilih. Pilihan tetap tersimpan saat pencarian berubah.</p>
          {tooMany && <p role="alert" className="text-sm text-rose-700">Maksimal 25 pesanan, 250 tiket per proses, dan 100 tiket per pesanan.</p>}
          <div className="max-h-64 space-y-2 overflow-y-auto">
            {!data && !error && <p className="text-sm text-slate-600">Memuat pesanan…</p>}
            {data && !data.orders?.length && <p className="text-sm text-slate-600">Tidak ada pesanan yang cocok.</p>}
            {(data?.orders ?? []).map((order) => <fieldset key={order.id} className="min-w-0 rounded-lg border border-slate-200 px-3 pb-2">
              <legend className="max-w-full break-words px-1 text-sm font-bold">{order.kode_order} · {order.nama}</legend>
              {order.tickets.map((ticket) => <label key={ticket.id} className="flex items-start gap-2 py-1.5 text-sm">
                <input type="checkbox" className="mt-0.5 h-4 w-4 shrink-0" checked={Boolean(selected[ticket.id])} disabled={!ticket.eligible} onChange={() => toggle(order, ticket)} />
                <span className="min-w-0 break-words">{ticket.nama_tiket}{sessionText(ticket.sesi) ? ` · ${sessionText(ticket.sesi)}` : ''} <span className="text-slate-600">#{ticket.id}{!ticket.eligible ? ` · ${ticketStatus[ticket.status] || 'Tidak dapat dibatalkan'}` : ''}</span></span>
              </label>)}
            </fieldset>)}
          </div>
          <label className="grid gap-1 text-sm font-semibold">Alasan untuk pembeli<Select value={mode} onChange={(e) => setMode(e.target.value)}><option value="default">Pembatalan oleh penyelenggara</option><option value="custom">Tulis alasan sendiri</option></Select></label>
          {mode === 'custom' ? <label className="grid gap-1 text-sm font-semibold">Alasan yang dikirim<textarea className="input min-h-20" value={custom} maxLength={500} onChange={(e) => setCustom(e.target.value)} /></label> : <p className="text-sm text-slate-600">{DEFAULT_REASON}</p>}
          <label className="grid gap-1 text-sm font-semibold">Catatan internal (opsional)<textarea className="input min-h-16" value={internal} maxLength={1000} onChange={(e) => setInternal(e.target.value)} /></label>
          <p className="text-xs text-slate-600">Catatan internal tidak dikirim ke pembeli.</p>
        </>}
      </>}
      {step === 'preview' && <>
        <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><strong>{picks.length} tiket dari {orders.length} pesanan</strong> akan dibatalkan. QR tiket yang dipilih tidak dapat digunakan. Tiket lain tetap berlaku. Pembatalan ini tidak mengembalikan dana secara otomatis.</p>
        <div className="max-h-80 space-y-2 overflow-y-auto" aria-label="Rincian pembatalan dan email">
          {orders.map(({ order, tickets }) => <details key={order.id} className="rounded-lg border border-slate-200 p-3 text-sm" open>
            <summary className="cursor-pointer font-semibold">{order.kode_order} · {tickets.length} tiket · Preview email</summary>
            <div className="mt-2 space-y-2 break-words">
              <p><strong>Subjek:</strong> Pembatalan tiket {order.kode_order} — Nontix</p>
              <p className="font-semibold">Pembatalan tiket</p>
              <p>QR berikut untuk {eventName} (pesanan {order.kode_order}) telah dibatalkan dan tidak bisa dipakai untuk masuk:</p>
              <ul className="list-disc pl-5">{[...tickets].sort((a, b) => a.id - b.id).map((ticket) => <li key={ticket.id}>{ticket.nama_tiket} — {sessionText(ticket.sesi)}</li>)}</ul>
              <p><strong>Alasan:</strong> {reason}</p>
              <p>Tiket lain dalam pesanan ini tidak terpengaruh oleh pembatalan ini.</p>
            </div>
          </details>)}
        </div>
      </>}
      {step === 'result' && <div role="status"><h3 className="font-bold">Hasil pembatalan</h3><ul className="mt-2 space-y-1 text-sm">{results.map((result) => <li key={result.order_id}>{result.kode_order || `Pesanan #${result.order_id}`} — {result.message || result.status}</li>)}</ul></div>}
      {step !== 'preview' && notifications.length > 0 && <section className="space-y-2 border-t border-slate-200 pt-3" aria-label="Status email pembatalan">
        <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold">Status email pembatalan</h3><Button variant="secondary" size="sm" disabled={busy} onClick={() => { void load(eventId, query) }}>Perbarui status</Button></div>
        <ul className="max-h-44 space-y-2 overflow-y-auto">{notifications.map((entry) => <li key={entry.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 p-2 text-sm"><span>{entry.kode_order} · {emailStatus[entry.status] || entry.status}</span>{entry.status === 'failed' && <Button variant="secondary" size="sm" disabled={busy} onClick={() => retry(entry.id)}>Kirim ulang email</Button>}</li>)}</ul>
      </section>}
    </div>
  </Modal>
}
