<?php

namespace Tests\Concerns;

use App\Models\Event;
use App\Models\Organizer;
use App\Models\TicketType;
use App\Models\User;

trait InteractsWithNontix
{
    /**
     * @return array{0: User, 1: Organizer}
     */
    protected function createPartner(): array
    {
        $organizer = Organizer::create([
            'nama_penyelenggara' => 'Test Organizer',
            'email' => 'org'.uniqid().'@nontix.id',
            'status_verifikasi' => 'terverifikasi',
            'status_akun' => 'aktif',
        ]);

        $user = User::create([
            'nama' => 'Partner User',
            'email' => 'partner'.uniqid().'@nontix.id',
            'password' => 'demo123',
            'peran' => 'partner',
            'organizer_id' => $organizer->id,
            'status' => 'aktif',
        ]);

        $organizer->update(['user_id' => $user->id]);

        return [$user, $organizer];
    }

    protected function createAdmin(): User
    {
        return User::create([
            'nama' => 'Admin Test',
            'email' => 'admin'.uniqid().'@nontix.id',
            'password' => 'admin123',
            'peran' => 'admin_platform',
            'status' => 'aktif',
        ]);
    }

    protected function createEvent(Organizer $organizer, array $overrides = []): Event
    {
        return Event::create(array_merge([
            'organizer_id' => $organizer->id,
            'nama_event' => 'Event Test',
            'slug' => 'event-test-'.uniqid(),
            'kategori' => 'Konser',
            'lokasi' => 'Jakarta',
            'tanggal_mulai' => now()->addDays(3),
            'status' => 'aktif',
        ], $overrides));
    }

    /**
     * @param  array<int, int>  $sessionIds
     */
    protected function createTicketType(Event $event, array $attrs, array $sessionIds = []): TicketType
    {
        $ticketType = $event->ticketTypes()->create(array_merge([
            'nama_tiket' => 'Reguler',
            'harga' => 100000,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 4,
            'is_bundle' => count($sessionIds) > 1,
            'status' => 'aktif',
        ], $attrs));

        if ($sessionIds) {
            $ticketType->sessions()->sync($sessionIds);
        }

        return $ticketType;
    }
}
