export function cn(...classes) {
  return classes.filter(Boolean).join(' ')
}

export function formatRupiah(value, withPrefix = true) {
  const n = Number(value || 0)
  const formatted = new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 0,
  }).format(Math.round(n))
  return withPrefix ? `Rp ${formatted}` : formatted
}

const BULAN = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]
const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']

export function formatTanggal(value, { withDay = false, withTime = false } = {}) {
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '-'
  const hari = withDay ? `${HARI[d.getDay()]}, ` : ''
  const jam = withTime
    ? ` · ${String(d.getHours()).padStart(2, '0')}.${String(d.getMinutes()).padStart(2, '0')}`
    : ''
  return `${hari}${d.getDate()} ${BULAN[d.getMonth()]} ${d.getFullYear()}${jam}`
}

export function formatTanggalShort(value) {
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '-'
  return `${d.getDate()} ${BULAN[d.getMonth()].slice(0, 3)} ${d.getFullYear()}`
}

export function daysUntil(value) {
  const now = new Date()
  now.setHours(0, 0, 0, 0)
  const target = new Date(value)
  target.setHours(0, 0, 0, 0)
  return Math.round((target - now) / 86400000)
}

export function countdownParts(target) {
  const diff = new Date(target).getTime() - Date.now()
  const clamped = Math.max(diff, 0)
  return {
    total: clamped,
    jam: Math.floor(clamped / 3600000),
    menit: Math.floor((clamped % 3600000) / 60000),
    detik: Math.floor((clamped % 60000) / 1000),
  }
}

export function pad(n) {
  return String(n).padStart(2, '0')
}

export function generateOrderCode() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  let out = ''
  for (let i = 0; i < 6; i += 1) out += chars[Math.floor(Math.random() * chars.length)]
  return `NTX-${out}`
}

export function generateTicketCode() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  const block = () =>
    Array.from({ length: 4 }, () => chars[Math.floor(Math.random() * chars.length)]).join('')
  return `${block()}-${block()}-${block()}`
}

export function slugify(text) {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .trim()
    .replace(/\s+/g, '-')
}
