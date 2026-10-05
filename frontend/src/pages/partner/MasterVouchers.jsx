import { Plus, BadgePercent, Copy } from 'lucide-react'
import { Button, Card, PageHeader, StatusBadge, Badge, ProgressBar } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah, formatTanggal } from '../../lib/utils'

export default function MasterVouchers() {
  const { data, loading } = useApi(() => partnerApi.vouchers(), [])
  const vouchers = data?.data || []

  return (
    <div>
      <PageHeader
        title="Voucher"
        description="Kode promo dan diskon untuk eventmu."
        action={<Button><Plus size={16} /> Buat Voucher</Button>}
      />
      {loading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <div key={i} className="h-52 animate-pulse rounded-2xl bg-slate-100" />)}
        </div>
      ) : (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {vouchers.map((v) => {
          const event = v.event
          return (
            <Card key={v.id} className="p-5">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-2">
                  <span className="grid h-10 w-10 place-items-center rounded-xl bg-brand-100 text-brand-700"><BadgePercent size={18} /></span>
                  <div>
                    <p className="font-mono text-base font-bold text-slate-900">{v.kode}</p>
                    <p className="text-xs text-slate-500">{v.tipe_diskon === 'persen' ? `${v.nilai}%` : formatRupiah(v.nilai)}</p>
                  </div>
                </div>
                <StatusBadge status={v.status} />
              </div>
              <div className="mt-3 text-xs text-slate-500">
                <p>Untuk: {event ? event.nama_event : 'Semua event'}</p>
                <p>Berlaku s/d {formatTanggal(v.berlaku_sampai)}</p>
              </div>
              <div className="mt-4">
                <div className="mb-1 flex justify-between text-xs text-slate-500">
                  <span>Terpakai {v.terpakai}/{v.kuota}</span>
                  <Badge color={v.terpakai >= v.kuota ? 'red' : 'green'}>{v.terpakai >= v.kuota ? 'Habis' : 'Tersedia'}</Badge>
                </div>
                <ProgressBar value={v.terpakai} max={v.kuota} color={v.terpakai >= v.kuota ? 'bg-rose-500' : 'bg-emerald-500'} />
              </div>
              <Button variant="secondary" size="sm" className="mt-4 w-full" onClick={() => navigator.clipboard?.writeText(v.kode)}>
                <Copy size={14} /> Salin Kode
              </Button>
            </Card>
          )
        })}
      </div>
      )}
    </div>
  )
}
