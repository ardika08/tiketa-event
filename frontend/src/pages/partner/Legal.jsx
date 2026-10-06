import { useEffect, useState } from 'react'
import { Save, Upload, FileText, CheckCircle2, Clock, XCircle, ShieldCheck } from 'lucide-react'
import { Button, Card, Field, Input, Select, PageHeader, StatusBadge } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'

const DOCS = [
  { id: 1, nama: 'KTP Penanggung Jawab', tipe: 'KTP', status: 'terverifikasi', tanggal: '12 Sep 2026' },
  { id: 2, nama: 'NPWP Badan Usaha', tipe: 'NPWP', status: 'pending', tanggal: '20 Sep 2026' },
  { id: 3, nama: 'Surat Izin Keramaian', tipe: 'Izin', status: 'ditolak', tanggal: '25 Sep 2026', alasan: 'Dokumen tidak terbaca / buram.' },
]

const STATUS_ICON = {
  terverifikasi: CheckCircle2,
  pending: Clock,
  ditolak: XCircle,
}

export default function Legal() {
  const { data } = useApi(() => partnerApi.profile(), [])
  const legal = data?.data?.legal
  const [form, setForm] = useState({ nama: '', no_identitas: '' })
  const [saved, setSaved] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (legal) {
      setForm({ nama: legal.nama_penanggung_jawab || '', no_identitas: legal.no_identitas || '' })
    }
  }, [legal])

  const save = async () => {
    setSaving(true)
    try {
      await partnerApi.updateLegal({ nama_penanggung_jawab: form.nama, no_identitas: form.no_identitas })
      setSaved(true)
      setTimeout(() => setSaved(false), 2000)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="Penanggung Jawab & Legalitas"
        description="Data penanggung jawab dan dokumen legal acara."
        action={<Button onClick={save} disabled={saving}><Save size={16} /> {saving ? 'Menyimpan...' : 'Simpan'}</Button>}
      />

      {saved && <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">Data berhasil disimpan.</div>}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="p-6">
          <h2 className="mb-4 font-bold text-slate-900">Penanggung Jawab</h2>
          <div className="space-y-4">
            <Field label="Nama Penanggung Jawab" required>
              <Input value={form.nama} onChange={(e) => setForm({ ...form, nama: e.target.value })} />
            </Field>
            <Field label="Nomor Identitas (KTP)" required>
              <Input value={form.no_identitas} onChange={(e) => setForm({ ...form, no_identitas: e.target.value })} />
            </Field>
            <div className="flex items-center gap-3 rounded-xl bg-slate-50 p-4">
              <ShieldCheck className="shrink-0 text-emerald-600" size={20} />
              <p className="text-sm text-slate-600">Data penanggung jawab digunakan untuk verifikasi platform dan tidak ditampilkan ke pembeli.</p>
            </div>
          </div>
        </Card>

        <Card className="p-6">
          <h2 className="mb-4 font-bold text-slate-900">Unggah Dokumen Legal</h2>
          <div className="space-y-3">
            <Field label="Tipe Dokumen">
              <Select defaultValue="KTP">
                <option>KTP</option>
                <option>NPWP</option>
                <option>Izin Keramaian</option>
                <option>Akta Pendirian</option>
              </Select>
            </Field>
            <label className="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 px-6 py-8 text-center hover:border-brand-400 hover:bg-brand-50/40">
              <Upload className="text-slate-400" size={24} />
              <p className="mt-2 text-sm font-medium text-slate-700">Klik untuk mengunggah dokumen</p>
              <p className="text-xs text-slate-400">PDF/JPG/PNG maks 5MB</p>
              <input type="file" className="hidden" />
            </label>
          </div>
        </Card>
      </div>

      <Card className="mt-6">
        <div className="border-b border-slate-100 px-5 py-4">
          <h2 className="font-bold text-slate-900">Status Dokumen</h2>
        </div>
        <div className="divide-y divide-slate-100">
          {DOCS.map((d) => {
            const Icon = STATUS_ICON[d.status]
            return (
              <div key={d.id} className="flex flex-wrap items-center gap-4 px-5 py-4">
                <span className="grid h-10 w-10 place-items-center rounded-xl bg-slate-100 text-slate-500"><FileText size={18} /></span>
                <div className="min-w-0 flex-1">
                  <p className="font-semibold text-slate-800">{d.nama}</p>
                  <p className="text-xs text-slate-500">{d.tipe} · diunggah {d.tanggal}</p>
                  {d.alasan && <p className="mt-0.5 text-xs font-medium text-rose-600">Alasan ditolak: {d.alasan}</p>}
                </div>
                <div className="flex items-center gap-2">
                  <Icon size={16} className={d.status === 'terverifikasi' ? 'text-emerald-600' : d.status === 'pending' ? 'text-amber-500' : 'text-rose-600'} />
                  <StatusBadge status={d.status} />
                </div>
                {d.status === 'ditolak' && <Button variant="secondary" size="sm"><Upload size={14} /> Unggah Ulang</Button>}
              </div>
            )
          })}
        </div>
      </Card>
    </div>
  )
}
