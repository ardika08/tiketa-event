import { Minus, Plus } from 'lucide-react'
import { cn } from '../lib/utils'

export default function QtyStepper({ value, min = 0, max = 10, onChange, disabled }) {
  const dec = () => onChange(Math.max(min, value - 1))
  const inc = () => onChange(Math.min(max, value + 1))
  return (
    <div className="inline-flex items-center rounded-xl border border-slate-300 bg-white">
      <button
        type="button"
        onClick={dec}
        disabled={disabled || value <= min}
        className="grid h-9 w-9 place-items-center rounded-l-xl text-slate-600 hover:bg-slate-50 disabled:opacity-40"
      >
        <Minus size={15} />
      </button>
      <span className={cn('w-10 text-center text-sm font-bold text-slate-800')}>{value}</span>
      <button
        type="button"
        onClick={inc}
        disabled={disabled || value >= max}
        className="grid h-9 w-9 place-items-center rounded-r-xl text-slate-600 hover:bg-slate-50 disabled:opacity-40"
      >
        <Plus size={15} />
      </button>
    </div>
  )
}
