import { useEffect, useState } from 'react'
import { CreditCard, Save, Check, Info, ShieldCheck, Star } from 'lucide-react'
import { Button, Card, PageHeader, Badge } from '../../components/ui'
import { adminApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { cn } from '../../lib/utils'

export default function AdminSettings() {
  const { data, loading, reload } = useApi(() => adminApi.paymentSettings(), [])
  const [enabled, setEnabled] = useState([])
  const [primary, setPrimary] = useState('')
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState(null)

  useEffect(() => {
    if (!data) return
    const active = data.active || []
    setEnabled(active)
    setPrimary(active[0] || '')
  }, [data])

  const gateways = data?.gateways || []

  const toggle = (id) => {
    setEnabled((list) => {
      if (list.includes(id)) {
        const next = list.filter((g) => g !== id)
        if (primary === id) setPrimary(next[0] || '')
        return next
      }
      const next = [...list, id]
      if (!primary) setPrimary(id)
      return next
    })
  }

  const save = async () => {
    if (enabled.length === 0) {
      setMessage({ type: 'error', text: 'Aktifkan minimal satu gateway.' })
      return
    }
    // Urutan: utama lebih dulu, lalu sisanya (urutan = fallback).
    const ordered = [primary, ...enabled.filter((g) => g !== primary)].filter(Boolean)

    setSaving(true)
    setMessage(null)
    try {
      await adminApi.updatePaymentSettings({ gateways: ordered })
      setMessage({ type: 'success', text: 'Pengaturan pembayaran disimpan.' })
      reload()
    } catch (err) {
      setMessage({ type: 'error', text: err.message })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="Pengaturan Pembayaran"
        description="Tentukan gateway pembayaran yang dipakai saat checkout."
        action={<Button onClick={save} disabled={saving || loading}><Save size={16} /> {saving ? 'Menyimpan...' : 'Simpan'}</Button>}
      />

      <div className="mb-5 flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
        <Info className="mt-0.5 shrink-0 text-sky-600" size={18} />
        <p>
          Pembeli <span className="font-semibold">tidak memilih</span> metode pembayaran. Checkout otomatis memakai
          gateway <span className="font-semibold">utama</span>; bila gagal, sistem mencoba gateway aktif berikutnya
          secara berurutan (fallback).
        </p>
      </div>

      <Card className="p-6">
        <div className="mb-4 flex items-center gap-2">
          <CreditCard size={18} className="text-brand-600" />
          <h2 className="font-bold text-slate-900">Gateway Tersedia</h2>
        </div>

        {loading ? (
          <div className="h-40 animate-pulse rounded-xl bg-slate-100" />
        ) : (
          <div className="space-y-3">
            {gateways.map((g) => {
              const isOn = enabled.includes(g.id)
              const isPrimary = primary === g.id
              return (
                <div
                  key={g.id}
                  className={cn(
                    'flex flex-wrap items-center gap-4 rounded-xl border p-4',
                    g.available ? 'border-slate-200' : 'border-dashed border-slate-200 bg-slate-50',
                  )}
                >
                  <span className={cn('grid h-11 w-11 shrink-0 place-items-center rounded-xl', g.available ? 'bg-brand-100 text-brand-700' : 'bg-slate-100 text-slate-400')}>
                    <ShieldCheck size={20} />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <p className="font-bold text-slate-900">{g.label}</p>
                      {isPrimary && isOn && <Badge color="brand"><Star size={11} /> Utama</Badge>}
                    </div>
                    <p className="text-xs text-slate-500">
                      {g.available
                        ? 'Kredensial terkonfigurasi — siap dipakai.'
                        : 'Belum dikonfigurasi (isi kunci API di .env server).'}
                    </p>
                  </div>

                  {g.available ? (
                    <div className="flex items-center gap-3">
                      {isOn && (
                        <label className="flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-slate-600">
                          <input
                            type="radio"
                            name="primary-gateway"
                            checked={isPrimary}
                            onChange={() => setPrimary(g.id)}
                            className="h-3.5 w-3.5 text-brand-600 focus:ring-brand-500"
                          />
                          Utama
                        </label>
                      )}
                      <button
                        type="button"
                        onClick={() => toggle(g.id)}
                        className={cn(
                          'relative h-6 w-11 shrink-0 rounded-full transition',
                          isOn ? 'bg-brand-600' : 'bg-slate-300',
                        )}
                        aria-pressed={isOn}
                      >
                        <span className={cn('absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition', isOn ? 'left-[22px]' : 'left-0.5')} />
                      </button>
                    </div>
                  ) : (
                    <Badge color="slate">Tidak tersedia</Badge>
                  )}
                </div>
              )
            })}
          </div>
        )}

        {message && (
          <p className={cn('mt-4 flex items-center gap-1.5 text-sm font-medium', message.type === 'error' ? 'text-rose-600' : 'text-emerald-600')}>
            {message.type === 'success' && <Check size={15} />} {message.text}
          </p>
        )}
      </Card>
    </div>
  )
}