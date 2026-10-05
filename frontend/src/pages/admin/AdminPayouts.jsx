import { useMemo, useState } from 'react'
import { Banknote, Search, Landmark, Clock, CheckCircle2 } from 'lucide-react'
import { Button, Card, PageHeader, Stat, Input, Select, Badge, StatusBadge, EmptyState, Modal, Field, Textarea } from '../../components/ui'
import { ImagePicker } from '../../components/ImagePicker'
import { adminApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah, formatTanggal } from '../../lib/utils'

const ACTIONS = {
  diproses: { label: 'Tandai Diproses', variant: 'secondary' },
  selesai: { label: 'Selesaikan', variant: 'success' },
  ditolak: { label: 'Tolak', variant: 'danger' },
}

export default function AdminPayouts() {
  const { data, loading, reload } = useApi(() => adminApi.payouts(), [])
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('all')
  const [action, setAction] = useState(null)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({ catatan_admin: '', bukti_transfer_url: '' })

  const payouts = data?.data || []

  const filtered = useMemo(
    () =>
      payouts.filter((p) => {
        const matchQ =
          (p.mitra || '').toLowerCase().includes(q.toLowerCase()) ||
          (p.nomor_rekening || '').includes(q)
        const matchS = status === 'all' || p.status === status
        return matchQ && matchS
      }),
    [payouts, q, status],
  )

  const menunggu = payouts.filter((p) => p.status === 'diajukan').length
  const totalSelesai = payouts
    .filter((p) => p.status === 'selesai')
    .reduce((s, p) => s + (p.jumlah || 0), 0)
  const totalDiproses = payouts
    .filter((p) => p.status === 'diproses')
    .reduce((s, p) => s + (p.jumlah || 0), 0)

  const openAction = (payout, status) => {
    setAction({ payout, status })
    setForm({ catatan_admin: payout.catatan_admin || '', bukti_transfer_url: payout.bukti_transfer_url || '' })
  }

  const submit = async () => {
    if (!action) return
    setSaving(true)
    try {
      await adminApi.updatePayout(action.payout.id, {
        status: action.status,
        catatan_admin: form.catatan_admin || null,
        bukti_transfer_url: form.bukti_transfer_url || null,
      })
      setAction(null)
      reload()
    } catch (err) {
      window.alert(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="Pencairan Mitra"
        description="Kelola pengajuan pencairan dana dari penyelenggara."
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="Menunggu Ditinjau" value={menunggu.toLocaleString('id-ID')} icon={Clock} color="amber" />
        <Stat label="Sedang Diproses" value={formatRupiah(totalDiproses)} icon={Landmark} color="blue" />
        <Stat label="Total Sudah Dicairkan" value={formatRupiah(totalSelesai)} icon={CheckCircle2} color="green" />
      </div>

      <Card className="mt-4 p-4">
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="relative flex-1">
            <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari mitra atau nomor rekening..." className="pl-10" />
          </div>
          <Select value={status} onChange={(e) => setStatus(e.target.value)} className="sm:w-52">
            <option value="all">Semua Status</option>
            <option value="diajukan">Diajukan</option>
            <option value="diproses">Diproses</option>
            <option value="selesai">Selesai</option>
            <option value="ditolak">Ditolak</option>
          </Select>
        </div>
      </Card>

      <Card className="mt-4">
        {loading ? (
          <div className="m-4 h-56 animate-pulse rounded-xl bg-slate-100" />
        ) : filtered.length === 0 ? (
          <EmptyState icon={Banknote} title="Tidak ada pengajuan" description="Pengajuan pencairan dari mitra akan tampil di sini." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[920px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Mitra</th>
                  <th className="px-5 py-3 font-semibold">Jumlah</th>
                  <th className="px-5 py-3 font-semibold">Rekening</th>
                  <th className="px-5 py-3 font-semibold">Diajukan</th>
                  <th className="px-5 py-3 font-semibold">Status</th>
                  <th className="px-5 py-3 font-semibold">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map((p) => (
                  <tr key={p.id} className="hover:bg-slate-50">
                    <td className="px-5 py-3">
                      <p className="font-semibold text-slate-800">{p.mitra}</p>
                      <p className="text-xs text-slate-400">{p.email}</p>
                      {p.catatan && <p className="mt-0.5 text-xs text-slate-500">"{p.catatan}"</p>}
                    </td>
                    <td className="px-5 py-3 font-bold text-slate-800">{formatRupiah(p.jumlah)}</td>
                    <td className="px-5 py-3 text-slate-600">
                      {p.nama_bank} · {p.nomor_rekening}
                      <p className="text-xs text-slate-400">{p.nama_rekening}</p>
                    </td>
                    <td className="px-5 py-3 text-slate-500">{formatTanggal(p.diajukan_at, { withTime: true })}</td>
                    <td className="px-5 py-3">
                      <StatusBadge status={p.status} />
                      {p.catatan_admin && <p className="mt-1 max-w-[200px] text-xs text-slate-400">{p.catatan_admin}</p>}
                      {p.bukti_transfer_url && (
                        <a href={p.bukti_transfer_url} target="_blank" rel="noreferrer" className="mt-1 block text-xs font-medium text-brand-600 hover:underline">
                          Lihat bukti
                        </a>
                      )}
                    </td>
                    <td className="px-5 py-3">
                      <div className="flex flex-wrap gap-1.5">
                        {p.status === 'diajukan' && (
                          <Button size="sm" variant="secondary" onClick={() => openAction(p, 'diproses')}>Proses</Button>
                        )}
                        {p.status !== 'selesai' && p.status !== 'ditolak' && (
                          <>
                            <Button size="sm" variant="success" onClick={() => openAction(p, 'selesai')}>Selesaikan</Button>
                            <Button size="sm" variant="ghost" className="text-rose-600" onClick={() => openAction(p, 'ditolak')}>Tolak</Button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={!!action}
        onClose={() => setAction(null)}
        title={action ? `${ACTIONS[action.status]?.label ?? 'Ubah Status'} — ${action.payout.mitra}` : ''}
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)} disabled={saving}>Batal</Button>
            <Button onClick={submit} disabled={saving}>{saving ? 'Menyimpan...' : 'Konfirmasi'}</Button>
          </>
        }
      >
        {action && (
          <div className="space-y-4">
            <div className="rounded-xl bg-slate-50 p-4 text-sm">
              <div className="flex justify-between">
                <span className="text-slate-500">Jumlah</span>
                <span className="font-bold text-slate-800">{formatRupiah(action.payout.jumlah)}</span>
              </div>
              <div className="mt-1 flex justify-between">
                <span className="text-slate-500">Rekening tujuan</span>
                <span className="font-medium text-slate-700">
                  {action.payout.nama_bank} · {action.payout.nomor_rekening} a/n {action.payout.nama_rekening}
                </span>
              </div>
            </div>

            {action.status === 'selesai' && (
              <Field label="Bukti Transfer" hint="Unggah screenshot/foto bukti transfer (opsional tapi disarankan).">
                <ImagePicker
                  value={form.bukti_transfer_url}
                  onChange={(url) => setForm((f) => ({ ...f, bukti_transfer_url: url }))}
                  folder="proofs"
                  label="Unggah Bukti"
                  uploadFn={adminApi.upload}
                />
              </Field>
            )}

            <Field label="Catatan Admin (opsional)">
              <Textarea
                value={form.catatan_admin}
                onChange={(e) => setForm((f) => ({ ...f, catatan_admin: e.target.value }))}
                placeholder={action.status === 'ditolak' ? 'Alasan penolakan...' : 'Catatan untuk mitra...'}
                className="min-h-[80px]"
              />
            </Field>

            {action.status === 'ditolak' && (
              <p className="flex items-center gap-2 text-xs text-rose-600">
                <Badge color="red">Perhatian</Badge>
                Dana akan tetap tersedia dan bisa diajukan ulang oleh mitra.
              </p>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}