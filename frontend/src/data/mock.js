const DAY = 86400000

function dateFromNow(days, hour = 19, minute = 0) {
  const d = new Date()
  d.setHours(hour, minute, 0, 0)
  return new Date(d.getTime() + days * DAY).toISOString()
}

export const KATEGORI = [
  'Konser',
  'Olahraga',
  'Seminar',
  'Pameran',
  'Komunitas',
  'Lomba',
  'Wisata',
]

export const BIAYA_LAYANAN = 2000

export const PAYMENT_METHODS = [
  { id: 'qris', nama: 'QRIS', kategori: 'E-Wallet & QRIS', logo: 'QR', warna: 'from-slate-700 to-slate-900' },
  { id: 'bca', nama: 'BCA Virtual Account', kategori: 'Transfer Bank', logo: 'BCA', warna: 'from-blue-600 to-blue-800' },
  { id: 'mandiri', nama: 'Mandiri Virtual Account', kategori: 'Transfer Bank', logo: 'MDR', warna: 'from-yellow-500 to-amber-700' },
  { id: 'bni', nama: 'BNI Virtual Account', kategori: 'Transfer Bank', logo: 'BNI', warna: 'from-orange-500 to-orange-700' },
  { id: 'gopay', nama: 'GoPay', kategori: 'E-Wallet', logo: 'GP', warna: 'from-emerald-500 to-teal-700' },
  { id: 'ovo', nama: 'OVO', kategori: 'E-Wallet', logo: 'OVO', warna: 'from-violet-500 to-purple-700' },
  { id: 'dana', nama: 'DANA', kategori: 'E-Wallet', logo: 'DANA', warna: 'from-sky-500 to-blue-700' },
  { id: 'shopeepay', nama: 'ShopeePay', kategori: 'E-Wallet', logo: 'SPay', warna: 'from-orange-500 to-rose-600' },
]

export const ORGANIZERS = [
  {
    id: 1,
    nama_penyelenggara: 'Suara Nusantara Live',
    email: 'partner@nontix.id',
    telepon: '0812-3456-7890',
    logo_url: '',
    deskripsi: 'Rumah produksi konser musik nasional dengan pengalaman 10 tahun menghadirkan pertunjukan berkualitas.',
    status_verifikasi: 'terverifikasi',
    status_akun: 'aktif',
    jumlah_event: 4,
    bergabung: dateFromNow(-420),
  },
  {
    id: 2,
    nama_penyelenggara: 'Arena Sports ID',
    email: 'arena@nontix.id',
    telepon: '0813-2222-1111',
    logo_url: '',
    deskripsi: 'Penyelenggara turnamen olahraga dan maraton untuk komunitas dan profesional.',
    status_verifikasi: 'terverifikasi',
    status_akun: 'aktif',
    jumlah_event: 2,
    bergabung: dateFromNow(-260),
  },
  {
    id: 3,
    nama_penyelenggara: 'Kampus Kreatif',
    email: 'hello@kampuskreatif.id',
    telepon: '0857-9876-5432',
    logo_url: '',
    deskripsi: 'Komunitas edukasi yang menyelenggarakan seminar, workshop, dan kelas kreatif.',
    status_verifikasi: 'pending',
    status_akun: 'aktif',
    jumlah_event: 1,
    bergabung: dateFromNow(-45),
  },
  {
    id: 4,
    nama_penyelenggara: 'Pesona Wisata Bali',
    email: 'info@pesonabali.id',
    telepon: '0878-1111-2222',
    logo_url: '',
    deskripsi: 'Penyedia paket wisata dan festival budaya di Bali.',
    status_verifikasi: 'ditolak',
    status_akun: 'nonaktif',
    jumlah_event: 0,
    bergabung: dateFromNow(-90),
  },
]

function tickets(list) {
  return list.map((t, i) => {
    const session_ids = t.session_ids ?? (t.session_id != null ? [t.session_id] : [])
    return {
      id: t.id ?? i + 1,
      max_per_order: t.max_per_order ?? 4,
      gambar_url: t.gambar_url ?? `https://picsum.photos/seed/nontix-tiket-${t.id ?? i + 1}/640/420`,
      ...t,
      session_ids,
      session_id: session_ids[0] ?? null,
    }
  })
}

export const EVENTS = [
  {
    id: 1,
    slug: 'konser-senja-nusantara',
    nama_event: 'Konser Senja Nusantara 2026',
    kategori: 'Konser',
    organizer_id: 1,
    organizer: 'Suara Nusantara Live',
    deskripsi:
      'Malam penuh musik dengan deretan musisi nasional. Nikmati perpaduan orkestra dan aransemen modern dalam satu panggung megah di bawah langit senja Jakarta.',
    hero_image_url: 'https://picsum.photos/seed/konser-senja/1200/700',
    tanggal_mulai: dateFromNow(5, 19, 30),
    tanggal_selesai: dateFromNow(6, 23, 0),
    lokasi: 'Istora Senayan, Jakarta',
    sessions: [
      {
        id: 1001,
        label: 'Day 1',
        nama_session: 'Malam Pembuka',
        tanggal_mulai: dateFromNow(5, 16, 0),
        tanggal_selesai: dateFromNow(5, 23, 0),
        lokasi: 'Istora Senayan, Jakarta',
        kapasitas: 2920,
        urutan: 1,
        status: 'aktif',
      },
      {
        id: 1002,
        label: 'Day 2',
        nama_session: 'Malam Puncak',
        tanggal_mulai: dateFromNow(6, 16, 0),
        tanggal_selesai: dateFromNow(6, 23, 0),
        lokasi: 'Istora Senayan, Jakarta',
        kapasitas: 2920,
        urutan: 2,
        status: 'aktif',
      },
    ],
    syarat_ketentuan:
      '1. Tiket berlaku untuk satu orang dan tidak dapat dipindahtangankan.\n2. Dilarang membawa senjata tajam, makanan, dan minuman dari luar.\n3. Anak di bawah 12 tahun harus didampingi orang tua.\n4. Pintu dibuka 1 jam sebelum acara dimulai.\n5. E-ticket wajib ditunjukkan saat memasuki venue.',
    status: 'aktif',
    tiket: tickets([
      { id: 101, session_id: 1001, nama_tiket: 'Festival Day 1 (Standing)', harga: 250000, kuota: 2000, sisa_kuota: 620, max_per_order: 6 },
      { id: 102, session_id: 1001, nama_tiket: 'Tribun Day 1 (Seated)', harga: 450000, kuota: 800, sisa_kuota: 145, max_per_order: 4 },
      { id: 103, session_id: 1001, nama_tiket: 'VIP Day 1 (Front Row)', harga: 1250000, kuota: 120, sisa_kuota: 0, max_per_order: 2 },
      { id: 104, session_id: 1002, nama_tiket: 'Festival Day 2 (Standing)', harga: 300000, kuota: 2000, sisa_kuota: 880, max_per_order: 6 },
      { id: 105, session_id: 1002, nama_tiket: 'Tribun Day 2 (Seated)', harga: 500000, kuota: 800, sisa_kuota: 320, max_per_order: 4 },
      { id: 106, session_id: 1002, nama_tiket: 'VIP Day 2 (Front Row)', harga: 1350000, kuota: 120, sisa_kuota: 36, max_per_order: 2 },
      { id: 107, session_ids: [1001, 1002], nama_tiket: '2-Day Pass', harga: 400000, kuota: 500, sisa_kuota: 264, max_per_order: 4, bundle: true },
      { id: 108, session_ids: [1001, 1002], nama_tiket: 'Full Festival Pass', harga: 1500000, kuota: 150, sisa_kuota: 42, max_per_order: 2, bundle: true },
    ]),
  },
  {
    id: 2,
    slug: 'maraton-nontix-run',
    nama_event: 'Nontix Run 10K & Half Marathon',
    kategori: 'Olahraga',
    organizer_id: 2,
    organizer: 'Arena Sports ID',
    deskripsi:
      'Lari bersama ribuan peserta di rute tepi pantai. Tersedia kategori 10K dan 21K dengan race pack lengkap, medali finisher, dan hiburan di garis akhir.',
    hero_image_url: 'https://picsum.photos/seed/maraton-run/1200/700',
    tanggal_mulai: dateFromNow(14, 5, 0),
    tanggal_selesai: dateFromNow(14, 11, 0),
    lokasi: 'Pantai Indah Ancol, Jakarta',
    syarat_ketentuan:
      '1. Peserta wajib berusia minimal 15 tahun pada hari perlombaan.\n2. Wajib mengenakan nomor BIB yang diberikan.\n3. Peserta disarankan datang 45 menit sebelum flag off.\n4. Membawa botol air pribadi dianjurkan.',
    status: 'aktif',
    tiket: tickets([
      { id: 201, nama_tiket: '10K Run', harga: 175000, kuota: 3000, sisa_kuota: 1180, max_per_order: 5 },
      { id: 202, nama_tiket: '21K Half Marathon', harga: 325000, kuota: 1500, sisa_kuota: 410, max_per_order: 3 },
    ]),
  },
  {
    id: 3,
    slug: 'seminar-digital-marketing',
    nama_event: 'Seminar Digital Marketing & AI 2026',
    kategori: 'Seminar',
    organizer_id: 3,
    organizer: 'Kampus Kreatif',
    deskripsi:
      'Belajar strategi pemasaran digital terbaru bersama praktisi. Topik mencakup AI untuk konten, iklan berbayar, dan pertumbuhan komunitas.',
    hero_image_url: 'https://picsum.photos/seed/seminar-digital/1200/700',
    tanggal_mulai: dateFromNow(21, 9, 0),
    tanggal_selesai: dateFromNow(21, 16, 0),
    lokasi: 'Auditorium Universitas Merdeka, Bandung',
    syarat_ketentuan:
      '1. Tiket termasuk materi seminar dan sertifikat.\n2. Tidak ada pengembalian dana setelah pembelian.\n3. Peserta wajib melakukan registrasi ulang di meja penerimaan.',
    status: 'aktif',
    tiket: tickets([
      { id: 301, nama_tiket: 'Early Bird', harga: 99000, kuota: 200, sisa_kuota: 0, max_per_order: 2 },
      { id: 302, nama_tiket: 'Reguler', harga: 175000, kuota: 500, sisa_kuota: 233, max_per_order: 4 },
      { id: 303, nama_tiket: 'Grup (5 orang)', harga: 700000, kuota: 60, sisa_kuota: 18, max_per_order: 1 },
    ]),
  },
  {
    id: 4,
    slug: 'festival-kopi-archipelago',
    nama_event: 'Festival Kopi Archipelago',
    kategori: 'Pameran',
    organizer_id: 1,
    organizer: 'Suara Nusantara Live',
    deskripsi:
      'Jelajahi puluhan booth kopi nusantara, kelas manual brew, dan talkshow bersama barista juara. Surga bagi pecinta kopi.',
    hero_image_url: 'https://picsum.photos/seed/festival-kopi/1200/700',
    tanggal_mulai: dateFromNow(40, 10, 0),
    tanggal_selesai: dateFromNow(42, 21, 0),
    lokasi: 'Grand City Convention Hall, Surabaya',
    syarat_ketentuan:
      '1. Tiket berlaku untuk satu kali kunjungan pada tanggal terpilih.\n2. Anak di bawah 5 tahun gratis.\n3. Dilarang membawa hewan peliharaan ke dalam area.',
    status: 'aktif',
    tiket: tickets([
      { id: 401, nama_tiket: 'Harian (Weekday)', harga: 50000, kuota: 1500, sisa_kuota: 1240, max_per_order: 8 },
      { id: 402, nama_tiket: 'Harian (Weekend)', harga: 75000, kuota: 2000, sisa_kuota: 1890, max_per_order: 8 },
      { id: 403, nama_tiket: 'Pass 3 Hari', harga: 150000, kuota: 500, sisa_kuota: 322, max_per_order: 4 },
    ]),
  },
  {
    id: 5,
    slug: 'workshop-fotografi-jalanan',
    nama_event: 'Workshop Fotografi Jalanan',
    kategori: 'Komunitas',
    organizer_id: 3,
    organizer: 'Kampus Kreatif',
    deskripsi:
      'Belajar teknik street photography, komposisi, dan editing. Termasuk sesi hunting foto keliling kota bersama mentor.',
    hero_image_url: 'https://picsum.photos/seed/workshop-foto/1200/700',
    tanggal_mulai: dateFromNow(9, 8, 0),
    tanggal_selesai: dateFromNow(9, 15, 0),
    lokasi: 'Kota Tua, Jakarta',
    syarat_ketentuan:
      '1. Peserta membawa kamera sendiri (DSLR/mirrorless/HP).\n2. Kuota terbatas demi kenyamanan sesi.\n3. Sesi berjalan meski hujan ringan.',
    status: 'aktif',
    tiket: tickets([
      { id: 501, nama_tiket: 'Peserta Umum', harga: 150000, kuota: 40, sisa_kuota: 7, max_per_order: 2 },
    ]),
  },
  {
    id: 6,
    slug: 'lomba-lari-5k-keluarga',
    nama_event: 'Fun Run 5K Keluarga',
    kategori: 'Lomba',
    organizer_id: 2,
    organizer: 'Arena Sports ID',
    deskripsi:
      'Lomba lari santai untuk seluruh keluarga dengan banyak hadiah dan bazar makanan di garis akhir.',
    hero_image_url: 'https://picsum.photos/seed/funrun-5k/1200/700',
    tanggal_mulai: dateFromNow(30, 6, 30),
    tanggal_selesai: dateFromNow(30, 10, 0),
    lokasi: 'Alun-Alun Kota, Yogyakarta',
    syarat_ketentuan:
      '1. Terbuka untuk semua usia.\n2. Anak di bawah 10 tahun gratis namun tetap wajib registrasi.\n3. Wajib mengenakan jersey yang disediakan.',
    status: 'aktif',
    tiket: tickets([
      { id: 601, nama_tiket: 'Dewasa', harga: 85000, kuota: 1000, sisa_kuota: 640, max_per_order: 6 },
      { id: 602, nama_tiket: 'Anak', harga: 50000, kuota: 500, sisa_kuota: 388, max_per_order: 6 },
    ]),
  },
  {
    id: 7,
    slug: 'konser-akustik-purnama',
    nama_event: 'Konser Akustik Purnama',
    kategori: 'Konser',
    organizer_id: 1,
    organizer: 'Suara Nusantara Live',
    deskripsi: 'Pertunjukan akustik intim di rooftop dengan pemandangan kota malam hari.',
    hero_image_url: 'https://picsum.photos/seed/konser-akustik/1200/700',
    tanggal_mulai: dateFromNow(-6, 20, 0),
    tanggal_selesai: dateFromNow(-6, 22, 30),
    lokasi: 'Rooftop Hotel Meridian, Jakarta',
    syarat_ketentuan: '1. Acara telah selesai.\n2. Tiket tidak dapat dikembalikan.',
    status: 'selesai',
    tiket: tickets([
      { id: 701, nama_tiket: 'Reguler', harga: 200000, kuota: 300, sisa_kuota: 0, max_per_order: 4 },
    ]),
  },
  {
    id: 8,
    slug: 'pameran-seni-rupa-muda',
    nama_event: 'Pameran Seni Rupa Muda',
    kategori: 'Pameran',
    organizer_id: 3,
    organizer: 'Kampus Kreatif',
    deskripsi: 'Pameran karya seniman muda dari berbagai kota. Draf acara yang belum diterbitkan.',
    hero_image_url: 'https://picsum.photos/seed/pameran-seni/1200/700',
    tanggal_mulai: dateFromNow(55, 10, 0),
    tanggal_selesai: dateFromNow(58, 20, 0),
    lokasi: 'Galeri Nasional, Jakarta',
    syarat_ketentuan: '1. Dilarang menyentuh karya seni.\n2. Diperbolehkan memotret tanpa flash.',
    status: 'draft',
    tiket: tickets([
      { id: 801, nama_tiket: 'Harian', harga: 35000, kuota: 800, sisa_kuota: 800, max_per_order: 6 },
    ]),
  },
]

export function ticketSessionIds(ticket) {
  if (!ticket) return []
  if (Array.isArray(ticket.session_ids)) return ticket.session_ids
  return ticket.session_id != null ? [ticket.session_id] : []
}

export function isBundleTicket(ticket) {
  return ticketSessionIds(ticket).length > 1
}

export function ticketCoversSession(ticket, sessionId) {
  return ticketSessionIds(ticket).includes(sessionId)
}

export function sessionsForTicket(event, ticket) {
  const ids = ticketSessionIds(ticket)
  return [...(event?.sessions || [])]
    .sort((a, b) => a.urutan - b.urutan)
    .filter((session) => ids.includes(session.id))
}

export function ticketSessionsLabel(event, ticket) {
  const sessions = [...(event?.sessions || [])].sort((a, b) => a.urutan - b.urutan)
  const covered = sessionsForTicket(event, ticket)
  if (!sessions.length || !covered.length) return null
  if (covered.length === 1) return covered[0].label
  if (covered.length === sessions.length) return 'Semua hari'
  return covered.map((session) => session.label).join(' + ')
}

export function groupItemsBySession(event, items) {
  const sessions = [...(event?.sessions || [])].sort((a, b) => a.urutan - b.urutan)
  if (!sessions.length) return [{ session: null, items }]

  const bundles = items.filter((item) => isBundleTicket(item))
  const singles = items.filter((item) => !isBundleTicket(item))

  const groups = sessions
    .map((session) => ({
      session,
      items: singles.filter((item) => ticketCoversSession(item, session.id)),
    }))
    .filter((group) => group.items.length > 0)

  const assigned = new Set(groups.flatMap((group) => group.items.map((item) => item.id)))
  const unassigned = singles.filter((item) => !assigned.has(item.id))
  if (unassigned.length) groups.push({ session: null, items: unassigned })
  if (bundles.length) groups.push({ session: null, bundle: true, items: bundles })
  return groups.length ? groups : [{ session: null, items }]
}

export const VOUCHERS = [
  { id: 1, kode: 'NONTIX50', tipe_diskon: 'nominal', nilai: 50000, kuota: 100, terpakai: 42, berlaku_sampai: dateFromNow(60), status: 'aktif', event_id: null },
  { id: 2, kode: 'HEMAT10', tipe_diskon: 'persen', nilai: 10, kuota: 500, terpakai: 210, berlaku_sampai: dateFromNow(30), status: 'aktif', event_id: 1 },
  { id: 3, kode: 'MERDEKA25', tipe_diskon: 'persen', nilai: 25, kuota: 50, terpakai: 50, berlaku_sampai: dateFromNow(-2), status: 'nonaktif', event_id: null },
]

export const CUSTOM_FORM_FIELDS = [
  { id: 1, label: 'Instagram', tipe: 'sosial', wajib: false, urutan: 1 },
  { id: 2, label: 'TikTok', tipe: 'sosial', wajib: false, urutan: 2 },
  { id: 3, label: 'Threads', tipe: 'sosial', wajib: false, urutan: 3 },
]

export const GATES = [
  { id: 1, nama_gate: 'Gate A - Utama', status: 'aktif' },
  { id: 2, nama_gate: 'Gate B - VIP', status: 'aktif' },
  { id: 3, nama_gate: 'Gate C - Belakang', status: 'nonaktif' },
]

export const STAFFS = [
  { id: 1, nama: 'Rizky Pratama', email: 'rizky@nontix.id', peran: 'Koordinator Gate', gate_id: 1, gate: 'Gate A - Utama' },
  { id: 2, nama: 'Dewi Anggraini', email: 'dewi@nontix.id', peran: 'Scanner', gate_id: 1, gate: 'Gate A - Utama' },
  { id: 3, nama: 'Bagus Setiawan', email: 'bagus@nontix.id', peran: 'Scanner', gate_id: 2, gate: 'Gate B - VIP' },
]

export const SEAT_PLAN = {
  id: 1,
  nama_denah: 'Istora - Layout Utama',
  layout_url: '',
  gambar_url: 'https://picsum.photos/seed/nontix-denah/1200/640',
  rows: [
    { baris: 'A', kategori: 'VIP', seats: Array.from({ length: 8 }, (_, i) => ({ kode: `A${i + 1}`, status: i % 5 === 0 ? 'terisi' : 'tersedia' })) },
    { baris: 'B', kategori: 'Tribun', seats: Array.from({ length: 12 }, (_, i) => ({ kode: `B${i + 1}`, status: i % 4 === 0 ? 'terisi' : 'tersedia' })) },
    { baris: 'C', kategori: 'Tribun', seats: Array.from({ length: 12 }, (_, i) => ({ kode: `C${i + 1}`, status: i % 7 === 0 ? 'terisi' : 'tersedia' })) },
  ],
}

export const BUYERS = [
  { id: 1, kode_order: 'NTX-8F2K9A', nama: 'Andini Putri', email: 'andini@mail.com', whatsapp: '0812-1111-2222', event_id: 1, event: 'Konser Senja Nusantara 2026', tiket: 'Tribun (Seated)', jumlah: 2, total: 900000, status: 'lunas', tanggal: dateFromNow(-3) },
  { id: 2, kode_order: 'NTX-3M7P1Q', nama: 'Bimo Saputra', email: 'bimo@mail.com', whatsapp: '0813-3333-4444', event_id: 1, event: 'Konser Senja Nusantara 2026', tiket: 'Festival (Standing)', jumlah: 4, total: 1000000, status: 'lunas', tanggal: dateFromNow(-2) },
  { id: 3, kode_order: 'NTX-9X2C5V', nama: 'Citra Lestari', email: 'citra@mail.com', whatsapp: '0857-5555-6666', event_id: 2, event: 'Nontix Run 10K & Half Marathon', tiket: '10K Run', jumlah: 1, total: 175000, status: 'lunas', tanggal: dateFromNow(-1) },
  { id: 4, kode_order: 'NTX-4B8N6R', nama: 'Dimas Aditya', email: 'dimas@mail.com', whatsapp: '0878-7777-8888', event_id: 3, event: 'Seminar Digital Marketing & AI 2026', tiket: 'Reguler', jumlah: 2, total: 350000, status: 'pending', tanggal: dateFromNow(0) },
  { id: 5, kode_order: 'NTX-7K1L3T', nama: 'Eka Wijaya', email: 'eka@mail.com', whatsapp: '0812-9999-0000', event_id: 4, event: 'Festival Kopi Archipelago', tiket: 'Pass 3 Hari', jumlah: 2, total: 300000, status: 'lunas', tanggal: dateFromNow(-5) },
  { id: 6, kode_order: 'NTX-2H6J8W', nama: 'Fajar Nugroho', email: 'fajar@mail.com', whatsapp: '0813-1212-3434', event_id: 2, event: 'Nontix Run 10K & Half Marathon', tiket: '21K Half Marathon', jumlah: 1, total: 325000, status: 'lunas', tanggal: dateFromNow(-4) },
  { id: 7, kode_order: 'NTX-5D9G2Y', nama: 'Gita Permata', email: 'gita@mail.com', whatsapp: '0857-5656-7878', event_id: 5, event: 'Workshop Fotografi Jalanan', tiket: 'Peserta Umum', jumlah: 1, total: 150000, status: 'kadaluarsa', tanggal: dateFromNow(-7) },
  { id: 8, kode_order: 'NTX-6T4E1U', nama: 'Hendra Gunawan', email: 'hendra@mail.com', whatsapp: '0878-9090-1234', event_id: 6, event: 'Fun Run 5K Keluarga', tiket: 'Dewasa', jumlah: 3, total: 255000, status: 'lunas', tanggal: dateFromNow(-2) },
  { id: 9, kode_order: 'NTX-1Q7S3Z', nama: 'Indah Sari', email: 'indah@mail.com', whatsapp: '0812-4545-6767', event_id: 1, event: 'Konser Senja Nusantara 2026', tiket: 'Festival (Standing)', jumlah: 2, total: 500000, status: 'lunas', tanggal: dateFromNow(-1) },
  { id: 10, kode_order: 'NTX-8V2B4M', nama: 'Joko Susilo', email: 'joko@mail.com', whatsapp: '0813-7878-9090', event_id: 4, event: 'Festival Kopi Archipelago', tiket: 'Harian (Weekend)', jumlah: 4, total: 300000, status: 'lunas', tanggal: dateFromNow(-6) },
  { id: 11, kode_order: 'NTX-3R9N5K', nama: 'Kartika Dewi', email: 'kartika@mail.com', whatsapp: '0857-1212-4545', event_id: 2, event: 'Nontix Run 10K & Half Marathon', tiket: '10K Run', jumlah: 2, total: 350000, status: 'lunas', tanggal: dateFromNow(-3) },
  { id: 12, kode_order: 'NTX-7Y1F6P', nama: 'Lukman Hakim', email: 'lukman@mail.com', whatsapp: '0878-3434-6767', event_id: 6, event: 'Fun Run 5K Keluarga', tiket: 'Anak', jumlah: 2, total: 100000, status: 'dibatalkan', tanggal: dateFromNow(-8) },
]

export const ATTENDANCE_SESSIONS = [
  { id: 1001, label: 'Day 1', nama_session: 'Malam Pembuka', total_tiket: 1500 },
  { id: 1002, label: 'Day 2', nama_session: 'Malam Puncak', total_tiket: 1200 },
]

export const CHECKINS = [
  { id: 1, kode_tiket: 'A7K2-9PLM-3XQ8', nama: 'Andini Putri', event: 'Konser Senja Nusantara 2026', session_id: 1001, gate: 'Gate A - Utama', waktu: dateFromNow(-1, 19, 12), status: 'berhasil' },
  { id: 2, kode_tiket: 'B3N8-2QW5-7RT1', nama: 'Bimo Saputra', event: 'Konser Senja Nusantara 2026', session_id: 1001, gate: 'Gate A - Utama', waktu: dateFromNow(-1, 19, 20), status: 'berhasil' },
  { id: 3, kode_tiket: 'C9M4-6VB2-1KD7', nama: 'Citra Lestari', event: 'Konser Senja Nusantara 2026', session_id: 1001, gate: 'Gate B - VIP', waktu: dateFromNow(-1, 19, 45), status: 'berhasil' },
  { id: 4, kode_tiket: 'D2P7-8XJ3-5LW9', nama: 'Eka Wijaya', event: 'Konser Senja Nusantara 2026', session_id: 1001, gate: 'Gate A - Utama', waktu: dateFromNow(-1, 20, 5), status: 'berhasil' },
  { id: 5, kode_tiket: 'E5Q1-4ZC6-9NM2', nama: 'Bimo Saputra', event: 'Konser Senja Nusantara 2026', session_id: 1001, gate: 'Gate A - Utama', waktu: dateFromNow(-1, 20, 10), status: 'gagal', catatan: 'Tiket sudah dipakai' },
  { id: 6, kode_tiket: 'F1H5-3KM8-2PT4', nama: 'Andini Putri', event: 'Konser Senja Nusantara 2026', session_id: 1002, gate: 'Gate A - Utama', waktu: dateFromNow(0, 18, 30), status: 'berhasil' },
  { id: 7, kode_tiket: 'G6N2-9QW4-7RB1', nama: 'Bimo Saputra', event: 'Konser Senja Nusantara 2026', session_id: 1002, gate: 'Gate A - Utama', waktu: dateFromNow(0, 18, 42), status: 'berhasil' },
  { id: 8, kode_tiket: 'H3J7-5LP2-8XC6', nama: 'Citra Lestari', event: 'Konser Senja Nusantara 2026', session_id: 1002, gate: 'Gate B - VIP', waktu: dateFromNow(0, 19, 5), status: 'berhasil' },
]

export const CHECKIN_PASSES = [
  { kode_qr: 'A7K2-9PLM-3XQ8', nama: 'Andini Putri', tiket: 'Tribun Day 1 (Seated)', session_id: 1001, status: 'belum_hadir' },
  { kode_qr: 'B3N8-2QW5-7RT1', nama: 'Bimo Saputra', tiket: 'Festival Day 1 (Standing)', session_id: 1001, status: 'belum_hadir' },
  { kode_qr: 'C9M4-6VB2-1KD7', nama: 'Citra Lestari', tiket: 'VIP Day 1 (Front Row)', session_id: 1001, status: 'belum_hadir' },
  { kode_qr: 'D2P7-8XJ3-5LW9', nama: 'Eka Wijaya', tiket: '2-Day Pass', session_id: 1001, status: 'hadir' },
  { kode_qr: 'F1H5-3KM8-2PT4', nama: 'Andini Putri', tiket: 'Tribun Day 2 (Seated)', session_id: 1002, status: 'belum_hadir' },
  { kode_qr: 'G6N2-9QW4-7RB1', nama: 'Bimo Saputra', tiket: 'Festival Day 2 (Standing)', session_id: 1002, status: 'belum_hadir' },
  { kode_qr: 'H3J7-5LP2-8XC6', nama: 'Citra Lestari', tiket: 'Full Festival Pass', session_id: 1002, status: 'belum_hadir' },
]

export const SUPPORT_TICKETS = [
  { id: 1, subjek: 'Kesulitan mengunggah logo', pesan: 'Logo saya selalu gagal diunggah dengan pesan format tidak didukung.', status: 'selesai', tanggal: dateFromNow(-10), balasan: 'Format yang didukung PNG/JPG maksimal 2MB. Sudah kami bantu perbarui.' },
  { id: 2, subjek: 'Penarikan dana belum masuk', pesan: 'Pencairan tanggal 1 belum masuk ke rekening.', status: 'diproses', tanggal: dateFromNow(-3), balasan: 'Sedang kami proses oleh tim keuangan, estimasi 1x24 jam.' },
  { id: 3, subjek: 'Cara menambah gate baru', pesan: 'Bagaimana menambahkan gate kedua untuk event saya?', status: 'terbuka', tanggal: dateFromNow(-1), balasan: '' },
]

export const SALES_TREND = [
  { tanggal: '01 Okt', tiket: 120, pendapatan: 28000000 },
  { tanggal: '02 Okt', tiket: 180, pendapatan: 41000000 },
  { tanggal: '03 Okt', tiket: 150, pendapatan: 35000000 },
  { tanggal: '04 Okt', tiket: 260, pendapatan: 62000000 },
  { tanggal: '05 Okt', tiket: 310, pendapatan: 74000000 },
  { tanggal: '06 Okt', tiket: 220, pendapatan: 51000000 },
  { tanggal: '07 Okt', tiket: 340, pendapatan: 81000000 },
]

export function getEventBySlug(slug) {
  return EVENTS.find((e) => e.slug === slug)
}

export function getEventById(id) {
  return EVENTS.find((e) => e.id === Number(id))
}

export function totalSisaKuota(event) {
  return event.tiket.reduce((sum, t) => sum + t.sisa_kuota, 0)
}

export function totalKuota(event) {
  return event.tiket.reduce((sum, t) => sum + t.kuota, 0)
}
