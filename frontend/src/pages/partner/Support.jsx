import { useState } from 'react'
import { LifeBuoy, Send, MessageSquare, CheckCircle2, Inbox } from 'lucide-react'
import { Button, Card, Field, Input, Textarea, PageHeader, EmptyState } from '../../components/ui'

export default function Support() {
  const [subjek, setSubjek] = useState('')
  const [pesan, setPesan] = useState('')
  const [sent, setSent] = useState(false)

  const submit = (e) => {
    e.preventDefault()
    if (!subjek.trim() || !pesan.trim()) return
    setSent(true)
    setSubjek('')
    setPesan('')
    setTimeout(() => setSent(false), 4000)
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
          {sent && (
            <div className="mb-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
              <CheckCircle2 size={16} /> Permintaan bantuan berhasil dikirim. Tim kami akan segera merespons.
            </div>
          )}
          <form onSubmit={submit} className="space-y-4">
            <Field label="Subjek" required>
              <Input value={subjek} onChange={(e) => setSubjek(e.target.value)} placeholder="Ringkasan kendala" />
            </Field>
            <Field label="Pesan" required>
              <Textarea value={pesan} onChange={(e) => setPesan(e.target.value)} placeholder="Jelaskan kendalamu sedetail mungkin..." />
            </Field>
            <Button type="submit"><Send size={16} /> Kirim</Button>
          </form>
        </Card>

        <Card className="p-6">
          <div className="flex items-center gap-2">
            <MessageSquare size={18} className="text-brand-600" />
            <h2 className="font-bold text-slate-900">Butuh cepat?</h2>
          </div>
          <p className="mt-2 text-sm text-slate-500">Hubungi kami langsung melalui kanal berikut.</p>
          <div className="mt-4 space-y-2 text-sm">
            <a href="mailto:support@nontix.id" className="block rounded-xl bg-slate-50 px-4 py-3 font-medium text-slate-700 hover:bg-slate-100">✉️ support@nontix.id</a>
            <a href="https://wa.me/6281200000000" className="block rounded-xl bg-slate-50 px-4 py-3 font-medium text-slate-700 hover:bg-slate-100">💬 WhatsApp 0812-0000-0000</a>
          </div>
        </Card>
      </div>

      <Card className="mt-6">
        <div className="border-b border-slate-100 px-5 py-4">
          <h2 className="font-bold text-slate-900">Riwayat Permintaan</h2>
        </div>
        <EmptyState
          icon={Inbox}
          title="Belum ada permintaan bantuan"
          description="Permintaan bantuan yang kamu kirim akan tampil di sini beserta status penanganannya."
        />
      </Card>
    </div>
  )
}
