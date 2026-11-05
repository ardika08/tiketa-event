import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { Button, Card, EmptyState } from '../../components/ui'
import { publicApi } from '../../lib/api'
import { useApi } from '../../lib/useApi'
import { normalizeEvent } from '../../lib/normalize'

/**
 * Halaman checkout langsung dari link khusus per tiket:
 * /checkout/:eventSlug/:ticketSlug
 *
 * Beda dengan /event/:slug yang menampilkan semua tiket, halaman ini
 * langsung menampilkan SATU tiket. Jumlah awal 1, dan pembeli tetap
 * mengisi data diri lalu checkout — tanpa melihat tiket lain.
 *
 * URL ini juga jalan untuk tiket yang disembunyikan (sembunyi=true):
 * itulah cara tiket undangan/komplemen/OTS dijual manual lewat tautan.
 */
export default function CheckoutTicket() {
  const { eventSlug, ticketSlug } = useParams()
  const navigate = useNavigate()

  const { data: event, loading, error } = useApi(
    () => publicApi.checkoutTicket(eventSlug, ticketSlug),
    [eventSlug, ticketSlug],
  )

  // Saat event + tiket siap (satu tiket dalam `tiket`), alihkan ke halaman
  // checkout biasa dengan keranjang 1 tiket.
  useEffect(() => {
    // request() mengembalikan envelope Laravel { data: ... }, bukan isi langsung.
    const isi = event?.data ?? event
    if (!isi) return
    const normalized = normalizeEvent(isi)
    const ticket = normalized?.tiket?.[0]
    if (!ticket) return

    const items = [{
      id: ticket.id,
      ticketId: ticket.id,
      nama_tiket: ticket.nama_tiket,
      harga: Number(ticket.harga ?? 0),
      jumlah: 1,
      session_ids: ticket.session_ids || [],
    }]

    navigate('/checkout', { state: { items, event: normalized }, replace: true })
  }, [event, navigate])

  if (error) {
    const notFound = error.status === 404
    return (
      <div className="container-page py-20">
        <Card>
          <EmptyState
            icon={ArrowLeft}
            title={notFound ? 'Tiket tidak ditemukan' : 'Gagal memuat tiket'}
            description={notFound
              ? 'Tiket ini sudah tidak tersedia atau link-nya salah.'
              : 'Terjadi kesalahan. Coba buka lagi tautannya.'}
            action={<Link to="/"><Button>Kembali ke Beranda</Button></Link>}
          />
        </Card>
      </div>
    )
  }

  // Skeleton selama memuat; kalau data kosong tanpa error, tampilkan
  // keadaan kosong supaya halaman tidak pernah benar-benar blank.
  if (loading || !event) {
    return (
      <div className="container-page py-20">
        <Card className="animate-pulse">
          <div className="h-6 w-2/3 rounded bg-slate-200" />
          <div className="mt-4 h-20 rounded bg-slate-200" />
        </Card>
      </div>
    )
  }

  return (
    <div className="container-page py-20">
      <Card>
        <EmptyState
          icon={ArrowLeft}
          title="Tiket tidak tersedia"
          description="Tautan ini tidak berisi tiket yang bisa dibeli."
          action={<Link to="/"><Button>Kembali ke Beranda</Button></Link>}
        />
      </Card>
    </div>
  )
}
