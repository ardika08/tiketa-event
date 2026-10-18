<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Gate;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Halaman Staff tadinya hanya tampilan: tombol Tambah/Kelola tidak ada aksinya.
 * Test ini mengunci API-nya, termasuk dua lubang yang ikut ditutup:
 * gate milik organizer lain, dan email milik akun lain yang bisa ditempelkan
 * sebagai akun staff.
 */
class StaffTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithNontix;

    private function actingPartner(): array
    {
        [$user, $organizer] = $this->createPartner();
        Sanctum::actingAs($user);

        return [$user, $organizer];
    }

    private function buatGate($organizer): Gate
    {
        return Gate::create([
            'organizer_id' => $organizer->id,
            'nama_gate' => 'Gate Utama',
        ]);
    }

    public function test_partner_bisa_tambah_ubah_dan_hapus_staff(): void
    {
        [, $organizer] = $this->actingPartner();
        $gate = $this->buatGate($organizer);

        $id = $this->postJson('/api/partner/staffs', [
            'nama' => 'Siti',
            'email' => 'siti@mail.com',
            'peran' => 'Scanner',
            'gate_id' => $gate->id,
        ])->assertCreated()->json('data.id');

        $staff = Staff::find($id);
        $this->assertSame('siti@mail.com', $staff->email);
        $this->assertSame($gate->id, $staff->gate_id);
        // Tanpa buat_akun: staff hanya catatan tugas, tidak dibuatkan user login.
        $this->assertNull($staff->user_id);

        $this->putJson("/api/partner/staffs/{$id}", [
            'nama' => 'Siti Aminah',
            'status' => 'nonaktif',
        ])->assertOk()->assertJsonPath('data.status', 'nonaktif');

        $this->assertSame('Siti Aminah', Staff::find($id)->nama);

        $this->deleteJson("/api/partner/staffs/{$id}")->assertOk();
        $this->assertNull(Staff::find($id));
    }

    public function test_gate_milik_organizer_lain_ditolak(): void
    {
        $this->actingPartner();
        [, $organizerLain] = $this->createPartner();
        $gateOrangLain = $this->buatGate($organizerLain);

        $this->postJson('/api/partner/staffs', [
            'nama' => 'Siti',
            'gate_id' => $gateOrangLain->id,
        ])->assertStatus(422)->assertJsonValidationErrors('gate_id');
    }

    public function test_email_staff_tidak_boleh_ganda_di_daftar_yang_sama(): void
    {
        $this->actingPartner();

        $this->postJson('/api/partner/staffs', [
            'nama' => 'Siti',
            'email' => 'siti@mail.com',
        ])->assertCreated();

        $this->postJson('/api/partner/staffs', [
            'nama' => 'Siti Kedua',
            'email' => 'siti@mail.com',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_email_milik_akun_lain_tidak_bisa_dijadikan_akun_staff(): void
    {
        $this->actingPartner();
        $admin = $this->createAdmin();

        $this->postJson('/api/partner/staffs', [
            'nama' => 'Penyusup',
            'email' => $admin->email,
            'buat_akun' => true,
            'password' => 'rahasia123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // Akun admin tidak ikut tertarik ke daftar staff mana pun.
        $this->assertSame(0, Staff::where('user_id', $admin->id)->count());
        $this->assertSame(Role::ADMIN, $admin->fresh()->peran);
        $this->assertNull($admin->fresh()->organizer_id);
    }

    public function test_ubah_email_dan_status_staff_ikut_ke_akun_login(): void
    {
        $this->actingPartner();

        $id = $this->postJson('/api/partner/staffs', [
            'nama' => 'Siti',
            'email' => 'siti@mail.com',
            'buat_akun' => true,
            'password' => 'rahasia123',
        ])->assertCreated()->json('data.id');

        $staff = Staff::find($id);
        $this->assertNotNull($staff->user_id);

        $this->putJson("/api/partner/staffs/{$id}", [
            'email' => 'siti.baru@mail.com',
            'status' => 'nonaktif',
        ])->assertOk();

        $akun = $staff->fresh()->user;

        // Tanpa sinkronisasi ini, email di daftar berubah tapi orangnya tetap
        // login dengan email lama, dan staff "nonaktif" masih bisa masuk.
        $this->assertSame('siti.baru@mail.com', $akun->email);
        $this->assertSame(UserStatus::INACTIVE, $akun->status);
        $this->assertSame('siti.baru@mail.com', $staff->fresh()->email);
    }

    public function test_hapus_staff_menonaktifkan_akun_login_bukan_menghapusnya(): void
    {
        $this->actingPartner();

        $id = $this->postJson('/api/partner/staffs', [
            'nama' => 'Siti',
            'email' => 'siti@mail.com',
            'buat_akun' => true,
            'password' => 'rahasia123',
        ])->assertCreated()->json('data.id');

        $userId = Staff::find($id)->user_id;

        $this->deleteJson("/api/partner/staffs/{$id}")->assertOk();

        $this->assertNull(Staff::find($id));
        // Akun tetap ada karena riwayat check-in menyimpan nama petugasnya.
        $this->assertSame(UserStatus::INACTIVE, User::find($userId)->status);
    }

    public function test_staff_organizer_lain_tidak_bisa_diubah_atau_dihapus(): void
    {
        $this->actingPartner();
        [, $organizerLain] = $this->createPartner();

        $staffLain = Staff::create([
            'organizer_id' => $organizerLain->id,
            'nama' => 'Bukan Milikmu',
        ]);

        $this->putJson("/api/partner/staffs/{$staffLain->id}", ['nama' => 'Diubah'])
            ->assertForbidden();

        $this->deleteJson("/api/partner/staffs/{$staffLain->id}")->assertForbidden();
    }
}
