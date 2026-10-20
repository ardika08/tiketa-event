<?php

namespace App\Http\Controllers\Api\Partner;

use App\Jobs\SendResendTicketEmail;
use App\Jobs\SendAlternativeTicketEmail;
use App\Models\EmailLog;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends PartnerController
{
    /**
     * Kirim ulang e-ticket sebuah pesanan milik partner ini.
     *
     * Dipakai tombol "Kirim Ulang" di /partner/pembeli. Alasan fitur ini ada:
     * pembeli yang tidak menerima tiket hampir selalu menghubungi
     * penyelenggara (WhatsApp/telepon) lebih dulu, bukan mencari menu
     * "Kirim Ulang Tiket" di situs publik. Tanpa endpoint ini partner harus
     * menyuruh pembeli mengurus sendiri — memindahkan beban ke pihak yang
     * sedang panik.
     *
     * KEAMANAN: e-mail SELALU dikirim ke alamat yang terdaftar pada pesanan
     * ($order->email), bukan ke alamat yang dikirim klien. Jadi partner tidak
     * bisa mengalihkan tiket milik pembeli ke pihak lain, sekalipun ia
     * mengubah payload.
     */
    public function resendTicket(Request $request, Order $order)
    {
        $order->loadMissing('event:id,organizer_id');

        // Pesanan milik partner lain diperlakukan sebagai "tidak ada" (404),
        // bukan 403 — supaya tidak membocorkan keberadaan pesanan itu.
        abort_if(
            ! $order->event || $order->event->organizer_id !== $this->organizerId($request),
            404,
            'Pesanan tidak ditemukan.',
        );

        if (! $order->isPaid()) {
            throw ValidationException::withMessages([
                'order' => 'Hanya pesanan berstatus lunas yang bisa dikirim ulang.',
            ]);
        }

        SendResendTicketEmail::dispatch($order->id);

        return response()->json([
            'message' => 'E-ticket dikirim ulang ke '.$order->email.'.',
        ]);
    }

    /**
     * Riwayat email sebuah pesanan.
     *
     * Dipakai tombol "Riwayat kirim" di /partner/pembeli: saat pembeli bilang
     * "belum menerima email", partner bisa menjawab dengan bukti kapan
     * dikirim, ke alamat apa, dan message_id-nya — tanpa grep log server.
     */
    public function riwayatEmail(Request $request, Order $order)
    {
        $this->pastikanMilikPartner($request, $order);

        $log = EmailLog::where('order_id', $order->id)
            ->with('user:id,nama')
            ->latest('created_at')
            ->limit(30)
            ->get()
            ->map(fn (EmailLog $e) => [
                'id' => $e->id,
                'jenis' => $e->jenis,
                'tujuan' => $e->tujuan,
                'email_pesanan' => $e->email_pesanan,
                'subject' => $e->subject,
                'status' => $e->status,
                'error' => $e->error,
                'message_id' => $e->message_id,
                'oleh' => $e->user?->nama,
                'waktu' => $e->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'email_pesanan' => $order->email,
            'data' => $log,
        ]);
    }

    /**
     * Kirim e-ticket ke alamat LAIN (sekali), untuk kasus email pembeli tidak
     * sampai ke inbox — mis. alamat @outlook.com yang diblokir diam-diam.
     *
     * orders.email TIDAK diubah. Tombol "Kirim Ulang" yang lama tetap berjalan
     * seperti biasa ke email pesanan; endpoint ini hanya menambah jalur darurat
     * yang tercatat di email_logs (jenis manual_alternative, lengkap dengan
     * siapa yang mengirim).
     */
    public function kirimKeEmailAlternatif(Request $request, Order $order)
    {
        $this->pastikanMilikPartner($request, $order);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'konfirmasi' => ['required', 'accepted'],
        ], [
            'email.required' => 'Email tujuan wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'konfirmasi.accepted' => 'Centang dulu bahwa email ini sudah diverifikasi dengan pembeli.',
        ]);

        if (! $order->isPaid()) {
            throw ValidationException::withMessages([
                'order' => 'Hanya pesanan berstatus lunas yang bisa dikirimi tiket.',
            ]);
        }

        $tujuan = strtolower(trim($data['email']));

        // Salah ketik domain adalah penyebab paling umum tiket nyasar. Ditolak
        // dengan saran, bukan dibiarkan — lebih baik partner mengetik ulang.
        if ($saran = $this->saranDomain($tujuan)) {
            throw ValidationException::withMessages([
                'email' => 'Domain email ini sepertinya salah ketik. Mungkin maksud Anda '.$saran.'?',
            ]);
        }

        if ($tujuan === strtolower((string) $order->email)) {
            throw ValidationException::withMessages([
                'email' => 'Alamat ini sama dengan email pesanan. Pakai tombol "Kirim Ulang" biasa.',
            ]);
        }

        // Pagar pemakaian: fitur ini untuk keadaan darurat, bukan alat blast.
        $hariIni = EmailLog::where('order_id', $order->id)
            ->manual()
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($hariIni >= 3) {
            throw ValidationException::withMessages([
                'email' => 'Sudah 3 kali kirim manual untuk pesanan ini hari ini. Tunggu besok atau hubungi admin.',
            ]);
        }

        SendAlternativeTicketEmail::dispatch($order->id, $tujuan, $request->user()->id);

        return response()->json([
            'message' => 'E-ticket dikirim ke '.$tujuan.'. Email pesanan tetap '.$order->email.'.',
            'tujuan' => $tujuan,
            'email_pesanan' => $order->email,
        ]);
    }

    /** 404 (bukan 403) supaya keberadaan pesanan partner lain tidak bocor. */
    private function pastikanMilikPartner(Request $request, Order $order): void
    {
        $order->loadMissing('event:id,organizer_id');

        abort_if(
            ! $order->event || $order->event->organizer_id !== $this->organizerId($request),
            404,
            'Pesanan tidak ditemukan.',
        );
    }

    /**
     * Deteksi salah ketik domain populer. Mengembalikan alamat yang disarankan,
     * atau null kalau domainnya wajar.
     */
    private function saranDomain(string $email): ?string
    {
        if (! str_contains($email, '@')) {
            return null;
        }

        [$user, $domain] = explode('@', $email, 2);

        $populer = [
            'gmail.com', 'yahoo.com', 'yahoo.co.id', 'outlook.com', 'hotmail.com',
            'hotmail.co.id', 'icloud.com', 'rocketmail.com', 'protonmail.com', 'zoho.com',
        ];

        if (in_array($domain, $populer, true)) {
            return null;
        }

        foreach ($populer as $benar) {
            // Salah ketik umum: karakter kurang/lebih/tertukar di domain.
            if (levenshtein($domain, $benar) <= 2) {
                return $user.'@'.$benar;
            }
        }

        return null;
    }
}
