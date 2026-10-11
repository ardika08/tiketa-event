<?php

namespace App\Http\Controllers\Api\Partner;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VoucherController extends PartnerController
{
    public function index(Request $request)
    {
        $vouchers = Voucher::where('organizer_id', $this->organizerId($request))
            ->with('event:id,nama_event')
            ->latest()
            ->get();

        $this->attachPemakaian($vouchers);

        return response()->json(['data' => $vouchers]);
    }

    /**
     * Tempelkan ringkasan pemakaian voucher (khusus pesanan lunas) ke tiap voucher.
     *
     * Hanya 3 query agregat untuk seluruh daftar, jadi tidak ada N+1 walau
     * voucher-nya banyak. Read-only: tidak mengubah kuota, diskon, atau order.
     */
    private function attachPemakaian(Collection $vouchers): void
    {
        if ($vouchers->isEmpty()) {
            return;
        }

        $ids = $vouchers->pluck('id')->all();

        // Pesanan lunas: jumlah pesanan, pembeli unik (per email), total diskon.
        $lunas = Order::query()
            ->selectRaw('voucher_id, COUNT(*) as pesanan, COUNT(DISTINCT LOWER(email)) as pembeli, SUM(diskon) as total_diskon')
            ->whereIn('voucher_id', $ids)
            ->where('status', OrderStatus::PAID)
            ->groupBy('voucher_id')
            ->get()
            ->keyBy('voucher_id');

        // Jumlah unit tiket dari pesanan lunas (butuh join ke order_items).
        $tiket = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw('orders.voucher_id as voucher_id, SUM(order_items.jumlah) as tiket')
            ->whereIn('orders.voucher_id', $ids)
            ->where('orders.status', OrderStatus::PAID)
            ->groupBy('orders.voucher_id')
            ->get()
            ->keyBy('voucher_id');

        // Pesanan yang masih menunggu bayar dan belum lewat masa tenggang.
        $grace = max(0, (int) config('nontix.expiry_grace_minutes'));

        $menunggu = Order::query()
            ->selectRaw('voucher_id, COUNT(*) as pesanan')
            ->whereIn('voucher_id', $ids)
            ->where('status', OrderStatus::PENDING)
            ->where('batas_bayar', '>', now()->subMinutes($grace))
            ->groupBy('voucher_id')
            ->get()
            ->keyBy('voucher_id');

        $ambil = function (Collection $rows, int $id, string $kolom) {
            $row = $rows->get($id);

            return $row ? $row->{$kolom} : 0;
        };

        foreach ($vouchers as $voucher) {
            $voucher->setAttribute('pemakaian', [
                'pembeli' => (int) $ambil($lunas, $voucher->id, 'pembeli'),
                'pesanan_lunas' => (int) $ambil($lunas, $voucher->id, 'pesanan'),
                'tiket' => (int) $ambil($tiket, $voucher->id, 'tiket'),
                'total_diskon' => (float) $ambil($lunas, $voucher->id, 'total_diskon'),
                'menunggu_bayar' => (int) $ambil($menunggu, $voucher->id, 'pesanan'),
            ]);
        }
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['organizer_id'] = $this->organizerId($request);
        $data['kode'] = strtoupper(trim($data['kode']));

        $voucher = Voucher::create($data);

        return response()->json(['data' => $voucher->load('event:id,nama_event')], 201);
    }

    public function update(Request $request, Voucher $voucher)
    {
        $this->authorizeVoucher($request, $voucher);
        $data = $this->validated($request, partial: true, voucher: $voucher);
        if (isset($data['kode'])) {
            $data['kode'] = strtoupper(trim($data['kode']));
        }

        $voucher->update($data);

        return response()->json(['data' => $voucher->fresh()->load('event:id,nama_event')]);
    }

    public function destroy(Request $request, Voucher $voucher)
    {
        $this->authorizeVoucher($request, $voucher);
        $voucher->delete();

        return response()->json(['message' => 'Voucher dihapus.']);
    }

    private function authorizeVoucher(Request $request, Voucher $voucher): void
    {
        abort_if($voucher->organizer_id !== $this->organizerId($request), 403, 'Voucher ini bukan milikmu.');
    }

    private function validated(Request $request, bool $partial = false, ?Voucher $voucher = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $uniqueRule = Rule::unique('vouchers', 'kode');
        if ($voucher) {
            $uniqueRule->ignore($voucher->id);
        }

        $data = $request->validate([
            'kode' => [$required, 'string', 'max:60', $uniqueRule],
            'event_id' => ['nullable', 'exists:events,id'],
            'tipe_diskon' => [$required, 'in:nominal,persen'],
            'nilai' => [$required, 'numeric', 'min:0'],
            'min_pembelian' => ['nullable', 'numeric', 'min:0'],
            'kuota' => ['nullable', 'integer', 'min:0'],
            'berlaku_mulai' => ['nullable', 'date'],
            'berlaku_sampai' => ['nullable', 'date', 'after_or_equal:berlaku_mulai'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ]);

        $tipe = $data['tipe_diskon'] ?? ($voucher?->tipe_diskon?->value ?? null);
        $nilai = isset($data['nilai']) ? (float) $data['nilai'] : (float) ($voucher?->nilai ?? 0);
        if ($tipe === 'persen' && $nilai > 100) {
            throw ValidationException::withMessages(['nilai' => 'Diskon persen maksimal 100%.']);
        }

        if (! empty($data['event_id'])) {
            $owned = Event::where('organizer_id', $this->organizerId($request))->whereKey($data['event_id'])->exists();
            abort_if(! $owned, 403, 'Event tidak valid untuk voucher ini.');
        }

        return $data;
    }
}
