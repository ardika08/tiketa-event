import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeft, Save, Send, Plus, Trash2, GripVertical, Info,
  AlertTriangle, Pencil, Power, PowerOff, Ticket as TicketIcon, Download,
} from 'lucide-react'
import { Button, Card, Field, Input, Select, Textarea, Badge, PageHeader, Modal } from '../../components/ui'
import { ImagePicker } from '../../components/ImagePicker'
import { KATEGORI } from '../../lib/constants'
import { ticketSessionIds, isBundleTicket } from '../../lib/ticketGroups'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { normalizeEvent } from '../../lib/normalize'
import { cn, formatRupiah } from '../../lib/utils'

/**
 * Cari celah jam antar kategori tiket — mis. Baby Shower tutup 10:00 tapi
 * Reguler baru buka 10:30: selama 30 menit itu semua QR ditolak di gerbang.
 * Kategori tanpa jam masuk (bebas masuk kapan saja) tidak ikut dihitung.
 */
function cariCelahJam(daftar) {
  const berjadwal = daftar
    .filter((t) => t.jam_masuk_mulai && t.jam_masuk_selesai && t.jam_masuk_mulai < t.jam_masuk_selesai)
    .map((t) => ({ nama: t.nama_tiket || 'Tanpa nama', mulai: t.jam_masuk_mulai, selesai: t.jam_masuk_selesai }))
    .sort((a, b) => a.mulai.localeCompare(b.mulai))

  const celah = []
  const bentrok = []

  for (let i = 0; i < berjadwal.length - 1; i += 1) {
    const ini = berjadwal[i]
    const lanjut = berjadwal[i + 1]
    if (ini.selesai < lanjut.mulai) {
      celah.push({ dari: ini.selesai, ke: lanjut.mulai, sebelum: ini.nama, sesudah: lanjut.nama })
    } else if (ini.selesai > lanjut.mulai) {
      bentrok.push({ nama: ini.nama, dengan: lanjut.nama })
    }
  }

  return { celah, bentrok, jumlahBerjadwal: berjadwal.length }
}

const TABS = [
  { id: 'info', label: 'Informasi Event' },
  { id: 'sesi', label: 'Sesi / Hari' },
  { id: 'tiket', label: 'Jenis Tiket & Kuota' },
  { id: 'form', label: 'Formulir Pembeli' },
  { id: 'gate', label: 'Gate' },
  { id: 'seat', label: 'Denah' },
  { id: 'staff', label: 'Staff' },
]

export default function EventForm() {
  const { id } = useParams()
  const navigate = useNavigate()
  const isNew = id === 'baru'

  const { data, reload: reloadEvent } = useApi(() => (isNew ? Promise.resolve(null) : partnerApi.event(id)), [id])
  const existing = useMemo(() => normalizeEvent(data?.data), [data])
  const { data: gatesData } = useApi(() => partnerApi.gates(), [])
  const { data: staffsData } = useApi(() => partnerApi.staffs(), [])
  const { data: fieldsData, reload: reloadFields } = useApi(() => partnerApi.formFields(), [])

  const [tab, setTab] = useState('info')
  const [saved, setSaved] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({
    nama_event: '',
    kategori: 'Konser',
    tanggal_mulai: '',
    tanggal_selesai: '',
    lokasi: '',
    deskripsi: '',
    hero_image_url: '',
    syarat_ketentuan: '',
    status: 'draft',
  })
  const [tiket, setTiket] = useState([])
  const [sessions, setSessions] = useState([])
  const [eventSessions, setEventSessions] = useState([])
  const [originalTicketIds, setOriginalTicketIds] = useState([])
  const [fields, setFields] = useState([])
  const [originalFieldIds, setOriginalFieldIds] = useState([])
  const [gates, setGates] = useState([])
  const [staffs, setStaffs] = useState([])
  const [denah, setDenah] = useState({ nama: 'Layout Utama', gambar_url: '' })

  useEffect(() => {
    if (!existing) return
    setForm({ ...existing })
    setTiket((existing.tiket || []).map((t) => ({ ...t, _key: t.id })))
    setSessions((existing.sessions || []).map((s) => ({ ...s, _key: s.id })))
    setEventSessions(existing.sessions || [])
    setOriginalTicketIds((existing.tiket || []).map((t) => t.id))
  }, [existing])

  useEffect(() => { if (gatesData) setGates(gatesData.data || []) }, [gatesData])
  useEffect(() => { if (staffsData) setStaffs(staffsData.data || []) }, [staffsData])
  useEffect(() => {
    if (!fieldsData) return
    // Hanya field milik event ini + field organizer-level (event_id NULL).
    const mine = (fieldsData.data || []).filter((f) => !f.event_id || f.event_id === existing?.id)
    setFields(mine.map((f) => ({ ...f, ticket_key: f.ticket_type_id ?? null, _key: f.id })))
    setOriginalFieldIds(mine.map((f) => f.id))
  }, [fieldsData, existing?.id])

  const set = (key, value) => setForm((f) => ({ ...f, [key]: value }))

  const save = async (status) => {
    // Kalau modal edit tiket masih terbuka, isiannya belum masuk daftar.
    // Tanpa ini, perubahan terakhir partner hilang tanpa peringatan.
    const tiketFinal = gabungDraft(tiket)
    if (draft) { setTiketModal(null); setDraft(null) }

    // FASE 3: kategori tiket wajib punya minimal 1 sesi. Kategori tanpa sesi
    // menghasilkan QR yang tidak bisa dipakai check-in di gate mana pun.
    const tanpaSesi = tiketFinal.filter((t) => t.nama_tiket && ticketSessionIds(t).length === 0)
    if (tanpaSesi.length > 0) {
      const daftar = tanpaSesi.map((t) => `• ${t.nama_tiket}`).join('\n')
      window.alert(
        eventSessions.length === 0
          ? `Event ini belum punya sesi/hari.\n\nBuka tab "Sesi", tambahkan minimal 1 sesi, lalu pilih sesi itu di setiap kategori tiket.\n\nKategori yang belum punya sesi:\n${daftar}`
          : `Setiap kategori tiket wajib punya minimal 1 sesi, supaya QR-nya bisa dipakai check-in.\n\nKategori yang belum punya sesi:\n${daftar}`,
      )
      return
    }

    setSaving(true)
    try {
      const sessionsPayload = sessions
        .filter((s) => s.label || s.nama_session)
        .map((s, i) => ({
          id: s.id || undefined,
          label: (s.label || `Day ${i + 1}`).trim(),
          nama_session: (s.nama_session || `Sesi ${i + 1}`).trim(),
          tanggal_mulai: s.tanggal_mulai || null,
          tanggal_selesai: s.tanggal_selesai || null,
          lokasi: s.lokasi || null,
          kapasitas: Number(s.kapasitas) || 0,
          urutan: Number(s.urutan) || i + 1,
          status: s.status || 'aktif',
        }))

      const eventPayload = {
        nama_event: form.nama_event,
        kategori: form.kategori,
        deskripsi: form.deskripsi,
        hero_image_url: form.hero_image_url,
        tanggal_mulai: form.tanggal_mulai || null,
        tanggal_selesai: form.tanggal_selesai || null,
        lokasi: form.lokasi,
        syarat_ketentuan: form.syarat_ketentuan,
        sembunyikan_sisa_kuota: !!form.sembunyikan_sisa_kuota,
        status: status || form.status,
        sessions: sessionsPayload,
      }

      let eventId = existing?.id
      if (isNew) {
        const res = await partnerApi.createEvent(eventPayload)
        eventId = res.data.id
      } else {
        await partnerApi.updateEvent(eventId, eventPayload)
      }

      const keptIds = []
      for (const t of tiketFinal) {
        if (!t.nama_tiket) continue
        const ticketPayload = {
          event_id: eventId,
          nama_tiket: t.nama_tiket,
          harga: Number(t.harga) || 0,
          kuota: Number(t.kuota) || 0,
          max_per_order: Number(t.max_per_order) || 1,
          jam_masuk_mulai: t.jam_masuk_mulai || null,
          jam_masuk_selesai: t.jam_masuk_selesai || null,
          gambar_url: t.gambar_url || null,
          session_ids: ticketSessionIds(t),
        }
        if (t.id) {
          await partnerApi.updateTicketType(t.id, ticketPayload)
          keptIds.push(t.id)
        } else {
          const res = await partnerApi.createTicketType(ticketPayload)
          keptIds.push(res.data.id)
        }
      }
      for (const oldId of originalTicketIds) {
        if (oldId && !keptIds.includes(oldId)) await partnerApi.deleteTicketType(oldId)
      }

      // Formulir custom: simpan field sungguhan (create/update/delete) + scope tiket.
      const ticketIdByKey = new Map(tiketFinal.map((t) => [String(t._key), t.id]))
      const keptFieldIds = []
      let fieldUrutan = 1
      for (const f of fields.filter((x) => !x.event_id || x.event_id === eventId)) {
        const targetId = f.ticket_key ? ticketIdByKey.get(String(f.ticket_key)) : null
        if (f.ticket_key && !targetId) continue // tiket referensinya sudah dihapus
        const payload = {
          label: (f.label || '').trim() || 'Field Baru',
          tipe: f.tipe || 'teks',
          wajib: !!f.wajib,
          urutan: fieldUrutan++,
          ticket_type_id: targetId ?? null,
        }
        if (!f.id) {
          const res = await partnerApi.createFormField({ ...payload, event_id: eventId })
          keptFieldIds.push(res.data.id)
        } else {
          const upd = { ...payload }
          if (!f.event_id && targetId) upd.event_id = eventId // field organizer-level di-scope ke event ini
          await partnerApi.updateFormField(f.id, upd)
          keptFieldIds.push(f.id)
        }
      }
      for (const oldId of originalFieldIds) {
        if (oldId && !keptFieldIds.includes(oldId)) await partnerApi.deleteFormField(oldId)
      }

      await reloadFields() // sinkronkan state fields dengan data terbaru dari server

      setSaved(true)
      setTimeout(() => setSaved(false), 2500)
      if (isNew) navigate(`/partner/master/event/${eventId}`)
      else await reloadEvent()
    } catch (err) {
      window.alert(err.message)
    } finally {
      setSaving(false)
    }
  }

  const addSession = () =>
    setSessions((list) => [
      ...list,
      {
        _key: `new-${Date.now()}`,
        id: null,
        label: `Day ${list.length + 1}`,
        nama_session: '',
        tanggal_mulai: '',
        tanggal_selesai: '',
        lokasi: '',
        kapasitas: 0,
        urutan: list.length + 1,
        status: 'aktif',
      },
    ])

  const updateSession = (key, field, value) =>
    setSessions((list) => list.map((s) => (s._key === key ? { ...s, [field]: value } : s)))

  const removeSession = (key) => setSessions((list) => list.filter((s) => s._key !== key))

  // Modal edit tiket: tiket di tab ini masih DRAF di memori (belum tersimpan
  // ke server), jadi perubahannya ditahan dulu di `draft`, lalu baru masuk ke
  // daftar saat partner menekan Simpan. Dengan begitu tabel tetap ringkas
  // walau tiketnya banyak, dan tombol Batal benar-benar membatalkan.
  const [tiketModal, setTiketModal] = useState(null) // _key tiket yang diedit
  const [draft, setDraft] = useState(null)

  const bukaModalTiket = (t) => {
    setTiketModal(t._key)
    setDraft({ ...t, session_ids: [...ticketSessionIds(t)] })
  }

  // Batal pada tiket BARU berarti membuang baris kosongnya. Tanpa ini,
  // daftar menyimpan entri tanpa nama yang membingungkan saat event disimpan.
  const batalDraft = () => {
    if (draft && !draft.id && !(draft.nama_tiket || '').trim()) {
      setTiket((l) => l.filter((x) => x._key !== draft._key))
    }
    setTiketModal(null)
    setDraft(null)
  }

  const ubahDraft = (field, value) => setDraft((d) => (d ? { ...d, [field]: value } : d))

  const toggleDraftSession = (sessionId) => {
    setDraft((d) => {
      if (!d) return d
      const ada = ticketSessionIds(d).includes(sessionId)
      return {
        ...d,
        session_ids: ada
          ? ticketSessionIds(d).filter((s) => s !== sessionId)
          : [...ticketSessionIds(d), sessionId],
      }
    })
  }

  // Gabungkan draft yang masih terbuka ke dalam daftar, lalu kembalikan
  // daftar hasilnya. Nilainya dikembalikan (bukan hanya di-set) karena
  // setTiket bersifat asinkron: pada render yang sama, variabel `tiket`
  // di save() belum tentu sudah memuat perubahan terbaru.
  const gabungDraft = (daftar) => {
    if (!draft) return daftar
    return daftar
      .map((t) => (t._key === draft._key
        ? ((draft.nama_tiket || '').trim() ? { ...draft, nama_tiket: draft.nama_tiket.trim() } : t)
        : t))
      .filter((t) => (t.nama_tiket || '').trim() || t.id)
  }

  const simpanDraft = () => {
    if (!draft) return
    const nama = (draft.nama_tiket || '').trim()
    if (!nama) return alert('Nama tiket wajib diisi.')
    if (Number(draft.kuota) < 1) return alert('Kuota minimal 1.')
    if (eventSessions.length > 0 && ticketSessionIds(draft).length === 0) {
      return alert('Pilih minimal 1 sesi — tanpa sesi, QR tiket ini tidak bisa dipakai check-in.')
    }
    setTiket((list) => list.map((t) => (t._key === draft._key ? { ...draft, nama_tiket: nama } : t)))
    setTiketModal(null)
    setDraft(null)
  }

  return (
    <div>
      <Link to="/partner/master/event" className="mb-4 inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-700">
        <ArrowLeft size={16} /> Kembali ke daftar event
      </Link>
      <PageHeader
        title={isNew ? 'Buat Event Baru' : 'Kelola Event'}
        description={isNew ? 'Lengkapi informasi acara sebelum dipublikasikan.' : form.nama_event}
        action={
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => save('draft')} disabled={saving}><Save size={16} /> {saving ? 'Menyimpan...' : 'Simpan Draf'}</Button>
            <Button onClick={() => save('aktif')} disabled={saving}><Send size={16} /> Terbitkan</Button>
          </div>
        }
      />

      {saved && (
        <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
          Perubahan berhasil disimpan.
        </div>
      )}

      <div className="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200">
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={cn(
              'whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition',
              tab === t.id ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700',
            )}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'info' && (
        <Card className="p-6">
          <div className="grid gap-5 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Field label="Nama Event" required>
                <Input value={form.nama_event} onChange={(e) => set('nama_event', e.target.value)} placeholder="Contoh: Konser Senja Nusantara" />
              </Field>
            </div>
            <Field label="Kategori">
              <Select value={form.kategori} onChange={(e) => set('kategori', e.target.value)}>
                {KATEGORI.map((k) => <option key={k}>{k}</option>)}
              </Select>
            </Field>
            <Field label="Status">
              <Select value={form.status} onChange={(e) => set('status', e.target.value)}>
                <option value="draft">Draf</option>
                <option value="aktif">Aktif</option>
                <option value="selesai">Selesai</option>
              </Select>
            </Field>
            <Field label="Tanggal & Jam Mulai">
              <Input type="datetime-local" value={form.tanggal_mulai?.slice(0, 16) || ''} onChange={(e) => set('tanggal_mulai', e.target.value)} />
            </Field>
            <Field label="Tanggal & Jam Selesai">
              <Input type="datetime-local" value={form.tanggal_selesai?.slice(0, 16) || ''} onChange={(e) => set('tanggal_selesai', e.target.value)} />
            </Field>
            <div className="sm:col-span-2">
              <Field label="Lokasi">
                <Input value={form.lokasi} onChange={(e) => set('lokasi', e.target.value)} placeholder="Nama venue & kota" />
              </Field>
            </div>
            <div className="sm:col-span-2">
              <Field label="Deskripsi">
                <Textarea value={form.deskripsi} onChange={(e) => set('deskripsi', e.target.value)} placeholder="Ceritakan tentang acaramu..." />
              </Field>
            </div>
            <div className="sm:col-span-2">
              <Field label="Gambar Utama" hint="Rasio disarankan 16:9, format JPG/PNG/WEBP maks 2MB">
                <ImagePicker
                  value={form.hero_image_url}
                  onChange={(url) => set('hero_image_url', url)}
                  folder="events"
                  label="Unggah Gambar Utama"
                  previewClassName="h-24 w-40"
                />
              </Field>
            </div>
            <div className="sm:col-span-2">
              <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input
                  type="checkbox"
                  checked={!!form.sembunyikan_sisa_kuota}
                  onChange={(e) => set('sembunyikan_sisa_kuota', e.target.checked)}
                  className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                />
                <span>
                  <span className="block text-sm font-semibold text-slate-800">Sembunyikan sisa kuota dari pembeli</span>
                  <span className="mt-0.5 block text-xs text-slate-500">
                    Jika aktif, halaman publik hanya menampilkan status "Tersedia/Habis" tanpa angka sisa tiket.
                  </span>
                </span>
              </label>
            </div>
            <div className="sm:col-span-2">
              <Field label="Syarat & Ketentuan">
                <Textarea value={form.syarat_ketentuan} onChange={(e) => set('syarat_ketentuan', e.target.value)} placeholder="1. ..." />
              </Field>
            </div>
          </div>
        </Card>
      )}

      {tab === 'sesi' && (
        <Card className="p-6">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h2 className="font-bold text-slate-900">Sesi / Hari Acara</h2>
              <p className="text-sm text-slate-500">Tambahkan hari/sesi (mis. Day 1, Day 2). Jenis tiket bisa dikaitkan ke satu atau beberapa sesi.</p>
            </div>
            <Button variant="secondary" size="sm" onClick={addSession}>
              <Plus size={15} /> Tambah Sesi
            </Button>
          </div>

          {sessions.length === 0 ? (
            <div className="rounded-xl border-2 border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
              Belum ada sesi. Klik <span className="font-semibold text-slate-700">Tambah Sesi</span> untuk membuat Day 1, Day 2, dst.
            </div>
          ) : (
            <div className="space-y-4">
              {sessions.map((s) => (
                <div key={s._key} className="rounded-xl border border-slate-200 p-3">
                  <div className="grid gap-3 sm:grid-cols-12">
                    <div className="sm:col-span-2">
                      <label className="label text-xs">Label</label>
                      <Input value={s.label} onChange={(e) => updateSession(s._key, 'label', e.target.value)} placeholder="Day 1" />
                    </div>
                    <div className="sm:col-span-4">
                      <label className="label text-xs">Nama Sesi</label>
                      <Input value={s.nama_session} onChange={(e) => updateSession(s._key, 'nama_session', e.target.value)} placeholder="Malam Pembuka" />
                    </div>
                    <div className="sm:col-span-2">
                      <label className="label text-xs">Kapasitas</label>
                      <Input type="number" value={s.kapasitas} onChange={(e) => updateSession(s._key, 'kapasitas', e.target.value)} />
                    </div>
                    <div className="sm:col-span-3">
                      <label className="label text-xs">Lokasi</label>
                      <Input value={s.lokasi || ''} onChange={(e) => updateSession(s._key, 'lokasi', e.target.value)} placeholder="Venue" />
                    </div>
                    <div className="flex items-end sm:col-span-1">
                      <Button variant="ghost" size="icon" className="text-rose-600" onClick={() => removeSession(s._key)}>
                        <Trash2 size={16} />
                      </Button>
                    </div>
                  </div>
                  <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                      <label className="label text-xs">Mulai</label>
                      <Input type="datetime-local" value={s.tanggal_mulai?.slice(0, 16) || ''} onChange={(e) => updateSession(s._key, 'tanggal_mulai', e.target.value)} />
                    </div>
                    <div>
                      <label className="label text-xs">Selesai</label>
                      <Input type="datetime-local" value={s.tanggal_selesai?.slice(0, 16) || ''} onChange={(e) => updateSession(s._key, 'tanggal_selesai', e.target.value)} />
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          <div className="mt-4 rounded-xl bg-slate-50 p-4 text-xs text-slate-500">
            Klik <span className="font-semibold text-slate-700">Simpan Draf / Terbitkan</span> untuk menyimpan sesi. Setelah tersimpan, buka tab
            <span className="font-semibold text-slate-700"> Jenis Tiket &amp; Kuota</span> untuk mengaitkan tiket ke sesi (pilih &gt;1 sesi = tiket bundle).
          </div>
        </Card>
      )}

      {tab === 'tiket' && (
        <Card className="p-6">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h2 className="font-bold text-slate-900">Jenis Tiket</h2>
              <p className="text-sm text-slate-500">Atur harga, kuota, gambar tiket (opsional), dan batas pembelian.</p>
            </div>
            <Button
              variant="secondary"
              size="sm"
              onClick={() => {
                // Langsung buka modal: menambah baris kosong ke tabel hanya
                // membuat partner harus mengklik Edit lagi.
                const baru = {
                  _key: `new-${Date.now()}`,
                  id: null,
                  nama_tiket: '',
                  harga: 0,
                  kuota: 0,
                  sisa_kuota: 0,
                  max_per_order: 4,
                  jam_masuk_mulai: '',
                  jam_masuk_selesai: '',
                  gambar_url: '',
                  session_ids: [],
                }
                setTiket((l) => [...l, baru])
                bukaModalTiket(baru)
              }}
            >
              <Plus size={15} /> Tambah
            </Button>
          </div>
          {(() => {
            const { celah, bentrok, jumlahBerjadwal } = cariCelahJam(tiket)
            if (!jumlahBerjadwal) return null
            return (
              <div className="mb-4 space-y-2">
                {celah.map((c, i) => (
                  <div key={`celah-${i}`} className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    <AlertTriangle size={16} className="mt-0.5 shrink-0" />
                    <p>
                      Ada celah jadwal <strong>{c.dari}–{c.ke}</strong> antara <strong>{c.sebelum}</strong> dan{' '}
                      <strong>{c.sesudah}</strong>. Selama celah itu semua QR ditolak di gerbang — rapatkan jamnya kalau tidak disengaja.
                    </p>
                  </div>
                ))}
                {bentrok.map((b, i) => (
                  <div key={`bentrok-${i}`} className="flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-800">
                    <Info size={16} className="mt-0.5 shrink-0" />
                    <p>
                      Jam masuk <strong>{b.nama}</strong> dan <strong>{b.dengan}</strong> tumpang tindih — kedua kategori boleh masuk bersamaan.
                    </p>
                  </div>
                ))}
              </div>
            )
          })()}
          <div className="space-y-4">
            {tiket.length === 0 && (
              <div className="rounded-xl border-2 border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
                Belum ada jenis tiket. Klik <span className="font-semibold text-slate-700">Tambah</span> untuk menambahkan.
              </div>
            )}
            {tiket.length > 0 && (
              <div className="overflow-x-auto rounded-xl border border-slate-200">
                <table className="w-full min-w-[720px] text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                      <th className="px-4 py-2.5 font-semibold">Jenis Tiket</th>
                      <th className="px-4 py-2.5 font-semibold">Harga</th>
                      <th className="px-4 py-2.5 font-semibold">Kuota</th>
                      <th className="px-4 py-2.5 font-semibold">Jam Masuk</th>
                      <th className="px-4 py-2.5 font-semibold">Sesi</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Aksi</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {tiket.map((t) => {
                      const sesi = ticketSessionIds(t)
                      const bundle = isBundleTicket(t)
                      const belumSesi = eventSessions.length > 0 && sesi.length === 0
                      return (
                        <tr key={t._key} className={belumSesi ? 'bg-rose-50/40' : 'hover:bg-slate-50'}>
                          <td className="px-4 py-2.5">
                            <div className="flex items-center gap-2.5">
                              <span className="grid h-9 w-14 shrink-0 place-items-center overflow-hidden rounded-lg bg-brand-100 text-brand-700">
                                {t.gambar_url ? (
                                  <img
                                    src={t.gambar_url}
                                    alt={t.nama_tiket}
                                    className="h-full w-full object-cover"
                                    onError={(ev) => { ev.currentTarget.style.display = 'none' }}
                                  />
                                ) : <TicketIcon size={14} />}
                              </span>
                              <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-1.5">
                                  <span className="font-semibold text-slate-800">{t.nama_tiket || '(tanpa nama)'}</span>
                                  {bundle && <Badge color="brand">Bundle</Badge>}
                                </div>
                                <p className="text-[11px] text-slate-400">maks {t.max_per_order}/pesanan</p>
                              </div>
                            </div>
                          </td>
                          <td className="px-4 py-2.5 font-semibold text-slate-800">{formatRupiah(t.harga)}</td>
                          <td className="px-4 py-2.5 text-slate-600">
                            {Number(t.kuota).toLocaleString('id-ID')}
                            {Number(t.sisa_kuota) < Number(t.kuota) && (
                              <p className="text-[11px] text-slate-400">
                                sisa {Number(t.sisa_kuota).toLocaleString('id-ID')}
                              </p>
                            )}
                          </td>
                          <td className="px-4 py-2.5 text-slate-600">
                            {t.jam_masuk_mulai
                              ? `${t.jam_masuk_mulai}${t.jam_masuk_selesai ? `–${t.jam_masuk_selesai}` : ''}`
                              : <span className="text-slate-400">—</span>}
                          </td>
                          <td className="px-4 py-2.5">
                            {sesi.length === 0 ? (
                              <span className="text-xs font-semibold text-rose-600">Belum dipilih</span>
                            ) : (
                              <span className="text-slate-600">
                                {bundle ? `${sesi.length} sesi (bundle)` : eventSessions.find((s) => s.id === sesi[0])?.label || '—'}
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-2.5">
                            <div className="flex items-center justify-end gap-1">
                              <Button size="sm" variant="secondary" onClick={() => bukaModalTiket(t)}>
                                <Pencil size={13} /> Edit
                              </Button>
                              <Button
                                size="sm"
                                variant="ghost"
                                className="text-rose-600"
                                title="Hapus dari daftar"
                                onClick={() => setTiket((l) => l.filter((x) => x._key !== t._key))}
                              >
                                <Trash2 size={15} />
                              </Button>
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}

            <p className="text-xs text-slate-400">
              Klik <span className="font-semibold text-slate-600">Edit</span> untuk mengubah detail tiket.
              Perubahan baru tersimpan ke daftar setelah ditekan Simpan pada jendela edit.
            </p>
          </div>
        </Card>
      )}

      {/* ── Modal edit tiket ── */}
      <Modal
        open={!!draft}
        onClose={batalDraft}
        title={`Edit Tiket — ${draft?.nama_tiket || 'Baru'}`}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={batalDraft}>Batal</Button>
            <Button onClick={simpanDraft}>Simpan</Button>
          </>
        }
      >
        {draft && (
          <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-6">
              <div className="sm:col-span-3">
                <label className="label text-xs">Nama Tiket</label>
                <Input
                  value={draft.nama_tiket}
                  onChange={(e) => ubahDraft('nama_tiket', e.target.value)}
                  placeholder="Reguler / VIP"
                />
              </div>
              <div className="sm:col-span-3">
                <label className="label text-xs">Harga (Rp)</label>
                <Input type="number" value={draft.harga} onChange={(e) => ubahDraft('harga', Number(e.target.value))} />
              </div>
              <div className="sm:col-span-3">
                <label className="label text-xs">Kuota</label>
                <Input type="number" value={draft.kuota} onChange={(e) => ubahDraft('kuota', Number(e.target.value))} />
              </div>
              <div className="sm:col-span-3">
                <label className="label text-xs">Maks/Order</label>
                <Input type="number" value={draft.max_per_order} onChange={(e) => ubahDraft('max_per_order', Number(e.target.value))} />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label text-xs">Jam Masuk Mulai</label>
                <Input type="time" value={draft.jam_masuk_mulai || ''} onChange={(e) => ubahDraft('jam_masuk_mulai', e.target.value)} />
              </div>
              <div>
                <label className="label text-xs">Jam Masuk Selesai</label>
                <Input type="time" value={draft.jam_masuk_selesai || ''} onChange={(e) => ubahDraft('jam_masuk_selesai', e.target.value)} />
              </div>
            </div>
            <p className="text-xs text-slate-400">
              Opsional — di gerbang, jam ini jadi jam masuk (gelombang) khusus kategori ini.
              Kosongkan jika kategori ini boleh masuk kapan saja.
            </p>

            <div>
              <label className="label text-xs">Gambar Tiket (opsional)</label>
              <ImagePicker
                value={draft.gambar_url}
                onChange={(url) => ubahDraft('gambar_url', url)}
                folder="tickets"
                label="Unggah Gambar Tiket"
                previewClassName="h-16 w-28"
              />
            </div>

            {eventSessions.length > 0 && (
              <div>
                <label className="label text-xs">Berlaku untuk Sesi</label>
                <div className="flex flex-wrap gap-2">
                  {eventSessions.map((s) => {
                    const checked = ticketSessionIds(draft).includes(s.id)
                    return (
                      <label
                        key={s.id}
                        className={cn(
                          'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition',
                          checked
                            ? 'border-brand-500 bg-brand-50 text-brand-700'
                            : 'border-slate-200 text-slate-600 hover:border-brand-300',
                        )}
                      >
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => toggleDraftSession(s.id)}
                          className="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        />
                        {s.label}
                      </label>
                    )
                  })}
                </div>
                <p className="mt-1.5 text-xs text-slate-500">
                  {isBundleTicket(draft)
                    ? `Bundle ${ticketSessionIds(draft).length} sesi (berlaku di beberapa hari).`
                    : 'Pilih lebih dari satu sesi untuk menjadikannya tiket bundle.'}
                </p>
                {ticketSessionIds(draft).length === 0 && (
                  <p className="mt-1 text-xs font-semibold text-rose-600">
                    Wajib pilih minimal 1 sesi — tanpa sesi, QR tiket ini tidak bisa dipakai check-in.
                  </p>
                )}
              </div>
            )}

            {eventSessions.length === 0 && (
              <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700">
                Belum ada sesi/hari untuk event ini. Tambahkan minimal 1 sesi di tab &quot;Sesi&quot; dulu,
                lalu pilih sesi tersebut di sini.
              </p>
            )}
          </div>
        )}
      </Modal>

      {tab === 'form' && (
        <Card className="p-6">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h2 className="font-bold text-slate-900">Formulir Data Pembeli</h2>
              <p className="text-sm text-slate-500">Pilih data yang wajib/opsional diisi pembeli.</p>
            </div>
            <Button variant="secondary" size="sm" onClick={() => setFields((l) => [...l, { _key: `new-${Date.now()}`, id: null, event_id: null, label: 'Field Baru', tipe: 'teks', wajib: false, urutan: l.length + 1, ticket_key: null }])}>
              <Plus size={15} /> Tambah Field
            </Button>
          </div>
          <div className="space-y-2">
            {fields.map((f) => (
              <div key={f._key} className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 p-3">
                <GripVertical size={16} className="text-slate-300" />
                <Input value={f.label} onChange={(e) => setFields((l) => l.map((x) => (x._key === f._key ? { ...x, label: e.target.value } : x)))} className="max-w-xs" />
                <Select value={f.tipe} onChange={(e) => setFields((l) => l.map((x) => (x._key === f._key ? { ...x, tipe: e.target.value } : x)))} className="max-w-[150px]">
                  <option value="teks">Teks</option>
                  <option value="email">Email</option>
                  <option value="nomor">Nomor</option>
                  <option value="sosial">Sosial</option>
                </Select>
                <Select value={String(f.ticket_key ?? '')} onChange={(e) => setFields((l) => l.map((x) => (x._key === f._key ? { ...x, ticket_key: e.target.value || null } : x)))} className="max-w-[180px]">
                  <option value="">Semua tiket</option>
                  {tiket.map((t) => (
                    <option key={t._key} value={String(t._key)}>{t.nama_tiket || '(tiket baru)'}</option>
                  ))}
                </Select>
                <label className="flex items-center gap-2 text-sm text-slate-600">
                  <input type="checkbox" checked={f.wajib} onChange={(e) => setFields((l) => l.map((x) => (x._key === f._key ? { ...x, wajib: e.target.checked } : x)))} className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                  Wajib
                </label>
                <Button variant="ghost" size="icon" className="ml-auto text-rose-600" onClick={() => setFields((l) => l.filter((x) => x._key !== f._key))}>
                  <Trash2 size={16} />
                </Button>
              </div>
            ))}
          </div>
        </Card>
      )}

      {tab === 'gate' && (
        <Card className="p-6">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h2 className="font-bold text-slate-900">Pintu Masuk (Gate)</h2>
              <p className="text-sm text-slate-500">Siapkan gate untuk proses check-in di lokasi.</p>
            </div>
            <Button variant="secondary" size="sm" onClick={() => setGates((l) => [...l, { id: Date.now(), nama_gate: 'Gate Baru', status: 'aktif' }])}>
              <Plus size={15} /> Tambah Gate
            </Button>
          </div>
          <div className="space-y-2">
            {gates.map((g) => (
              <div key={g.id} className="flex items-center gap-3 rounded-xl border border-slate-200 p-3">
                <Input value={g.nama_gate} onChange={(e) => setGates((l) => l.map((x) => (x.id === g.id ? { ...x, nama_gate: e.target.value } : x)))} />
                <button
                  onClick={() => setGates((l) => l.map((x) => (x.id === g.id ? { ...x, status: x.status === 'aktif' ? 'nonaktif' : 'aktif' } : x)))}
                >
                  <Badge color={g.status === 'aktif' ? 'green' : 'slate'}>{g.status}</Badge>
                </button>
                <Button variant="ghost" size="icon" className="text-rose-600" onClick={() => setGates((l) => l.filter((x) => x.id !== g.id))}>
                  <Trash2 size={16} />
                </Button>
              </div>
            ))}
          </div>
        </Card>
      )}

      {tab === 'seat' && (
        <Card className="p-6">
          <div className="mb-4 flex items-center gap-2">
            <Info size={17} className="text-brand-600" />
            <h2 className="font-bold text-slate-900">Denah Lokasi & Tempat Duduk</h2>
          </div>
          <div className="grid gap-6 lg:grid-cols-2">
            <div className="space-y-4">
              <Field label="Nama Denah">
                <Input value={denah.nama} onChange={(e) => setDenah({ ...denah, nama: e.target.value })} className="max-w-sm" />
              </Field>
              <Field label="Gambar Denah (opsional)" hint="Format PNG/JPG maks 2MB. Tampil di halaman publik event.">
                <div className="space-y-2">
                  <Input
                    value={denah.gambar_url}
                    onChange={(e) => setDenah({ ...denah, gambar_url: e.target.value })}
                    placeholder="Tempel URL gambar atau unggah file"
                  />
                  <div className="flex items-center gap-3">
                    <ImagePicker
                      value={denah.gambar_url}
                      onChange={(url) => setDenah((d) => ({ ...d, gambar_url: url }))}
                      folder="seat-plans"
                      label="Unggah Gambar"
                    />
                    {denah.gambar_url && (
                      <Button variant="ghost" size="sm" className="text-rose-600" onClick={() => setDenah((d) => ({ ...d, gambar_url: '' }))}>
                        Hapus Gambar
                      </Button>
                    )}
                  </div>
                </div>
              </Field>
              <div className="overflow-hidden rounded-2xl border border-slate-200">
                {denah.gambar_url ? (
                  <img key={denah.gambar_url} src={denah.gambar_url} alt="Pratinjau denah" className="h-40 w-full object-cover" onError={(ev) => { ev.currentTarget.style.display = 'none' }} />
                ) : (
                  <div className="grid h-40 place-items-center text-sm text-slate-400">Belum ada gambar denah</div>
                )}
              </div>
            </div>

            <div>
              <p className="mb-3 text-sm text-slate-500">Pratinjau denah kursi. Kursi dapat diatur kode, baris, dan kategorinya.</p>
              <div className="overflow-x-auto rounded-xl bg-slate-50 p-4">
                {['A', 'B', 'C'].map((row, ri) => (
                  <div key={row} className="mb-2 flex items-center gap-3">
                    <span className="w-6 text-sm font-bold text-slate-400">{row}</span>
                    <div className="flex gap-1.5">
                      {Array.from({ length: ri === 0 ? 8 : 12 }, (_, i) => (
                        <span key={i} className={cn('grid h-7 w-7 place-items-center rounded-md text-[10px] font-semibold', i % 4 === 0 ? 'bg-slate-300 text-slate-500' : 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-300')}>
                          {i + 1}
                        </span>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </Card>
      )}

      {tab === 'staff' && (
        <Card className="p-6">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h2 className="font-bold text-slate-900">Staff & Petugas</h2>
              <p className="text-sm text-slate-500">Tambahkan petugas dan kaitkan ke gate.</p>
            </div>
            <Button variant="secondary" size="sm" onClick={() => setStaffs((l) => [...l, { id: Date.now(), nama: '', email: '', peran: 'Scanner', gate_id: 1 }])}>
              <Plus size={15} /> Tambah Staff
            </Button>
          </div>
          <div className="space-y-2">
            {staffs.map((s) => (
              <div key={s.id} className="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-12">
                <Input className="sm:col-span-3" value={s.nama} placeholder="Nama" onChange={(e) => setStaffs((l) => l.map((x) => (x.id === s.id ? { ...x, nama: e.target.value } : x)))} />
                <Input className="sm:col-span-4" value={s.email} placeholder="Email" onChange={(e) => setStaffs((l) => l.map((x) => (x.id === s.id ? { ...x, email: e.target.value } : x)))} />
                <Input className="sm:col-span-2" value={s.peran} onChange={(e) => setStaffs((l) => l.map((x) => (x.id === s.id ? { ...x, peran: e.target.value } : x)))} />
                <Select className="sm:col-span-2" value={s.gate_id} onChange={(e) => setStaffs((l) => l.map((x) => (x.id === s.id ? { ...x, gate_id: Number(e.target.value) } : x)))}>
                  {gates.map((g) => <option key={g.id} value={g.id}>{g.nama_gate}</option>)}
                </Select>
                <div className="flex items-center justify-end sm:col-span-1">
                  <Button variant="ghost" size="icon" className="text-rose-600" onClick={() => setStaffs((l) => l.filter((x) => x.id !== s.id))}>
                    <Trash2 size={16} />
                  </Button>
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  )
}
