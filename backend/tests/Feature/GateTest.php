<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Gate;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Halaman Gate tadinya juga cuma tampilan: tombol "Tambah Gate" dan
 * "Nonaktifkan" tidak punya aksi. Test ini mengunci API-nya, termasuk aturan
 * yang menjaga riwayat check-in tetap punya jejak gate.
 */
class GateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithNontix;

    private function actingPartner(): array
    {
        [$user, $organizer] = $this->createPartner();
        Sanctum::actingAs($user);

        return [$user, $organizer];
    }

    public function test_partner_bisa_tambah_ubah_dan_hapus_gate(): void
    {
        [, $organizer] = $this->actingPartner();
        $event = $this->createEvent($organizer);

        $id = $this->postJson('/api/partner/gates', [
            'nama_gate' => 'Gate Utama',
            'event_id' => $event->id,
        ])->assertCreated()->json('data.id');

        $gate = Gate::find($id);
        $this->assertSame('Gate Utama', $gate->nama_gate);
        $this->assertSame($event->id, $gate->event_id);
        $this->assertSame('aktif', $gate->status->value);

        $this->putJson("/api/partner/gates/{$id}", [
            'nama_gate' => 'Gate Depan',
            'status' => 'nonaktif',
        ])->assertOk()->assertJsonPath('data.nama_gate', 'Gate Depan');

        $this->assertSame('nonaktif', Gate::find($id)->status->value);

        $this->deleteJson("/api/partner/gates/{$id}")->assertOk();
        $this->assertNull(Gate::find($id));
    }

    public function test_gate_bisa_dipindah_ke_event_lain(): void
    {
        [, $organizer] = $this->actingPartner();
        $eventA = $this->createEvent($organizer);
        $eventB = $this->createEvent($organizer);

        $id = $this->postJson('/api/partner/gates', [
            'nama_gate' => 'Gate Utama',
            'event_id' => $eventA->id,
        ])->assertCreated()->json('data.id');

        // Gate yang terlanjur tertaut ke event salah harus bisa dibetulkan
        // tanpa menghapus dan membuat ulang.
        $this->putJson("/api/partner/gates/{$id}", [
            'event_id' => $eventB->id,
        ])->assertOk()->assertJsonPath('data.event_id', $eventB->id);

        $this->assertSame($eventB->id, Gate::find($id)->event_id);
    }

    public function test_event_milik_organizer_lain_ditolak(): void
    {
        $this->actingPartner();
        [, $organizerLain] = $this->createPartner();
        $eventOrangLain = $this->createEvent($organizerLain);

        $this->postJson('/api/partner/gates', [
            'nama_gate' => 'Gate Nyasar',
            'event_id' => $eventOrangLain->id,
        ])->assertStatus(422)->assertJsonValidationErrors('event_id');
    }

    public function test_gate_yang_sudah_dipakai_checkin_tidak_bisa_dihapus(): void
    {
        [, $organizer] = $this->actingPartner();
        $event = $this->createEvent($organizer);

        $gate = Gate::create([
            'organizer_id' => $organizer->id,
            'nama_gate' => 'Gate Utama',
        ]);

        Checkin::create([
            'event_id' => $event->id,
            'gate_id' => $gate->id,
            'kode_qr' => 'QR-TEST-1',
            'status' => 'berhasil',
            'checked_in_at' => now(),
        ]);

        // checkins.gate_id memakai nullOnDelete, jadi menghapus gate akan
        // menghapus jejak "masuk lewat gate mana" dari riwayat.
        $this->deleteJson("/api/partner/gates/{$gate->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Gate ini sudah dipakai di riwayat check-in, jadi tidak bisa dihapus. Nonaktifkan saja.');

        $this->assertNotNull(Gate::find($gate->id));
    }

    public function test_hapus_gate_menyebut_jumlah_staff_yang_kehilangan_penugasan(): void
    {
        [, $organizer] = $this->actingPartner();

        $gate = Gate::create([
            'organizer_id' => $organizer->id,
            'nama_gate' => 'Gate Utama',
        ]);

        Staff::create(['organizer_id' => $organizer->id, 'gate_id' => $gate->id, 'nama' => 'Siti']);
        Staff::create(['organizer_id' => $organizer->id, 'gate_id' => $gate->id, 'nama' => 'Budi']);

        $this->deleteJson("/api/partner/gates/{$gate->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Gate dihapus. 2 staff sekarang tanpa gate.');

        // Staff tetap ada, hanya penugasannya yang lepas.
        $this->assertSame(2, Staff::whereNull('gate_id')->count());
    }

    public function test_index_menyertakan_jumlah_staff_dan_urutan_aktif_dulu(): void
    {
        [, $organizer] = $this->actingPartner();

        $nonaktif = Gate::create([
            'organizer_id' => $organizer->id,
            'nama_gate' => 'Gate Lama',
            'status' => 'nonaktif',
        ]);

        $aktif = Gate::create([
            'organizer_id' => $organizer->id,
            'nama_gate' => 'Gate Baru',
        ]);

        Staff::create(['organizer_id' => $organizer->id, 'gate_id' => $aktif->id, 'nama' => 'Siti']);

        $data = $this->getJson('/api/partner/gates')->assertOk()->json('data');

        $this->assertSame($aktif->id, $data[0]['id']);
        $this->assertSame($nonaktif->id, $data[1]['id']);
        $this->assertSame(1, $data[0]['staffs_count']);
        $this->assertSame(0, $data[1]['staffs_count']);
    }

    public function test_gate_organizer_lain_tidak_bisa_diubah_atau_dihapus(): void
    {
        $this->actingPartner();
        [, $organizerLain] = $this->createPartner();

        $gateLain = Gate::create([
            'organizer_id' => $organizerLain->id,
            'nama_gate' => 'Bukan Milikmu',
        ]);

        $this->putJson("/api/partner/gates/{$gateLain->id}", ['nama_gate' => 'Diubah'])
            ->assertForbidden();

        $this->deleteJson("/api/partner/gates/{$gateLain->id}")->assertForbidden();
    }
}
