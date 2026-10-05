import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Search, Ticket, Wallet, QrCode, ArrowRight, MapPin, CalendarDays, SlidersHorizontal, X, BadgeDollarSign, ScanLine, Landmark, TrendingUp, Users } from 'lucide-react'
import { Button, EmptyState } from '../../components/ui'
import EventCard from '../../components/EventCard'
import { KATEGORI } from '../../lib/constants'
import { publicApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { normalizeEvent } from '../../lib/normalize'
import { cn } from '../../lib/utils'

export default function Home() {
  const [q, setQ] = useState('')
  const [kategori, setKategori] = useState('Semua')
  const [showFilters, setShowFilters] = useState(false)
  const [lokasi, setLokasi] = useState('')
  const [tanggal, setTanggal] = useState('')

  const { data, loading } = useApi(() => publicApi.events(), [])
  const events = useMemo(() => (data?.data || []).map(normalizeEvent), [data])

  const filtered = useMemo(() => {
    return events.filter((e) => {
      const matchQ = e.nama_event.toLowerCase().includes(q.toLowerCase())
      const matchK = kategori === 'Semua' || e.kategori === kategori
      const matchLokasi = !lokasi || (e.lokasi || '').toLowerCase().includes(lokasi.toLowerCase())
      const matchTanggal = !tanggal || (e.tanggal_mulai || '').slice(0, 10) === tanggal
      return matchQ && matchK && matchLokasi && matchTanggal
    })
  }, [events, q, kategori, lokasi, tanggal])

  const kategoriList = ['Semua', ...KATEGORI]

  return (
    <div>
      <section className="hero-event relative flex min-h-[500px] items-center overflow-hidden bg-slate-950 text-white sm:min-h-[510px]">
        <div className="hero-event__image absolute inset-0 bg-cover bg-center" style={{ backgroundImage: "url('/nontix-hero.webp')" }} aria-hidden="true" />
        <div className="hero-event__overlay absolute inset-0" aria-hidden="true" />
        <div className="container-page relative z-10 flex w-full justify-center py-20 sm:py-24">
          <div className="hero-event__content w-full max-w-4xl text-center">
            <h1 className="text-4xl font-extrabold leading-[1.08] tracking-tight text-white sm:text-[44px] lg:text-[52px]">
              Temukan Event Seru, Pesan Tiket Tanpa Ribet
            </h1>
            <p className="mx-auto mt-4 max-w-2xl text-[15px] leading-relaxed text-white/85 sm:text-base">
              Konser, olahraga, seminar, sampai festival — semua tiket ada di satu tempat.
            </p>

            <form className="mx-auto mt-8 flex w-full max-w-[850px] flex-col gap-2 rounded-[var(--radius-card)] bg-white p-2 shadow-2xl ring-1 ring-white/30 sm:mt-10 sm:flex-row sm:items-center sm:gap-0" onSubmit={(event) => event.preventDefault()}>
              <label className="flex min-h-12 flex-1 items-center gap-3 px-3 text-left">
                <Search size={20} className="shrink-0 text-slate-400" />
                <span className="sr-only">Nama event</span>
                <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama event..." className="min-w-0 w-full bg-transparent text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none" />
              </label>
              <div className="hidden h-8 w-px bg-slate-200 sm:block" aria-hidden="true" />
              <label className="flex min-h-12 flex-1 items-center gap-3 border-t border-slate-200 px-3 text-left sm:border-t-0 sm:px-5">
                <MapPin size={20} className="shrink-0 text-slate-400" />
                <span className="sr-only">Lokasi event</span>
                <input value={lokasi} onChange={(e) => setLokasi(e.target.value)} placeholder="Pilih lokasi..." className="min-w-0 w-full bg-transparent text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none" />
              </label>
              <Button type="submit" size="lg" className="w-full shrink-0 sm:w-auto">Cari</Button>
            </form>
            <div className="mt-3 flex min-h-9 flex-col items-center">
              <button type="button" onClick={() => setShowFilters((value) => !value)} aria-expanded={showFilters} className="inline-flex items-center gap-2 rounded-full bg-slate-950/35 px-3 py-1.5 text-xs font-semibold text-white/90 ring-1 ring-white/20 backdrop-blur transition hover:bg-slate-950/50 hover:text-white">
                <SlidersHorizontal size={14} /> Filter tanggal
              </button>
              {showFilters && (
                <div className="mt-2 flex w-full max-w-sm items-center gap-2 rounded-[var(--radius-control)] bg-white p-2 text-left shadow-lg">
                  <label className="flex min-h-10 min-w-0 flex-1 items-center gap-2 px-2">
                    <CalendarDays size={17} className="shrink-0 text-brand-500" />
                    <span className="sr-only">Tanggal event</span>
                    <input type="date" value={tanggal} onChange={(e) => setTanggal(e.target.value)} className="min-w-0 w-full bg-transparent text-sm text-slate-700 focus:outline-none" />
                  </label>
                  {tanggal && <button type="button" onClick={() => setTanggal('')} aria-label="Hapus filter tanggal" className="rounded-[var(--radius-control)] p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"><X size={17} /></button>}
                </div>
              )}
            </div>
          </div>
        </div>
      </section>

      <section className="container-page relative z-10 -mt-8 pb-2 sm:-mt-10">
        <div className="grid gap-4 sm:grid-cols-3">
          {[
            { icon: Ticket, title: 'Pilihan Lengkap', desc: 'Ribuan tiket dari berbagai jenis event.' },
            { icon: Wallet, title: 'Bayar Mudah', desc: 'QRIS, transfer bank, dan e-wallet.' },
            { icon: QrCode, title: 'E-Ticket Instan', desc: 'Tiket digital dikirim ke emailmu.' },
          ].map((f) => (
            <div key={f.title} className="card flex items-start gap-3 p-5 transition duration-200 hover:-translate-y-1 hover:shadow-[var(--shadow-card-hover)]">
              <div className="rounded-[var(--radius-control)] bg-brand-100 p-2.5 text-brand-700">
                <f.icon size={20} />
              </div>
              <div>
                <h3 className="font-semibold text-slate-900">{f.title}</h3>
                <p className="text-sm text-slate-500">{f.desc}</p>
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="container-page py-12">
        <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h2 className="text-2xl font-semibold text-slate-900">Event Aktif</h2>
            <p className="text-sm text-slate-500">{loading ? 'Memuat event...' : `${filtered.length} acara sedang dijual`}</p>
          </div>
        </div>

        <div className="category-scroll -mx-5 mb-6 flex snap-x snap-mandatory gap-2 overflow-x-auto px-5 pb-2 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:pb-0">
          {kategoriList.map((k) => (
            <button
              key={k}
              type="button"
              onClick={() => setKategori(k)}
              aria-pressed={kategori === k}
              className={cn(
                'inline-flex h-10 shrink-0 snap-start items-center justify-center rounded-full border px-4 text-sm font-semibold transition duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2',
                kategori === k
                  ? 'border-brand-600 bg-brand-600 text-white shadow-sm ring-2 ring-brand-100'
                  : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700',
              )}
            >
              {k}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {[1, 2, 3].map((i) => (
              <div key={i} className="card h-72 animate-pulse bg-slate-100" />
            ))}
          </div>
        ) : filtered.length === 0 ? (
          <div className="card">
              <EmptyState
              icon={Search}
              title="Acara tidak ditemukan"
              description="Coba kata kunci lain atau ubah kategori pencarianmu."
              action={<Button variant="secondary" onClick={() => { setQ(''); setKategori('Semua'); setLokasi(''); setTanggal('') }}>Reset filter</Button>}
            />
          </div>
        ) : (
          <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {filtered.map((event) => (
              <EventCard key={event.id} event={event} />
            ))}
          </div>
        )}
      </section>

      <section className="container-page pb-16">
        <div className="relative overflow-hidden rounded-[var(--radius-card)] bg-slate-950 text-white shadow-xl">
          <div className="absolute -left-20 top-0 h-72 w-72 rounded-full bg-brand-600/20 blur-3xl" />
          <div className="absolute -right-20 bottom-0 h-72 w-72 rounded-full bg-accent-500/15 blur-3xl" />
          <div className="relative grid items-center gap-12 px-7 py-10 sm:px-12 sm:py-14 lg:grid-cols-[0.9fr_1.1fr] lg:px-16 lg:py-16">
            <div className="max-w-xl">
              <span className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-400">Untuk penyelenggara</span>
              <h2 className="mt-3 text-3xl font-extrabold leading-tight text-white sm:text-4xl">Punya acara? Saatnya tiketmu terjual.</h2>
              <p className="mt-4 text-base leading-relaxed text-slate-300 sm:text-lg">
                Kelola event, penjualan, dan peserta dari satu dashboard. Mulai cepat tanpa biaya tersembunyi.
              </p>

              <div className="mt-7 grid gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
                {[
                  { icon: BadgeDollarSign, title: 'Rp 2.000', label: 'per tiket' },
                  { icon: ScanLine, title: 'Check-in QR', label: 'cepat & aman' },
                  { icon: Landmark, title: 'Pencairan', label: 'transparan' },
                ].map((item) => (
                  <div key={item.title} className="flex items-center gap-3 rounded-xl bg-white/[0.06] p-3 ring-1 ring-white/10">
                    <div className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-accent-400/15 text-accent-400"><item.icon size={18} /></div>
                    <div>
                      <p className="text-sm font-bold text-white">{item.title}</p>
                      <p className="text-xs text-slate-400">{item.label}</p>
                    </div>
                  </div>
                ))}
              </div>

              <div className="mt-8 flex flex-wrap gap-3">
                <Link to="/partner/daftar">
                  <Button size="lg" variant="accent" className="hover:-translate-y-0.5">Mulai Sekarang <ArrowRight size={18} /></Button>
                </Link>
                <Link to="/partner/masuk">
                  <Button size="lg" className="border border-white/15 bg-white/10 text-white hover:bg-white/15">Masuk Dashboard</Button>
                </Link>
              </div>
            </div>

            <div className="relative mx-auto w-full max-w-2xl lg:translate-x-4">
              <div className="rounded-[var(--radius-card)] border border-white/10 bg-slate-900/90 p-3 shadow-xl backdrop-blur sm:p-5">
                <div className="mb-5 flex items-center justify-between">
                  <div>
                    <p className="text-xs text-slate-500">Dashboard event</p>
                    <p className="mt-0.5 text-sm font-bold text-white sm:text-base">Konser Senja Nusantara</p>
                  </div>
                  <span className="rounded-full bg-emerald-400/10 px-2.5 py-1 text-[10px] font-semibold text-emerald-400 ring-1 ring-emerald-400/20">Penjualan aktif</span>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div className="rounded-xl bg-white/[0.05] p-4 ring-1 ring-white/10">
                    <div className="flex items-center gap-2 text-xs text-slate-400"><TrendingUp size={14} className="text-emerald-400" /> Total penjualan</div>
                    <p className="mt-2 text-xl font-bold text-white sm:text-2xl">Rp 48,6 jt</p>
                    <p className="mt-1 text-[11px] font-medium text-emerald-400">+18,4% minggu ini</p>
                  </div>
                  <div className="rounded-xl bg-white/[0.05] p-4 ring-1 ring-white/10">
                    <div className="flex items-center gap-2 text-xs text-slate-400"><Users size={14} className="text-accent-400" /> Tiket terjual</div>
                    <p className="mt-2 text-xl font-bold text-white sm:text-2xl">1.248</p>
                    <p className="mt-1 text-[11px] text-slate-500">dari 1.500 tiket</p>
                  </div>
                </div>

                <div className="mt-3 rounded-xl bg-white/[0.05] p-4 ring-1 ring-white/10">
                  <div className="mb-4 flex items-center justify-between">
                    <p className="text-xs font-semibold text-slate-300">Penjualan 7 hari terakhir</p>
                    <p className="text-[10px] text-slate-500">Tiket terjual</p>
                  </div>
                  <div className="flex h-24 items-end gap-2 sm:gap-3">
                    {[38, 55, 46, 72, 61, 82, 100].map((height, index) => (
                      <div key={index} className="flex h-full flex-1 items-end rounded-t-sm bg-white/[0.04]">
                        <div className="w-full rounded-t-sm bg-gradient-to-t from-brand-600 to-accent-400" style={{ height: `${height}%` }} />
                      </div>
                    ))}
                  </div>
                </div>
              </div>

              <div className="relative -mt-8 ml-auto mr-3 flex w-[78%] items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3 text-slate-900 shadow-2xl sm:mr-6 sm:w-72">
                <div className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600"><QrCode size={27} /></div>
                <div className="min-w-0">
                  <p className="text-[10px] font-semibold uppercase tracking-wider text-brand-600">E-ticket siap</p>
                  <p className="truncate text-sm font-bold">General Admission</p>
                  <p className="text-[11px] text-slate-500">NTX-24A8-X91C</p>
                </div>
                <Ticket size={18} className="ml-auto shrink-0 text-slate-300" />
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  )
}
