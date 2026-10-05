import { useEffect, useMemo, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { ArrowLeft, BadgePercent, Check, Ticket as TicketIcon, Info, Layers } from 'lucide-react'
import { Button, Card, Field, Input, Badge, EmptyState, Modal } from '../../components/ui'
import { groupItemsBySession } from '../../data/mock'
import { publicApi } from '../../lib/api'
import { formatRupiah, formatTanggal, cn } from '../../lib/utils'
import { useOrder } from '../../context/OrderContext'

const STANDARD_SOCIAL = ['instagram', 'tiktok', 'threads']
const DEFAULT_FIELDS = [
  { id: 'instagram', label: 'Instagram', key: 'instagram', tipe: 'sosial', wajib: false },
  { id: 'tiktok', label: 'TikTok', key: 'tiktok', tipe: 'sosial', wajib: false },
  { id: 'threads', label: 'Threads', key: 'threads', tipe: 'sosial', wajib: false },
]

export default function Checkout() {
  const { state } = useLocation()
  const navigate = useNavigate()
  const { draft, updateDraft, createOrder, markPaid } = useOrder()

  const event = state?.event || draft?.event
  const items = state?.items || draft?.items || []
  const itemGroups = groupItemsBySession(event, items)
  const customFields = event?.formFields?.length
    ? event.formFields.map((f) => ({ ...f, key: f.key || f.label.toLowerCase() }))
    : DEFAULT_FIELDS

  const [form, setForm] = useState({
    nama: '', email: '', whatsapp: '', instagram: '', tiktok: '', threads: '',
    ...(draft?.buyer || {}),
  })
  const [errors, setErrors] = useState({})
  const [voucherCode, setVoucherCode] = useState('')
  const [voucher, setVoucher] = useState(draft?.voucher || null)
  const [voucherMsg, setVoucherMsg] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [pendingOrder, setPendingOrder] = useState(null)
  const [paying, setPaying] = useState(false)
  const [payError, setPayError] = useState(null)

  useEffect(() => {
    if (!event || items.length === 0) return
    updateDraft({ items, event, buyer: form, voucher })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const subtotal = useMemo(() => items.reduce((s, it) => s + it.harga * it.jumlah, 0), [items])
  const diskon = useMemo(() => {
    if (!voucher) return 0
    const d = voucher.tipe_diskon === 'persen' ? Math.round((subtotal * voucher.nilai) / 100) : voucher.nilai
    return Math.min(d, subtotal)
  }, [voucher, subtotal])
  const total = subtotal - diskon

  if (!event || items.length === 0) {
    return (
      <div className="container-page py-20">
        <Card>
          <EmptyState
            icon={Info}
            title="Belum ada tiket dipilih"
            description="Silakan pilih event dan tiket terlebih dahulu."
            action={<Link to="/"><Button>Jelajahi Event</Button></Link>}
          />
        </Card>
      </div>
    )
  }

  const validate = () => {
    const e = {}
    if (!form.nama.trim()) e.nama = 'Nama lengkap wajib diisi'
    if (!form.email.trim()) e.email = 'Email wajib diisi'
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) e.email = 'Format email tidak valid'
    if (!form.whatsapp.trim()) e.whatsapp = 'Nomor WhatsApp wajib diisi'
    else if (!/^[0-9+\-\s]{8,16}$/.test(form.whatsapp)) e.whatsapp = 'Format WhatsApp tidak valid'
    customFields.forEach((f) => {
      if (f.wajib && !String(form[f.key] || '').trim()) e[f.key] = `${f.label} wajib diisi`
    })
    setErrors(e)
    return Object.keys(e).length === 0
  }

  const applyVoucher = async () => {
    const code = voucherCode.trim().toUpperCase()
    if (!code) return
    try {
      const res = await publicApi.validateVoucher({ kode: code, event_id: event.id, subtotal })
      setVoucher(res)
      setVoucherMsg({ type: 'success', text: `Voucher ${res.kode} berhasil dipakai!` })
    } catch (err) {
      setVoucher(null)
      setVoucherMsg({ type: 'error', text: err.message })
    }
  }

  const removeVoucher = () => {
    setVoucher(null)
    setVoucherCode('')
    setVoucherMsg(null)
  }

  const handleCheckout = async () => {
    if (!validate()) return
    updateDraft({ items, event, buyer: form, voucher })
    const formData = customFields
      .filter((f) => !STANDARD_SOCIAL.includes(f.key))
      .reduce((acc, f) => ({ ...acc, [f.key]: form[f.key] || '' }), {})
    const payload = {
      event_id: event.id,
      nama: form.nama,
      email: form.email,
      whatsapp: form.whatsapp,
      instagram: form.instagram || null,
      tiktok: form.tiktok || null,
      threads: form.threads || null,
      form_data: formData,
      voucher_code: voucher?.kode || null,
      items: items.map((it) => ({ ticket_type_id: it.ticketId ?? it.id, jumlah: it.jumlah })),
    }
    try {
      setSubmitting(true)
      setPayError(null)
      const created = await createOrder(payload)
      setPendingOrder(created)
    } catch (err) {
      setVoucherMsg({ type: 'error', text: err.message })
    } finally {
      setSubmitting(false)
    }
  }

  const handlePay = async () => {
    if (!pendingOrder) return
    setPayError(null)

    if (pendingOrder.payment_provider === 'mayar') {
      if (!pendingOrder.payment_url) {
        setPayError('Link pembayaran Mayar belum tersedia. Coba beberapa saat lagi.')
        return
      }
      window.location.href = pendingOrder.payment_url
      return
    }

    try {
      setPaying(true)
      await markPaid()
      navigate('/pembayaran/berhasil')
    } catch (err) {
      setPayError(err.message)
    } finally {
      setPaying(false)
    }
  }

  const set = (key, value) => setForm((f) => ({ ...f, [key]: value }))

  return (
    <div className="container-page py-8">
      <Link to={`/event/${event.slug}`} className="mb-4 inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-700">
        <ArrowLeft size={16} /> Kembali ke event
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-900">Checkout</h1>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="min-w-0 space-y-6 lg:col-span-2">
          <Card className="p-6">
            <h2 className="mb-4 text-lg font-bold text-slate-900">Data Pembeli</h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Nama Lengkap" required error={errors.nama}>
                <Input value={form.nama} onChange={(e) => set('nama', e.target.value)} placeholder="Sesuai identitas" />
              </Field>
              <Field label="Email Aktif" required error={errors.email}>
                <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} placeholder="nama@email.com" />
              </Field>
              <Field label="Nomor WhatsApp" required error={errors.whatsapp}>
                <Input value={form.whatsapp} onChange={(e) => set('whatsapp', e.target.value)} placeholder="08xxxxxxxxxx" />
              </Field>
              {customFields.map((f) => (
                <Field key={f.id || f.key} label={f.label} required={f.wajib} error={errors[f.key]} hint={f.wajib ? undefined : 'Opsional'}>
                  <Input
                    value={form[f.key] || ''}
                    onChange={(e) => set(f.key, e.target.value)}
                    placeholder={`@${f.key}`}
                  />
                </Field>
              ))}
            </div>
          </Card>

          <Card className="p-6">
            <h2 className="mb-4 text-lg font-bold text-slate-900">Kode Voucher</h2>
            <div className="flex gap-2">
              <Input
                value={voucherCode}
                onChange={(e) => setVoucherCode(e.target.value.toUpperCase())}
                placeholder="Masukkan kode promo"
                disabled={!!voucher}
              />
              {voucher ? (
                <Button variant="secondary" onClick={removeVoucher}>Hapus</Button>
              ) : (
                <Button variant="secondary" onClick={applyVoucher}><BadgePercent size={16} /> Pakai</Button>
              )}
            </div>
            {voucherMsg && (
              <p className={cn('mt-2 text-sm font-medium', voucherMsg.type === 'error' ? 'text-rose-600' : 'text-emerald-600')}>
                {voucherMsg.text}
              </p>
            )}
            <p className="mt-3 text-xs text-slate-400">Coba kode demo: NONTIX50 (potongan Rp 50.000) atau HEMAT10 (diskon 10%).</p>
          </Card>
        </div>

        <div>
          <Card className="p-6 lg:sticky lg:top-24">
            <h2 className="mb-4 text-lg font-bold text-slate-900">Ringkasan Pesanan</h2>
            <div className="rounded-xl bg-slate-50 p-3.5">
              <p className="text-sm font-semibold text-slate-800">{event.nama_event}</p>
              <p className="text-xs text-slate-500">{event.lokasi}</p>
            </div>
            <div className="mt-4 space-y-4">
              {itemGroups.map(({ session, bundle, items: sessionItems }, index) => (
                <div key={session?.id || `event-${index}`} className="space-y-3">
                  {session && (
                    <div className="rounded-[var(--radius-control)] bg-brand-50 p-3 ring-1 ring-brand-100">
                      <p className="text-xs font-bold uppercase tracking-wide text-brand-700">{session.label}</p>
                      <p className="text-sm font-semibold text-slate-800">{session.nama_session}</p>
                      <p className="text-xs text-slate-500">{formatTanggal(session.tanggal_mulai, { withTime: true })}</p>
                    </div>
                  )}
                  {bundle && (
                    <div className="flex items-center gap-2 rounded-[var(--radius-control)] bg-brand-50 p-3 ring-1 ring-brand-100">
                      <Layers size={15} className="text-brand-700" />
                      <div>
                        <p className="text-xs font-bold uppercase tracking-wide text-brand-700">Paket Multi-Hari</p>
                        <p className="text-sm font-semibold text-slate-800">Berlaku untuk semua hari yang tercakup</p>
                      </div>
                    </div>
                  )}
                  {sessionItems.map((it) => (
                    <div key={it.id} className="flex items-start justify-between gap-3 text-sm">
                      <div>
                        <p className="font-medium text-slate-700">{it.nama_tiket}</p>
                        <p className="text-xs text-slate-500">{formatRupiah(it.harga)} × {it.jumlah}</p>
                      </div>
                      <p className="font-semibold text-slate-800">{formatRupiah(it.harga * it.jumlah)}</p>
                    </div>
                  ))}
                </div>
              ))}
            </div>
            <div className="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm">
              <div className="flex justify-between text-slate-500">
                <span>Subtotal</span>
                <span>{formatRupiah(subtotal)}</span>
              </div>
              {diskon > 0 && (
                <div className="flex justify-between text-emerald-600">
                  <span className="flex items-center gap-1"><Check size={14} /> Diskon {voucher?.kode}</span>
                  <span>-{formatRupiah(diskon)}</span>
                </div>
              )}
              <div className="flex justify-between border-t border-slate-100 pt-2 text-base">
                <span className="font-semibold text-slate-800">Total Bayar</span>
                <span className="font-bold text-brand-700">{formatRupiah(total)}</span>
              </div>
            </div>
            <Button className="mt-5 w-full" size="lg" onClick={handleCheckout} disabled={submitting}>
              <TicketIcon size={18} /> Checkout
            </Button>
            <p className="mt-3 text-center text-xs text-slate-400">
              Dengan checkout kamu menyetujui Syarat & Ketentuan Nontix.
            </p>
          </Card>
        </div>
      </div>

      <Modal
        open={!!pendingOrder}
        onClose={() => { if (!paying) setPendingOrder(null) }}
        title="Detail Pesanan"
        footer={
          <>
            <Button variant="secondary" onClick={() => setPendingOrder(null)} disabled={paying}>Kembali</Button>
            <Button onClick={handlePay} disabled={paying}>
              {paying ? 'Memproses...' : 'Bayar Sekarang'}
            </Button>
          </>
        }
      >
        {pendingOrder && (
          <div className="space-y-4">
            <div className="rounded-xl bg-slate-50 p-4 text-sm">
              <div className="flex items-center justify-between">
                <span className="text-slate-500">Kode Pesanan</span>
                <span className="font-mono font-bold text-slate-800">{pendingOrder.kode_order}</span>
              </div>
              <div className="mt-2 flex items-center justify-between gap-3">
                <span className="text-slate-500">Event</span>
                <span className="truncate text-right font-medium text-slate-700">{pendingOrder.event?.nama_event}</span>
              </div>
              <div className="mt-2 flex items-center justify-between gap-3">
                <span className="text-slate-500">Pembeli</span>
                <span className="truncate text-right font-medium text-slate-700">{pendingOrder.buyer?.nama}</span>
              </div>
            </div>

            <div className="space-y-2 text-sm">
              {pendingOrder.items?.map((it) => (
                <div key={it.id} className="flex items-start justify-between gap-3">
                  <span className="text-slate-600">{it.nama_tiket} × {it.jumlah}</span>
                  <span className="shrink-0 font-medium text-slate-800">{formatRupiah(it.harga * it.jumlah)}</span>
                </div>
              ))}
            </div>

            <div className="space-y-1 border-t border-slate-100 pt-3 text-sm">
              <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{formatRupiah(pendingOrder.subtotal)}</span></div>
              {pendingOrder.diskon > 0 && (
                <div className="flex justify-between text-emerald-600"><span>Diskon</span><span>-{formatRupiah(pendingOrder.diskon)}</span></div>
              )}
              <div className="flex justify-between pt-1 text-base">
                <span className="font-semibold text-slate-800">Total Bayar</span>
                <span className="font-bold text-brand-700">{formatRupiah(pendingOrder.total)}</span>
              </div>
            </div>

            {payError && <p className="text-sm font-medium text-rose-600">{payError}</p>}

            <p className="text-xs text-slate-400">
              {pendingOrder.payment_provider === 'mayar'
                ? 'Kamu akan diarahkan ke halaman pembayaran aman Mayar.'
                : 'Mode demo: pesanan akan langsung ditandai lunas.'}
            </p>
          </div>
        )}
      </Modal>
    </div>
  )
}
