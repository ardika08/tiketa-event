import {
  BarChart3,
  Users,
  CalendarDays,
  Ticket,
  ClipboardList,
  BadgePercent,
  DoorOpen,
  Armchair,
  UserCog,
  Wallet,
  Building2,
  ShieldCheck,
  LifeBuoy,
  QrCode,
  LayoutDashboard,
  TrendingUp,
  FileText,
  Handshake,
  ShoppingBag,
  Banknote,
} from 'lucide-react'

export const partnerNav = [
  { type: 'link', to: '/partner/analisis', label: 'Analisis', icon: BarChart3 },
  { type: 'link', to: '/partner/pembeli', label: 'Data Pembeli', icon: Users },
  { type: 'link', to: '/partner/penjualan', label: 'Penjualan Tiket', icon: ShoppingBag },
  {
    type: 'group',
    label: 'Data Master',
    icon: ClipboardList,
    children: [
      { to: '/partner/master/event', label: 'Event', icon: CalendarDays },
      { to: '/partner/master/tiket', label: 'Tiket', icon: Ticket },
      { to: '/partner/master/formulir', label: 'Formulir Custom', icon: ClipboardList },
      { to: '/partner/master/voucher', label: 'Voucher', icon: BadgePercent },
      { to: '/partner/master/gate', label: 'Gate', icon: DoorOpen },
      { to: '/partner/master/seat-plan', label: 'Seat Plan', icon: Armchair },
      { to: '/partner/master/staff', label: 'Staff', icon: UserCog },
    ],
  },
  { type: 'link', to: '/partner/keuangan', label: 'Keuangan', icon: Wallet },
  {
    type: 'group',
    label: 'Profil & Legalitas',
    icon: Building2,
    children: [
      { to: '/partner/profil', label: 'Profil Penyelenggara', icon: Building2 },
      { to: '/partner/legalitas', label: 'Penanggung Jawab & Legalitas', icon: ShieldCheck },
    ],
  },
  {
    type: 'group',
    label: 'Check-in',
    icon: QrCode,
    children: [
      { to: '/partner/scan', label: 'Scan Tiket', icon: QrCode },
      { to: '/partner/kehadiran', label: 'Riwayat Kehadiran', icon: ClipboardList },
    ],
  },
  { type: 'link', to: '/partner/support', label: 'Support', icon: LifeBuoy },
]

export const adminNav = [
  { type: 'link', to: '/admin/dashboard', label: 'Ringkasan', icon: LayoutDashboard },
  { type: 'link', to: '/admin/event', label: 'Semua Event', icon: CalendarDays },
  { type: 'link', to: '/admin/mitra', label: 'Mitra Penyelenggara', icon: Handshake },
  { type: 'link', to: '/admin/pendapatan', label: 'Pendapatan Platform', icon: TrendingUp },
  { type: 'link', to: '/admin/pencairan', label: 'Pencairan Mitra', icon: Banknote },
  { type: 'link', to: '/admin/laporan', label: 'Laporan & Ekspor', icon: FileText },
]
