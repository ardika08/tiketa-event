<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class ManualCheckinWindowTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    public function test_manual_window_replaces_both_default_checks_only_for_the_qr_pair(): void
    {
        Queue::fake();
        config(['nontix.mayar.mode' => 'fake', 'nontix.mayar.callback_token' => null, 'nontix.buka_lebih_awal_menit' => 30]);
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $day1 = $event->sessions()->create([
            'nama_session' => 'Session', 'label' => 'Day 1', 'urutan' => 1,
            'tanggal_mulai' => '2026-11-24 10:00:00', 'tanggal_selesai' => '2026-11-24 20:00:00',
        ]);
        $day2 = $event->sessions()->create([
            'nama_session' => 'Session', 'label' => 'Day 2', 'urutan' => 2,
            'tanggal_mulai' => '2026-11-25 10:00:00', 'tanggal_selesai' => '2026-11-25 20:00:00',
        ]);
        $bundle = $this->createTicketType($event, [
            'nama_tiket' => 'Bundle', 'jam_masuk_mulai' => '12:00', 'jam_masuk_selesai' => '13:00',
        ], [$day1->id, $day2->id]);
        $regular = $this->createTicketType($event, [], [$day2->id]);

        $code = $this->postJson('/api/orders', [
            'event_id' => $event->id, 'nama' => 'Budi', 'email' => 'budi@mail.com', 'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $bundle->id, 'jumlah' => 2], ['ticket_type_id' => $regular->id, 'jumlah' => 1]],
        ])->assertCreated()->json('data.kode_order');
        $this->getJson("/api/payments/{$code}/fake")->assertOk();
        $tickets = Order::where('kode_order', $code)->with('tickets.passes')->firstOrFail()->tickets;
        $bundlePasses = $tickets->where('ticket_type_id', $bundle->id)->flatMap->passes;
        $day1Pass = $bundlePasses->firstWhere('event_session_id', $day1->id);
        $day2Passes = $bundlePasses->where('event_session_id', $day2->id)->values();
        $regularPass = $tickets->firstWhere('ticket_type_id', $regular->id)->passes->first();

        Sanctum::actingAs($user);
        $url = "/api/partner/scan/sessions/{$event->id}";
        $data = [
            'session_id' => $day2->id, 'ticket_type_id' => $bundle->id,
            'checkin_mulai' => '2026-11-24T09:00', 'checkin_selesai' => '2026-11-24T09:45',
        ];
        $this->putJson("{$url}/jadwal", $data)->assertOk();
        $scan = fn ($pass) => $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $pass->kode_qr,
        ])->assertOk();

        $this->travelTo(Carbon::parse('2026-11-24 08:59:59', 'Asia/Jakarta'));
        $scan($day2Passes[0])->assertJsonPath('status', 'not_open');
        $this->getJson($url)->assertOk()->assertJsonPath('scan_tersedia', false);

        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'Asia/Jakarta'));
        $this->getJson($url)->assertOk()
            ->assertJsonPath('sesi_aktif_id', $day2->id)
            ->assertJsonPath('data.0.scan_tersedia', false)
            ->assertJsonPath('data.1.scan_tersedia', true)
            ->assertJsonPath('scan_tersedia', true)
            ->assertJsonPath('data.1.tanggal_mulai', '2026-11-25T10:00:00+07:00')
            ->assertJsonFragment($data + ['nama_tiket' => 'Bundle'])
            ->assertJsonFragment([
                'session_id' => $day1->id, 'ticket_type_id' => $bundle->id, 'nama_tiket' => 'Bundle',
                'checkin_mulai' => null, 'checkin_selesai' => null,
            ])
            ->assertJsonPath('kategori.0.status', 'dibuka')
            ->assertJsonPath('kategori.0.label_gerbang', '09:00–09:45');

        $scan($day1Pass)->assertJsonPath('status', 'not_open');
        $scan($regularPass)->assertJsonPath('status', 'not_open');
        $this->travelTo(Carbon::parse('2026-11-24 09:45:00', 'Asia/Jakarta'));
        // The dashboard sends Day 1 as its legacy fallback; an open manual
        // schedule must still accept the QR's own Day 2 with no event dates.
        $day2->update(['tanggal_mulai' => null, 'tanggal_selesai' => null]);
        $scan($day2Passes[0])->assertJsonPath('status', 'valid');
        $day2->update(['tanggal_mulai' => '2026-11-25 10:00:00', 'tanggal_selesai' => '2026-11-25 20:00:00']);
        $scan($day2Passes[0])->assertJsonPath('status', 'used');
        $this->assertDatabaseHas('checkins', [
            'kode_qr' => $day2Passes[0]->kode_qr, 'event_session_id' => $day2->id, 'status' => 'berhasil',
        ]);

        $this->travelTo(Carbon::parse('2026-11-24 09:45:01', 'Asia/Jakarta'));
        $scan($day2Passes[1])->assertJsonPath('status', 'closed');
        $this->getJson($url)->assertOk()->assertJsonPath('scan_tersedia', false);

        $this->putJson("{$url}/jadwal", array_replace($data, ['checkin_mulai' => null, 'checkin_selesai' => null]))->assertOk();
        $this->getJson($url)->assertOk()->assertJsonFragment([
            'session_id' => $day2->id, 'ticket_type_id' => $bundle->id, 'nama_tiket' => 'Bundle',
            'checkin_mulai' => null, 'checkin_selesai' => null,
        ]);
        $this->travelTo(Carbon::parse('2026-11-25 11:30:00', 'Asia/Jakarta'));
        $scan($day2Passes[1])->assertJsonPath('status', 'valid');
    }

    public function test_scanner_stays_open_for_issued_qr_after_its_ticket_type_moves_to_another_session(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $day1 = $event->sessions()->create([
            'nama_session' => 'First', 'label' => 'Day 1', 'urutan' => 1,
            'tanggal_mulai' => '2026-11-24 08:00:00', 'tanggal_selesai' => '2026-11-24 10:00:00',
        ]);
        $day2 = $event->sessions()->create([
            'nama_session' => 'Second', 'label' => 'Day 2', 'urutan' => 2,
            'tanggal_mulai' => '2026-11-25 08:00:00', 'tanggal_selesai' => '2026-11-25 10:00:00',
        ]);
        $ticket = $this->createTicketType($event, ['harga' => 0], [$day1->id]);
        $code = $this->postJson('/api/orders', [
            'event_id' => $event->id, 'nama' => 'Budi', 'email' => 'budi@mail.com', 'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $ticket->id, 'jumlah' => 1]],
        ])->assertCreated()->json('data.kode_order');
        $pass = Order::where('kode_order', $code)->firstOrFail()->tickets->first()->passes->first();
        $ticket->sessions()->sync([$day2->id]);
        Sanctum::actingAs($user);
        $url = "/api/partner/scan/sessions/{$event->id}";
        $this->putJson("{$url}/jadwal", [
            'session_id' => $day2->id, 'ticket_type_id' => $ticket->id,
            'checkin_mulai' => '2026-11-25T08:00', 'checkin_selesai' => '2026-11-25T10:00',
        ])->assertOk();

        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'Asia/Jakarta'));
        $this->getJson($url)->assertOk()
            ->assertJsonPath('scan_tersedia', true)
            ->assertJsonPath('data.0.scan_tersedia', true)
            ->assertJsonPath('data.1.scan_tersedia', false);
        $day1->update(['tanggal_mulai' => null, 'tanggal_selesai' => null]);
        $this->putJson("{$url}/jadwal", [
            'session_id' => $day2->id, 'ticket_type_id' => $ticket->id,
            'checkin_mulai' => null, 'checkin_selesai' => null,
        ])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('scan_tersedia', true)
            ->assertJsonPath('data.0.scan_tersedia', true);
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $pass->kode_qr,
        ])->assertOk()->assertJsonPath('status', 'valid');

        $ticket->sessions()->detach();
        $day1->update(['tanggal_mulai' => '2026-11-24 08:00:00', 'tanggal_selesai' => '2026-11-24 10:00:00']);
        $day2->update(['tanggal_mulai' => '2026-11-24 11:00:00', 'tanggal_selesai' => '2026-11-24 12:00:00']);
        $this->travelTo(Carbon::parse('2026-11-24 11:30:00', 'Asia/Jakarta'));
        $this->getJson($url)->assertOk()->assertJsonPath('scan_tersedia', false);
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day2->id, 'kode_qr' => $pass->kode_qr,
        ])->assertOk()->assertJsonPath('status', 'closed');
    }

    public function test_manual_window_requires_both_ordered_local_times_and_an_owned_pair(): void
    {
        [$user, $organizer] = $this->createPartner();
        [, $otherOrganizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $otherEvent = $this->createEvent($otherOrganizer);
        $session = $event->sessions()->create(['nama_session' => 'Session', 'label' => 'Day 1']);
        $otherSession = $otherEvent->sessions()->create(['nama_session' => 'Session', 'label' => 'Day 2']);
        $ticket = $this->createTicketType($event, [], [$session->id]);
        $unlinked = $this->createTicketType($event, ['nama_tiket' => 'Unlinked']);
        $foreignTicket = $this->createTicketType($otherEvent, [], [$otherSession->id]);
        $data = [
            'session_id' => $session->id, 'ticket_type_id' => $ticket->id,
            'checkin_mulai' => '2026-11-24T09:00', 'checkin_selesai' => '2026-11-24T10:00',
        ];
        $url = "/api/partner/scan/sessions/{$event->id}/jadwal";
        $this->putJson($url, $data)->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->putJson("/api/partner/scan/sessions/{$otherEvent->id}/jadwal", $data)->assertForbidden();
        $this->putJson($url, array_replace($data, ['session_id' => $otherSession->id]))->assertNotFound();
        $this->putJson($url, array_replace($data, ['ticket_type_id' => $foreignTicket->id]))->assertNotFound();
        $this->putJson($url, array_replace($data, ['ticket_type_id' => $unlinked->id]))->assertNotFound();
        $this->putJson($url, array_replace($data, ['checkin_selesai' => null]))->assertUnprocessable()->assertJsonValidationErrors('checkin_selesai');
        $this->putJson($url, array_replace($data, ['checkin_mulai' => '2026-11-24T09:00Z']))->assertUnprocessable()->assertJsonValidationErrors('checkin_mulai');
        $this->putJson($url, array_replace($data, ['checkin_selesai' => '2026-11-24T09:00']))->assertUnprocessable()->assertJsonValidationErrors('checkin_selesai');
        $this->putJson($url, array_diff_key($data, ['checkin_mulai' => true, 'checkin_selesai' => true]))->assertUnprocessable();

        $staff = $user->replicate(['email']);
        $staff->forceFill(['peran' => 'staff', 'email' => 'checkin-staff@example.test'])->save();
        Sanctum::actingAs($staff);
        $this->putJson($url, $data)->assertForbidden();
        $this->putJson("/api/staff/scan/sessions/{$event->id}/jadwal", $data)->assertNotFound();
        $this->getJson("/api/staff/scan/sessions/{$event->id}")->assertOk()->assertJsonPath('jadwal_checkin.0.checkin_mulai', null);
    }
}
