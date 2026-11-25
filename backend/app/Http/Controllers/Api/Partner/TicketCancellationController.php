<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\TicketCancellationNotification;
use App\Services\TicketCancellationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TicketCancellationController extends Controller
{
    public function index(Request $request, Event $event, TicketCancellationService $service): JsonResponse
    {
        $this->authorizeEvent($request, $event);
        $data = $request->validate(['query' => ['nullable', 'string', 'max:120']]);

        return response()->json($service->search($event, $data['query'] ?? null));
    }

    public function store(Request $request, Event $event, TicketCancellationService $service): JsonResponse
    {
        $this->authorizeEvent($request, $event);
        $data = $request->validate([
            'orders' => ['required', 'array', 'list', 'max:25'],
            'orders.*' => ['required', 'array:order_id,ticket_ids'],
            'orders.*.order_id' => ['required', 'integer', 'min:1', 'distinct'],
            'orders.*.ticket_ids' => ['required', 'array', 'list', 'max:100'],
            'orders.*.ticket_ids.*' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'request_id' => ['required', 'string', 'max:100'],
        ]);
        $ticketCount = 0;
        foreach ($data['orders'] as $order) {
            $ids = array_map('intval', $order['ticket_ids']);
            $ticketCount += count($ids);
            if (count(array_unique($ids)) !== count($ids) || $ticketCount > 250) {
                throw ValidationException::withMessages(['orders' => 'Pilih paling banyak 250 tiket tanpa duplikat dalam satu pesanan.']);
            }
        }

        $results = [];
        foreach ($data['orders'] as $selection) {
            try {
                $results[] = $service->cancel($event, $request->user()->id, $selection, $data['reason'], $data['internal_note'] ?? null, $data['request_id']);
            } catch (QueryException $error) {
                // Do not log SQL bindings: these can contain buyer data and internal notes.
                Log::error('Ticket cancellation transaction failed.', ['order_id' => $selection['order_id'], 'sqlstate' => $error->getCode()]);
                $results[] = [
                    'order_id' => (int) $selection['order_id'],
                    'kode_order' => null,
                    'status' => 'failed',
                    'message' => 'Hasil belum dapat dipastikan. Ulangi dengan ID permintaan yang sama.',
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    public function retry(Request $request, Event $event, int $notification): JsonResponse
    {
        $this->authorizeEvent($request, $event);
        $item = TicketCancellationNotification::query()
            ->whereHas('cancellation.order', fn ($orders) => $orders->where('event_id', $event->id))
            ->findOrFail($notification);
        TicketCancellationNotification::whereKey($item->id)->where('status', 'failed')->update(['status' => 'pending']);
        $status = $item->refresh()->status;
        abort_if($status === 'uncertain', 409, 'Status pengiriman belum pasti. Periksa dengan penyedia email sebelum mengirim ulang.');

        return response()->json(['id' => $item->id, 'status' => $status === 'sending' ? 'pending' : $status]);
    }

    private function authorizeEvent(Request $request, Event $event): void
    {
        $user = $request->user();
        abort_unless($user->isPartner() && $user->isActive() && $user->organizer_id !== null && $user->organizer_id === $event->organizer_id, 403);
    }
}
