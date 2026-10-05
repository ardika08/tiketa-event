<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class PartnerApiTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    public function test_partner_can_manage_event_sessions_and_bundle_ticket(): void
    {
        [$user] = $this->createPartner();
        Sanctum::actingAs($user);

        $eventRes = $this->postJson('/api/partner/events', [
            'nama_event' => 'Festival Uji',
            'kategori' => 'Konser',
            'status' => 'aktif',
            'sessions' => [
                ['label' => 'Day 1', 'nama_session' => 'Hari 1', 'urutan' => 1],
                ['label' => 'Day 2', 'nama_session' => 'Hari 2', 'urutan' => 2],
            ],
        ])->assertCreated();

        $eventId = $eventRes->json('data.id');
        $sessionIds = collect($eventRes->json('data.sessions'))->pluck('id')->all();
        $this->assertCount(2, $sessionIds);

        $ticketRes = $this->postJson('/api/partner/ticket-types', [
            'event_id' => $eventId,
            'nama_tiket' => '2-Day Pass',
            'harga' => 200000,
            'kuota' => 50,
            'max_per_order' => 4,
            'session_ids' => $sessionIds,
        ])->assertCreated();

        $this->assertTrue($ticketRes->json('data.is_bundle'));
        $this->assertEqualsCanonicalizing($sessionIds, $ticketRes->json('data.session_ids'));

        $this->getJson('/api/partner/analytics')
            ->assertOk()
            ->assertJsonStructure(['summary', 'trend', 'events']);

        // Listing event partner harus menyertakan agregat penjualan lunas.
        $this->getJson('/api/partner/events')
            ->assertOk()
            ->assertJsonPath('data.0.tiket.0.terjual_lunas', 0);
    }

    public function test_partner_cannot_access_another_partners_event(): void
    {
        [$userA] = $this->createPartner();
        [, $organizerB] = $this->createPartner();
        $eventB = $this->createEvent($organizerB);

        Sanctum::actingAs($userA);

        $this->getJson("/api/partner/events/{$eventB->id}")->assertForbidden();
    }
}
