<?php

namespace App\Support;

use App\Models\TicketType;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jejak (ledger) setiap mutasi kuota tiket.
 *
 * `ticket_types.sisa_kuota` adalah ANGKA TURUNAN: nilainya seharusnya selalu
 * sama dengan `kuota` dikurangi tiket yang masih dipegang order (lunas +
 * pending yang belum lewat batas bayar). Setiap perubahan angka ini wajib
 * punya jejak — tanpa jejak, kebocoran kuota di masa lalu tidak bisa
 * ditelusuri. Ini pernah terjadi: 3 kursi nyangkut dari order kadaluarsa
 * 2026-10-06 dan penyebabnya sampai sekarang tidak bisa dipastikan karena
 * tidak ada catatan mutasi.
 *
 * Tulisan masuk ke channel log `stock` (storage/logs/stock.log, rotasi harian).
 * Pencatatan TIDAK BOLEH menggagalkan penjualan tiket: semua kegagalan
 * ditelan dan hanya dicatat sebagai warning di log utama.
 */
class StockLedger
{
    /** Kuota dipesan pembeli (order baru dibuat). */
    public const RESERVE = 'reservasi_order';

    /** Kuota kembali karena order dibatalkan / kadaluarsa. */
    public const RELEASE = 'pembatalan_order';

    /** Kategori tiket baru dibuat. */
    public const CREATED = 'buat_kategori';

    /** Kuota kategori diubah partner (sisa_kuota ikut dihitung ulang). */
    public const QUOTA_EDIT = 'ubah_kuota';

    /** Koreksi oleh nontix:reconcile-stock. */
    public const RECONCILE = 'rekonsiliasi';

    /**
     * @param  int  $delta  Perubahan kuota tersisa (negatif = berkurang).
     * @param  string  $reason  Salah satu konstanta di kelas ini.
     * @param  string|null  $ref  Kode order / keterangan asal perubahan.
     */
    public static function record(
        TicketType $ticketType,
        int $delta,
        string $reason,
        ?string $ref = null,
    ): void {
        try {
            Log::channel('stock')->info('mutasi kuota', [
                'ticket_type_id' => $ticketType->id,
                'kategori' => $ticketType->nama_tiket,
                'event_id' => $ticketType->event_id,
                'delta' => $delta,
                'sisa_kuota' => $ticketType->sisa_kuota,
                'kuota' => $ticketType->kuota,
                'alasan' => $reason,
                'ref' => $ref,
            ]);
        } catch (Throwable $e) {
            Log::warning('[Stock] gagal mencatat mutasi kuota', [
                'ticket_type_id' => $ticketType->id,
                'pesan' => $e->getMessage(),
            ]);
        }
    }
}
