<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pembersihan data lama: sebelum perbaikan, tiket + QR dibuat saat
     * checkout sehingga order yang belum lunas punya tiket. Mulai sekarang
     * tiket hanya dibuat saat pembayaran lunas, jadi tiket milik order
     * non-lunas dihapus. Kuota sudah/tetap dikembalikan oleh mekanisme
     * expire/cancel order. Log check-in tetap tersimpan (ticket_pass_id null).
     */
    public function up(): void
    {
        $orderIds = DB::table('orders')
            ->where('status', '!=', 'lunas')
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return;
        }

        DB::table('tickets')->whereIn('order_id', $orderIds)->delete();
    }

    public function down(): void
    {
        // Tidak ada yang perlu dipulihkan.
    }
};