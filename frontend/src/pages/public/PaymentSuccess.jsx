import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CheckCircle2, Mail, Ticket as TicketIcon, FileText, Home, Clock, RefreshCw } from 'lucide-react'
import { Button, Card, Badge } from '../../components/ui'
import { publicApi } from '../../lib/api'
import { formatRupiah, formatTanggal } from '../../lib/utils'
import { useOrder } from '../../context/OrderContext'

export default function PaymentSuccess() {
  const { order, refreshOrder } = useOrder()
  const [params] = useSearchParams()
  const kode = params.get('order')
  const [checking, setChecking] = useState(true)

  useEffect(() => {
    let active = true
    let attempts = 0

    const run = async () => {
      setChecking(true)
      try {
        let current = order
        if (!current || (kode && current.kode_order !== kode)) {
          current = await refreshOrder(kode)
        }

        // Coba rekonsiliasi status langsung ke Mayar.
        if (current?.status === 'pending') {
          try {
            await publicApi.syncPayment(current.kode_order)
          } catch {
            // abaikan, mungkin mode fake
          }
          current = await refreshOrder(current.kode_order)
        }

        while (active && current?.status === 'pending' && attempts < 10) {
          attempts += 1
          await new Promise((resolve) => setTimeout(resolve, 3000))
          try {
            await publicApi.syncPayment(current.kode_order)
          } catch {
            // abaikan
          }
          current = await refreshOrder(current.kode_order)
        }
      } finally {
        if (active) setChecking(false)
      }
    }

    run()
    return () => {
      active = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (!order) {
    return (
      <div className="container-page py-20 text-center">
        {checking ? (
          <p className="text-slate-500">Memeriksa status pembayaran...</p>
        ) : (
          <>
            <p className="text-slate-500">Tidak ada data pembayaran.</p>
            <Link to="/" className="mt-4 inline-block"><Button>Ke Beranda</Button></Link>
          </>
        )}
      </div>
    )
  }

  const pending = order.status === 'pending'

  if (pending) {
    return (
      <div className="container-page max-w-2xl py-12">
        <Card className="overflow-hidden">
          <div className="bg-gradient-to-br from-amber-500 to-orange-600 px-6 py-10 text-center text-white">
            <div className="mx-auto grid h-16 w-16 place-items-center rounded-full bg-white/20">
              <Clock size={36} />
            </div>
            <h1 className="mt-4 text-2xl font-extrabold text-white">Menunggu Pembayaran</h1>
            <p className="mt-1 text-white/90">Pembayaranmu belum terkonfirmasi. Selesaikan pembayaran bila belum, atau tunggu beberapa saat.</p>
          </div>
          <div className="p-6">
            <div className="rounded-xl bg-slate-50 p-4 text-sm">
              <div className="flex items-center justify-between">
                <span className="text-slate-500">Kode Pesanan</span>
                <span className="font-mono font-bold text-slate-800">{order.kode_order}</span>
              </div>
              <div className="mt-2 flex items-center justify-between">
                <span className="text-slate-500">Total</span>
                <span className="font-bold text-slate-800">{formatRupiah(order.total)}</span>
              </div>
            </div>
            <div className="mt-6 grid gap-3 sm:grid-cols-2">
              {order.payment_url && (
                <a href={order.payment_url}><Button size="lg" className="w-full">Lanjutkan Pembayaran</Button></a>
              )}
              <Button size="lg" variant="secondary" className="w-full" onClick={() => window.location.reload()}>
                <RefreshCw size={16} /> Cek Status
              </Button>
            </div>
            <p className="mt-3 text-center text-xs text-slate-400">
              Status akan otomatis diperbarui setelah pembayaran diterima Mayar.
            </p>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div className="container-page max-w-2xl py-12">
      <Card className="overflow-hidden">
        <div className="bg-gradient-to-br from-emerald-500 to-teal-600 px-6 py-10 text-center text-white">
          <div className="mx-auto grid h-16 w-16 place-items-center rounded-full bg-white/20">
            <CheckCircle2 size={36} />
          </div>
          <h1 className="mt-4 text-2xl font-extrabold text-white">Pembayaran Berhasil!</h1>
          <p className="mt-1 text-white/90">Pesananmu sudah lunas dan tiket telah diterbitkan.</p>
          <Badge color="green" className="mt-3 bg-white/20 text-white ring-white/30">LUNAS</Badge>
        </div>

        <div className="p-6">
          <div className="rounded-xl bg-slate-50 p-4">
            <div className="flex items-center justify-between">
              <span className="text-sm text-slate-500">Kode Pesanan</span>
              <span className="font-mono text-sm font-bold text-slate-800">{order.kode_order}</span>
            </div>
            <div className="mt-3 space-y-2 text-sm">
              <div className="flex justify-between"><span className="text-slate-500">Event</span><span className="font-medium text-slate-700">{order.event.nama_event}</span></div>
              <div className="flex justify-between gap-4"><span className="text-slate-500">Jadwal</span><span className="text-right font-medium text-slate-700">{order.event?.sessions?.length > 1 ? `${order.event.sessions.length} hari event` : formatTanggal(order.event?.sessions?.[0]?.tanggal_mulai || order.event?.tanggal_mulai, { withDay: true })}</span></div>
              <div className="flex justify-between"><span className="text-slate-500">Jumlah Tiket</span><span className="font-medium text-slate-700">{order.jumlah_tiket} tiket</span></div>
              <div className="flex justify-between border-t border-slate-200 pt-2"><span className="text-slate-500">Total Dibayar</span><span className="font-bold text-emerald-600">{formatRupiah(order.total)}</span></div>
            </div>
          </div>

          <div className="mt-5 flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4">
            <Mail className="mt-0.5 shrink-0 text-sky-600" size={20} />
            <p className="text-sm text-sky-800">
              Invoice dan e-ticket telah dikirim ke <span className="font-semibold">{order.buyer.email}</span> melalui Mailketing SMTP.
            </p>
          </div>

          <div className="mt-6 grid gap-3 sm:grid-cols-2">
            <Link to="/tiket/saya"><Button size="lg" className="w-full"><TicketIcon size={18} /> Lihat E-Ticket</Button></Link>
            <Link to="/invoice"><Button size="lg" variant="secondary" className="w-full"><FileText size={18} /> Lihat Invoice</Button></Link>
          </div>
          <Link to="/" className="mt-3 block">
            <Button variant="ghost" className="w-full"><Home size={16} /> Kembali ke Beranda</Button>
          </Link>
        </div>
      </Card>
    </div>
  )
}
