import { useState } from 'react'
import { LifeBuoy, Send, Mail } from 'lucide-react'
import { Button, Card, Field, Input, Textarea, PageHeader } from '../../components/ui'

const EMAIL_BANTUAN = 'info@diamcreative.com'

export default function Support() {
  const [subjek, setSubjek] = useState('')
  const [pesan, setPesan] = useState('')

  // Backend tidak punya endpoint support sama sekali, jadi dulu form ini hanya
  // menampilkan "Permintaan bantuan berhasil dikirim" padahal tidak ada yang
  // terkirim. Sekarang form ini membuka aplikasi email pengguna dengan subjek
  // dan isi sudah terisi, sehingga pesannya benar-benar terkirim.
  const submit = (e) => {
    e.preventDefault()
    if (!subjek.trim() || !pesan.trim()) return
    const subject = encodeURIComponent(`[Bantuan Nontix] ${subjek.trim()}`)
    const body = encodeURIComponent(pesan.trim())
    window.location.href = `mailto:${EMAIL_BANTUAN}?subject=${subject}&body=${body}`
  }

  return (
    <div>
      <PageHeader title="Support" description="Hubungi tim bantuan Nontix bila menemui kendala." />

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="min-w-0 p-6 lg:col-span-2">
          <div className="mb-4 flex items-center gap-2">
            <LifeBuoy size={18} className="text-brand-600" />
            <h2 className="font-bold text-slate-900">Kirim Permintaan Bantuan</h2>
          </div>
          <form onSubmit={submit} className="space-y-4">
            <Field label="Subjek" required>
              <Input value={subjek} onChange={(e) => setSubjek(e.target.value)} placeholder="Ringkasan kendala" />
            </Field>
            <Field label="Pesan" required>
              <Textarea value={pesan} onChange={(e) => setPesan(e.target.value)} placeholder="Jelaskan kendalamu sedetail mungkin..." />
            </Field>
            <Button type="submit"><Send size={16} /> Buka Email &amp; Kirim</Button>
            <p className="text-xs text-slate-500">
              Tombol ini membuka aplikasi email di perangkatmu dengan subjek dan isi yang sudah terisi.
              Pastikan emailnya benar-benar terkirim dari sana.
            </p>
          </form>
        </Card>

        <Card className="p-6">
          <div className="flex items-center gap-2">
            <Mail size={18} className="text-brand-600" />
            <h2 className="font-bold text-slate-900">Kontak langsung</h2>
          </div>
          <p className="mt-2 text-sm text-slate-500">Bisa juga email kami langsung dari alamat mana pun.</p>
          <div className="mt-4 text-sm">
            <a
              href="mailto:info@diamcreative.com"
              className="block rounded-xl bg-slate-50 px-4 py-3 font-medium text-slate-700 hover:bg-slate-100"
            >
              ✉️ info@diamcreative.com
            </a>
          </div>
          <p className="mt-3 text-xs text-slate-400">Dibalas pada hari kerja, 09.00–18.00 WIB.</p>
        </Card>
      </div>
    </div>
  )
}
