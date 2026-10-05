import { Plus, UserCog, Mail, DoorOpen } from 'lucide-react'
import { Button, Card, PageHeader, Badge } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'

export default function MasterStaff() {
  const { data, loading } = useApi(() => partnerApi.staffs(), [])
  const staffs = data?.data || []

  return (
    <div>
      <PageHeader
        title="Staff"
        description="Petugas yang bertugas di pintu masuk acara."
        action={<Button><Plus size={16} /> Tambah Staff</Button>}
      />
      {loading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <div key={i} className="h-48 animate-pulse rounded-2xl bg-slate-100" />)}
        </div>
      ) : (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {staffs.map((s) => (
          <Card key={s.id} className="p-5">
            <div className="flex items-center gap-3">
              <span className="grid h-12 w-12 place-items-center rounded-full bg-brand-600 text-lg font-bold text-white">
                {s.nama.charAt(0)}
              </span>
              <div className="min-w-0">
                <p className="truncate font-bold text-slate-900">{s.nama}</p>
                <p className="truncate text-xs text-slate-500">{s.peran}</p>
              </div>
            </div>
            <div className="mt-4 space-y-2 text-sm text-slate-600">
              <p className="flex items-center gap-2 truncate"><Mail size={14} className="text-slate-400" /> {s.email}</p>
              <p className="flex items-center gap-2"><DoorOpen size={14} className="text-slate-400" /> {s.gate?.nama_gate || 'Belum ditugaskan'}</p>
            </div>
            <div className="mt-4 flex items-center justify-between">
              <Badge color="green">Aktif</Badge>
              <Button variant="ghost" size="sm"><UserCog size={14} /> Kelola</Button>
            </div>
          </Card>
        ))}
      </div>
      )}
    </div>
  )
}
