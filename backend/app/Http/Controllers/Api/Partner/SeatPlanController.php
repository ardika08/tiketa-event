<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Event;
use App\Models\SeatPlan;
use Illuminate\Http\Request;

class SeatPlanController extends PartnerController
{
    public function show(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);

        return response()->json(['data' => $event->seatPlan]);
    }

    public function upsert(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);

        $data = $request->validate([
            'nama_denah' => ['nullable', 'string', 'max:150'],
            'layout_url' => ['nullable', 'string', 'max:500'],
            'gambar_url' => ['nullable', 'string', 'max:500'],
            'rows' => ['nullable', 'array'],
        ]);

        $seatPlan = SeatPlan::updateOrCreate(
            ['event_id' => $event->id],
            $data,
        );

        return response()->json(['data' => $seatPlan], 200);
    }
}
