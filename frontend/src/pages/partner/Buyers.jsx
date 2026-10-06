import { Fragment, useMemo, useState } from 'react'
import { Search, Download, Users, ChevronLeft, ChevronRight, ChevronDown } from 'lucide-react'
import { Button, Card, Input, PageHeader, Select, StatusBadge, EmptyState } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah, formatTanggal } from '../../lib/utils'

const PER_PAGE = 6

/** Panel jawaban formulir custom untuk satu order (mendukung format baru & lama). */
function FormDataPanel({ order }) {
  const fd = order?.form_data
  if (!fd || typeof fd !== 'object') {
    return <p className="text-sm text-slate-400">Tidak ada jawaban formulir untuk pesanan ini.</p>
  }

  const sections = []
  if (fd.order && typeof fd.order === 'object' && Object.keys(fd.order).length) {
    sections.push({ title: 'Data pembeli', entries: Object.entries(fd.order) })
  }
  if (fd.tickets && typeof fd.tickets === 'object') {
    for (const [tid, answers] of Object.entries(fd.tickets)) {
      if (answers && typeof answers === 'object' && Object.keys(answers).length) {
        sections.push({ title: order.ticket_names?.[tid] || `Jenis tiket #${tid}`, entries: Object.entries(answers) })
      }
    }
  }
  // Format lama (sebelum restrukturisasi): kunci flat di level atas.
  const reserved = ['order', 'tickets']
  const legacy = Object.entries(fd).filter(
    ([k, v]) => !reserved.includes(k) && v !== null && typeof v !== 'object' && String(v).trim() !== '',
  )
  if (legacy.length) sections.push({ title: 'Lainnya', entries: legacy })

  if (!sections.length) {
    return <p className="text-sm text-slate-400">Tidak ada jawaban formulir untuk pesanan ini.</p>
  }

  return (
    <div className="space-y-3">
      {sections.map((s) => (
        <div key={s.title}>
          <p className="text-xs font-bold uppercase tracking-wide text-slate-400">{s.title}</p>
          <dl className="mt-1.5 space-y-1">
            {s.entries.map(([label, value]) => (
              <div key={label} className="flex gap-2 text-sm">
                <dt className="w-48 shrink-0 text-slate-500">{label}</dt>
                <dd className="min-w-0 font-medium text-slate-700">{String(value) || '—'}</dd>
              </div>
            ))}
          </dl>
        </div>
      ))}
    </div>
  )
}

export default function Buyers() {
  const [q, setQ] = useState('')
  const [eventName, setEventName] = useState('all')
  const [status, setStatus] = useState('all')
  const [expanded, setExpanded] = useState(null)
  const [page, setPage] = useState(1)

  const { data, loading } = useApi(() => partnerApi.buyers(), [])
  const { data: eventsData } = useApi(() => partnerApi.events(), [])
  const buyers = data?.data || []
  const events = eventsData?.data || []

  const filtered = useMemo(() => {
    return buyers.filter((b) => {
      const matchQ = b.nama.toLowerCase().includes(q.toLowerCase()) || b.email.toLowerCase().includes(q.toLowerCase())
      const matchE = eventName === 'all' || b.event === eventName
      const matchS = status === 'all' || b.status === status
      return matchQ && matchE && matchS
    })
  }, [buyers, q, eventName, status])

  const jumlahLunas = useMemo(() => buyers.filter((b) => b.status === 'lunas').length, [buyers])

  const totalPages = Math.max(1, Math.ceil(filtered.length / PER_PAGE))
  const current = Math.min(page, totalPages)
  const rows = filtered.slice((current - 1) * PER_PAGE, current * PER_PAGE)

  return (
    <div>
      <PageHeader
        title="Data Pembeli"
        description={`Semua pesanan tiket beserta kontaknya. ${jumlahLunas} dari ${buyers.length} sudah lunas. Klik baris untuk melihat jawaban formulir.`}
        action={<Button variant="secondary"><Download size={16} /> Ekspor</Button>}
      />

      <Card className="mb-4 p-4">
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="relative flex-1">
            <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <Input value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} placeholder="Cari nama atau email..." className="pl-10" />
          </div>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} className="sm:w-48">
            <option value="all">Semua Status</option>
            <option value="lunas">Lunas</option>
            <option value="pending">Menunggu Pembayaran</option>
            <option value="dibatalkan">Dibatalkan</option>
            <option value="kadaluarsa">Kadaluarsa</option>
          </Select>
          <Select value={eventName} onChange={(e) => { setEventName(e.target.value); setPage(1) }} className="sm:w-64">
            <option value="all">Semua Event</option>
            {events.map((e) => <option key={e.id} value={e.nama_event}>{e.nama_event}</option>)}
          </Select>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="m-4 h-56 animate-pulse rounded-xl bg-slate-100" />
        ) : rows.length === 0 ? (
          <EmptyState icon={Users} title="Tidak ada pembeli" description="Coba ubah kata kunci atau filter event." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Pembeli</th>
                  <th className="px-5 py-3 font-semibold">Kontak</th>
                  <th className="px-5 py-3 font-semibold">Tiket</th>
                  <th className="px-5 py-3 font-semibold">Total</th>
                  <th className="px-5 py-3 font-semibold">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((b) => (
                  <Fragment key={b.id}>
                    <tr
                      className={`cursor-pointer hover:bg-slate-50 ${expanded === b.id ? 'bg-slate-50/60' : ''}`}
                      onClick={() => setExpanded((cur) => (cur === b.id ? null : b.id))}
                    >
                      <td className="px-5 py-3">
                        <p className="font-semibold text-slate-800">{b.nama}</p>
                        <p className="font-mono text-xs text-slate-400">{b.kode_order}</p>
                      </td>
                      <td className="px-5 py-3">
                        <p className="text-slate-600">{b.email}</p>
                        <p className="text-xs text-slate-400">{b.whatsapp}</p>
                      </td>
                      <td className="px-5 py-3">
                        <p className="text-slate-700">{b.tiket}</p>
                        <p className="text-xs text-slate-400">{b.jumlah} tiket · {b.event}</p>
                      </td>
                      <td className="px-5 py-3 font-semibold text-slate-800">{formatRupiah(b.total)}</td>
                      <td className="px-5 py-3">
                        <div className="flex items-center gap-1.5">
                          <StatusBadge status={b.status} />
                          <ChevronDown
                            size={15}
                            className={`shrink-0 text-slate-400 transition-transform ${expanded === b.id ? 'rotate-180' : ''}`}
                          />
                        </div>
                        <p className="mt-1 text-xs text-slate-400">{formatTanggal(b.tanggal)}</p>
                        {b.status === 'pending' && b.batas_bayar && (
                          <p className="mt-0.5 text-xs text-amber-600">Bayar s/d {formatTanggal(b.batas_bayar, { withTime: true })}</p>
                        )}
                      </td>
                    </tr>
                    {expanded === b.id && (
                      <tr>
                        <td colSpan={5} className="bg-brand-50/40 px-5 py-4">
                          <FormDataPanel order={b} />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {filtered.length > 0 && (
          <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3.5">
            <p className="text-sm text-slate-500">
              Menampilkan <span className="font-medium text-slate-700">{rows.length}</span> dari {filtered.length} pembeli
            </p>
            <div className="flex items-center gap-1">
              <Button variant="ghost" size="sm" disabled={current <= 1} onClick={() => setPage(current - 1)}>
                <ChevronLeft size={16} />
              </Button>
              <span className="px-2 text-sm font-medium text-slate-600">{current} / {totalPages}</span>
              <Button variant="ghost" size="sm" disabled={current >= totalPages} onClick={() => setPage(current + 1)}>
                <ChevronRight size={16} />
              </Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  )
}
