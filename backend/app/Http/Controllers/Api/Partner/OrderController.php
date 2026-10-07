<?php

namespace App\Http\Controllers\Api\Partner;

use App\Jobs\SendResendTicketEmail;
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
}
