<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $events = Event::query()
            ->active()
            ->with(['organizer:id,nama_penyelenggara,logo_url', 'sessions', 'ticketTypes.sessions'])
            ->when($request->query('q'), fn ($q, $v) => $q->where('nama_event', 'like', "%{$v}%"))
            ->when($request->query('kategori'), fn ($q, $v) => $q->where('kategori', $v))
            ->when($request->query('lokasi'), fn ($q, $v) => $q->where('lokasi', 'like', "%{$v}%"))
            ->orderBy('tanggal_mulai')
            ->get();

        return EventResource::collection($events);
    }

    public function show(string $slug)
    {
        $event = Event::query()
            ->active()
            ->where('slug', $slug)
            ->with(['organizer', 'sessions', 'ticketTypes.sessions', 'seatPlan', 'formFields'])
            ->firstOrFail();

        return new EventResource($event);
    }
}
