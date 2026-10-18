import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus, UserCog, Mail, DoorOpen, Trash2, Users, AlertCircle } from 'lucide-react'
import {
  Button, Card, PageHeader, StatusBadge, Modal, Field, Input, Select, EmptyState,
} from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'

// Peran yang umum di pintu masuk. Bebas diganti nanti kalau vendor punya
// istilah sendiri — ini hanya isi awal dropdown.
const PERAN = ['Scanner', 'Koordinator', 'Usher', 'Ticketing']

const EMPTY_FORM = {
  nama: '',
  email: '',
  peran: 'Scanner',
  gate_id: '',
  status: 'aktif',
}

export default function MasterStaff() {
  const { data, loading, reload } = useApi(() => partnerApi.staffs(), [])
  const { data: gatesData } = useApi(() => partnerApi.gates(), [])

  const staffs = data?.data || []
  const gates = gatesData?.data || []

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState('create') // 'create' | 'edit'
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const setField = (key, value) => setForm((prev) => ({ ...prev, [key]: value }))

  const openCreate = () => {
    setModalMode('create')
    setEditingId(null)
    // Kalau gate-nya cuma satu (kasus umum: 1 gerbang), langsung dipilihkan.
    setForm({ ...EMPTY_FORM, gate_id: gates.length === 1 ? String(gates[0].id) : '' })
    setFormError('')
    setModalOpen(true)
  }

  const openEdit = (s) => {
    setModalMode('edit')
    setEditingId(s.id)
    setForm({
      nama: s.nama || '',
      email: s.email || '',
      peran: s.peran || 'Scanner',
      gate_id: s.gate_id ? String(s.gate_id) : '',
      status: s.status || 'aktif',
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleSubmit = async (e) => {
    e?.preventDefault()
    setFormError('')

    if (!form.nama.trim()) {
      setFormError('Nama staff wajib diisi.')
      return
    }

    const payload = {
      nama: form.nama.trim(),
      email: form.email.trim() || null,
      peran: form.peran || null,
      gate_id: form.gate_id ? Number(form.gate_id) : null,
    }

    setSaving(true)
    try {
      if (modalMode === 'create') {
        await partnerApi.createStaff(payload)
      } else {
        await partnerApi.updateStaff(editingId, { ...payload, status: form.status })
      }
      setModalOpen(false)
      reload()
    } catch (err) {
      const respon = err.response?.data
      const pesanField = respon?.errors ? Object.values(respon.errors)[0]?.[0] : null
      setFormError(pesanField || respon?.message || 'Gagal menyimpan staff.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      await partnerApi.deleteStaff(deleteTarget.id)
      setDeleteTarget(null)
      reload()
    } catch (err) {
      alert(err.response?.data?.message || 'Gagal menghapus staff.')
    } finally {
      setDeleting(false)
    }
  }

  const jumlahAktif = staffs.filter((s) => s.status === 'aktif').length

  return (
    <div>
      <PageHeader
        title="Staff"
        description="Petugas yang bertugas di pintu masuk acara."
        action={<Button onClick={openCreate}><Plus size={16} /> Tambah staff</Button>}
      />

      {!loading && staffs.length > 0 && (
        <p className="mb-4 text-xs text-slate-500">
          {staffs.length} staff · {jumlahAktif} aktif · {staffs.length - jumlahAktif} nonaktif
        </p>
      )}

      {loading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <div key={i} className="h-48 animate-pulse rounded-2xl bg-slate-100" />)}
        </div>
      ) : staffs.length === 0 ? (
        <Card>
          <EmptyState
            icon={Users}
            title="Belum ada staff"
            description="Tambahkan petugas yang bertugas di pintu masuk, lalu tugaskan ke gate."
            action={<Button onClick={openCreate}><Plus size={16} /> Tambah staff</Button>}
          />
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {staffs.map((s) => (
            <Card key={s.id} className="p-5">
              <div className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 items-center gap-3">
                  <span className="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-brand-600 text-lg font-bold text-white">
                    {(s.nama || '?').charAt(0).toUpperCase()}
                  </span>
                  <div className="min-w-0">
                    <p className="truncate font-bold text-slate-900">{s.nama}</p>
                    <p className="truncate text-xs text-slate-500">{s.peran || 'Scanner'}</p>
                  </div>
                </div>
                <StatusBadge status={s.status} />
              </div>

              <div className="mt-4 space-y-2 text-sm text-slate-600">
                <p className="flex items-center gap-2 truncate">
                  <Mail size={14} className="shrink-0 text-slate-400" />
                  {s.email || <span className="text-slate-400">Email belum diisi</span>}
                </p>
                <p className="flex items-center gap-2 truncate">
                  <DoorOpen size={14} className="shrink-0 text-slate-400" />
                  {s.gate?.nama_gate || <span className="text-slate-400">Belum ditugaskan</span>}
                </p>
              </div>

              <div className="mt-4 flex items-center gap-2">
                <Button variant="secondary" size="sm" className="flex-1" onClick={() => openEdit(s)}>
                  <UserCog size={14} /> Kelola
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setDeleteTarget(s)}
                  aria-label={`Hapus ${s.nama}`}
                >
                  <Trash2 size={14} className="text-rose-500" />
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={modalMode === 'create' ? 'Tambah staff' : 'Kelola staff'}
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>Batal</Button>
            <Button onClick={handleSubmit} disabled={saving}>
              {saving ? 'Menyimpan…' : 'Simpan'}
            </Button>
          </>
        }
      >
        <form onSubmit={handleSubmit} className="space-y-4">
          {formError && (
            <div className="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
              <AlertCircle size={16} className="mt-0.5 shrink-0" />
              <span>{formError}</span>
            </div>
          )}

          <Field label="Nama" required>
            <Input
              value={form.nama}
              onChange={(e) => setField('nama', e.target.value)}
              placeholder="Nama petugas"
              autoFocus
            />
          </Field>

          <Field
            label="Email"
            hint="Untuk catatan pembagian tugas. Petugas belum bisa login sendiri di versi ini."
          >
            <Input
              type="email"
              value={form.email}
              onChange={(e) => setField('email', e.target.value)}
              placeholder="Opsional"
            />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Peran">
              <Select value={form.peran} onChange={(e) => setField('peran', e.target.value)}>
                {PERAN.map((p) => <option key={p} value={p}>{p}</option>)}
              </Select>
            </Field>

            <Field label="Gate">
              <Select value={form.gate_id} onChange={(e) => setField('gate_id', e.target.value)}>
                <option value="">Belum ditugaskan</option>
                {gates.map((g) => <option key={g.id} value={g.id}>{g.nama_gate}</option>)}
              </Select>
            </Field>
          </div>

          {modalMode === 'edit' && (
            <Field label="Status">
              <Select value={form.status} onChange={(e) => setField('status', e.target.value)}>
                <option value="aktif">Aktif</option>
                <option value="nonaktif">Nonaktif</option>
              </Select>
            </Field>
          )}

          {gates.length === 0 && (
            <p className="text-xs text-amber-700">
              Belum ada gate.{' '}
              <Link to="/partner/master/gate" className="underline">Tambahkan gate</Link>{' '}
              dulu supaya staff bisa ditugaskan.
            </p>
          )}
        </form>
      </Modal>

      <Modal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title="Hapus staff?"
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleteTarget(null)} disabled={deleting}>Batal</Button>
            <Button onClick={handleDelete} disabled={deleting}>
              {deleting ? 'Menghapus…' : 'Hapus'}
            </Button>
          </>
        }
      >
        <p className="text-sm text-slate-600">
          <strong>{deleteTarget?.nama}</strong> akan dihapus dari daftar staff.
          Riwayat check-in yang sudah tercatat tidak berubah.
        </p>
      </Modal>
    </div>
  )
}
