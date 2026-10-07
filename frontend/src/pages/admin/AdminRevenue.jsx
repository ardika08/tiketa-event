import { useMemo } from 'react'
import { Wallet, Ticket, TrendingUp } from 'lucide-react'
import { Card, PageHeader, Stat, Badge } from '../../components/ui'
import { adminApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { BIAYA_LAYANAN } from '../../lib/constants'
import { formatRupiah } from '../../lib/utils'

export default function AdminRevenue() {
  const { data } = useApi(() => adminApi.revenue(), [])
  const { data: eventsData } = useApi(() => adminApi.events(), [])

  const summary = data?.summary || {}
  const mitra = data?.mitra || []
  const events = eventsData?.data || []

  const perEvent = useMemo(
    () => [...events].sort((a, b) => (b.pendapatan || 0) - (a.pendapatan || 0)),
    [events],
  )
  const perMitra = useMemo(
    () => [...mitra].sort((a, b) => (b.pendapatan_platform || 0) - (a.pendapatan_platform || 0)),
    [mitra],
  )

  return (
    <div>
      <PageHeader title="Pendapatan Platform" description="Pemasukan dari biaya layanan tiap tiket terjual." />

      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="Total Pendapatan" value={formatRupiah(summary.pendapatan_platform || 0)} icon={Wallet} color="green" />
        <Stat label="Total Tiket Terjual" value={(summary.tiket_terjual || 0).toLocaleString('id-ID')} icon={Ticket} color="brand" />
        <Stat label="Tarif Berlaku" value={formatRupiah(BIAYA_LAYANAN)} icon={TrendingUp} color="amber" hint="per tiket terjual" />
      </div>

      <div className="mt-6 flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800">
        <Badge color="brand">Info</Badge>
        Biaya layanan Nontix {formatRupiah(BIAYA_LAYANAN)}/tiket dipotong otomatis dari harga tiket penyelenggara.
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <Card>
          <div className="border-b border-slate-100 px-5 py-4">
            <h2 className="font-bold text-slate-900">Pendapatan per Event</h2>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Event</th>
                  <th className="px-5 py-3 font-semibold">Pesanan</th>
                  <th className="px-5 py-3 font-semibold">Pendapatan Kotor</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {perEvent.map((r) => (
                  <tr key={r.id} className="hover:bg-slate-50">
                    <td className="px-5 py-3">
                      <p className="font-medium text-slate-800">{r.nama_event}</p>
                      <p className="text-xs text-slate-400">{r.organizer}</p>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{(r.order_lunas || 0).toLocaleString('id-ID')}</td>
                    <td className="px-5 py-3 font-semibold text-emerald-600">{formatRupiah(r.pendapatan || 0)}</td>
                  </tr>
                ))}
                {perEvent.length === 0 && (
                  <tr><td colSpan={3} className="px-5 py-4 text-sm text-slate-400">Belum ada data.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </Card>

        <Card>
          <div className="border-b border-slate-100 px-5 py-4">
            <h2 className="font-bold text-slate-900">Pendapatan per Mitra</h2>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Mitra</th>
                  <th className="px-5 py-3 font-semibold">Event</th>
                  <th className="px-5 py-3 font-semibold">Biaya Layanan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {perMitra.map((r) => (
                  <tr key={r.id} className="hover:bg-slate-50">
                    <td className="px-5 py-3 font-medium text-slate-800">{r.nama_penyelenggara}</td>
                    <td className="px-5 py-3 text-slate-600">{r.jumlah_event}</td>
                    <td className="px-5 py-3 font-semibold text-emerald-600">{formatRupiah(r.pendapatan_platform || 0)}</td>
                  </tr>
                ))}
                {perMitra.length === 0 && (
                  <tr><td colSpan={3} className="px-5 py-4 text-sm text-slate-400">Belum ada data.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </Card>
      </div>
    </div>
  )
}
