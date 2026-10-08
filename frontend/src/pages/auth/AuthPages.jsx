import { useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { Ticket, ArrowRight, Check } from 'lucide-react'
import { Button, Field, Input } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'
import { formatRupiah } from '../../lib/utils'
import { BIAYA_LAYANAN } from '../../lib/constants'

function AuthShell({ title, subtitle, children, footer, bullets }) {
  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      <div className="relative hidden overflow-hidden bg-gradient-to-br from-brand-700 via-brand-600 to-accent-500 p-10 text-white lg:flex lg:flex-col lg:justify-between">
        <Link to="/" className="flex items-center gap-2">
          <span className="grid h-10 w-10 place-items-center rounded-xl bg-white/15 backdrop-blur">
            <Ticket size={20} />
          </span>
          <span className="flex flex-col leading-none">
            <span className="text-xl font-extrabold">Nontix</span>
            <span className="text-xs text-white/70">Ticket Management System</span>
          </span>
        </Link>
        <div>
          <h2 className="max-w-md text-3xl font-extrabold leading-tight text-white">
            Kelola event dan jual tiket online tanpa ribet.
          </h2>
          <ul className="mt-6 space-y-3">
            {bullets.map((b) => (
              <li key={b} className="flex items-center gap-3 text-white/90">
                <span className="grid h-6 w-6 place-items-center rounded-full bg-white/20"><Check size={14} /></span>
                {b}
              </li>
            ))}
          </ul>
        </div>
        <p className="text-sm text-white/60">Biaya layanan {formatRupiah(BIAYA_LAYANAN)}/tiket · Transparan & terjangkau</p>
      </div>

      <div className="flex items-center justify-center bg-slate-50 p-6">
        <div className="w-full max-w-md">
          <Link to="/" className="mb-8 flex items-center gap-2 lg:hidden">
            <span className="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-accent-500 text-white">
              <Ticket size={18} />
            </span>
            <span className="text-lg font-extrabold text-slate-900">Nontix</span>
          </Link>
          <h1 className="text-2xl font-bold text-slate-900">{title}</h1>
          <p className="mt-1 text-sm text-slate-500">{subtitle}</p>
          <div className="mt-6">{children}</div>
          <div className="mt-6 text-center text-sm text-slate-500">{footer}</div>
        </div>
      </div>
    </div>
  )
}

export function PartnerLogin() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ email: '', password: '' })
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  if (user?.peran === 'partner') return <Navigate to="/partner/analisis" replace />

  const submit = async (e) => {
    e.preventDefault()
    if (!form.email || !form.password) {
      setError('Email dan kata sandi wajib diisi.')
      return
    }
    setError('')
    setLoading(true)
    try {
      await login('partner', { email: form.email, password: form.password })
      navigate('/partner/analisis')
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <AuthShell
      title="Masuk ke Dashboard"
      subtitle="Kelola event, tiket, dan penjualanmu."
      bullets={['Buat event tanpa batas', 'Pantau penjualan real-time', 'Pencairan otomatis & transparan']}
      footer={<>Belum punya akun? <Link to="/partner/daftar" className="font-semibold text-brand-700 hover:underline">Daftar sekarang</Link></>}
    >
      <form onSubmit={submit} className="space-y-4">
        <Field label="Email">
          <Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="email@penyelenggara.com" />
        </Field>
        <Field label="Kata Sandi">
          <Input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="••••••••" />
        </Field>
        {error && <p className="text-sm font-medium text-rose-600">{error}</p>}
        <Button type="submit" className="w-full" size="lg" disabled={loading}>{loading ? 'Memproses...' : 'Masuk'} <ArrowRight size={18} /></Button>
      </form>
    </AuthShell>
  )
}

export function PartnerRegister() {
  const { user, register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ nama: '', email: '', password: '' })
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  if (user?.peran === 'partner') return <Navigate to="/partner/analisis" replace />

  const submit = async (e) => {
    e.preventDefault()
    if (!form.nama || !form.email || !form.password) {
      setError('Semua kolom wajib diisi.')
      return
    }
    if (form.password.length < 6) {
      setError('Kata sandi minimal 6 karakter.')
      return
    }
    setError('')
    setLoading(true)
    try {
      await register({
        nama: form.nama,
        email: form.email,
        password: form.password,
        nama_penyelenggara: form.nama,
      })
      navigate('/partner/analisis')
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <AuthShell
      title="Daftar sebagai Penyelenggara"
      subtitle="Mulai jual tiket eventmu dalam hitungan menit."
      bullets={['Gratis mendaftar', 'Atur tiket & kuota sesukamu', 'Laporan penjualan rapi']}
      footer={<>Sudah punya akun? <Link to="/partner/masuk" className="font-semibold text-brand-700 hover:underline">Masuk</Link></>}
    >
      <form onSubmit={submit} className="space-y-4">
        <Field label="Nama Penyelenggara">
          <Input value={form.nama} onChange={(e) => setForm({ ...form, nama: e.target.value })} placeholder="Nama brand / komunitas" />
        </Field>
        <Field label="Email">
          <Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="email@penyelenggara.com" />
        </Field>
        <Field label="Kata Sandi" hint="Minimal 6 karakter">
          <Input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="••••••••" />
        </Field>
        {error && <p className="text-sm font-medium text-rose-600">{error}</p>}
        <Button type="submit" className="w-full" size="lg" disabled={loading}>{loading ? 'Memproses...' : 'Daftar & Masuk'} <ArrowRight size={18} /></Button>
      </form>
    </AuthShell>
  )
}

export function AdminLogin() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ email: '', password: '' })
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  if (user?.peran === 'admin') return <Navigate to="/admin/dashboard" replace />

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      await login('admin', { email: form.email, password: form.password })
      navigate('/admin/dashboard')
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <AuthShell
      title="Dashboard Admin Platform"
      subtitle="Pantau seluruh event, mitra, dan pendapatan platform."
      bullets={['Ringkasan semua event', 'Kelola mitra penyelenggara', 'Pantau biaya layanan']}
      footer={<Link to="/" className="font-semibold text-brand-700 hover:underline">Kembali ke situs</Link>}
    >
      <form onSubmit={submit} className="space-y-4">
        <Field label="Email Admin">
          <Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
        </Field>
        <Field label="Kata Sandi">
          <Input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
        </Field>
        {error && <p className="text-sm font-medium text-rose-600">{error}</p>}
        <Button type="submit" className="w-full" size="lg" disabled={loading}>{loading ? 'Memproses...' : 'Masuk sebagai Admin'}</Button>
      </form>
    </AuthShell>
  )
}
