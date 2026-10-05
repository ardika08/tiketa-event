import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Search, Handshake, ChevronRight, CheckCircle2, Clock, XCircle } from 'lucide-react'
import { Card, PageHeader, Input, Select, StatusBadge, Badge, EmptyState } from '../../components/ui'
import { adminApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah } from '../../lib/utils'

const VERIF = {
  terverifikasi: { icon: CheckCircle2, color: 'text-emerald-600' },
  pending: { icon: Clock, color: 'text-amber-500' },
  ditolak: { icon: XCircle, color: 'text-rose-600' },
}

export default function AdminPartners() {
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('all')
  const { data, loading } = useApi(() => adminApi.partners(), [])
  const partners = data?.data || []

  const filtered = useMemo(
    () =>
      partners.filter((o) => {
        const matchQ = o.nama_penyelenggara.toLowerCase().includes(q.toLowerCase()) || (o.email || '').toLowerCase().includes(q.toLowerCase())
        const matchS = status === 'all' || o.status_verifikasi === status
        return matchQ && matchS
      }),
    [partners, q, status],
  )

  return (
    <div>
      <PageHeader title="Kelola Mitra Penyelenggara" description="Daftar akun penyelenggara yang terdaftar di platform." />

      <Card className="mb-4 p-4">
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="relative flex-1">
            <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari mitra..." className="pl-10" />
          </div>
          <Select value={status} onChange={(e) => setStatus(e.target.value)} className="sm:w-56">
            <option value="all">Semua Verifikasi</option>
            <option value="terverifikasi">Terverifikasi</option>
            <option value="pending">Pending</option>
            <option value="ditolak">Ditolak</option>
          </Select>
        </div>
      </Card>

      {loading ? (
        <div className="grid gap-4 sm:grid-cols-2">
          {[1, 2].map((i) => <div key={i} className="h-24 animate-pulse rounded-2xl bg-slate-100" />)}
        </div>
      ) : filtered.length === 0 ? (
        <Card><EmptyState icon={Handshake} title="Tidak ada mitra" description="Coba ubah filter pencarian." /></Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {filtered.map((o) => {
            const V = VERIF[o.status_verifikasi] || VERIF.pending
            return (
              <Link key={o.id} to={`/admin/mitra/${o.id}`} className="card flex items-center gap-4 p-4 transition hover:shadow-md">
                <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-100 text-lg font-bold text-brand-700">
                  {o.nama_penyelenggara.charAt(0)}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <p className="truncate font-bold text-slate-900">{o.nama_penyelenggara}</p>
                    <V.icon size={15} className={V.color} />
                  </div>
                  <p className="truncate text-sm text-slate-500">{o.email}</p>
                  <div className="mt-1 flex items-center gap-2">
                    <StatusBadge status={o.status_verifikasi} />
                    <Badge color={o.status_akun === 'aktif' ? 'green' : 'slate'}>{o.status_akun}</Badge>
                    <span className="text-xs text-slate-400">{o.jumlah_event} event · {formatRupiah(o.pendapatan_platform || 0)}</span>
                  </div>
                </div>
                <ChevronRight size={18} className="shrink-0 text-slate-300" />
              </Link>
            )
          })}
        </div>
      )}
    </div>
  )
}
