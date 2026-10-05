import { useEffect, useState } from 'react'
import { Save, Building2, Check, Landmark } from 'lucide-react'
import { Button, Card, Field, Input, Textarea, PageHeader, Badge } from '../../components/ui'
import { ImagePicker } from '../../components/ImagePicker'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'

const EMPTY_FORM = {
  nama_penyelenggara: '',
  deskripsi: '',
  logo_url: '',
  nama_bank: '',
  nomor_rekening: '',
  nama_rekening: '',
}

export default function Profile() {
  const { data } = useApi(() => partnerApi.profile(), [])
  const org = data?.data
  const [form, setForm] = useState(EMPTY_FORM)
  const [saved, setSaved] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (org) {
      setForm({
        nama_penyelenggara: org.nama_penyelenggara || '',
        deskripsi: org.deskripsi || '',
        logo_url: org.logo_url || '',
        nama_bank: org.nama_bank || '',
        nomor_rekening: org.nomor_rekening || '',
        nama_rekening: org.nama_rekening || '',
      })
    }
  }, [org])

  const save = async () => {
    setSaving(true)
    try {
      await partnerApi.updateProfile(form)
      setSaved(true)
      setTimeout(() => setSaved(false), 2500)
    } finally {
      setSaving(false)
    }
  }

  const set = (key, value) => setForm((f) => ({ ...f, [key]: value }))

  return (
    <div>
      <PageHeader
        title="Profil Penyelenggara"
        description="Identitas yang tampil ke pembeli."
        action={<Button onClick={save} disabled={saving}><Save size={16} /> {saving ? 'Menyimpan...' : 'Simpan'}</Button>}
      />

      {saved && (
        <div className="mb-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
          <Check size={16} /> Profil berhasil disimpan.
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="min-w-0 space-y-6 lg:col-span-2">
          <Card className="p-6">
            <h2 className="mb-4 font-bold text-slate-900">Informasi Penyelenggara</h2>
            <div className="space-y-4">
              <Field label="Nama Penyelenggara" required>
                <Input value={form.nama_penyelenggara} onChange={(e) => set('nama_penyelenggara', e.target.value)} />
              </Field>
              <Field label="Deskripsi" hint="Tampil di halaman event untuk membangun kepercayaan pembeli.">
                <Textarea value={form.deskripsi} onChange={(e) => set('deskripsi', e.target.value)} />
              </Field>
            </div>
          </Card>

          <Card className="p-6">
            <h2 className="mb-4 font-bold text-slate-900">Logo</h2>
            <div className="flex items-center gap-4">
              <div className="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-2xl bg-slate-100 text-slate-400">
                {form.logo_url ? <img src={form.logo_url} alt="logo" className="h-full w-full object-cover" /> : <Building2 size={28} />}
              </div>
              <div className="min-w-0">
                <ImagePicker
                  value={form.logo_url}
                  onChange={(url) => set('logo_url', url)}
                  folder="logos"
                  label="Unggah Logo"
                  previewClassName="hidden"
                />
                <p className="mt-1.5 text-xs text-slate-500">Format PNG/JPG, maksimal 2MB.</p>
              </div>
            </div>
          </Card>

          <Card className="p-6">
            <div className="mb-4 flex items-center gap-2">
              <Landmark size={18} className="text-brand-600" />
              <div>
                <h2 className="font-bold text-slate-900">Rekening Pencairan</h2>
                <p className="text-sm text-slate-500">Dana penjualan tiket ditransfer ke rekening ini.</p>
              </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Nama Bank">
                <Input value={form.nama_bank} onChange={(e) => set('nama_bank', e.target.value)} placeholder="BCA / Mandiri / BRI" />
              </Field>
              <Field label="Nomor Rekening">
                <Input value={form.nomor_rekening} onChange={(e) => set('nomor_rekening', e.target.value)} placeholder="1234567890" />
              </Field>
              <div className="sm:col-span-2">
                <Field label="Nama Pemilik Rekening">
                  <Input value={form.nama_rekening} onChange={(e) => set('nama_rekening', e.target.value)} placeholder="Sesuai buku tabungan" />
                </Field>
              </div>
            </div>
          </Card>
        </div>

        <div>
          <Card className="p-6 lg:sticky lg:top-24">
            <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Pratinjau pembeli</p>
            <div className="rounded-2xl border border-slate-200 p-4">
              <div className="flex items-center gap-3">
                <div className="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-full bg-brand-100 text-brand-700">
                  {form.logo_url ? <img src={form.logo_url} alt="logo" className="h-full w-full object-cover" /> : <Building2 size={20} />}
                </div>
                <div className="min-w-0">
                  <p className="truncate font-bold text-slate-900">{form.nama_penyelenggara || 'Nama Penyelenggara'}</p>
                  <Badge color="green" className="mt-0.5">Terverifikasi</Badge>
                </div>
              </div>
              <p className="mt-3 text-sm text-slate-600">{form.deskripsi || 'Deskripsi penyelenggara akan tampil di sini.'}</p>
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}