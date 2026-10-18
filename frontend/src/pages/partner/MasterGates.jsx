import { useState } from 'react'
import { Plus, DoorOpen, Power, Trash2, AlertCircle, CalendarDays, Users } from 'lucide-react'
import {
  Button, Card, PageHeader, StatusBadge, Modal, Field, Input, Select, EmptyState,
} from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'

const EMPTY_FORM = {
  nama_gate: '',
  event_id: '',
  status: 'aktif',
}

export default function MasterGates() {
  const { data, loading, reload } = useApi(() => partnerApi.gates(), [])
  const { data: eventsData } = useApi(() => partnerApi.events(), [])

  const gates = data?.data || []
  const events = eventsData?.data || []

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState('create') // 'create' | 'edit'
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleting, setDeleting] = useState(false)
  const [busyId, setBusyId] = useState(null)

  const setField = (key, value) => setForm((prev) => ({ ...prev, [key]: value }))

  const openCreate = () => {
    setModalMode('create')
    setEditingId(null)
    // Kalau event-nya cuma satu, langsung dipilihkan.
    setForm({ ...EMPTY_FORM, event_id: events.length === 1 ? String(events[0].id) : '' })
    setFormError('')
    setModalOpen(true)
  }

  const openEdit = (g) => {
    setModalMode('edit')
    setEditingId(g.id)
    setForm({
      nama_gate: g.nama_gate || '',
      event_id: g.event_id ? String(g.event_id) : '',
      status: g.status || 'aktif',
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleSubmit = async (e) => {
    e?.preventDefault()
    setFormError('')

    if (!form.nama_gate.trim()) {
      setFormError('Nama gate wajib diisi.')
      return
    }

    const payload = {
      nama_gate: form.nama_gate.trim(),
      event_id: form.event_id ? Number(form.event_id) : null,
    }

    setSaving(true)
    try {
      if (modalMode === 'create') {
        await partnerApi.createGate(payload)
      } else {
        await partnerApi.updateGate(editingId, { ...payload, status: form.status })
      }
      setModalOpen(false)
      reload()
    } catch (err) {
      // ApiError membawa .errors dan .message langsung (lihat lib/api.js),
      // bukan err.response.data seperti axios biasa.
      const pesanField = err.errors ? Object.values(err.errors)[0]?.[0] : null
      setFormError(pesanField || err.message || 'Gagal menyimpan gate.')
    } finally {
      setSaving(false)
    }
  }

  const toggleStatus = async (g) => {
    setBusyId(g.id)
    try {
      await partnerApi.updateGate(g.id, { status: g.status === 'aktif' ? 'nonaktif' : 'aktif' })
      reload()
    } catch (err) {
      alert(err.message || 'Gagal mengubah status gate.')
    } finally {
      setBusyId(null)
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      const res = await partnerApi.deleteGate(deleteTarget.id)
      setDeleteTarget(null)
      reload()
      if (res?.message && res.message !== 'Gate dihapus.') {
        alert(res.message)
      }
    } catch (err) {
      alert(err.message || 'Gagal menghapus gate.')
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="Gate"
        description="Pintu masuk yang digunakan saat check-in acara."
        action={<Button onClick={openCreate}><Plus size={16} /> Tambah gate</Button>}
      />

      {loading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <div key={i} className="h-52 animate-pulse rounded-2xl bg-slate-100" />)}
        </div>
      ) : gates.length === 0 ? (
        <Card>
          <EmptyState
            icon={DoorOpen}
            title="Belum ada gate"
            description="Tambahkan pintu masuk yang dipakai saat check-in. Kalau acara hanya punya satu pintu, cukup satu gate."
            action={<Button onClick={openCreate}><Plus size={16} /> Tambah gate</Button>}
          />
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {gates.map((g) => (
            <Card key={g.id} className="p-5">
              <div className="flex items-center justify-between">
                <span className="grid h-11 w-11 place-items-center rounded-xl bg-brand-100 text-brand-700">
                  <DoorOpen size={20} />
                </span>
                <StatusBadge status={g.status} />
              </div>

              <h3 className="mt-3 font-bold text-slate-900">{g.nama_gate}</h3>
              <p className="text-xs text-slate-500">Kode gate: GT-{String(g.id).padStart(3, '0')}</p>

              <div className="mt-3 space-y-1.5 text-sm text-slate-600">
                <p className="flex items-center gap-2 truncate">
                  <CalendarDays size={14} className="shrink-0 text-slate-400" />
                  {g.event?.nama_event || <span className="text-slate-400">Semua event</span>}
                </p>
                <p className="flex items-center gap-2">
                  <Users size={14} className="shrink-0 text-slate-400" />
                  {g.staffs_count > 0
                    ? `${g.staffs_count} staff ditugaskan`
                    : <span className="text-slate-400">Belum ada staff</span>}
                </p>
              </div>

              <div className="mt-4 flex items-center gap-2">
                <Button
                  variant="secondary"
                  size="sm"
                  className="flex-1"
                  onClick={() => openEdit(g)}
                >
                  Kelola
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => toggleStatus(g)}
                  disabled={busyId === g.id}
                  title={g.status === 'aktif' ? 'Nonaktifkan gate' : 'Aktifkan gate'}
                  aria-label={g.status === 'aktif' ? `Nonaktifkan ${g.nama_gate}` : `Aktifkan ${g.nama_gate}`}
                >
                  <Power size={15} className={g.status === 'aktif' ? 'text-slate-500' : 'text-emerald-600'} />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setDeleteTarget(g)}
                  title="Hapus gate"
                  aria-label={`Hapus ${g.nama_gate}`}
                >
                  <Trash2 size={15} className="text-rose-500" />
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={modalMode === 'create' ? 'Tambah gate' : 'Kelola gate'}
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

          <Field label="Nama gate" required>
            <Input
              value={form.nama_gate}
              onChange={(e) => setField('nama_gate', e.target.value)}
              placeholder="Contoh: Gate Utama"
              autoFocus
            />
          </Field>

          <Field label="Event" hint="Kosongkan kalau gate ini dipakai untuk semua event.">
            <Select value={form.event_id} onChange={(e) => setField('event_id', e.target.value)}>
              <option value="">Semua event</option>
              {events.map((ev) => (
                <option key={ev.id} value={ev.id}>{ev.nama_event}</option>
              ))}
            </Select>
          </Field>

          {modalMode === 'edit' && (
            <Field label="Status" hint="Gate nonaktif tidak dipakai saat memilih gate di layar scan.">
              <Select value={form.status} onChange={(e) => setField('status', e.target.value)}>
                <option value="aktif">Aktif</option>
                <option value="nonaktif">Nonaktif</option>
              </Select>
            </Field>
          )}
        </form>
      </Modal>

      <Modal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title="Hapus gate?"
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
          <strong>{deleteTarget?.nama_gate}</strong> akan dihapus.
          {deleteTarget?.staffs_count > 0 && (
            <> {deleteTarget.staffs_count} staff yang ditugaskan di gate ini akan menjadi tanpa gate.</>
          )}
        </p>
        <p className="mt-2 text-xs text-slate-500">
          Gate yang sudah dipakai di riwayat check-in tidak bisa dihapus — nonaktifkan saja
          supaya jejak masuknya tetap tersimpan.
        </p>
      </Modal>
    </div>
  )
}
