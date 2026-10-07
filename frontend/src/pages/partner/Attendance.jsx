import { useEffect, useMemo, useState } from 'react'
import { Search, Download, Users, CheckCircle2, Clock, CalendarDays } from 'lucide-react'
import { Button, Card, Input, PageHeader, Stat, StatusBadge, EmptyState, Badge, ProgressBar, Select } from '../../components/ui'
import { partnerApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { formatTanggal, cn, slugify } from '../../lib/utils'
import { downloadCsv, namaFileCsv } from '../../lib/csv'

export default function Attendance() {
  const [q, setQ] = useState('')
  const [sessionFilter, setSessionFilter] = useState('all')

  const { data: eventsData } = useApi(() => partnerApi.events(), [])
  const events = eventsData?.data || []
  const [eventId, setEventId] = useState(null)

  useEffect(() => {
    if (!eventId && events.length) setEventId(events[0].id)
  }, [events, eventId])

  const { data, loading } = useApi(
    () => (eventId ? partnerApi.attendance({ event_id: eventId }) : Promise.resolve(null)),
    [eventId],
  )
  const sessions = data?.sessions || []
  const checkins = data?.checkins || []

  const filtered = useMemo(
    () =>
      checkins.filter((c) => {
        const matchSession = sessionFilter === 'all' || c.event_session_id === sessionFilter
        const matchQuery =
          (c.nama_pemegang || '').toLowerCase().includes(q.toLowerCase()) ||
          (c.kode_qr || '').toLowerCase().includes(q.toLowerCase())
        return matchSession && matchQuery
      }),
    [checkins, q, sessionFilter],
  )

  const scope = sessionFilter === 'all' ? sessions : sessions.filter((s) => s.id === sessionFilter)
  const hadir = scope.reduce((s, x) => s + (x.total_hadir || 0), 0)
  const totalTiket = scope.reduce((s, x) => s + (x.total_pass || 0), 0)
  const persen = totalTiket ? Math.round((hadir / totalTiket) * 100) : 0

  // Ekspor CSV asli dari baris yang sedang tampil (ikut filter sesi & pencarian).
  const unduhLaporan = () => {
    const namaEvent = events.find((e) => e.id === eventId)?.nama_event || 'event'
    const labelSesi =
      sessionFilter === 'all'
        ? 'Semua sesi'
        : sessions.find((s) => s.id === sessionFilter)?.label || '-'
    const rows = [
      [`Riwayat Kehadiran — ${namaEvent}`],
      [`Filter sesi: ${labelSesi}`],
      [`Jumlah baris: ${filtered.length}`],
      [],
      ['Nama Pemegang', 'Kode QR', 'Sesi', 'Gate', 'Waktu Check-in', 'Status', 'Catatan'],
      ...filtered.map((c) => [
        c.nama_pemegang,
        c.kode_qr,
        c.session?.label ?? '',
        c.gate?.nama_gate ?? '',
        c.checked_in_at ? formatTanggal(c.checked_in_at, { withTime: true }) : '',
        c.status,
        c.catatan ?? '',
      ]),
    ]
    downloadCsv(namaFileCsv(`kehadiran-${slugify(namaEvent)}`), rows)
  }

  return (
    <div>
      <PageHeader
        title="Riwayat Kehadiran"
        description="Pantau kehadiran per sesi/hari acara."
        action={
          <Button variant="secondary" onClick={unduhLaporan} disabled={!filtered.length}>
            <Download size={16} /> Unduh Laporan
          </Button>
        }
      />

      {events.length > 1 && (
        <Card className="mb-4 p-4">
          <Select value={eventId || ''} onChange={(e) => { setEventId(Number(e.target.value)); setSessionFilter('all') }} className="sm:w-72">
            {events.map((e) => <option key={e.id} value={e.id}>{e.nama_event}</option>)}
          </Select>
        </Card>
      )}

      <div className="mb-6 flex flex-wrap gap-2">
        <button
          onClick={() => setSessionFilter('all')}
          className={cn(
            'rounded-xl border px-4 py-2 text-sm font-semibold transition',
            sessionFilter === 'all' ? 'border-brand-600 bg-brand-50 text-brand-700 ring-2 ring-brand-100' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300',
          )}
        >
          Semua Sesi
        </button>
        {sessions.map((s) => (
          <button
            key={s.id}
            onClick={() => setSessionFilter(s.id)}
            className={cn(
              'rounded-xl border px-4 py-2 text-left transition',
              sessionFilter === s.id ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-100' : 'border-slate-200 bg-white hover:border-brand-300',
            )}
          >
            <span className={cn('text-xs font-bold uppercase tracking-wide', sessionFilter === s.id ? 'text-brand-700' : 'text-slate-500')}>{s.label}</span>
            <span className="block text-sm font-semibold text-slate-900">{s.nama_session}</span>
          </button>
        ))}
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="Sudah Hadir" value={hadir.toLocaleString('id-ID')} icon={CheckCircle2} color="green" />
        <Stat label="Total Tiket" value={totalTiket.toLocaleString('id-ID')} icon={Users} color="brand" />
        <Stat label="Kehadiran" value={`${persen}%`} icon={Clock} color="amber" hint={`${hadir} dari ${totalTiket} tiket sudah masuk`} />
      </div>

      <Card className="mt-6 p-5">
        <div className="mb-4 flex items-center gap-2">
          <CalendarDays size={18} className="text-brand-600" />
          <h2 className="font-bold text-slate-900">Laporan per Sesi</h2>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {sessions.map((s) => {
            const pct = s.total_pass ? Math.round((s.total_hadir / s.total_pass) * 100) : 0
            return (
              <div key={s.id} className="rounded-xl border border-slate-200 p-4">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="text-xs font-bold uppercase tracking-wide text-brand-700">{s.label}</p>
                    <p className="text-sm font-semibold text-slate-800">{s.nama_session}</p>
                  </div>
                  <Badge color={pct >= 75 ? 'green' : pct >= 40 ? 'amber' : 'slate'}>{pct}%</Badge>
                </div>
                <div className="mb-1 mt-3 flex justify-between text-xs text-slate-500">
                  <span>{(s.total_hadir || 0).toLocaleString('id-ID')} hadir</span>
                  <span>{(s.total_pass || 0).toLocaleString('id-ID')} tiket</span>
                </div>
                <ProgressBar value={s.total_hadir || 0} max={s.total_pass || 1} />
              </div>
            )
          })}
          {sessions.length === 0 && <p className="text-sm text-slate-400">Belum ada data sesi.</p>}
        </div>
      </Card>

      <Card className="mt-6">
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="relative sm:w-72">
            <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama atau kode tiket..." className="pl-10" />
          </div>
          <Badge color="green">{hadir} hadir</Badge>
        </div>
        {loading ? (
          <div className="m-4 h-56 animate-pulse rounded-xl bg-slate-100" />
        ) : filtered.length === 0 ? (
          <EmptyState icon={Users} title="Belum ada data kehadiran" description="Data akan muncul setelah petugas melakukan scan." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-5 py-3 font-semibold">Nama</th>
                  <th className="px-5 py-3 font-semibold">Kode Tiket</th>
                  <th className="px-5 py-3 font-semibold">Sesi</th>
                  <th className="px-5 py-3 font-semibold">Gate</th>
                  <th className="px-5 py-3 font-semibold">Waktu Masuk</th>
                  <th className="px-5 py-3 font-semibold">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map((c) => (
                  <tr key={c.id} className="hover:bg-slate-50">
                    <td className="px-5 py-3 font-medium text-slate-800">{c.nama_pemegang}</td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-500">{c.kode_qr}</td>
                    <td className="px-5 py-3">
                      <Badge color="slate">{c.session?.label ?? '—'}</Badge>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{c.gate?.nama_gate ?? '—'}</td>
                    <td className="px-5 py-3 text-slate-600">{formatTanggal(c.checked_in_at, { withTime: true })}</td>
                    <td className="px-5 py-3">
                      <StatusBadge status={c.status} />
                      {c.catatan && (
                        <p className={cn('mt-0.5 text-xs', c.status === 'berhasil' ? 'text-emerald-600' : 'text-rose-500')}>
                          {c.catatan}
                        </p>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
