import { X } from 'lucide-react'
import { cn } from '../lib/utils'

const VARIANTS = {
  primary: 'bg-brand-600 text-white shadow-[var(--shadow-button)] hover:bg-brand-700 active:bg-brand-800',
  secondary: 'border border-slate-300 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-50',
  ghost: 'text-slate-600 hover:bg-slate-100',
  danger: 'bg-rose-600 text-white shadow-[var(--shadow-button)] hover:bg-rose-700',
  success: 'bg-emerald-600 text-white shadow-[var(--shadow-button)] hover:bg-emerald-700',
  accent: 'bg-accent-500 text-white shadow-[var(--shadow-button)] hover:bg-accent-600',
}

const SIZES = {
  sm: 'px-3.5 py-2 text-xs rounded-[var(--radius-control)]',
  md: 'px-4 py-2.5 text-sm rounded-[var(--radius-control)]',
  lg: 'px-6 py-3 text-[15px] rounded-[var(--radius-control)]',
  icon: 'p-2 rounded-[var(--radius-control)]',
}

export function Button({ variant = 'primary', size = 'md', className, children, ...props }) {
  return (
    <button
      className={cn(
        'inline-flex items-center justify-center gap-2 font-semibold transition duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
        VARIANTS[variant],
        SIZES[size],
        className,
      )}
      {...props}
    >
      {children}
    </button>
  )
}

const BADGE_COLORS = {
  slate: 'bg-slate-100 text-slate-700 ring-slate-200',
  brand: 'bg-brand-100 text-brand-700 ring-brand-200',
  green: 'bg-emerald-100 text-emerald-700 ring-emerald-200',
  amber: 'bg-amber-100 text-amber-700 ring-amber-200',
  red: 'bg-rose-100 text-rose-700 ring-rose-200',
  blue: 'bg-sky-100 text-sky-700 ring-sky-200',
}

export function Badge({ color = 'slate', className, children }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset',
        BADGE_COLORS[color],
        className,
      )}
    >
      {children}
    </span>
  )
}

const STATUS_MAP = {
  aktif: 'green',
  lunas: 'green',
  berhasil: 'green',
  terverifikasi: 'green',
  selesai: 'slate',
  draft: 'amber',
  pending: 'amber',
  diajukan: 'amber',
  terbuka: 'amber',
  diproses: 'blue',
  nonaktif: 'slate',
  dibatalkan: 'red',
  kadaluarsa: 'red',
  ditolak: 'red',
  gagal: 'red',
  belum_hadir: 'slate',
  hadir: 'green',
}

export function StatusBadge({ status }) {
  const color = STATUS_MAP[status] || 'slate'
  const label = String(status || '-').replace(/_/g, ' ')
  return <Badge color={color} className="capitalize">{label}</Badge>
}

export function Card({ className, children, ...props }) {
  return (
    <div className={cn('card', className)} {...props}>
      {children}
    </div>
  )
}

export function Field({ label, hint, error, required, children }) {
  return (
    <div>
      {label && (
        <label className="label">
          {label} {required && <span className="text-rose-500">*</span>}
        </label>
      )}
      {children}
      {hint && !error && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
      {error && <p className="mt-1 text-xs font-medium text-rose-600">{error}</p>}
    </div>
  )
}

export function Input({ className, ...props }) {
  return <input className={cn('input', className)} {...props} />
}

export function Select({ className, children, ...props }) {
  return (
    <select className={cn('input appearance-none pr-9', className)} {...props}>
      {children}
    </select>
  )
}

export function Textarea({ className, ...props }) {
  return <textarea className={cn('input min-h-[110px]', className)} {...props} />
}

export function Modal({ open, onClose, title, children, footer, size = 'md' }) {
  if (!open) return null
  const sizes = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center overflow-hidden p-0 sm:items-center sm:p-4">
      <div className="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onClick={onClose} />
      <div
        className={cn(
          'relative z-10 flex max-h-[92vh] w-full flex-col rounded-t-2xl bg-white shadow-xl sm:max-h-[85vh] sm:rounded-[var(--radius-card)]',
          sizes[size],
        )}
      >
        <div className="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
          <h3 className="min-w-0 truncate pr-2 text-base font-semibold text-slate-900">{title}</h3>
          <button onClick={onClose} className="shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
            <X size={18} />
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>
        {footer && (
          <div className="flex shrink-0 flex-col-reverse gap-2 border-t border-slate-200 px-5 py-4 sm:flex-row sm:justify-end">
            {footer}
          </div>
        )}
      </div>
    </div>
  )
}

export function Stat({ label, value, icon: Icon, hint, trend, color = 'brand' }) {
  const colors = {
    brand: 'bg-brand-100 text-brand-700',
    green: 'bg-emerald-100 text-emerald-700',
    amber: 'bg-amber-100 text-amber-700',
    blue: 'bg-sky-100 text-sky-700',
    rose: 'bg-rose-100 text-rose-700',
  }
  return (
    <Card className="p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-medium text-slate-500">{label}</p>
          <p className="mt-1.5 truncate text-[28px] font-bold leading-tight text-slate-900">{value}</p>
          {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
          {trend && <p className="mt-1 text-xs font-semibold text-emerald-600">{trend}</p>}
        </div>
        {Icon && (
          <div className={cn('rounded-[var(--radius-control)] p-2.5', colors[color])}>
            <Icon size={20} />
          </div>
        )}
      </div>
    </Card>
  )
}

export function EmptyState({ icon: Icon, title, description, action }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
      {Icon && (
        <div className="mb-3 rounded-2xl bg-slate-100 p-4 text-slate-400">
          <Icon size={28} />
        </div>
      )}
      <h3 className="text-base font-semibold text-slate-800">{title}</h3>
      {description && <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}

export function ProgressBar({ value, max = 100, color = 'bg-brand-600' }) {
  const pct = Math.min(100, Math.max(0, (value / (max || 1)) * 100))
  return (
    <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
      <div className={cn('h-full rounded-full', color)} style={{ width: `${pct}%` }} />
    </div>
  )
}

export function PageHeader({ title, description, action }) {
  return (
    <div className="mb-7 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <h1 className="truncate text-2xl font-semibold text-slate-900 sm:text-[28px]">{title}</h1>
        {description && <p className="mt-1.5 break-words text-[15px] text-slate-500">{description}</p>}
      </div>
      {action && <div className="flex shrink-0 flex-wrap gap-2">{action}</div>}
    </div>
  )
}
