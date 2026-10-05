import { useMemo } from 'react'
import {
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from 'recharts'
import { Link } from 'react-router-dom'
import { CalendarDays, Handshake, TrendingUp, Ticket, ArrowRight, Clock } from 'lucide-react'
import { Card, PageHeader, Stat, StatusBadge, Badge, ProgressBar } from '../../components/ui'
import { adminApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah } from '../../lib/utils'

function ChartTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null
  return (
    <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-lg">
      <p className="text-xs font-semibold text-slate-500">{label}</p>
      <p className="text-sm font-bold text-brand-700">{formatRupiah(payload[0].value)}</p>
    </div>
  )
}

export default function AdminDashboard() {
  const { data } = useApi(() => adminApi.dashboard(), [])
  const { data: eventsData } = useApi(() => adminApi.events(), [])
  const { data: partnersData } = useApi(() => adminApi.partners(), [])

  const summary = data?.summary || {}
  const trend = useMemo(
    () => (data?.trend || []).map((t) => ({ ...t, tanggal: (t.tanggal || '').slice(5) })),
    [data],
  )
  const events = eventsData?.data || []
  const partners = partnersData?.data || []
  const topPartners = useMemo(
    () => [...partners].sort((a, b) => b.jumlah_event - a.jumlah_event).filter((o) => o.jumlah_event > 0),
    [partners],
  )
  const maxEvent = Math.max(1, ...topPartners.map((o) => o.jumlah_event))

  return (
    <div>
      <PageHeader title="Ringkasan Platform" description="Pantau seluruh aktivitas Nontix dalam satu tampilan." />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Total Event" value={(summary.total_event || 0).toLocaleString('id-ID')} icon={CalendarDays} color="brand" hint={`${summary.event_aktif || 0} event aktif`} />
        <Stat label="Mitra Terdaftar" value={(summary.total_mitra || 0).toLocaleString('id-ID')} icon={Handshake} color="blue" hint={`${summary.mitra_terverifikasi || 0} terverifikasi`} />
        <Stat label="Tiket Terjual" value={(summary.tiket_terjual || 0).toLocaleString('id-ID')} icon={Ticket} color="green" hint="Hanya pesanan lunas" />
        <Stat label="Pendapatan Biaya Layanan" value={formatRupiah(summary.pendapatan_platform || 0)} icon={TrendingUp} color="amber" hint="Rp 2.000/tiket lunas" />
      </div>

      {(summary.transaksi_pending || 0) > 0 && (
        <div className="mt-4 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Clock size={16} className="shrink-0" />
          <span className="font-semibold">{(summary.transaksi_pending || 0).toLocaleString('id-ID')} pesanan menunggu pembayaran</span>
          <span className="text-amber-700">senilai {formatRupiah(summary.nilai_pending || 0)} — tidak dihitung sebagai penjualan.</span>
        </div>
      )}

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <Card className="min-w-0 p-5 lg:col-span-2">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h2 className="font-bold text-slate-900">Pendapatan Platform</h2>
              <p className="text-sm text-slate-500">Pendapatan harian dari biaya layanan</p>
            </div>
            <Badge color="brand">Biaya Rp 2.000/tiket</Badge>
          </div>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={trend} margin={{ left: -10, right: 10, top: 10 }}>
                <defs>
                  <linearGradient id="adminFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#0ea5e9" stopOpacity={0.35} />
                    <stop offset="95%" stopColor="#0ea5e9" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={false} />
                <XAxis dataKey="tanggal" tick={{ fontSize: 12, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 12, fill: '#94a3b8' }} axisLine={false} tickLine={false} tickFormatter={(v) => `${v / 1000000}jt`} />
                <Tooltip content={<ChartTooltip />} />
                <Area type="monotone" dataKey="pendapatan" stroke="#0ea5e9" strokeWidth={2.5} fill="url(#adminFill)" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card className="p-5">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="font-bold text-slate-900">Mitra Teratas</h2>
            <Link to="/admin/mitra" className="text-sm font-medium text-brand-600 hover:underline">Lihat semua</Link>
          </div>
          <div className="space-y-4">
            {topPartners.map((o) => (
              <div key={o.id}>
                <div className="mb-1.5 flex items-center justify-between gap-2">
                  <div className="flex items-center gap-2 min-w-0">
                    <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">{o.nama_penyelenggara.charAt(0)}</span>
                    <span className="truncate text-sm font-medium text-slate-700">{o.nama_penyelenggara}</span>
                  </div>
                  <span className="shrink-0 text-xs font-semibold text-slate-500">{o.jumlah_event} event</span>
                </div>
                <ProgressBar value={o.jumlah_event} max={maxEvent} color="bg-brand-600" />
              </div>
            ))}
            {topPartners.length === 0 && <p className="text-sm text-slate-400">Belum ada mitra dengan event.</p>}
          </div>
        </Card>
      </div>

      <Card className="mt-6">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <h2 className="font-bold text-slate-900">Event Terbaru</h2>
          <Link to="/admin/event" className="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:underline">
            Semua event <ArrowRight size={14} />
          </Link>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[680px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-5 py-3 font-semibold">Event</th>
                <th className="px-5 py-3 font-semibold">Penyelenggara</th>
                <th className="px-5 py-3 font-semibold">Pesanan Lunas</th>
                <th className="px-5 py-3 font-semibold">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {events.slice(0, 5).map((e) => (
                <tr key={e.id} className="hover:bg-slate-50">
                  <td className="px-5 py-3 font-medium text-slate-800">{e.nama_event}</td>
                  <td className="px-5 py-3 text-slate-600">{e.organizer}</td>
                  <td className="px-5 py-3 text-slate-600">{(e.order_lunas || 0).toLocaleString('id-ID')}</td>
                  <td className="px-5 py-3"><StatusBadge status={e.status} /></td>
                </tr>
              ))}
              {events.length === 0 && (
                <tr><td colSpan={4} className="px-5 py-4 text-sm text-slate-400">Belum ada event.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  )
}
