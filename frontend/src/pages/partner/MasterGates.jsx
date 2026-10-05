import { Plus, DoorOpen, Power } from 'lucide-react'
import { Button, Card, PageHeader, StatusBadge, Badge } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'

export default function MasterGates() {
  const { data, loading } = useApi(() => partnerApi.gates(), [])
  const gates = data?.data || []

  return (
    <div>
      <PageHeader
        title="Gate"
        description="Pintu masuk yang digunakan saat check-in acara."
        action={<Button><Plus size={16} /> Tambah Gate</Button>}
      />
      {loading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <div key={i} className="h-44 animate-pulse rounded-2xl bg-slate-100" />)}
        </div>
      ) : (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {gates.map((g) => (
          <Card key={g.id} className="p-5">
            <div className="flex items-center justify-between">
              <span className="grid h-11 w-11 place-items-center rounded-xl bg-brand-100 text-brand-700"><DoorOpen size={20} /></span>
              <StatusBadge status={g.status} />
            </div>
            <h3 className="mt-3 font-bold text-slate-900">{g.nama_gate}</h3>
            <p className="text-xs text-slate-500">Kode gate: GT-{String(g.id).padStart(3, '0')}</p>
            <div className="mt-4 flex items-center gap-2">
              <Button variant="secondary" size="sm" className="flex-1"><Power size={14} /> {g.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
              <Badge color="slate">{g.status === 'aktif' ? 'Siap dipakai' : 'Tidak aktif'}</Badge>
            </div>
          </Card>
        ))}
      </div>
      )}
    </div>
  )
}
