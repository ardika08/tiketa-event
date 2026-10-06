import { useState } from 'react'
import { ChevronDown, LifeBuoy, Mail, MessageCircle } from 'lucide-react'
import { Card } from '../../components/ui'
import { cn } from '../../lib/utils'

const FAQ = [
  {
    q: 'Bagaimana cara membeli tiket?',
    a: 'Pilih event di halaman utama, tentukan jenis dan jumlah tiket, isi data pembeli, lalu lakukan pembayaran. E-ticket akan dikirim ke emailmu secara otomatis.',
  },
  {
    q: 'Metode pembayaran apa yang tersedia?',
    a: 'Pembayaran hanya melalui QRIS. Scan QR yang muncul dengan aplikasi apa pun yang mendukung QRIS — m-banking, GoPay, OVO, DANA, ShopeePay, dan lainnya. Status pesanan otomatis diperbarui begitu pembayaran berhasil, jadi selesaikan sebelum batas waktu yang tertera di halaman pembayaran.',
  },
  {
    q: 'Saya tidak menerima email tiket, apa yang harus dilakukan?',
    a: 'Cek folder spam terlebih dahulu. Jika tetap tidak ada, gunakan menu Kirim Ulang Tiket dengan memasukkan email atau kode pesananmu.',
  },
  {
    q: 'Apakah tiket bisa dipindahtangankan?',
    a: 'Tiket berlaku untuk satu orang sesuai ketentuan masing-masing penyelenggara. Sebagian besar event tidak memperbolehkan pemindahtanganan.',
  },
  {
    q: 'Berapa biaya layanan Nontix?',
    a: 'Nontix hanya memungut biaya layanan Rp 2.000 per tiket terjual, dipotong otomatis dari harga tiket penyelenggara.',
  },
]

export default function Help() {
  const [open, setOpen] = useState(0)

  return (
    <div className="container-page max-w-3xl py-12">
      <div className="text-center">
        <div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-brand-100 text-brand-700">
          <LifeBuoy size={26} />
        </div>
        <h1 className="mt-4 text-3xl font-bold text-slate-900">Pusat Bantuan</h1>
        <p className="mt-2 text-slate-500">Temukan jawaban atas pertanyaan yang sering diajukan.</p>
      </div>

      <div className="mt-8 space-y-3">
        {FAQ.map((item, i) => (
          <Card key={item.q} className="overflow-hidden">
            <button
              onClick={() => setOpen(open === i ? -1 : i)}
              className="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
            >
              <span className="font-semibold text-slate-800">{item.q}</span>
              <ChevronDown size={18} className={cn('shrink-0 text-slate-400 transition', open === i && 'rotate-180')} />
            </button>
            {open === i && <p className="border-t border-slate-100 px-5 py-4 text-sm leading-relaxed text-slate-600">{item.a}</p>}
          </Card>
        ))}
      </div>

      <Card className="mt-8 p-6 text-center">
        <h2 className="font-bold text-slate-900">Masih butuh bantuan?</h2>
        <p className="mt-1 text-sm text-slate-500">Tim kami siap membantu setiap hari kerja, 09.00–18.00 WIB.</p>
        <div className="mt-4 flex flex-wrap justify-center gap-3">
          <a href="mailto:support@nontix.id" className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
            <Mail size={16} /> support@nontix.id
          </a>
          <a href="https://wa.me/6281200000000" className="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <MessageCircle size={16} /> WhatsApp
          </a>
        </div>
      </Card>
    </div>
  )
}
