import { useMemo, useState } from 'react'
import {
  Plus, Ticket as TicketIcon, Layers, Search, Pencil, Trash2, Power, PowerOff,
  AlertTriangle, Lock, Users,
} from 'lucide-react'
import { Button, Card, PageHeader, ProgressBar, Badge, Modal, Field, Input, Select } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { normalizeEvent } from '../../lib/normalize'
import { ticketSessionsLabel, isBundleTicket } from '../../lib/ticketGroups'
import { formatRupiah } from '../../lib/utils'

const EMPTY_FORM = {
  event_id: '', nama_tiket: '', harga: 0, kuota: 0, max_per_order: 4,
  jam_masuk_mulai: '', jam_masuk_selesai: '', session_ids: [], status: 'aktif',
}

/**
 * Halaman kelola jenis tiket.
 *
 * Aturan yang dipegang di sini:
 * - Tiket yang belum punya pembeli boleh diedit dan dihapus bebas.
 * - Tiket yang sudah terjual TIDAK boleh dihapus (backend menolak dengan 409,
 *   karena FK cascade akan ikut menghapus QR pembeli). Untuk tiket seperti itu
 *   tombol hapus diganti "Nonaktifkan", yang menghentikan penjualan tanpa
 *   menyentuh data pembeli.
 */
export default function MasterTickets() {
  const { data, loading, reload } = useApi(() => partnerApi.events(), [])
  const events = useMemo(() => (data?.data || []).map(normalizeEvent), [data])

  const [open, setOpen] = useState(false)
  const [editTarget, setEditTarget] = useState(null) // null = mode tambah
  const [form, setForm] = useState(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notif, setNotif] = useState(null)

  const [hapusTarget, setHapusTarget] = useState(null)
  const [menghapus, setMenghapus] = useState(false)

  // Filter & pencarian — halaman ini gampang jadi panjang kalau eventnya banyak.
  const [cari, setCari] = useState('')
  const [filterEvent, setFilterEvent] = useState('semua')

  const rows = useMemo(
    () => events.flatMap((e) => e.tiket.map((t) => ({ ...t, event: e.nama_event, eventData: e }))),
    [events],
  )

  const terfilter = useMemo(() => rows.filter((t) => {
    if (filterEvent !== 'semua' && String(t.eventData.id) !== String(filterEvent)) return false
    if (!cari.trim()) return true
    const q = cari.toLowerCase()
    return t.nama_tiket.toLowerCase().includes(q) || (t.event || '').toLowerCase().includes(q)
  }), [rows, cari, filterEvent])

  const selectedEvent = events.find((e) => String(e.id) === String(form.event_id))
  const sessions = selectedEvent?.sessions || []

  // Tiket dianggap sudah punya pembeli kalau ada tiket terbit atau pesanan
  // yang masih menahan kuota. Dua-duanya membuat penghapusan berbahaya.
  const jumlahTerjual = (t) => Number(t.terjual_lunas ?? 0)
  const punyaPembeli = (t) => jumlahTerjual(t) > 0
  const nonaktif = (t) => t.status === 'nonaktif'

  const bukaTambah = () => {
    setEditTarget(null)
    setForm({ ...EMPTY_FORM, event_id: events[0]?.id ?? '' })
    setError('')
    setOpen(true)
  }

  const bukaEdit = (t) => {
    setEditTarget(t)
    setForm({
      event_id: t.eventData.id,
      nama_tiket: t.nama_tiket,
      harga: t.harga,
      kuota: t.kuota,
      max_per_order: t.max_per_order ?? 4,
      jam_masuk_mulai: t.jam_masuk_mulai || '',
      jam_masuk_selesai: t.jam_masuk_selesai || '',
      // normalizeTicketType sudah meratakan sesi ke session_ids, jadi tidak
      // perlu menebak bentuk responsenya di sini.
      session_ids: t.session_ids || [],
      status: t.status || 'aktif',
    })
    setError('')
    setOpen(true)
  }

  const toggleSession = (sessionId) => {
    setForm((f) => {
      const has = f.session_ids.includes(sessionId)
      return { ...f, session_ids: has ? f.session_ids.filter((s) => s !== sessionId) : [...f.session_ids, sessionId] }
    })
  }

  const simpan = async () => {
    if (!form.event_id) return setError('Pilih event terlebih dahulu.')
    if (!form.nama_tiket.trim()) return setError('Nama tiket wajib diisi.')
    if (Number(form.kuota) < 1) return setError('Kuota minimal 1.')
    // FASE 3: tiket tanpa sesi tidak bisa dipakai check-in di gate mana pun.
    if (form.session_ids.length === 0) {
      return setError('Pilih minimal 1 sesi — tiket tanpa sesi tidak bisa dipakai check-in.')
    }

    const payload = {
      event_id: Number(form.event_id),
      nama_tiket: form.nama_tiket.trim(),
      harga: Number(form.harga) || 0,
      kuota: Number(form.kuota) || 0,
      max_per_order: Number(form.max_per_order) || 1,
      jam_masuk_mulai: form.jam_masuk_mulai || null,
      jam_masuk_selesai: form.jam_masuk_selesai || null,
      session_ids: form.session_ids,
      status: form.status,
    }

    setSaving(true)
    setError('')
    try {
      if (editTarget) {
        await partnerApi.updateTicketType(editTarget.id, payload)
        setNotif({ ok: true, text: `Tiket "${payload.nama_tiket}" diperbarui.` })
      } else {
        await partnerApi.createTicketType(payload)
        setNotif({ ok: true, text: `Tiket "${payload.nama_tiket}" ditambahkan.` })
      }
      setOpen(false)
      reload()
    } catch (err) {
      // Pesan validasi per field lebih membantu daripada pesan umum.
      const pesanField = err.errors ? Object.values(err.errors)[0]?.[0] : null
      setError(pesanField || err.message || 'Gagal menyimpan tiket.')
    } finally {
      setSaving(false)
    }
  }

  const ubahStatus = async (t) => {
    const baru = nonaktif(t) ? 'aktif' : 'nonaktif'
    try {
      await partnerApi.updateTicketType(t.id, { status: baru })
      setNotif({
        ok: true,
        text: baru === 'aktif'
          ? `Tiket "${t.nama_tiket}" diaktifkan kembali dan bisa dibeli.`
          : `Tiket "${t.nama_tiket}" dinonaktifkan. Tidak tampil di halaman publik dan tidak bisa dibeli.`,
      })
      reload()
    } catch (err) {
      setNotif({ ok: false, text: err.message || 'Gagal mengubah status tiket.' })
    }
  }

  const konfirmasiHapus = async () => {
    if (!hapusTarget) return
    setMenghapus(true)
    try {
      await partnerApi.deleteTicketType(hapusTarget.id)
      setNotif({ ok: true, text: `Tiket "${hapusTarget.nama_tiket}" dihapus.` })
      setHapusTarget(null)
      reload()
    } catch (err) {
      setNotif({ ok: false, text: err.message || 'Gagal menghapus tiket.' })
      setHapusTarget(null)
    } finally {
      setMenghapus(false)
    }
  }

  const ringkas = {
    total: rows.length,
    terjual: rows.reduce((n, t) => n + jumlahTerjual(t), 0),
    nonaktif: rows.filter(nonaktif).length,
  }

  return (
    <div>
      <PageHeader
        title="Jenis Tiket"
        description={`${ringkas.total} jenis tiket · ${ringkas.terjual.toLocaleString('id-ID')} tiket terjual${ringkas.nonaktif > 0 ? ` · ${ringkas.nonaktif} nonaktif` : ''}.`}
        action={
          <Button onClick={bukaTambah} disabled={events.length === 0}>
            <Plus size={16} /> Tambah Jenis Tiket
          </Button>
        }
      />

      {notif && (
        <div className={`mb-4 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ${
          notif.ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'
        }`}>
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />
          <p className="flex-1">{notif.text}</p>
          <button onClick={() => setNotif(null)} className="shrink-0 text-xs font-semibold underline">Tutup</button>
        </div>
      )}

      {/* Filter & pencarian: menyaring per event supaya daftar tetap terbaca
          ketika satu organizer punya banyak event. */}
      {rows.length > 0 && (
        <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
          <div className="relative flex-1">
            <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <Input
              value={cari}
              onChange={(e) => setCari(e.target.value)}
              placeholder="Cari nama tiket atau event…"
              className="pl-9"
            />
          </div>
          <Select value={filterEvent} onChange={(e) => setFilterEvent(e.target.value)} className="sm:w-72">
            <option value="semua">Semua event ({rows.length} tiket)</option>
            {events.map((e) => (
              <option key={e.id} value={e.id}>{e.nama_event} ({e.tiket.length})</option>
            ))}
          </Select>
        </div>
      )}

      <Card>
        {loading ? (
          <div className="m-4 h-56 animate-pulse rounded-xl bg-slate-100" />
        ) : rows.length === 0 ? (
          <div className="p-8 text-center text-sm text-slate-500">
            Belum ada jenis tiket. {events.length === 0 ? 'Buat event terlebih dahulu.' : 'Klik “Tambah Jenis Tiket”.'}
          </div>
        ) : terfilter.length === 0 ? (
          <div className="p-8 text-center text-sm text-slate-500">
            Tidak ada tiket yang cocok dengan pencarian.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[980px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Jenis Tiket</th>
                  <th className="px-5 py-3 font-semibold">Harga</th>
                  <th className="px-5 py-3 font-semibold">Berlaku</th>
                  <th className="px-5 py-3 font-semibold">Penjualan</th>
                  <th className="px-5 py-3 text-right font-semibold">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {terfilter.map((t) => {
                  const terjual = jumlahTerjual(t)
                  const dipesan = Math.max(0, t.kuota - t.sisa_kuota - terjual)
                  const mati = nonaktif(t)
                  return (
                    <tr key={t.id} className={mati ? 'bg-slate-50/60' : 'hover:bg-slate-50'}>
                      <td className="px-5 py-3">
                        <div className="flex items-start gap-3">
                          <span className="grid h-11 w-16 shrink-0 place-items-center overflow-hidden rounded-lg bg-brand-100 text-brand-700">
                            {t.gambar_url ? (
                              <img
                                key={t.gambar_url}
                                src={t.gambar_url}
                                alt={t.nama_tiket}
                                className="h-full w-full object-cover"
                                onError={(ev) => { ev.currentTarget.style.display = 'none' }}
                              />
                            ) : <TicketIcon size={16} />}
                          </span>
                          <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-1.5">
                              <span className={`font-semibold ${mati ? 'text-slate-500' : 'text-slate-800'}`}>
                                {t.nama_tiket}
                              </span>
                              {isBundleTicket(t) && <Badge color="brand"><Layers size={11} /> Bundle</Badge>}
                              {mati && <Badge color="slate">Nonaktif</Badge>}
                            </div>
                            <p className="mt-0.5 truncate text-xs text-slate-500">{t.event}</p>
                            {t.jam_masuk_mulai && (
                              <p className="text-[11px] text-slate-400">
                                Masuk {t.jam_masuk_mulai}{t.jam_masuk_selesai ? `–${t.jam_masuk_selesai}` : ''} WIB
                              </p>
                            )}
                          </div>
                        </div>
                      </td>
                      <td className="px-5 py-3">
                        <span className="font-semibold text-slate-800">{formatRupiah(t.harga)}</span>
                        <p className="text-[11px] text-slate-400">maks {t.max_per_order}/pesanan</p>
                      </td>
                      <td className="px-5 py-3 text-slate-600">
                        {ticketSessionsLabel(t.eventData, t) || <span className="text-slate-400">—</span>}
                      </td>
                      <td className="w-52 px-5 py-3">
                        <div className="mb-1 flex items-center justify-between gap-2 text-xs text-slate-500">
                          <span>
                            <span className="font-semibold text-slate-700">{terjual.toLocaleString('id-ID')}</span>
                            {' / '}{t.kuota.toLocaleString('id-ID')}
                          </span>
                          {t.sisa_kuota === 0 && !mati && <Badge color="red">Habis</Badge>}
                        </div>
                        <ProgressBar value={terjual} max={t.kuota} color={mati ? 'bg-slate-400' : 'bg-emerald-500'} />
                        <p className="mt-1 text-[11px] text-slate-500">
                          Sisa {t.sisa_kuota.toLocaleString('id-ID')}
                          {dipesan > 0 && <span className="text-amber-600"> · {dipesan} dipesan belum bayar</span>}
                        </p>
                      </td>
                      <td className="px-5 py-3">
                        <div className="flex items-center justify-end gap-1.5">
                          <Button size="sm" variant="secondary" onClick={() => bukaEdit(t)} title="Edit tiket">
                            <Pencil size={13} /> Edit
                          </Button>

                          {/* Tiket yang sudah terjual tidak bisa dihapus: FK cascade
                              akan ikut menghapus QR pembeli. Ganti jadi Nonaktifkan. */}
                          {punyaPembeli(t) ? (
                            <Button
                              size="sm"
                              variant={mati ? 'success' : 'secondary'}
                              onClick={() => ubahStatus(t)}
                              title={mati ? 'Aktifkan kembali' : 'Hentikan penjualan tanpa menghapus data pembeli'}
                            >
                              {mati ? <><Power size={13} /> Aktifkan</> : <><PowerOff size={13} /> Nonaktifkan</>}
                            </Button>
                          ) : (
                            <>
                              <Button
                                size="sm"
                                variant={mati ? 'success' : 'secondary'}
                                onClick={() => ubahStatus(t)}
                                title={mati ? 'Aktifkan kembali' : 'Sembunyikan dari publik tanpa menghapus'}
                              >
                                {mati ? <Power size={13} /> : <PowerOff size={13} />}
                              </Button>
                              <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => setHapusTarget(t)}
                                className="text-rose-600 hover:border-rose-300 hover:bg-rose-50"
                                title="Hapus tiket"
                              >
                                <Trash2 size={13} />
                              </Button>
                            </>
                          )}
                        </div>
                        {punyaPembeli(t) && (
                          <p className="mt-1 flex items-center justify-end gap-1 text-[11px] text-slate-400">
                            <Lock size={10} /> Sudah ada pembeli
                          </p>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* ── Modal tambah / edit ── */}
      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editTarget ? `Edit Tiket — ${editTarget.nama_tiket}` : 'Tambah Jenis Tiket'}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>Batal</Button>
            <Button onClick={simpan} disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan'}</Button>
          </>
        }
      >
        <div className="space-y-4">
          {editTarget && punyaPembeli(editTarget) && (
            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
              Tiket ini sudah terjual {jumlahTerjual(editTarget).toLocaleString('id-ID')}.
              Menambah kuota aman; menurunkannya di bawah jumlah terjual akan ditolak sistem.
            </p>
          )}

          <Field label="Event" required>
            <Select
              value={form.event_id}
              onChange={(e) => setForm({ ...form, event_id: e.target.value, session_ids: [] })}
              disabled={!!editTarget}
            >
              <option value="">Pilih event</option>
              {events.map((e) => <option key={e.id} value={e.id}>{e.nama_event}</option>)}
            </Select>
            {editTarget && <p className="mt-1 text-xs text-slate-400">Event tidak bisa dipindah. Hapus tiket lalu buat di event yang benar.</p>}
          </Field>

          <Field label="Nama Tiket" required>
            <Input
              value={form.nama_tiket}
              onChange={(e) => setForm({ ...form, nama_tiket: e.target.value })}
              placeholder="Reguler / VIP / 2-Day Pass"
            />
          </Field>

          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Field label="Harga (Rp)" required>
              <Input type="number" value={form.harga} onChange={(e) => setForm({ ...form, harga: e.target.value })} />
            </Field>
            <Field label="Kuota" required hint={editTarget ? `Terjual ${jumlahTerjual(editTarget)}` : undefined}>
              <Input type="number" value={form.kuota} onChange={(e) => setForm({ ...form, kuota: e.target.value })} />
            </Field>
            <Field label="Maks/Order">
              <Input type="number" value={form.max_per_order} onChange={(e) => setForm({ ...form, max_per_order: e.target.value })} />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Jam Masuk Mulai">
              <Input type="time" value={form.jam_masuk_mulai} onChange={(e) => setForm({ ...form, jam_masuk_mulai: e.target.value })} />
            </Field>
            <Field label="Jam Masuk Selesai">
              <Input type="time" value={form.jam_masuk_selesai} onChange={(e) => setForm({ ...form, jam_masuk_selesai: e.target.value })} />
            </Field>
          </div>
          <p className="text-xs text-slate-400">
            Opsional — jam masuk khusus untuk jenis tiket ini (mis. VIP masuk lebih awal). Kosongkan jika mengikuti jam event.
          </p>

          <Field label="Status">
            <Select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
              <option value="aktif">Aktif — tampil di halaman publik dan bisa dibeli</option>
              <option value="nonaktif">Nonaktif — disembunyikan dan tidak bisa dibeli</option>
            </Select>
          </Field>

          {sessions.length > 0 && (
            <div>
              <label className="label text-xs">Berlaku untuk Sesi (pilih &gt;1 untuk bundle)</label>
              <div className="flex flex-wrap gap-2">
                {sessions.map((s) => {
                  const checked = form.session_ids.includes(s.id)
                  return (
                    <label
                      key={s.id}
                      className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold ${
                        checked ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={checked}
                        onChange={() => toggleSession(s.id)}
                        className="h-3.5 w-3.5 rounded border-slate-300 text-brand-600"
                      />
                      {s.label}
                    </label>
                  )
                })}
              </div>
              {form.session_ids.length === 0 && (
                <p className="mt-2 text-xs font-semibold text-rose-600">
                  Wajib pilih minimal 1 sesi — tanpa sesi, tiket ini tidak bisa dipakai check-in.
                </p>
              )}
            </div>
          )}

          {sessions.length === 0 && (
            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700">
              Event ini belum punya sesi/hari. Tambahkan minimal 1 sesi di menu Event dulu, lalu buat tiketnya di sini.
            </p>
          )}

          {error && <p className="text-sm font-medium text-rose-600">{error}</p>}
        </div>
      </Modal>

      {/* ── Konfirmasi hapus ── */}
      <Modal
        open={!!hapusTarget}
        onClose={() => setHapusTarget(null)}
        title="Hapus jenis tiket?"
        footer={
          <>
            <Button variant="secondary" onClick={() => setHapusTarget(null)}>Batal</Button>
            <Button variant="danger" onClick={konfirmasiHapus} disabled={menghapus}>
              {menghapus ? 'Menghapus…' : 'Ya, hapus'}
            </Button>
          </>
        }
      >
        {hapusTarget && (
          <div className="space-y-3 text-sm text-slate-600">
            <p>
              Tiket <span className="font-semibold text-slate-900">“{hapusTarget.nama_tiket}”</span> akan dihapus permanen.
              Tindakan ini tidak bisa dibatalkan.
            </p>
            <div className="rounded-xl bg-slate-50 px-4 py-3 text-xs">
              <p className="flex items-center gap-1.5 font-semibold text-emerald-700">
                <Users size={12} /> Belum ada pembeli
              </p>
              <p className="mt-1 text-slate-500">
                Tiket ini belum pernah terjual, jadi aman dihapus. Kalau nanti berubah pikiran,
                kamu harus membuatnya ulang dari awal.
              </p>
            </div>
              <p className="text-xs text-slate-500">
              Kalau hanya ingin menghentikan penjualan sementara, pakai <span className="font-semibold">Nonaktifkan</span> —
              tiket tetap tersimpan dan bisa diaktifkan kembali.
            </p>
          </div>
        )}
      </Modal>
    </div>
  )
}
