import { Link } from 'react-router-dom'
import { Ticket, Home } from 'lucide-react'
import { Button } from '../../components/ui'

export default function NotFound() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-6 text-center">
      <span className="grid h-16 w-16 place-items-center rounded-2xl bg-gradient-to-br from-brand-600 to-accent-500 text-white">
        <Ticket size={30} />
      </span>
      <p className="mt-6 text-6xl font-extrabold text-slate-900">404</p>
      <h1 className="mt-2 text-xl font-bold text-slate-800">Halaman tidak ditemukan</h1>
      <p className="mt-1 max-w-sm text-sm text-slate-500">
        Halaman yang kamu cari mungkin sudah dipindahkan atau tidak pernah ada.
      </p>
      <Link to="/" className="mt-6">
        <Button size="lg"><Home size={18} /> Kembali ke Beranda</Button>
      </Link>
    </div>
  )
}
