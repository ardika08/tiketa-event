import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import { OrderProvider } from './context/OrderContext'

import PublicLayout from './layouts/PublicLayout'
import DashboardLayout from './layouts/DashboardLayout'

import Home from './pages/public/Home'
import EventDetail from './pages/public/EventDetail'
import Checkout from './pages/public/Checkout'
import Payment from './pages/public/Payment'
import PaymentSuccess from './pages/public/PaymentSuccess'
import Ticket from './pages/public/Ticket'
import Invoice from './pages/public/Invoice'
import ResendTicket from './pages/public/ResendTicket'
import Help from './pages/public/Help'
import NotFound from './pages/public/NotFound'

import { PartnerLogin, PartnerRegister, AdminLogin } from './pages/auth/AuthPages'

import Analytics from './pages/partner/Analytics'
import Buyers from './pages/partner/Buyers'
import Sales from './pages/partner/Sales'
import MasterEvents from './pages/partner/MasterEvents'
import EventForm from './pages/partner/EventForm'
import MasterTickets from './pages/partner/MasterTickets'
import MasterFormFields from './pages/partner/MasterFormFields'
import MasterVouchers from './pages/partner/MasterVouchers'
import MasterGates from './pages/partner/MasterGates'
import MasterSeatPlan from './pages/partner/MasterSeatPlan'
import MasterStaff from './pages/partner/MasterStaff'
import Finance from './pages/partner/Finance'
import Profile from './pages/partner/Profile'
import Legal from './pages/partner/Legal'
import Support from './pages/partner/Support'
import Scan from './pages/partner/Scan'
import Attendance from './pages/partner/Attendance'

import AdminDashboard from './pages/admin/AdminDashboard'
import AdminEvents from './pages/admin/AdminEvents'
import AdminPartners from './pages/admin/AdminPartners'
import AdminPartnerDetail from './pages/admin/AdminPartnerDetail'
import AdminRevenue from './pages/admin/AdminRevenue'
import AdminReports from './pages/admin/AdminReports'
import AdminPayouts from './pages/admin/AdminPayouts'
import AdminSettings from './pages/admin/AdminSettings'

function ProtectedRoute({ role, children }) {
  const { user } = useAuth()
  if (!user) return <Navigate to={role === 'admin' ? '/admin/masuk' : '/partner/masuk'} replace />
  if (user.peran !== role) return <Navigate to="/" replace />
  return children
}

export default function App() {
  return (
    <AuthProvider>
      <OrderProvider>
        <BrowserRouter>
          <Routes>
            <Route element={<PublicLayout />}>
              <Route path="/" element={<Home />} />
              <Route path="/event/:slug" element={<EventDetail />} />
              <Route path="/checkout" element={<Checkout />} />
              <Route path="/pembayaran" element={<Payment />} />
              <Route path="/pembayaran/berhasil" element={<PaymentSuccess />} />
              <Route path="/tiket/saya" element={<Ticket />} />
              <Route path="/tiket/kirim-ulang" element={<ResendTicket />} />
              <Route path="/invoice" element={<Invoice />} />
              <Route path="/bantuan" element={<Help />} />
            </Route>

            <Route path="/partner/masuk" element={<PartnerLogin />} />
            <Route path="/partner/daftar" element={<PartnerRegister />} />
            <Route path="/admin/masuk" element={<AdminLogin />} />

            <Route
              path="/partner"
              element={
                <ProtectedRoute role="partner">
                  <DashboardLayout role="partner" />
                </ProtectedRoute>
              }
            >
              <Route index element={<Navigate to="/partner/analisis" replace />} />
              <Route path="analisis" element={<Analytics />} />
              <Route path="pembeli" element={<Buyers />} />
              <Route path="penjualan" element={<Sales />} />
              <Route path="master/event" element={<MasterEvents />} />
              <Route path="master/event/:id" element={<EventForm />} />
              <Route path="master/tiket" element={<MasterTickets />} />
              <Route path="master/formulir" element={<MasterFormFields />} />
              <Route path="master/voucher" element={<MasterVouchers />} />
              <Route path="master/gate" element={<MasterGates />} />
              <Route path="master/seat-plan" element={<MasterSeatPlan />} />
              <Route path="master/staff" element={<MasterStaff />} />
              <Route path="keuangan" element={<Finance />} />
              <Route path="profil" element={<Profile />} />
              <Route path="legalitas" element={<Legal />} />
              <Route path="support" element={<Support />} />
              <Route path="scan" element={<Scan />} />
              <Route path="kehadiran" element={<Attendance />} />
            </Route>

            <Route
              path="/admin"
              element={
                <ProtectedRoute role="admin">
                  <DashboardLayout role="admin" />
                </ProtectedRoute>
              }
            >
              <Route index element={<Navigate to="/admin/dashboard" replace />} />
              <Route path="dashboard" element={<AdminDashboard />} />
              <Route path="event" element={<AdminEvents />} />
              <Route path="mitra" element={<AdminPartners />} />
              <Route path="mitra/:id" element={<AdminPartnerDetail />} />
              <Route path="pendapatan" element={<AdminRevenue />} />
              <Route path="pencairan" element={<AdminPayouts />} />
              <Route path="laporan" element={<AdminReports />} />
              <Route path="pengaturan" element={<AdminSettings />} />
            </Route>

            <Route path="*" element={<NotFound />} />
          </Routes>
        </BrowserRouter>
      </OrderProvider>
    </AuthProvider>
  )
}
