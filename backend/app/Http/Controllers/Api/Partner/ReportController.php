<?php

namespace App\Http\Controllers\Api\Partner;

use App\Services\ReportService;
use App\Services\KeuanganPdfService;
use Illuminate\Http\Request;

class ReportController extends PartnerController
{
    public function analytics(Request $request, ReportService $report)
    {
        $id = $this->organizerId($request);

        return response()->json([
            'summary' => $report->partnerSummary($id),
            'trend' => $report->partnerSalesTrend($id, (int) $request->query('days', 7)),
            'events' => $report->partnerEvents($id),
        ]);
    }

    public function buyers(Request $request, ReportService $report)
    {
        return response()->json(['data' => $report->partnerBuyers($this->organizerId($request))]);
    }

    public function finance(Request $request, ReportService $report)
    {
        return response()->json($report->partnerFinance($this->organizerId($request)));
    }

    /**
     * Unduh laporan keuangan sebagai PDF.
     *
     * Sengaja endpoint terpisah (bukan parameter ?format=pdf di /finance):
     * responsnya berkas biner, bukan JSON, jadi kontrak API-nya berbeda.
     */
    public function financePdf(Request $request, KeuanganPdfService $pdf)
    {
        [$berkas, $namaFile] = $pdf->buat($this->organizerId($request));

        return response($berkas->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$namaFile.'"',
        ]);
    }

    public function sales(Request $request, ReportService $report)
    {
        return response()->json($report->partnerSales($this->organizerId($request)));
    }

    public function payouts(Request $request, ReportService $report)
    {
        return response()->json($report->partnerPayouts($this->organizerId($request)));
    }
}
