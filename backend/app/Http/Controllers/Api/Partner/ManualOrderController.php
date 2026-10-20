<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Event;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

/**
 * Penjualan tiket manual oleh partner: OTS, tunai, atau transfer langsung.
 *
 * Kenapa ada: sebagian penjualan terjadi di luar website (pintu masuk, DM,
 * transfer pribadi). Tanpa jalur ini, tiketnya tidak masuk data pembeli, tidak
 * memotong kuota, dan tidak bisa di-scan di gerbang — pembeli harus dicatat di
 * kertas terpisah.
 *
 * Yang TIDAK dilakukan endpoint ini: memverifikasi uang. Tidak ada gateway di
 * jalur ini, jadi sistem hanya mencatat (metode + siapa yang menginput). Itu
 * memang batasnya, dan alasannya dicatat di komentar migrasi orders.sumber.
 */
class ManualOrderController extends PartnerController
{
    public function store(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'nama' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'whatsapp' => ['required', 'string', 'max:30'],
            'instagram' => ['nullable', 'string', 'max:100'],
            'tiktok' => ['nullable', 'string', 'max:100'],
            'threads' => ['nullable', 'string', 'max:100'],
            'metode_bayar' => ['required', 'in:tunai,transfer,bayar_di_lokasi'],
            'catatan' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ticket_type_id' => ['required', 'integer'],
            'items.*.jumlah' => ['required', 'integer', 'min:1', 'max:50'],
        ], [
            'nama.required' => 'Nama pembeli wajib diisi.',
            'email.required' => 'Email wajib diisi — e-ticket dan QR dikirim ke sini.',
            'email.email' => 'Format email tidak valid.',
            'whatsapp.required' => 'Nomor WhatsApp wajib diisi.',
            'metode_bayar.required' => 'Pilih metode pembayaran.',
            'metode_bayar.in' => 'Metode pembayaran tidak dikenal.',
            'items.required' => 'Pilih minimal satu kategori tiket.',
            'items.*.jumlah.min' => 'Jumlah tiket minimal 1.',
            'items.*.jumlah.max' => 'Maksimal 50 tiket per kategori dalam satu pesanan.',
        ]);

        // Event wajib milik partner yang login (404, bukan 403, agar tidak bocor).
        $event = Event::where('organizer_id', $this->organizerId($request))->find($data['event_id']);
        abort_if(! $event, 404, 'Event tidak ditemukan.');

        $order = $orders->createManual(
            $event,
            [
                'nama' => $data['nama'],
                'email' => $data['email'],
                'whatsapp' => $data['whatsapp'],
                'instagram' => $data['instagram'] ?? null,
                'tiktok' => $data['tiktok'] ?? null,
                'threads' => $data['threads'] ?? null,
            ],
            $data['items'],
            $data['metode_bayar'],
            $data['catatan'] ?? null,
            $request->user()->id,
        );

        return response()->json([
            'message' => 'Tiket dibuat. E-ticket dikirim ke '.$order->email.'.',
            'data' => [
                'id' => $order->id,
                'kode_order' => $order->kode_order,
                'nama' => $order->nama_pembeli,
                'email' => $order->email,
                'total_harga' => (float) $order->total_harga,
                'biaya_layanan' => (float) $order->biaya_layanan,
                'sumber' => $order->sumber,
                'metode_bayar' => $order->metode_bayar,
                'jumlah_tiket' => (int) $order->tickets()->count(),
            ],
        ], 201);
    }
}
