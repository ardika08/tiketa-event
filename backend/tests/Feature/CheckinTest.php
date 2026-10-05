<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class CheckinTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
    }

    public function test_scan_validates_per_session_and_reports_attendance(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $day1 = $event->sessions()->create(['label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100]);
        $day2 = $event->sessions()->create(['label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 2, 'kapasitas' => 100]);
        $bundle = $this->createTicketType($event, ['nama_tiket' => '2-Day Pass', 'harga' => 180000, 'kuota' => 5, 'sisa_kuota' => 5], [$day1->id, $day2->id]);
        $gate = $organizer->gates()->create(['nama_gate' => 'Gate A', 'status' => 'aktif']);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $bundle->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $order = Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail();
        $ticket = $order->tickets->first();
        $day1Pass = $ticket->passes->firstWhere('event_session_id', $day1->id);
        $day2Pass = $ticket->passes->firstWhere('event_session_id', $day2->id);

        Sanctum::actingAs($user);

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id,
            'kode_qr' => $day1Pass->kode_qr, 'gate_id' => $gate->id,
        ])->assertOk()->assertJsonPath('status', 'valid');

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $day1Pass->kode_qr,
        ])->assertJsonPath('status', 'used');

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $day2Pass->kode_qr,
        ])->assertJsonPath('status', 'wrong_session');

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => 'XXXX-XXXX-XXXX',
        ])->assertJsonPath('status', 'invalid');

        $this->assertDatabaseHas('checkins', ['kode_qr' => $day1Pass->kode_qr, 'status' => 'berhasil']);

        $this->getJson("/api/partner/attendance?event_id={$event->id}")
            ->assertOk()
            ->assertJsonStructure(['sessions', 'checkins']);
    }
}
