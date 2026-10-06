import { useEffect } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Printer, ArrowLeft, Info } from 'lucide-react'
import { Button, Card, Badge, EmptyState } from '../../components/ui'
import { groupItemsBySession } from '../../lib/ticketGroups'
import { formatRupiah, formatTanggal } from '../../lib/utils'
import { useOrder } from '../../context/OrderContext'
import { paymentProviderLabel } from '../../lib/paymentProvider'

export default function Invoice() {
  const { order, refreshOrder } = useOrder()
  const [searchParams] = useSearchParams()

  useEffect(() => {
    const kode = searchParams.get('kode')
    if (kode) {
      refreshOrder(kode).catch(() => {})
    } else if (!order) {
      refreshOrder().catch(() => {})
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (!order) {
    return (
      <div className="container-page py-20">
        <Card>
          <EmptyState
            icon={Info}
            title="Invoice tidak ditemukan"
            description="Invoice akan tersedia setelah kamu melakukan pembelian."
            action={<Link to="/"><Button>Jelajahi Event</Button></Link>}
          />
        </Card>
      </div>
    )
  }

  const itemGroups = groupItemsBySession(order.event, order.items)

  return (
    <div className="container-page max-w-3xl py-8">
      <div className="mb-4 flex items-center justify-between print:hidden">
        <Link to="/" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-700">
          <ArrowLeft size={16} /> Kembali
        </Link>
        <Button variant="secondary" size="sm" onClick={() => window.print()}><Printer size={15} /> Cetak Invoice</Button>
      </div>

      <Card className="p-6 sm:p-8">
        <div className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-6">
          <div>
            <div className="flex items-center gap-2">
              <span className="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-accent-500 text-sm font-bold text-white">N</span>
              <span className="text-xl font-extrabold text-slate-900">Nontix</span>
            </div>
            <p className="mt-2 text-sm text-slate-500">Platform tiket event · Gak perlu ribet</p>
          </div>
          <div className="text-right">
            <p className="text-lg font-bold text-slate-900">INVOICE</p>
            <p className="font-mono text-sm text-slate-600">{order.kode_order}</p>
            <Badge color="green" className="mt-1">LUNAS</Badge>
          </div>
        </div>

        <div className="grid gap-6 py-6 sm:grid-cols-2">
          <div>
            <p className="text-xs font-semibold uppercase text-slate-400">Ditagihkan kepada</p>
            <p className="mt-1 font-semibold text-slate-800">{order.buyer.nama}</p>
            <p className="text-sm text-slate-500">{order.buyer.email}</p>
            <p className="text-sm text-slate-500">{order.buyer.whatsapp}</p>
          </div>
          <div className="sm:text-right">
            <p className="text-xs font-semibold uppercase text-slate-400">Detail</p>
            <p className="mt-1 text-sm text-slate-600">Tanggal: {formatTanggal(order.created_at)}</p>
            <p className="text-sm text-slate-600">Metode: {order.metode?.nama || paymentProviderLabel(order.payment_provider) || 'Online'}</p>
          </div>
        </div>

        <div className="rounded-xl border border-slate-200">
          <div className="grid grid-cols-12 gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2.5 text-xs font-semibold uppercase text-slate-500">
            <span className="col-span-6">Deskripsi</span>
            <span className="col-span-2 text-center">Qty</span>
            <span className="col-span-4 text-right">Jumlah</span>
          </div>
          {itemGroups.map(({ session, bundle, items }, index) => (
            <div key={session?.id || `event-${index}`}>
              {session && (
                <div className="border-b border-brand-100 bg-brand-50 px-4 py-2.5">
                  <p className="text-xs font-bold uppercase tracking-wide text-brand-700">{session.label} · {session.nama_session}</p>
                  <p className="text-xs text-slate-500">{formatTanggal(session.tanggal_mulai, { withTime: true })}</p>
                </div>
              )}
              {bundle && (
                <div className="border-b border-brand-100 bg-brand-50 px-4 py-2.5">
                  <p className="text-xs font-bold uppercase tracking-wide text-brand-700">Paket Multi-Hari</p>
                  <p className="text-xs text-slate-500">Berlaku untuk semua hari yang tercakup</p>
                </div>
              )}
              {items.map((it) => (
                <div key={it.id} className="grid grid-cols-12 gap-2 border-b border-slate-100 px-4 py-3 text-sm last:border-0">
                  <div className="col-span-6">
                    <p className="font-medium text-slate-800">{it.nama_tiket}</p>
                    <p className="text-xs text-slate-500">{order.event.nama_event}</p>
                  </div>
                  <span className="col-span-2 text-center text-slate-600">{it.jumlah}</span>
                  <span className="col-span-4 text-right font-medium text-slate-800">{formatRupiah(it.harga * it.jumlah)}</span>
                </div>
              ))}
            </div>
          ))}
        </div>

        <div className="mt-4 ml-auto max-w-xs space-y-2 text-sm">
          <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{formatRupiah(order.subtotal)}</span></div>
          {order.diskon > 0 && (
            <div className="flex justify-between text-emerald-600"><span>Diskon</span><span>-{formatRupiah(order.diskon)}</span></div>
          )}
          <div className="flex justify-between border-t border-slate-200 pt-2 text-base">
            <span className="font-semibold text-slate-800">Total</span>
            <span className="font-bold text-brand-700">{formatRupiah(order.total)}</span>
          </div>
        </div>

        <p className="mt-8 border-t border-slate-100 pt-4 text-center text-xs text-slate-400">
          Terima kasih telah berbelanja di Nontix. Invoice ini dibuat otomatis dan sah tanpa tanda tangan.
        </p>
      </Card>
    </div>
  )
}
