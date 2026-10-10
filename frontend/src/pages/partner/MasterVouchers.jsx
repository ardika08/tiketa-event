import { useState, useMemo } from 'react'
import { Plus, BadgePercent, Copy, Check, Pencil, Trash2, Calendar, AlertCircle } from 'lucide-react'
import { Button, Card, PageHeader, StatusBadge, Badge, ProgressBar, Modal, Field, Input, Select } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { normalizeEvent } from '../../lib/normalize'
import { formatRupiah, formatTanggal } from '../../lib/utils'

const EMPTY_FORM = {
  kode: '',
  event_id: '',
  tipe_diskon: 'nominal',
  nilai: '',
  min_pembelian: '',
  kuota: '',
  berlaku_mulai: '',
  berlaku_sampai: '',
  status: 'aktif',
}

export default function MasterVouchers() {
  const { data: voucherData, loading: loadingVouchers, reload: reloadVouchers } = useApi(() => partnerApi.vouchers(), [])
  const { data: eventData } = useApi(() => partnerApi.events(), [])

  const vouchers = voucherData?.data || []
  const events = useMemo(() => (eventData?.data || []).map(normalizeEvent), [eventData])

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState('create') // 'create' | 'edit'
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  const [deleteModalOpen, setDeleteModalOpen] = useState(false)
  const [deletingVoucher, setDeletingVoucher] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const [copiedId, setCopiedId] = useState(null)

  const handleCopy = (v) => {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(v.kode)
      setCopiedId(v.id)
      setTimeout(() => setCopiedId(null), 2000)
    }
  }

  const openCreateModal = () => {
    setModalMode('create')
    setEditingId(null)
    setForm(EMPTY_FORM)
    setFormError('')
    setModalOpen(true)
  }

  const openEditModal = (v) => {
    setModalMode('edit')
    setEditingId(v.id)
    setForm({
      kode: v.kode,
      event_id: v.event_id ? String(v.event_id) : '',
      tipe_diskon: v.tipe_diskon || 'nominal',
      nilai: v.nilai ?? '',
      min_pembelian: v.min_pembelian && Number(v.min_pembelian) > 0 ? Number(v.min_pembelian) : '',
      kuota: v.kuota ?? '',
      berlaku_mulai: v.berlaku_mulai ? v.berlaku_mulai.split('T')[0] : '',
      berlaku_sampai: v.berlaku_sampai ? v.berlaku_sampai.split('T')[0] : '',
      status: v.status || 'aktif',
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleFormChange = (key, val) => {
    setForm((prev) => ({
      ...prev,
      [key]: key === 'kode' ? val.toUpperCase().replace(/\s+/g, '') : val,
    }))
  }

  const submitForm = async () => {
    if (!form.kode.trim()) {
      return setFormError('Kode voucher wajib diisi.')
    }
    const nilaiNum = Number(form.nilai)
    if (isNaN(nilaiNum) || nilaiNum <= 0) {
      return setFormError('Nilai diskon harus lebih besar dari 0.')
    }
    if (form.tipe_diskon === 'persen' && nilaiNum > 100) {
      return setFormError('Diskon persen maksimal 100%.')
    }

    if (form.berlaku_mulai && form.berlaku_sampai && form.berlaku_sampai < form.berlaku_mulai) {
      return setFormError('Tanggal berakhir tidak boleh mendahului tanggal mulai.')
    }

    setSaving(true)
    setFormError('')

    const payload = {
      kode: form.kode.trim(),
      event_id: form.event_id ? Number(form.event_id) : null,
      tipe_diskon: form.tipe_diskon,
      nilai: nilaiNum,
      min_pembelian: form.min_pembelian !== '' ? Math.max(0, Number(form.min_pembelian)) : 0,
      kuota: form.kuota !== '' ? Math.max(0, parseInt(form.kuota, 10)) : 0,
      berlaku_mulai: form.berlaku_mulai || null,
      berlaku_sampai: form.berlaku_sampai || null,
      status: form.status,
    }

    try {
      if (modalMode === 'create') {
        await partnerApi.createVoucher(payload)
      } else {
        await partnerApi.updateVoucher(editingId, payload)
      }
      setModalOpen(false)
      reloadVouchers()
    } catch (err) {
      const msg = err.response?.data?.message || err.message || 'Gagal menyimpan voucher.'
      setFormError(msg)
    } finally {
      setSaving(false)
    }
  }

  const confirmDelete = (v) => {
    setDeletingVoucher(v)
    setDeleteModalOpen(true)
  }

  const handleDelete = async () => {
    if (!deletingVoucher) return
    setDeleting(true)
    try {
      await partnerApi.deleteVoucher(deletingVoucher.id)
      setDeleteModalOpen(false)
      setDeletingVoucher(null)
      reloadVouchers()
    } catch (err) {
      alert(err.response?.data?.message || err.message || 'Gagal menghapus voucher.')
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="Voucher"
        description="Buat dan kelola kode promo atau diskon tiket event Anda."
        action={
          <Button onClick={openCreateModal}>
            <Plus size={16} /> Buat Voucher
          </Button>
        }
      />

      {loadingVouchers ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-56 animate-pulse rounded-2xl bg-slate-100" />
          ))}
        </div>
      ) : vouchers.length === 0 ? (
        <Card className="flex flex-col items-center justify-center p-12 text-center">
          <div className="grid h-14 w-14 place-items-center rounded-2xl bg-brand-50 text-brand-600">
            <BadgePercent size={28} />
          </div>
          <h3 className="mt-4 text-base font-semibold text-slate-900">Belum Ada Voucher</h3>
          <p className="mt-1 max-w-sm text-sm text-slate-500">
            Tingkatkan penjualan tiket dengan memberikan kode diskon spesial kepada calon pembeli.
          </p>
          <Button onClick={openCreateModal} className="mt-5">
            <Plus size={16} /> Buat Voucher Pertama
          </Button>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {vouchers.map((v) => {
            const event = v.event
            const isUnlimited = Number(v.kuota) === 0
            const isHabis = !isUnlimited && v.terpakai >= v.kuota
            const isCopied = copiedId === v.id

            return (
              <Card key={v.id} className="flex flex-col justify-between p-5">
                <div>
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex items-center gap-3">
                      <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700">
                        <BadgePercent size={20} />
                      </span>
                      <div>
                        <p className="font-mono text-lg font-bold tracking-wider text-slate-900">{v.kode}</p>
                        <p className="text-xs font-semibold text-brand-600">
                          {v.tipe_diskon === 'persen' ? `Diskon ${Number(v.nilai)}%` : `Potongan ${formatRupiah(v.nilai)}`}
                        </p>
                      </div>
                    </div>
                    <StatusBadge status={v.status} />
                  </div>

                  <div className="mt-4 space-y-1.5 border-t border-slate-100 pt-3 text-xs text-slate-500">
                    <div className="flex items-center justify-between">
                      <span className="text-slate-400">Target Event:</span>
                      <span className="font-medium text-slate-700 truncate max-w-[170px]" title={event?.nama_event || 'Semua Event'}>
                        {event ? event.nama_event : 'Semua Event'}
                      </span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-slate-400">Min. Belanja:</span>
                      <span className="font-medium text-slate-700">
                        {Number(v.min_pembelian) > 0 ? formatRupiah(v.min_pembelian) : 'Tanpa minimum'}
                      </span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-slate-400">Periode:</span>
                      <span className="font-medium text-slate-700">
                        {v.berlaku_sampai ? `s/d ${formatTanggal(v.berlaku_sampai)}` : 'Tanpa batas waktu'}
                      </span>
                    </div>
                  </div>

                  <div className="mt-4">
                    <div className="mb-1.5 flex justify-between text-xs text-slate-500">
                      <span>
                        Terpakai <strong className="text-slate-700">{v.terpakai}</strong>
                        {isUnlimited ? ' (Tanpa batas kuota)' : ` / ${v.kuota}`}
                      </span>
                      <Badge color={isHabis ? 'red' : isUnlimited ? 'blue' : 'green'}>
                        {isHabis ? 'Habis' : isUnlimited ? 'Unlimited' : 'Tersedia'}
                      </Badge>
                    </div>
                    {!isUnlimited && (
                      <ProgressBar
                        value={v.terpakai}
                        max={v.kuota}
                        color={isHabis ? 'bg-rose-500' : 'bg-emerald-500'}
                      />
                    )}
                  </div>
                </div>

                <div className="mt-5 space-y-2 border-t border-slate-100 pt-3">
                  <Button
                    variant="secondary"
                    size="sm"
                    className="w-full font-mono text-xs"
                    onClick={() => handleCopy(v)}
                  >
                    {isCopied ? (
                      <>
                        <Check size={14} className="text-emerald-600" /> Tersalin!
                      </>
                    ) : (
                      <>
                        <Copy size={14} /> Salin Kode
                      </>
                    )}
                  </Button>
                  <div className="flex gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      className="flex-1 text-slate-600 hover:text-slate-900"
                      onClick={() => openEditModal(v)}
                    >
                      <Pencil size={13} /> Edit
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="flex-1 text-rose-600 hover:bg-rose-50 hover:text-rose-700"
                      onClick={() => confirmDelete(v)}
                    >
                      <Trash2 size={13} /> Hapus
                    </Button>
                  </div>
                </div>
              </Card>
            )
          })}
        </div>
      )}

      {/* Modal Form Tambah / Edit Voucher */}
      <Modal
        open={modalOpen}
        onClose={() => !saving && setModalOpen(false)}
        title={modalMode === 'create' ? 'Buat Voucher Baru' : `Edit Voucher: ${form.kode}`}
        size="md"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>
              Batal
            </Button>
            <Button onClick={submitForm} disabled={saving}>
              {saving ? 'Menyimpan...' : modalMode === 'create' ? 'Buat Voucher' : 'Simpan Perubahan'}
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          {formError && (
            <div className="flex items-center gap-2 rounded-xl bg-rose-50 p-3 text-xs font-medium text-rose-700">
              <AlertCircle size={16} className="shrink-0" />
              <span>{formError}</span>
            </div>
          )}

          <Field label="Kode Voucher" required hint="Contoh: PROMO10, DISKONHEMAT (tanpa spasi)">
            <Input
              placeholder="KODE VOUCHER"
              value={form.kode}
              onChange={(e) => handleFormChange('kode', e.target.value)}
              className="font-mono uppercase tracking-wider font-semibold"
              maxLength={30}
            />
          </Field>

          <Field label="Target Event" hint="Pilih event spesifik atau berlaku untuk semua event Anda">
            <Select
              value={form.event_id}
              onChange={(e) => handleFormChange('event_id', e.target.value)}
            >
              <option value="">Semua Event Saya</option>
              {events.map((e) => (
                <option key={e.id} value={e.id}>
                  {e.nama_event}
                </option>
              ))}
            </Select>
          </Field>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Tipe Diskon" required>
              <Select
                value={form.tipe_diskon}
                onChange={(e) => handleFormChange('tipe_diskon', e.target.value)}
              >
                <option value="nominal">Nominal (Rp)</option>
                <option value="persen">Persentase (%)</option>
              </Select>
            </Field>

            <Field
              label={form.tipe_diskon === 'persen' ? 'Persen Diskon (%)' : 'Potongan Harga (Rp)'}
              required
            >
              <Input
                type="number"
                min="1"
                max={form.tipe_diskon === 'persen' ? '100' : undefined}
                placeholder={form.tipe_diskon === 'persen' ? '10' : '25000'}
                value={form.nilai}
                onChange={(e) => handleFormChange('nilai', e.target.value)}
              />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Minimal Belanja (Rp)" hint="0 = tanpa syarat minimal">
              <Input
                type="number"
                min="0"
                placeholder="0 (Tanpa Syarat)"
                value={form.min_pembelian}
                onChange={(e) => handleFormChange('min_pembelian', e.target.value)}
              />
            </Field>

            <Field label="Kuota Pemakaian" hint="0 = tanpa batasan kuota">
              <Input
                type="number"
                min="0"
                placeholder="0 (Unlimited)"
                value={form.kuota}
                onChange={(e) => handleFormChange('kuota', e.target.value)}
              />
            </Field>
          </div>

          <div className="grid grid-cols-3 gap-3">
            <Field label="Status">
              <Select
                value={form.status}
                onChange={(e) => handleFormChange('status', e.target.value)}
              >
                <option value="aktif">Aktif</option>
                <option value="nonaktif">Nonaktif</option>
              </Select>
            </Field>

            <Field label="Berlaku Mulai" hint="Opsional">
              <Input
                type="date"
                value={form.berlaku_mulai}
                onChange={(e) => handleFormChange('berlaku_mulai', e.target.value)}
              />
            </Field>

            <Field label="Berlaku Sampai" hint="Opsional">
              <Input
                type="date"
                value={form.berlaku_sampai}
                onChange={(e) => handleFormChange('berlaku_sampai', e.target.value)}
              />
            </Field>
          </div>
        </div>
      </Modal>

      {/* Modal Konfirmasi Hapus */}
      <Modal
        open={deleteModalOpen}
        onClose={() => !deleting && setDeleteModalOpen(false)}
        title="Hapus Voucher?"
        size="sm"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteModalOpen(false)} disabled={deleting}>
              Batal
            </Button>
            <Button
              className="bg-rose-600 hover:bg-rose-700 text-white"
              onClick={handleDelete}
              disabled={deleting}
            >
              {deleting ? 'Menghapus...' : 'Ya, Hapus'}
            </Button>
          </div>
        }
      >
        <p className="text-sm text-slate-600">
          Apakah Anda yakin ingin menghapus voucher{' '}
          <strong className="font-mono text-slate-900">{deletingVoucher?.kode}</strong>?
        </p>
        {deletingVoucher && deletingVoucher.terpakai > 0 && (
          <div className="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-xs text-amber-800">
            <AlertCircle size={16} className="shrink-0 mt-0.5" />
            <span>
              Voucher ini sudah terpakai sebanyak <strong>{deletingVoucher.terpakai}x</strong>. Menghapusnya tidak akan
              membatalkan pesanan yang sudah lunas, namun kode ini tidak dapat digunakan lagi.
            </span>
          </div>
        )}
      </Modal>
    </div>
  )
}
