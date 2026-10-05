import { useMemo, useState } from 'react'
import { Search, CalendarDays } from 'lucide-react'
import { Card, PageHeader, Input, Select, StatusBadge, EmptyState } from '../../components/ui'
import { adminApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah } from '../../lib/utils'

export default function AdminEvents() {
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('all')
  const { data, loading } = useApi(() => adminApi.events(), [])
  const events = data?.data || []

  const filtered = useMemo(
    () =>
      events.filter((e) => {
        const matchQ =
          e.nama_event.toLowerCase().includes(q.toLowerCase()) ||
          (e.organizer || '').toLowerCase().includes(q.toLowerCase())
        const matchS = status === 'all' || e.status === status
        return matchQ && matchS
      }),
    [events, q, status],
  )

  return (
    <div>
      <PageHeader title="Ringkasan Semua Event" description="Seluruh acara dan penjualan tiket di platform." />

      <Card className="mb-4 p-4">
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="relative flex-1">
            <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari event atau penyelenggara..." className="pl-10" />
          </div>
          <Select value={status} onChange={(e) => setStatus(e.target.value)} className="sm:w-48">
            <option value="all">Semua Status</option>
            <option value="draft">Draf</option>
            <option value="aktif">Aktif</option>
            <option value="selesai">Selesai</option>
          </Select>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="m-4 h-56 animate-pulse rounded-xl bg-slate-100" />
        ) : filtered.length === 0 ? (
          <EmptyState icon={CalendarDays} title="Tidak ada event" description="Coba ubah filter pencarian." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Event</th>
                  <th className="px-5 py-3 font-semibold">Penyelenggara</th>
                  <th className="px-5 py-3 font-semibold">Pesanan Lunas</th>
                  <th className="px-5 py-3 font-semibold">Pendapatan</th>
                  <th className="px-5 py-3 font-semibold">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map((e) => (
                  <tr key={e.id} className="hover:bg-slate-50">
                    <td className="px-5 py-3">
                      <p className="font-semibold text-slate-800">{e.nama_event}</p>
                      <p className="text-xs text-slate-400">/{e.slug}</p>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{e.organizer}</td>
                    <td className="px-5 py-3 text-slate-600">{(e.order_lunas || 0).toLocaleString('id-ID')}</td>
                    <td className="px-5 py-3 font-semibold text-slate-800">{formatRupiah(e.pendapatan || 0)}</td>
                    <td className="px-5 py-3"><StatusBadge status={e.status} /></td>
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
