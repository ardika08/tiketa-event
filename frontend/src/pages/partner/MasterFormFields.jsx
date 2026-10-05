import { Plus, Trash2, GripVertical } from 'lucide-react'
import { Button, Card, PageHeader, Badge } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'

const TIPE_LABEL = { teks: 'Teks', email: 'Email', nomor: 'Nomor', sosial: 'Sosial' }

export default function MasterFormFields() {
  const { data, loading, reload } = useApi(() => partnerApi.formFields(), [])
  const fields = data?.data || []
  const wajib = fields.filter((f) => f.wajib).length

  const tambah = async () => {
    await partnerApi.createFormField({ label: 'Field Baru', tipe: 'teks', wajib: false, urutan: fields.length + 1 })
    reload()
  }

  const hapus = async (id) => {
    await partnerApi.deleteFormField(id)
    reload()
  }

  return (
    <div>
      <PageHeader
        title="Formulir Custom"
        description="Field data pembeli yang tampil di halaman pembelian."
        action={<Button onClick={tambah}><Plus size={16} /> Tambah Field</Button>}
      />
      <Card className="p-5">
        <div className="mb-4 flex flex-wrap gap-2 text-sm text-slate-500">
          <span>{fields.length} field</span>·<span>{wajib} wajib diisi</span>·<span>{fields.length - wajib} opsional</span>
        </div>
        <div className="space-y-2">
          {fields.map((f) => (
            <div key={f.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 p-3">
              <GripVertical size={16} className="text-slate-300" />
              <div className="flex-1">
                <p className="font-medium text-slate-800">{f.label}</p>
                <p className="text-xs text-slate-500">Tipe: {TIPE_LABEL[f.tipe]} · Urutan {f.urutan}</p>
              </div>
              <Badge color={f.wajib ? 'red' : 'slate'}>{f.wajib ? 'Wajib' : 'Opsional'}</Badge>
              <Button variant="ghost" size="icon" className="text-rose-600" onClick={() => hapus(f.id)}>
                <Trash2 size={16} />
              </Button>
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}
