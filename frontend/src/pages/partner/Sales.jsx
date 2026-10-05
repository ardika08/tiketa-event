import { useMemo, useState } from 'react'
import { Search, Ticket, TrendingUp, Wallet } from 'lucide-react'
import { Card, PageHeader, Input, Select, Stat, Badge, EmptyState, ProgressBar } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah } from '../../lib/utils'

export default function Sales() {
  const { data, loading } = useApi(() => partnerApi.sales(), [])
  const { data: eventsData } = useApi(() => partnerApi.events(), [])
  const [q, setQ] = useState('')
  const [eventName, setEventName] = useState('all')

  const rows = data?.data || []
  const summary = data?.summary || {}
  const events = eventsData?.data || []

  const filtered = useMemo(
    () =>
      rows.filter((r) => {
        const matchQ = (r.nama_tiket || '').toLowerCase().includes(q.toLowerCase())
        const matchE = eventName === 'all' || r.event === eventName
        return matchQ && matchE
      }),
    [rows, q, eventName],
  )

  const totalTerjual = filtered.reduce((s, r) => s + (r.terjual || 0), 0)
  const totalPendapatan = filtered.reduce((s, r) => s + (r.pendapatan || 0), 0)

  return (
    <div>
      <PageHeader
        title="Penjualan Tiket"
        description="Rekap tiket yang benar-benar terjual (pembayaran lunas) per jenis tiket."
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="Tiket Terjual" value={totalTerjual.toLocaleString('id-ID')} icon={Ticket} color="green" hint="Hanya order lunas" />
        <Stat label="Pendapatan Tiket" value={formatRupiah(totalPendapatan)} icon={Wallet} color="brand" />
        <Stat label="Jenis Tiket" value={filtered.length.toLocaleString('id-ID')} icon={TrendingUp} color="blue" />
      </div>

      <div className="mt-6 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
        <Badge color="green">Akurat</Badge>
        Hanya pesanan berstatus <span className="font-semibold">lunas</span> yang dihitung. Order yang belum dibayar tidak masuk rekap ini.
      </div>

      <Card className="mt-4 p-4">
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="relative flex-1">
            <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari jenis tiket..." className="pl-10" />
          </div>
          <Select value={eventName} onChange={(e) => setEventName(e.target.value)} className="sm:w-64">
            <option value="all">Semua Event</option>
            {events.map((e) => <option key={e.id} value={e.nama_event}>{e.nama_event}</option>)}
          </Select>
        </div>
      </Card>

      <Card className="mt-4">
        {loading ? (
          <div className="m-4 h-56 animate-pulse rounded-xl bg-slate-100" />
        ) : filtered.length === 0 ? (
          <EmptyState icon={Ticket} title="Belum ada penjualan" description="Tiket yang terjual (lunas) akan muncul di sini." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Jenis Tiket</th>
                  <th className="px-5 py-3 font-semibold">Event</th>
                  <th className="px-5 py-3 font-semibold">Harga</th>
                  <th className="px-5 py-3 font-semibold">Terjual / Kuota</th>
                  <th className="px-5 py-3 font-semibold">Sisa</th>
                  <th className="px-5 py-3 font-semibold">Pendapatan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map((r) => (
                  <tr key={r.id} className="hover:bg-slate-50">
                    <td className="px-5 py-3">
                      <p className="font-semibold text-slate-800">{r.nama_tiket}</p>
                      {r.status === 'nonaktif' && <Badge color="slate" className="mt-1">Nonaktif</Badge>}
                    </td>
                    <td className="px-5 py-3 text-slate-600">{r.event}</td>
                    <td className="px-5 py-3 font-medium text-slate-700">{formatRupiah(r.harga)}</td>
                    <td className="px-5 py-3 w-52">
                      <div className="mb-1 flex justify-between text-xs text-slate-500">
                        <span className="font-semibold text-slate-700">{(r.terjual || 0).toLocaleString('id-ID')} terjual</span>
                        <span>dari {(r.kuota || 0).toLocaleString('id-ID')}</span>
                      </div>
                      <ProgressBar value={r.terjual || 0} max={r.kuota || 1} color="bg-emerald-500" />
                    </td>
                    <td className="px-5 py-3 text-slate-600">{r.sisa_kuota.toLocaleString('id-ID')}</td>
                    <td className="px-5 py-3 font-semibold text-slate-800">{formatRupiah(r.pendapatan || 0)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}