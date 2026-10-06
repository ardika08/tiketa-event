import { useState } from 'react'
import { FileText, CalendarDays, Handshake, TrendingUp, Download, CheckCircle2 } from 'lucide-react'
import { Button, Card, PageHeader, Modal, Badge } from '../../components/ui'
import { cn } from '../../lib/utils'

const REPORTS = [
  { id: 'event', icon: CalendarDays, title: 'Data Acara', desc: 'Seluruh acara beserta penyelenggara, status, dan penjualannya.', format: 'CSV / XLSX' },
  { id: 'mitra', icon: Handshake, title: 'Data Mitra', desc: 'Daftar mitra penyelenggara, kontak, dan status verifikasi.', format: 'CSV / XLSX' },
  { id: 'pendapatan', icon: TrendingUp, title: 'Data Pendapatan', desc: 'Rincian biaya layanan per event dan per mitra.', format: 'CSV / XLSX' },
]

export default function AdminReports() {
  const [selected, setSelected] = useState(REPORTS[0])
  const [format, setFormat] = useState('CSV')
  const [open, setOpen] = useState(false)
  const [done, setDone] = useState(false)

  const download = () => {
    setDone(true)
    setTimeout(() => { setOpen(false); setDone(false) }, 1800)
  }

  return (
    <div>
      <PageHeader title="Laporan & Ekspor" description="Unduh rekap data platform untuk kebutuhan internal." />

      <div className="grid gap-4 sm:grid-cols-3">
        {REPORTS.map((r) => (
          <button
            key={r.id}
            onClick={() => setSelected(r)}
            className={cn(
              'card p-5 text-left transition',
              selected.id === r.id ? 'ring-2 ring-brand-500' : 'hover:shadow-md',
            )}
          >
            <span className={cn('grid h-11 w-11 place-items-center rounded-xl', selected.id === r.id ? 'bg-brand-600 text-white' : 'bg-brand-100 text-brand-700')}>
              <r.icon size={20} />
            </span>
            <h3 className="mt-3 font-bold text-slate-900">{r.title}</h3>
            <p className="mt-1 text-sm text-slate-500">{r.desc}</p>
            <Badge color="slate" className="mt-3">{r.format}</Badge>
          </button>
        ))}
      </div>

      <Card className="mt-6 p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h2 className="font-bold text-slate-900">Ekspor {selected.title}</h2>
            <p className="mt-1 text-sm text-slate-500">Pilih format file lalu unduh laporan.</p>
          </div>
          <div className="flex items-end gap-3">
            <div>
              <label className="label">Format</label>
              <select value={format} onChange={(e) => setFormat(e.target.value)} className="input w-40">
                <option>CSV</option>
                <option>XLSX</option>
              </select>
            </div>
            <Button onClick={() => setOpen(true)}><Download size={16} /> Ekspor</Button>
          </div>
        </div>
      </Card>

      <Modal
        open={open}
        onClose={() => { setOpen(false); setDone(false) }}
        title="Konfirmasi Ekspor"
        footer={
          done ? <Button onClick={() => { setOpen(false); setDone(false) }}>Selesai</Button> : (
            <>
              <Button variant="secondary" onClick={() => setOpen(false)}>Batal</Button>
              <Button onClick={download}><Download size={16} /> Unduh</Button>
            </>
          )
        }
      >
        {done ? (
          <div className="py-6 text-center">
            <div className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-emerald-600"><CheckCircle2 size={28} /></div>
            <p className="mt-3 font-semibold text-slate-800">File berhasil disiapkan</p>
            <p className="text-sm text-slate-500">{selected.id}-report.{format.toLowerCase()} siap diunduh.</p>
          </div>
        ) : (
          <div className="flex items-center gap-3">
            <FileText className="shrink-0 text-brand-600" size={20} />
            <p className="text-sm text-slate-600">
              Ekspor <span className="font-semibold">{selected.title}</span> dalam format <span className="font-semibold">{format}</span>?
            </p>
          </div>
        )}
      </Modal>
    </div>
  )
}
