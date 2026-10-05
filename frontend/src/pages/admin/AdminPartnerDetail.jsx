import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, Mail, Phone, CalendarDays, CheckCircle2, XCircle, Handshake } from 'lucide-react'
import { Button, Card, PageHeader, StatusBadge, Badge, EmptyState, Modal } from '../../components/ui'
import { adminApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatRupiah } from '../../lib/utils'

export default function AdminPartnerDetail() {
  const { id } = useParams()
  const { data, loading, reload } = useApi(() => adminApi.partner(id), [id])
  const org = data?.data
  const events = data?.events || []

  const [status, setStatus] = useState('pending')
  const [confirm, setConfirm] = useState(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (org) setStatus(org.status_verifikasi)
  }, [org])

  if (loading) {
    return <div className="h-72 animate-pulse rounded-2xl bg-slate-100" />
  }

  if (!org) {
    return (
      <div className="container-page py-20">
        <Card><EmptyState icon={Handshake} title="Mitra tidak ditemukan" action={<Link to="/admin/mitra"><Button>Kembali</Button></Link>} /></Card>
      </div>
    )
  }

  const applyStatus = async () => {
    setSaving(true)
    try {
      await adminApi.updatePartner(org.id, { status_verifikasi: confirm })
      setStatus(confirm)
      setConfirm(null)
      reload()
    } finally {
      setSaving(false)
    }
  }

  const toggleAkun = async () => {
    setSaving(true)
    try {
      await adminApi.updatePartner(org.id, { status_akun: org.status_akun === 'aktif' ? 'nonaktif' : 'aktif' })
      reload()
    } finally {
      setSaving(false)
    }
  }

  const totalPesanan = events.reduce((s, e) => s + (e.order_lunas || 0), 0)
  const totalPendapatan = events.reduce((s, e) => s + (e.pendapatan || 0), 0)

  return (
    <div>
      <Link to="/admin/mitra" className="mb-4 inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-700">
        <ArrowLeft size={16} /> Kembali ke daftar mitra
      </Link>
      <PageHeader
        title={org.nama_penyelenggara}
        description={org.deskripsi}
        action={
          <div className="flex gap-2">
            <Button variant="danger" onClick={() => setConfirm('ditolak')} disabled={saving}><XCircle size={16} /> Tolak</Button>
            <Button variant="success" onClick={() => setConfirm('terverifikasi')} disabled={saving}><CheckCircle2 size={16} /> Verifikasi</Button>
          </div>
        }
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="p-6">
          <div className="flex items-center gap-3">
            <span className="grid h-14 w-14 place-items-center rounded-2xl bg-brand-100 text-xl font-bold text-brand-700">{org.nama_penyelenggara.charAt(0)}</span>
            <div>
              <p className="font-bold text-slate-900">{org.nama_penyelenggara}</p>
              <StatusBadge status={status} />
            </div>
          </div>
          <div className="mt-5 space-y-3 text-sm text-slate-600">
            <p className="flex items-center gap-2"><Mail size={15} className="text-slate-400" /> {org.email || org.user?.email}</p>
            <p className="flex items-center gap-2"><Phone size={15} className="text-slate-400" /> {org.telepon || '-'}</p>
            {org.legal && (
              <p className="flex items-center gap-2"><CalendarDays size={15} className="text-slate-400" /> PJ: {org.legal.nama_penanggung_jawab || '-'}</p>
            )}
          </div>
          <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
            <span className="text-sm text-slate-500">Status akun</span>
            <Badge color={org.status_akun === 'aktif' ? 'green' : 'slate'}>{org.status_akun}</Badge>
          </div>
          <Button variant="secondary" className="mt-4 w-full" onClick={toggleAkun} disabled={saving}>
            {org.status_akun === 'aktif' ? 'Nonaktifkan Akun' : 'Aktifkan Akun'}
          </Button>
        </Card>

        <div className="lg:col-span-2">
          <div className="grid gap-4 sm:grid-cols-3">
            <Card className="p-4">
              <p className="text-sm text-slate-500">Jumlah Event</p>
              <p className="mt-1 text-2xl font-bold text-slate-900">{events.length}</p>
            </Card>
            <Card className="p-4">
              <p className="text-sm text-slate-500">Pesanan Lunas</p>
              <p className="mt-1 text-2xl font-bold text-slate-900">{totalPesanan.toLocaleString('id-ID')}</p>
            </Card>
            <Card className="p-4">
              <p className="text-sm text-slate-500">Pendapatan Kotor</p>
              <p className="mt-1 text-2xl font-bold text-slate-900">{formatRupiah(totalPendapatan)}</p>
            </Card>
          </div>

          <Card className="mt-4">
            <div className="border-b border-slate-100 px-5 py-4">
              <h2 className="font-bold text-slate-900">Event Mitra</h2>
            </div>
            {events.length === 0 ? (
              <EmptyState icon={CalendarDays} title="Belum ada event" description="Mitra ini belum membuat event." />
            ) : (
              <div className="divide-y divide-slate-100">
                {events.map((e) => (
                  <div key={e.id} className="flex items-center gap-4 px-5 py-4">
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium text-slate-800">{e.nama_event}</p>
                      <p className="text-xs text-slate-500">{e.order_lunas || 0} pesanan · {formatRupiah(e.pendapatan || 0)}</p>
                    </div>
                    <StatusBadge status={e.status} />
                  </div>
                ))}
              </div>
            )}
          </Card>
        </div>
      </div>

      <Modal
        open={!!confirm}
        onClose={() => setConfirm(null)}
        title="Ubah Status Verifikasi"
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirm(null)}>Batal</Button>
            <Button variant={confirm === 'ditolak' ? 'danger' : 'success'} onClick={applyStatus} disabled={saving}>
              Konfirmasi
            </Button>
          </>
        }
      >
        <p className="text-sm text-slate-600">
          Ubah status verifikasi <span className="font-semibold">{org.nama_penyelenggara}</span> menjadi{' '}
          <span className="font-semibold capitalize">{confirm}</span>?
        </p>
      </Modal>
    </div>
  )
}
