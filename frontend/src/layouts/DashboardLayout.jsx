import { useState } from 'react'
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'
import { ChevronDown, LogOut, Menu, Ticket, X, ExternalLink } from 'lucide-react'
import { cn } from '../lib/utils'
import { useAuth } from '../context/AuthContext'
import { partnerNav, adminNav } from './navConfig'

function NavGroup({ item }) {
  const [open, setOpen] = useState(true)
  const Icon = item.icon
  return (
    <div>
      <button
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
      >
        {Icon && <Icon size={17} className="shrink-0 text-slate-400" />}
        <span className="flex-1 text-left">{item.label}</span>
        <ChevronDown size={15} className={cn('transition', open && 'rotate-180')} />
      </button>
      {open && (
        <div className="mt-1 space-y-0.5 border-l border-slate-200 pl-3 ml-4">
          {item.children.map((child) => (
            <NavLink
              key={child.to}
              to={child.to}
              end
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition',
                  isActive
                    ? 'bg-brand-50 font-semibold text-brand-700'
                    : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700',
                )
              }
            >
              {child.icon && <child.icon size={15} className="shrink-0" />}
              <span>{child.label}</span>
            </NavLink>
          ))}
        </div>
      )}
    </div>
  )
}

export default function DashboardLayout({ role = 'partner' }) {
  const [open, setOpen] = useState(false)
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const nav = role === 'admin' ? adminNav : partnerNav

  const handleLogout = () => {
    logout()
    navigate(role === 'admin' ? '/admin/masuk' : '/partner/masuk')
  }

  const SidebarContent = (
    <div className="flex h-full flex-col">
      <Link to="/" className="flex items-center gap-2 border-b border-slate-200 px-5 py-4">
        <span className="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-accent-500 text-white">
          <Ticket size={18} />
        </span>
        <span className="flex flex-col leading-none">
          <span className="font-display text-lg font-extrabold tracking-tight text-slate-900">Nontix</span>
          <span className="text-[10px] font-semibold uppercase tracking-wide text-brand-600">
            {role === 'admin' ? 'Admin Platform' : 'Partner'}
          </span>
        </span>
      </Link>

      <nav className="flex-1 space-y-1 overflow-y-auto p-3">
        {nav.map((item) =>
          item.type === 'group' ? (
            <NavGroup key={item.label} item={item} />
          ) : (
            <NavLink
              key={item.to}
              to={item.to}
              end
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                  isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100',
                )
              }
            >
              {item.icon && <item.icon size={17} className="shrink-0 text-slate-400" />}
              {item.label}
            </NavLink>
          ),
        )}
      </nav>

      <div className="border-t border-slate-200 p-3">
        <Link
          to="/"
          className="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-500 hover:bg-slate-100"
        >
          <ExternalLink size={16} /> Lihat Halaman Publik
        </Link>
        <div className="flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-2.5">
          <div className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white">
            {(user?.nama || 'N').charAt(0)}
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold text-slate-800">{user?.nama || 'Tamu'}</p>
            <p className="truncate text-xs text-slate-500">{user?.email}</p>
          </div>
          <button onClick={handleLogout} title="Logout" className="rounded-lg p-1.5 text-slate-400 hover:bg-white hover:text-rose-600">
            <LogOut size={16} />
          </button>
        </div>
      </div>
    </div>
  )

  return (
    <div className="min-h-screen bg-slate-50">
      <aside className="fixed inset-y-0 left-0 z-40 hidden w-72 border-r border-slate-200 bg-white lg:block">
        {SidebarContent}
      </aside>

      {open && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <div className="absolute inset-0 bg-slate-900/50" onClick={() => setOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-72 bg-white">
            <button className="absolute right-3 top-4 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" onClick={() => setOpen(false)}>
              <X size={18} />
            </button>
            {SidebarContent}
          </aside>
        </div>
      )}

      <div className="lg:pl-72">
        <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:hidden">
          <button onClick={() => setOpen(true)} className="rounded-lg p-2 text-slate-600 hover:bg-slate-100">
            <Menu size={20} />
          </button>
          <span className="font-bold text-slate-900">Nontix</span>
        </header>
        <main className="p-5 sm:p-8 lg:p-10">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
