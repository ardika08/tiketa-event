<?php

namespace App\Http\Controllers\Api\Partner;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayoutController extends PartnerController
{
    public function index(Request $request, ReportService $report)
    {
        return response()->json($report->partnerPayouts($this->organizerId($request)));
    }

    public function store(Request $request, ReportService $report)
    {
        $organizer = $this->organizer($request);

        $data = $request->validate([
            'jumlah' => ['required', 'numeric', 'min:1'],
            'catatan' => ['nullable', 'string', 'max:500'],
        ]);

        $summary = $report->partnerPayouts($organizer->id)['ringkasan'];
        $jumlah = (float) $data['jumlah'];

        if ($jumlah > $summary['saldo_tersedia']) {
            throw ValidationException::withMessages(['jumlah' => 'Jumlah melebihi saldo tersedia.']);
        }

        if ($jumlah < $summary['min_payout']) {
            throw ValidationException::withMessages([
                'jumlah' => 'Minimal pencairan Rp '.number_format($summary['min_payout'], 0, ',', '.').'.',
            ]);
        }

        if (empty($organizer->nama_bank) || empty($organizer->nomor_rekening) || empty($organizer->nama_rekening)) {
            throw ValidationException::withMessages([
                'rekening' => 'Lengkapi data rekening di menu Profil sebelum mengajukan pencairan.',
            ]);
        }

        $payout = Payout::create([
            'organizer_id' => $organizer->id,
            'jumlah' => $jumlah,
            'status' => PayoutStatus::DIAJUKAN,
            'nama_bank' => $organizer->nama_bank,
            'nomor_rekening' => $organizer->nomor_rekening,
            'nama_rekening' => $organizer->nama_rekening,
            'catatan' => $data['catatan'] ?? null,
        ]);

        return response()->json(['data' => $payout], 201);
    }

    public function destroy(Request $request, Payout $payout)
    {
        abort_if($payout->organizer_id !== $this->organizerId($request), 403, 'Pencairan ini bukan milikmu.');

        if ($payout->status !== PayoutStatus::DIAJUKAN) {
            throw ValidationException::withMessages(['status' => 'Pengajuan yang sudah diproses tidak bisa dibatalkan.']);
        }

        $payout->update(['status' => PayoutStatus::DITOLAK, 'catatan_admin' => 'Dibatalkan oleh partner.']);

        return response()->json(['message' => 'Pengajuan pencairan dibatalkan.']);
    }
}