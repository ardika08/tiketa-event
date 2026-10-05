import { useState } from 'react'
import { Mail, Send, CheckCircle2 } from 'lucide-react'
import { Button, Card, Field, Input } from '../../components/ui'
import { publicApi } from '../../lib/api'

export default function ResendTicket() {
  const [input, setInput] = useState('')
  const [sent, setSent] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    const value = input.trim()
    if (!value) {
      setError('Masukkan email atau kode pesanan terlebih dahulu.')
      return
    }
    setError('')
    setLoading(true)
    try {
      const payload = value.includes('@') ? { email: value } : { kode_order: value.toUpperCase() }
      await publicApi.resendTicket(payload)
      setSent(true)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="container-page max-w-lg py-16">
      <div className="mb-6 text-center">
        <div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-brand-100 text-brand-700">
          <Mail size={26} />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-slate-900">Kirim Ulang Tiket</h1>
        <p className="mt-1 text-sm text-slate-500">
          Email tiket hilang atau terhapus? Masukkan email atau kode pesanan, kami akan mengirim ulang ke email yang sama.
        </p>
      </div>

      <Card className="p-6">
        {sent ? (
          <div className="text-center">
            <div className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-emerald-600">
              <CheckCircle2 size={30} />
            </div>
            <h2 className="mt-4 text-lg font-bold text-slate-900">Email telah dikirim</h2>
            <p className="mt-1 text-sm text-slate-500">
              Jika data ditemukan, tiket akan dikirim ulang ke email yang sama seperti saat pembelian. Cek juga folder spam.
            </p>
            <Button variant="secondary" className="mt-5" onClick={() => { setSent(false); setInput('') }}>
              Kirim ke data lain
            </Button>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <Field label="Email atau Kode Pesanan" error={error}>
              <Input
                value={input}
                onChange={(e) => setInput(e.target.value)}
                placeholder="nama@email.com atau NTX-XXXXXX"
              />
            </Field>
            <Button type="submit" className="w-full" size="lg" disabled={loading}>
              <Send size={17} /> {loading ? 'Mengirim...' : 'Kirim Ulang Tiket'}
            </Button>
            <p className="text-center text-xs text-slate-400">
              Demi keamanan, tiket hanya dikirim ke email yang digunakan saat pembelian.
            </p>
          </form>
        )}
      </Card>
    </div>
  )
}
