<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\PaymentGateway;
use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Models\Organizer;
use App\Models\Payout;
use App\Models\Setting;
use App\Services\MayarService;
use App\Services\PaymentManager;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function dashboard(Request $request, ReportService $report)
    {
        return response()->json([
            'summary' => $report->adminSummary(),
            'trend' => $report->adminRevenueTrend((int) $request->query('days', 7)),
        ]);
    }

    public function partners(ReportService $report)
    {
        return response()->json(['data' => $report->adminPartners()]);
    }

    public function partner(Organizer $organizer, ReportService $report)
    {
        $organizer->load(['user:id,nama,email', 'legal']);

        $events = collect($report->adminEvents())
            ->where('organizer_id', $organizer->id)
            ->values();

        return response()->json([
            'data' => $organizer,
            'events' => $events,
        ]);
    }

    public function updatePartner(Request $request, Organizer $organizer)
    {
        $data = $request->validate([
            'status_verifikasi' => ['nullable', 'in:pending,terverifikasi,ditolak'],
            'status_akun' => ['nullable', 'in:aktif,nonaktif'],
        ]);

        $organizer->update($data);

        return response()->json(['data' => $organizer->fresh()]);
    }

    public function events(ReportService $report)
    {
        return response()->json(['data' => $report->adminEvents()]);
    }

    public function revenue(Request $request, ReportService $report)
    {
        $summary = $report->adminSummary();

        return response()->json([
            'summary' => [
                'pendapatan_platform' => $summary['pendapatan_platform'],
                'transaksi_lunas' => $summary['transaksi_lunas'],
                'tiket_terjual' => $summary['tiket_terjual'],
            ],
            'trend' => $report->adminRevenueTrend((int) $request->query('days', 7)),
            'mitra' => $report->adminPartners(),
        ]);
    }

    public function reports(Request $request, ReportService $report)
    {
        return response()->json([
            'summary' => $report->adminSummary(),
            'trend' => $report->adminRevenueTrend((int) $request->query('days', 30)),
            'events' => $report->adminEvents(),
            'mitra' => $report->adminPartners(),
        ]);
    }

    public function payouts(ReportService $report)
    {
        return response()->json(['data' => $report->adminPayouts()]);
    }

    public function updatePayout(Request $request, Payout $payout)
    {
        $data = $request->validate([
            'status' => ['required', 'in:diproses,selesai,ditolak'],
            'catatan_admin' => ['nullable', 'string', 'max:500'],
            'bukti_transfer_url' => ['nullable', 'string', 'max:500'],
        ]);

        $status = PayoutStatus::from($data['status']);

        $payload = [
            'status' => $status,
            'catatan_admin' => $data['catatan_admin'] ?? $payout->catatan_admin,
        ];

        if ($status === PayoutStatus::DIPROSES) {
            $payload['diproses_at'] = now();
        }

        if ($status === PayoutStatus::SELESAI) {
            $payload['selesai_at'] = now();
            $payload['bukti_transfer_url'] = $data['bukti_transfer_url'] ?? $payout->bukti_transfer_url;
        }

        $payout->update($payload);

        return response()->json(['data' => $payout->fresh('organizer:id,nama_penyelenggara')]);
    }

    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $file = $data['file'];
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'bukti';
        $filename = $name.'-'.Str::random(8).'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('proofs', $filename, 'public');

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ],
        ], 201);
    }

    /**
     * Pengaturan gateway pembayaran (hanya admin yang bisa mengubah).
     */
    public function paymentSettings(PaymentManager $payments)
    {
        $available = collect($payments->availableGateways())->map(fn (PaymentGateway $g) => [
            'id' => $g->name(),
            'label' => $g->name() === 'xendit' ? 'Xendit' : ucfirst($g->name()),
            'available' => true,
        ])->values();

        $all = collect($payments->allGateways())->map(fn (PaymentGateway $g) => [
            'id' => $g->name(),
            'label' => $g->name() === 'xendit' ? 'Xendit' : ucfirst($g->name()),
            'available' => $g->isEnabled(),
        ])->values();

        return response()->json([
            'gateways' => $all,
            'active' => $payments->configuredOrder(),
            'available_count' => $available->count(),
        ]);
    }

    public function updatePaymentSettings(Request $request, PaymentManager $payments)
    {
        $data = $request->validate([
            'gateways' => ['present', 'array'],
            'gateways.*' => ['string', 'in:mayar,xendit'],
        ]);

        $allowed = collect($payments->availableGateways())->map(fn (PaymentGateway $g) => $g->name());

        // Hanya gateway yang kredensialnya tersedia yang boleh diaktifkan.
        $active = array_values(array_filter($data['gateways'], fn ($name) => $allowed->contains($name)));

        if (empty($active)) {
            return response()->json([
                'message' => 'Minimal satu gateway yang tersedia harus diaktifkan.',
                'available' => $allowed->values(),
            ], 422);
        }

        Setting::put('payment_gateways', $active);

        return response()->json([
            'message' => 'Pengaturan pembayaran disimpan.',
            'active' => $active,
        ]);
    }

    public function mayarWebhookInfo(MayarService $mayar)
    {
        return response()->json([
            'url' => $mayar->webhookUrl(),
            'mode' => config('nontix.mayar.mode'),
            'registered' => $mayar->isLive(),
        ]);
    }

    /**
     * Daftarkan URL webhook Mayar secara otomatis (API v2).
     */
    public function registerMayarWebhook(Request $request, MayarService $mayar)
    {
        $data = $request->validate([
            'url' => ['nullable', 'url'],
        ]);

        $url = $data['url'] ?? $mayar->webhookUrl();
        $result = $mayar->registerWebhook($url);

        return response()->json([
            'url' => $url,
            'mayar' => $result,
        ]);
    }
}
