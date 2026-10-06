import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Clock, ShieldCheck, Info, ChevronRight, AlertTriangle, Layers } from 'lucide-react'
import { Button, Card, Badge, EmptyState } from '../../components/ui'
import { PAYMENT_METHODS } from '../../lib/constants'
import { groupItemsBySession } from '../../data/mock'
import { formatRupiah, formatTanggal, cn, pad } from '../../lib/utils'
import { useOrder } from '../../context/OrderContext'
import { paymentProviderLabel, isHostedProvider } from '../../lib/paymentProvider'

export default function Payment() {
  const { order, markPaid } = useOrder()
  const navigate = useNavigate()
  const [selected, setSelected] = useState(order?.metode?.id || null)
  const [processing, setProcessing] = useState(false)
  const [payError, setPayError] = useState(null)
  const [now, setNow] = useState(Date.now())

  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(t)
  }, [])

  if (!order) {
    return (
      <div className="container-page py-20">
        <Card>
          <EmptyState
            icon={Info}
            title="Tidak ada pesanan aktif"
            description="Silakan pilih tiket terlebih dahulu untuk memulai pembayaran."
            action={<Link to="/"><Button>Jelajahi Event</Button></Link>}
          />
        </Card>
      </div>
    )
  }

  const providerLabel = paymentProviderLabel(order.payment_provider)
  const sisa = Math.max(new Date(order.batas_bayar).getTime() - now, 0)
  const kadaluarsa = sisa <= 0
  const jam = Math.floor(sisa / 3600000)
  const menit = Math.floor((sisa % 3600000) / 60000)
  const detik = Math.floor((sisa % 60000) / 1000)
  const itemGroups = groupItemsBySession(order.event, order.items)

  const groups = PAYMENT_METHODS.reduce((acc, m) => {
    acc[m.kategori] = acc[m.kategori] || []
    acc[m.kategori].push(m)
    return acc
  }, {})

  const handlePay = async () => {
    if (!selected) return
    setPayError(null)

    // Gateway hosted (Xendit/Mayar): pembeli menyelesaikan pembayaran di halaman provider.
    if (isHostedProvider(order?.payment_provider)) {
      if (!order?.payment_url) {
        setPayError(`Link pembayaran ${paymentProviderLabel(order.payment_provider)} belum tersedia. Coba beberapa saat lagi.`)
        return
      }
      window.location.href = order.payment_url
      return
    }

    try {
      setProcessing(true)
      await markPaid()
      navigate('/pembayaran/berhasil')
    } catch (err) {
      setPayError(err.message)
    } finally {
      setProcessing(false)
    }
  }

  return (
    <div className="container-page py-8">
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Pembayaran</h1>
          <p className="text-sm text-slate-500">Pesanan <span className="font-semibold text-slate-700">{order.kode_order}</span></p>
        </div>
        <div className={cn('flex items-center gap-2 rounded-xl px-4 py-2.5', kadaluarsa ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700')}>
          <Clock size={18} />
          <div className="text-sm">
            <p className="font-medium">{kadaluarsa ? 'Waktu habis' : 'Selesaikan dalam'}</p>
            <p className="text-lg font-extrabold tabular-nums">
              {kadaluarsa ? '00:00:00' : `${pad(jam)}:${pad(menit)}:${pad(detik)}`}
            </p>
          </div>
        </div>
      </div>

      {kadaluarsa && (
        <Card className="mb-6 border-rose-200 bg-rose-50 p-5">
          <div className="flex items-start gap-3">
            <AlertTriangle className="mt-0.5 shrink-0 text-rose-600" size={20} />
            <div>
              <p className="font-semibold text-rose-800">Pesanan dibatalkan karena melewati batas waktu</p>
              <p className="mt-1 text-sm text-rose-700">Tiket sudah dikembalikan ke kuota. Silakan pesan ulang untuk melanjutkan pembelian.</p>
              <Link to="/"><Button variant="danger" size="sm" className="mt-3">Pesan Ulang</Button></Link>
            </div>
          </div>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="min-w-0 lg:col-span-2">
          <Card className="p-6">
            <h2 className="mb-4 text-lg font-bold text-slate-900">Pilih Metode Bayar</h2>
            <div className="space-y-5">
              {Object.entries(groups).map(([kategori, methods]) => (
                <div key={kategori}>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{kategori}</p>
                  <div className="grid gap-2 sm:grid-cols-2">
                    {methods.map((m) => (
                      <button
                        key={m.id}
                        onClick={() => setSelected(m.id)}
                        disabled={kadaluarsa}
                        className={cn(
                          'flex items-center gap-3 rounded-xl border p-3 text-left transition disabled:opacity-50',
                          selected === m.id
                            ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/30'
                            : 'border-slate-200 hover:border-brand-300',
                        )}
                      >
                        <span className={cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-gradient-to-br text-xs font-bold text-white', m.warna)}>
                          {m.logo}
                        </span>
                        <span className="flex-1 text-sm font-medium text-slate-700">{m.nama}</span>
                        <span className={cn('grid h-5 w-5 place-items-center rounded-full border-2', selected === m.id ? 'border-brand-600 bg-brand-600' : 'border-slate-300')}>
                          {selected === m.id && <span className="h-2 w-2 rounded-full bg-white" />}
                        </span>
                      </button>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </Card>

          <Card className="mt-6 p-5">
            <div className="flex items-start gap-3">
              <ShieldCheck className="mt-0.5 shrink-0 text-emerald-600" size={20} />
              <p className="text-sm text-slate-600">
                Pembayaran diproses aman melalui <span className="font-semibold">{providerLabel ?? 'halaman pembayaran aman'}</span>. Status pesanan ter-update otomatis setelah pembayaran berhasil.
              </p>
            </div>
          </Card>
        </div>

        <div>
          <Card className="p-6 lg:sticky lg:top-24">
            <h2 className="mb-4 text-lg font-bold text-slate-900">Rincian Tagihan</h2>
            <div className="rounded-xl bg-slate-50 p-3.5">
              <p className="text-sm font-semibold text-slate-800">{order.event.nama_event}</p>
              <p className="text-xs text-slate-500">{formatTanggal(order.event.tanggal_mulai, { withDay: true })}</p>
            </div>
            <div className="mt-4 space-y-4">
              {itemGroups.map(({ session, bundle, items }, index) => (
                <div key={session?.id || `event-${index}`} className="space-y-2">
                  {session && (
                    <div className="border-b border-slate-100 pb-2">
                      <p className="text-xs font-bold uppercase tracking-wide text-brand-700">{session.label} · {session.nama_session}</p>
                      <p className="text-xs text-slate-500">{formatTanggal(session.tanggal_mulai, { withDay: true })}</p>
                    </div>
                  )}
                  {bundle && (
                    <div className="flex items-center gap-2 border-b border-slate-100 pb-2">
                      <Layers size={14} className="text-brand-700" />
                      <p className="text-xs font-bold uppercase tracking-wide text-brand-700">Paket Multi-Hari</p>
                    </div>
                  )}
                  {items.map((it) => (
                    <div key={it.id} className="flex justify-between gap-3 text-sm">
                      <span className="text-slate-600">{it.nama_tiket} × {it.jumlah}</span>
                      <span className="shrink-0 font-medium text-slate-800">{formatRupiah(it.harga * it.jumlah)}</span>
                    </div>
                  ))}
                </div>
              ))}
            </div>
            <div className="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm">
              <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{formatRupiah(order.subtotal)}</span></div>
              {order.diskon > 0 && (
                <div className="flex justify-between text-emerald-600"><span>Diskon</span><span>-{formatRupiah(order.diskon)}</span></div>
              )}
              <div className="flex justify-between border-t border-slate-100 pt-2">
                <span className="font-semibold text-slate-800">Total Bayar</span>
                <span className="text-lg font-bold text-brand-700">{formatRupiah(order.total)}</span>
              </div>
            </div>
            <Button className="mt-5 w-full" size="lg" disabled={!selected || kadaluarsa || processing} onClick={handlePay}>
              {processing ? 'Memproses...' : 'Bayar Sekarang'} <ChevronRight size={18} />
            </Button>
            {!selected && !kadaluarsa && <p className="mt-2 text-center text-xs text-slate-400">Pilih metode bayar dulu</p>}
            {payError && <p className="mt-2 text-center text-xs font-medium text-rose-600">{payError}</p>}
            <div className="mt-4 flex items-center justify-center gap-2 text-xs text-slate-400">
              {providerLabel ? (
                <><Badge color="brand">{providerLabel}</Badge> kamu akan diarahkan ke halaman pembayaran aman</>
              ) : (
                <><Badge color="slate">Demo</Badge> tombol bayar akan menandai pesanan lunas</>
              )}
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
