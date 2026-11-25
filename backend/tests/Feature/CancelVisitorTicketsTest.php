<?php

namespace Tests\Feature;

use App\Mail\ResendTicketMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketCancellationNotification;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class CancelVisitorTicketsTest extends TestCase
{
    use InteractsWithNontix;

    protected function setUp(): void
    {
        parent::setUp();
        // Use real commits without rolling back unrelated legacy migrations at teardown.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate')->assertSuccessful();
        Queue::fake();
        Http::preventStrayRequests();
        config(['nontix.mailketing.mode' => 'fake']);
    }

    public function test_search_and_bulk_execution_cancel_only_selected_physical_tickets(): void
    {
        [$partner, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $order = $this->issue($event, ['Visitor Gratis' => [0, 2], 'Visitor VIP' => [1000, 1]]);
        [$selected, $other, $paid] = $order->tickets->values();
        $url = "/api/partner/ticket-cancellations/{$event->id}";
        Sanctum::actingAs($partner);

        $preview = $this->getJson($url.'?query='.$order->kode_order)->assertOk()
            ->assertJsonPath('orders.0.tickets.0.eligible', true)
            ->assertJsonPath('orders.0.tickets.0.sesi', ['Day A', 'Day B'])
            ->assertJsonPath('orders.0.tickets.2.eligible', false);
        $this->assertStringNotContainsString($selected->passes[0]->kode_qr, $preview->getContent());
        $this->assertNull($selected->passes[0]->fresh()->cancelled_at);

        $payload = $this->selection($order, [$selected->id]);
        $result = $this->postJson($url, $payload)->assertOk()
            ->assertJsonPath('results.0.status', 'cancelled')->json();
        $this->postJson($url, $payload)->assertOk()->assertExactJson($result);
        $this->postJson($url, array_replace($payload, ['reason' => 'changed']))->assertOk()
            ->assertJsonPath('results.0.status', 'skipped');
        $this->assertDatabaseCount('ticket_cancellation_notifications', 1);
        $this->assertSame(2, $selected->passes()->whereNotNull('cancelled_at')->count());
        $this->assertSame(0, $other->passes()->whereNotNull('cancelled_at')->count());
        $this->assertSame(0, $paid->passes()->whereNotNull('cancelled_at')->count());
        $this->assertTrue($order->fresh()->isPaid());
        $this->assertDatabaseHas('ticket_cancellations', ['actor_id' => $partner->id, 'public_reason' => 'Perubahan acara', 'internal_note' => 'Jangan tampilkan ini']);

        foreach (['partner', 'staff'] as $role) {
            $this->postJson("/api/{$role}/scan", ['event_id' => $event->id, 'kode_qr' => $selected->passes[0]->kode_qr])
                ->assertOk()->assertJsonPath('status', 'cancelled');
        }
        $this->postJson('/api/partner/scan', ['event_id' => $event->id, 'kode_qr' => $other->passes[0]->kode_qr])
            ->assertOk()->assertJsonPath('status', 'valid');
    }

    public function test_order_scope_and_revalidation_allow_partial_batch_and_stable_replay(): void
    {
        [$partner, $organizer] = $this->createPartner();
        [, $otherOrganizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $otherEvent = $this->createEvent($organizer);
        $foreignEvent = $this->createEvent($otherOrganizer);
        $blocked = $this->issue($event, ['Visitor' => [0, 2]]);
        $eligible = $this->issue($event, ['Visitor Free' => [0, 1]]);
        $foreign = $this->issue($otherEvent, ['Visitor' => [0, 1]]);
        $url = "/api/partner/ticket-cancellations/{$event->id}";
        $payload = $this->selection($blocked, $blocked->tickets->modelKeys());
        $payload['orders'][] = ['order_id' => $eligible->id, 'ticket_ids' => $eligible->tickets->modelKeys()];
        $payload['orders'][] = ['order_id' => $foreign->id, 'ticket_ids' => $foreign->tickets->modelKeys()];

        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs($this->createAdmin());
        $this->postJson($url, $payload)->assertForbidden();
        Sanctum::actingAs($partner);
        $this->getJson("/api/partner/ticket-cancellations/{$foreignEvent->id}")->assertForbidden();
        $this->getJson($url.'?query='.$blocked->kode_order)->assertJsonPath('orders.0.tickets.0.eligible', true);
        $this->postJson('/api/staff/scan', ['event_id' => $event->id, 'kode_qr' => $blocked->tickets[0]->passes[0]->kode_qr])
            ->assertJsonPath('status', 'valid');

        $response = $this->postJson($url, $payload)->assertOk()
            ->assertJsonPath('results.0.status', 'skipped')
            ->assertJsonPath('results.1.status', 'cancelled')
            ->assertJsonPath('results.2.kode_order', null)
            ->assertJsonPath('results.2.status', 'skipped');
        $this->postJson($url, $payload)->assertExactJson($response->json());
        $this->assertNull($blocked->tickets[1]->passes[0]->fresh()->cancelled_at);
        $this->assertNull($foreign->tickets[0]->passes[0]->fresh()->cancelled_at);
        $this->assertDatabaseCount('ticket_cancellation_notifications', 1);
        $this->postJson($url, array_replace($payload, ['request_id' => 'different', 'orders' => [
            ['order_id' => $blocked->id, 'ticket_ids' => [$foreign->tickets[0]->id, $blocked->tickets[1]->id]],
        ]]))->assertJsonPath('results.0.status', 'skipped');
        $this->assertNull($blocked->tickets[1]->passes[0]->fresh()->cancelled_at);
    }

    public function test_outbox_rollback_and_retry_send_only_the_public_notice_once(): void
    {
        [$partner, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $order = $this->issue($event, ['Visitor Gratis' => [0, 1], 'VIP Unaffected' => [1000, 1]]);
        $url = "/api/partner/ticket-cancellations/{$event->id}";
        $payload = $this->selection($order, [$order->tickets[0]->id]);
        Sanctum::actingAs($partner);

        // Force the result of the outbox insert to fail after pass update inside the order transaction.
        DB::unprepared("CREATE TRIGGER reject_notice BEFORE INSERT ON ticket_cancellation_notifications BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('results.0.status', 'failed');
        $this->assertDatabaseCount('ticket_cancellations', 0);
        $this->assertNull($order->tickets[0]->passes[0]->fresh()->cancelled_at);
        DB::unprepared('DROP TRIGGER reject_notice');

        $this->postJson($url, $payload)->assertJsonPath('results.0.status', 'cancelled');
        $notice = TicketCancellationNotification::firstOrFail();
        $this->getJson($url)->assertJsonPath('notifications.0.status', 'pending');
        $this->artisan('nontix:send-ticket-cancellation-notifications')->assertSuccessful();
        $this->assertSame('failed', $notice->fresh()->status); // fake mode must not mark sent
        $this->getJson($url.'?query='.$order->kode_order)->assertJsonPath('notifications.0.id', $notice->id)
            ->assertJsonPath('notifications.0.status', 'failed');
        $this->getJson($url.'?query=no-match')->assertJsonCount(0, 'notifications');
        Http::assertNothingSent();

        $otherEvent = $this->createEvent($organizer);
        $this->postJson("/api/partner/ticket-cancellations/{$otherEvent->id}/retry/{$notice->id}")->assertNotFound();
        config(['nontix.mailketing.mode' => 'live', 'nontix.mailketing.base_url' => 'https://mail.example.test', 'nontix.mailketing.api_token' => 'test-transport-token']);
        Http::fake(['mail.example.test/*' => Http::sequence()->push(['success' => false], 422)->push(['success' => true])]);
        $retry = "$url/retry/{$notice->id}";
        $this->postJson($retry)->assertJsonPath('status', 'pending');
        $this->postJson($retry)->assertJsonPath('status', 'pending');
        $this->artisan('nontix:send-ticket-cancellation-notifications')->assertSuccessful();
        $this->assertSame('failed', $notice->fresh()->status);
        $this->postJson($retry)->assertJsonPath('status', 'pending');
        $this->artisan('nontix:send-ticket-cancellation-notifications')->assertSuccessful();
        $this->postJson($retry)->assertJsonPath('status', 'sent');
        $this->artisan('nontix:send-ticket-cancellation-notifications')->assertSuccessful();
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($order->email, $request['recipient']);
            $this->assertSame('nontix-cancellation-'.$notice->message_id, $request['message_id']);
            foreach ([$event->nama_event, 'Visitor Gratis', 'Day A', 'Day B', 'Perubahan acara'] as $expected) {
                $this->assertStringContainsString($expected, $request['content']);
            }
            foreach (['Jangan tampilkan ini', 'VIP Unaffected', $order->tickets[0]->passes[0]->kode_qr] as $excluded) {
                $this->assertStringNotContainsString($excluded, $request['content']);
            }
        }
    }

    public function test_public_lookup_and_resend_exclude_revoked_qrs(): void
    {
        [$partner, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $mixed = $this->issue($event, ['Visitor' => [0, 1], 'VIP' => [1000, 1]]);
        $all = $this->issue($event, ['Visitor Free' => [0, 1]]);
        Sanctum::actingAs($partner);
        $url = "/api/partner/ticket-cancellations/{$event->id}";
        foreach ([$mixed, $all] as $order) {
            $this->postJson($url, $this->selection($order, [$order->tickets[0]->id]))->assertJsonPath('results.0.status', 'cancelled');
        }

        $reloaded = $mixed->fresh(app(OrderService::class)->relations());
        $html = (new ResendTicketMail($reloaded))->render();
        $this->assertStringNotContainsString($mixed->tickets[0]->passes[0]->kode_qr, $html);
        $this->assertStringContainsString($mixed->tickets[1]->passes[0]->kode_qr, $html);
        $this->getJson('/api/tickets?kode_order='.$mixed->kode_order)->assertOk()
            ->assertJsonCount(1, 'data.tickets')->assertJsonPath('data.tickets.0.id', $mixed->tickets[1]->id);
        $this->postJson('/api/tickets/resend', ['kode_order' => $all->kode_order])->assertUnprocessable();
        $this->postJson("/api/partner/orders/{$all->id}/resend")->assertUnprocessable();

        $mixed->forceFill(['paid_at' => now()->subMinute()])->save();
        $this->getJson('/api/tickets?email='.$mixed->email)->assertOk()
            ->assertJsonPath('data.kode_order', $mixed->kode_order);
        $this->travel(2)->minutes(); // Allow the public resend cooldown to expire.
        $this->postJson('/api/tickets/resend', ['email' => $mixed->email])->assertOk();
        Queue::assertPushed(\App\Jobs\SendResendTicketEmail::class, fn ($job) => $job->orderId === $mixed->id);
    }

    public function test_ambiguous_delivery_and_stale_claim_never_resend_blindly(): void
    {
        [$partner, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $order = $this->issue($event, ['Visitor' => [0, 2]]);
        $url = "/api/partner/ticket-cancellations/{$event->id}";
        Sanctum::actingAs($partner);
        foreach ($order->tickets as $ticket) {
            $payload = $this->selection($order, [$ticket->id]);
            $payload['request_id'] .= '-'.$ticket->id;
            $this->postJson($url, $payload)->assertJsonPath('results.0.status', 'cancelled');
        }
        [$interrupted, $pending] = TicketCancellationNotification::orderBy('id')->get();
        $interrupted->forceFill(['status' => 'sending', 'attempts' => 1, 'updated_at' => now()->subMinutes(6)])->save();

        config(['nontix.mailketing.mode' => 'live', 'nontix.mailketing.base_url' => 'https://mail.example.test', 'nontix.mailketing.api_token' => 'test-transport-token']);
        Http::fake(['mail.example.test/*' => Http::response(['success' => false], 503)]);
        $this->artisan('nontix:send-ticket-cancellation-notifications')->assertSuccessful();
        foreach ([$interrupted, $pending] as $notice) {
            $this->assertSame('uncertain', $notice->fresh()->status);
            $this->postJson("$url/retry/{$notice->id}")->assertConflict();
        }
        $this->artisan('nontix:send-ticket-cancellation-notifications')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_eligibility_uses_price_at_purchase_and_requires_visitor_token(): void
    {
        [$partner, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $order = $this->issue($event, ['Visitor' => [1000, 1], 'VisitorPlus' => [0, 1], 'Free Visitor' => [0, 1]]);
        [$paid, $other, $visitor] = $order->tickets->values();
        $paid->ticketType()->update(['harga' => 0]);
        $visitor->ticketType()->update(['harga' => 1000]);
        $url = "/api/partner/ticket-cancellations/{$event->id}";
        Sanctum::actingAs($partner);
        $this->getJson($url.'?query='.$order->email)->assertOk()
            ->assertJsonPath('orders.0.tickets.0.eligible', false)
            ->assertJsonPath('orders.0.tickets.1.eligible', false)
            ->assertJsonPath('orders.0.tickets.2.eligible', true);
        $this->postJson($url, $this->selection($order, [$paid->id, $other->id, $visitor->id]))
            ->assertJsonPath('results.0.status', 'skipped');
        $this->assertNull($visitor->passes[0]->fresh()->cancelled_at);
    }

    private function issue(Event $event, array $types): Order
    {
        $sessions = $event->sessions()->pluck('id')->all() ?: collect(['Day A', 'Day B'])->map(
            fn ($label) => $event->sessions()->create(['label' => $label, 'nama_session' => $label, 'kapasitas' => 100])->id
        )->all();
        $items = [];
        foreach ($types as $name => [$price, $quantity]) {
            $type = $this->createTicketType($event, ['nama_tiket' => $name, 'harga' => $price], $sessions);
            $items[] = ['ticket_type_id' => $type->id, 'jumlah' => $quantity];
        }

        $service = app(OrderService::class);
        $order = $service->create($event, ['nama' => 'Pembeli Test', 'email' => 'buyer@example.test', 'whatsapp' => '08123456789'], $items);
        if (! $order->isPaid()) {
            $order = $service->markPaid($order);
        }

        return $order->load('tickets.passes');
    }

    private function selection(Order $order, array $tickets): array
    {
        return [
            'orders' => [['order_id' => $order->id, 'ticket_ids' => $tickets]],
            'reason' => 'Perubahan acara',
            'internal_note' => 'Jangan tampilkan ini',
            'request_id' => 'test-request-1',
        ];
    }
}
