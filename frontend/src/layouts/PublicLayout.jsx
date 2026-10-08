import { useState } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { Menu, X, Ticket, Search, Instagram, Facebook, Linkedin } from 'lucide-react'
import { Button } from '../components/ui'
import { cn } from '../lib/utils'

const NAV = [
  { to: '/', label: 'Jelajahi Event' },
  { to: '/bantuan', label: 'Bantuan' },
]

export default function PublicLayout() {
  const [open, setOpen] = useState(false)

  return (
    <div className="flex min-h-screen flex-col">
      <header className="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 shadow-sm backdrop-blur-md">
        <div className="container-page flex h-[72px] items-center gap-4 md:h-20 md:gap-8 lg:gap-12">
          <Link to="/" className="flex shrink-0 items-center gap-3" onClick={() => setOpen(false)}>
            <span className="grid h-11 w-11 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-accent-500 text-white shadow-sm md:h-12 md:w-12">
              <Ticket size={24} />
            </span>
            <span className="font-display text-[22px] font-extrabold tracking-tight text-slate-900 md:text-2xl">Nontix</span>
          </Link>

          <nav className="hidden items-center gap-3 lg:flex">
            {NAV.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                className={({ isActive }) =>
                  cn(
                    'rounded-lg px-4 py-2.5 text-base font-medium transition',
                    isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                  )
                }
              >
                {item.label}
              </NavLink>
            ))}
          </nav>

          <div className="ml-auto hidden items-center gap-4 lg:flex">
            <Link to="/partner/masuk">
              <Button variant="secondary" className="px-4 py-2.5">Masuk Partner</Button>
            </Link>
            <Link to="/partner/daftar">
              <Button className="px-5 py-3 text-[15px] hover:-translate-y-0.5">
                Mulai Jual Tiket
              </Button>
            </Link>
          </div>

          <button className="ml-auto rounded-lg p-2 text-slate-600 lg:hidden" onClick={() => setOpen((v) => !v)}>
            {open ? <X size={22} /> : <Menu size={22} />}
          </button>
        </div>

        {open && (
          <div className="border-t border-slate-200 bg-white lg:hidden">
            <div className="container-page flex flex-col gap-1 py-3">
              {NAV.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  onClick={() => setOpen(false)}
                  className="rounded-lg px-3.5 py-2.5 text-[15px] font-medium text-slate-700 hover:bg-slate-100"
                >
                  {item.label}
                </NavLink>
              ))}
              <div className="mt-2 grid grid-cols-2 gap-2">
                <Link to="/partner/masuk" onClick={() => setOpen(false)}>
                  <Button variant="secondary" className="w-full">Masuk</Button>
                </Link>
                <Link to="/partner/daftar" onClick={() => setOpen(false)}>
                  <Button className="w-full">Daftar</Button>
                </Link>
              </div>
            </div>
          </div>
        )}
      </header>

      <main className="flex-1">
        <Outlet />
      </main>

      <footer className="mt-16 border-t border-slate-800 bg-slate-950 text-slate-300">
        <div className="container-page grid gap-10 py-12 sm:grid-cols-2 sm:gap-x-8 sm:gap-y-12 lg:grid-cols-[1.4fr_1fr_1fr_1fr] lg:gap-14 lg:py-16">
          <div className="sm:col-span-2 lg:col-span-1">
            <div className="flex items-center gap-2">
              <span className="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-brand-600 to-accent-500 text-white">
                <Ticket size={16} />
              </span>
              <span className="text-lg font-extrabold text-white">Nontix</span>
            </div>
            <p className="mt-4 max-w-sm text-sm leading-relaxed text-slate-300">
              Platform tiket event untuk penyelenggara di Indonesia. Buat acara, jual tiket, dan pantau penjualan dalam satu sistem.
            </p>
            <div className="mt-6 flex items-center gap-2">
              {[
                { label: 'Instagram', icon: Instagram },
                { label: 'Facebook', icon: Facebook },
                { label: 'LinkedIn', icon: Linkedin },
              ].map(({ label, icon: Icon }) => (
                <a key={label} href="#" aria-label={`Nontix di ${label}`} className="grid h-9 w-9 place-items-center rounded-lg border border-slate-700 text-slate-400 transition hover:border-brand-400 hover:bg-brand-500/10 hover:text-brand-300">
                  <Icon size={17} />
                </a>
              ))}
            </div>
          </div>
          <div>
            <h4 className="text-sm font-bold text-white">Untuk Pembeli</h4>
            <ul className="mt-4 space-y-3 text-sm text-slate-300">
              <li><Link to="/" className="transition hover:text-white">Jelajahi Event</Link></li>
              <li><Link to="/tiket/kirim-ulang" className="transition hover:text-white">Kirim Ulang Tiket</Link></li>
              <li><Link to="/bantuan" className="transition hover:text-white">Pusat Bantuan</Link></li>
            </ul>
          </div>
          <div>
            <h4 className="text-sm font-bold text-white">Untuk Penyelenggara</h4>
            <ul className="mt-4 space-y-3 text-sm text-slate-300">
              <li><Link to="/partner/daftar" className="transition hover:text-white">Daftar Partner</Link></li>
              <li><Link to="/partner/masuk" className="transition hover:text-white">Masuk Dashboard</Link></li>
              <li><Link to="/partner/support" className="transition hover:text-white">Dukungan</Link></li>
            </ul>
          </div>
          <div>
            <h4 className="text-sm font-bold text-white">Lainnya</h4>
            <ul className="mt-4 space-y-3 text-sm text-slate-300">
              <li><span className="text-slate-300">Syarat & Ketentuan</span></li>
              <li><span className="text-slate-300">Kebijakan Privasi</span></li>
            </ul>
          </div>
        </div>
        <div className="border-t border-slate-800 py-6">
          <p className="container-page text-center text-xs text-slate-400">
            © {new Date().getFullYear()} <span className="font-semibold text-slate-300">Nontix</span> — PT. DIARMA AKSARA MEDIA. Seluruh hak cipta dilindungi.
          </p>
        </div>
      </footer>
    </div>
  )
}
