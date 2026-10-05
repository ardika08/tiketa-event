<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organizer;
use Illuminate\Http\Request;

abstract class PartnerController extends Controller
{
    protected function organizer(Request $request): Organizer
    {
        $organizer = Organizer::find($request->user()->organizer_id);

        abort_if(! $organizer, 403, 'Akun partner tidak terhubung ke penyelenggara.');

        return $organizer;
    }

    protected function organizerId(Request $request): int
    {
        return $this->organizer($request)->id;
    }

    protected function authorizeEvent(Request $request, Event $event): void
    {
        abort_if($event->organizer_id !== $this->organizerId($request), 403, 'Event ini bukan milikmu.');
    }
}
