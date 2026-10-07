import { useMemo, useState } from 'react'
import {
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from 'recharts'
import { Ticket, Wallet, ShoppingCart, TrendingUp, CalendarDays } from 'lucide-react'
import { Card, PageHeader, Stat, StatusBadge, ProgressBar } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah, formatTanggal } from '../../lib/utils'
import { BIAYA_LAYANAN } from '../../lib/constants'

function ChartTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null
  return (
    <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-lg">
      <p className="text-xs font-semibold text-slate-500">{label}</p>
      <p className="text-sm font-bold text-brand-700">{formatRupiah(payload[0].value)}</p>
      <p className="text-xs text-slate-500">{payload[0].payload.order} pesanan</p>
    </div>
  )
}

export default function Analytics() {
  const [range, setRange] = useState('7d')
  const days = range === '30d' ? 30 : 7

  const { data, loading } = useApi(() => partnerApi.analytics({ days }), [range])
  const { data: buyersData } = useApi(() => partnerApi.buyers(), [])

  const summary = data?.summary || {}
  const events = data?.events || []
  const buyers = buyersData?.data || []
  const trend = useMemo(
    () => (data?.trend || []).map((t) => ({ ...t, tanggal: (t.tanggal || '').slice(5) })),
    [data],
  )
  const maxPendapatan = Math.max(1, ...events.map((e) => e.pendapatan || 0))

  return (
    <div>
      <PageHeader
        title="Analisis"
        description="Ringkasan performa penjualan tiket eventmu."
        action={
          <select value={range} onChange={(e) => setRange(e.target.value)} className="input w-auto">
            <option value="7d">7 hari terakhir</option>
            <option value="30d">30 hari terakhir</option>
          </select>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Tiket Terjual" value={(summary.tiket_terjual || 0).toLocaleString('id-ID')} icon={Ticket} color="brand" />
        <Stat label="Pendapatan Kotor" value={formatRupiah(summary.pendapatan_kotor || 0)} icon={Wallet} color="green" />
        <Stat label="Pesanan Lunas" value={(summary.jumlah_order || 0).toLocaleString('id-ID')} icon={ShoppingCart} color="blue" />
        <Stat label="Biaya Layanan" value={formatRupiah(summary.biaya_layanan || 0)} icon={TrendingUp} color="amber" hint={`${formatRupiah(BIAYA_LAYANAN)}/tiket`} />
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <Card className="min-w-0 p-5 lg:col-span-2">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h2 className="font-bold text-slate-900">Tren Penjualan</h2>
              <p className="text-sm text-slate-500">Pendapatan harian pada periode terpilih</p>
            </div>
            <CalendarDays size={18} className="text-slate-400" />
          </div>
          <div className="h-72">
            {loading ? (
              <div className="h-full animate-pulse rounded-xl bg-slate-100" />
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={trend} margin={{ left: -10, right: 10, top: 10 }}>
                  <defs>
                    <linearGradient id="fill" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="#0362fd" stopOpacity={0.35} />
                      <stop offset="95%" stopColor="#0362fd" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={false} />
                  <XAxis dataKey="tanggal" tick={{ fontSize: 12, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                  <YAxis tick={{ fontSize: 12, fill: '#94a3b8' }} axisLine={false} tickLine={false} tickFormatter={(v) => `${v / 1000000}jt`} />
                  <Tooltip content={<ChartTooltip />} />
                  <Area type="monotone" dataKey="pendapatan" stroke="#0362fd" strokeWidth={2.5} fill="url(#fill)" />
                </AreaChart>
              </ResponsiveContainer>
            )}
          </div>
        </Card>

        <Card className="p-5">
          <h2 className="font-bold text-slate-900">Event Teratas</h2>
          <p className="text-sm text-slate-500">Berdasarkan pendapatan</p>
          <div className="mt-4 space-y-4">
            {events.slice(0, 4).map((e) => (
              <div key={e.id}>
                <div className="mb-1.5 flex items-center justify-between gap-2 text-sm">
                  <span className="truncate font-medium text-slate-700">{e.nama_event}</span>
                  <span className="shrink-0 font-semibold text-slate-500">{formatRupiah(e.pendapatan)}</span>
                </div>
                <ProgressBar value={e.pendapatan} max={maxPendapatan} />
              </div>
            ))}
            {events.length === 0 && <p className="text-sm text-slate-400">Belum ada data penjualan.</p>}
          </div>
        </Card>
      </div>

      <Card className="mt-6">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <h2 className="font-bold text-slate-900">Pesanan Terbaru</h2>
        </div>
        <div className="divide-y divide-slate-100">
          {buyers.slice(0, 5).map((b) => (
            <div key={b.id} className="flex items-center gap-4 px-5 py-3.5">
              <div className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-100 text-sm font-bold text-brand-700">
                {b.nama.charAt(0)}
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-slate-800">{b.nama}</p>
                <p className="truncate text-xs text-slate-500">{b.event}</p>
              </div>
              <div className="hidden text-right sm:block">
                <p className="text-sm font-semibold text-slate-800">{formatRupiah(b.total)}</p>
                <p className="text-xs text-slate-400">{formatTanggal(b.tanggal)}</p>
              </div>
              <StatusBadge status={b.status} />
            </div>
          ))}
          {buyers.length === 0 && <p className="px-5 py-4 text-sm text-slate-400">Belum ada pesanan.</p>}
        </div>
      </Card>
    </div>
  )
}
