<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Event;
use App\Models\Voucher;
use Illuminate\Http\Request;

class VoucherController extends PartnerController
{
    public function index(Request $request)
    {
        $vouchers = Voucher::where('organizer_id', $this->organizerId($request))
            ->with('event:id,nama_event')
            ->latest()
            ->get();

        return response()->json(['data' => $vouchers]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['organizer_id'] = $this->organizerId($request);
        $data['kode'] = strtoupper($data['kode']);

        $voucher = Voucher::create($data);

        return response()->json(['data' => $voucher], 201);
    }

    public function update(Request $request, Voucher $voucher)
    {
        $this->authorizeVoucher($request, $voucher);
        $voucher->update($this->validated($request, partial: true));

        return response()->json(['data' => $voucher->fresh()]);
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

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'kode' => [$required, 'string', 'max:60'],
            'event_id' => ['nullable', 'exists:events,id'],
            'tipe_diskon' => [$required, 'in:nominal,persen'],
            'nilai' => [$required, 'numeric', 'min:0'],
            'kuota' => ['nullable', 'integer', 'min:0'],
            'berlaku_mulai' => ['nullable', 'date'],
            'berlaku_sampai' => ['nullable', 'date', 'after_or_equal:berlaku_mulai'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ]);

        if (! empty($data['event_id'])) {
            $owned = Event::where('organizer_id', $this->organizerId($request))->whereKey($data['event_id'])->exists();
            abort_if(! $owned, 403, 'Event tidak valid untuk voucher ini.');
        }

        return $data;
    }
}
