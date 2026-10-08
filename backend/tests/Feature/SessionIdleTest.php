<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Fase 1 — masa berlaku sesi login.
 *
 * Yang dijaga:
 *   - token yang menganggur terlalu lama dicabut + dibalas 401 dengan kode
 *     SESSION_IDLE_EXPIRED (frontend memakai kode ini untuk mengarahkan ke
 *     halaman masuk, lihat Fase 2);
 *   - batas menganggur BERBEDA per peran — khususnya staff yang memindai QR
 *     di lokasi: 40 menit menganggur TIDAK boleh mengeluarkannya, karena
 *     petugas yang ter-logout di tengah antrean scan bikin acara kacau;
 *   - batas absolut (umur token) tetap ditegakkan Sanctum.
 *
 * ⚠️ Satu request terautentikasi per method. Guard Sanctum meng-cache user di
 * dalam container, dan container itu tidak di-reset antar-request dalam satu
 * test — request kedua akan tetap memakai user dari request pertama. Kalau
 * butuh membandingkan dua peran, buat dua test method terpisah.
 */
class SessionIdleTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    /** Buat token seperti proses login, kembalikan bearer-nya. */
    private function masukSebagai(User $user): string
    {
        return $user->createToken($user->peran->value)->plainTextToken;
    }

    /** Geser last_used_at token terakhir milik user (simulasi menganggur). */
    private function anggurkan(User $user, int $menit): void
    {
        $token = $user->tokens()->latest('id')->firstOrFail();
        $token->forceFill(['last_used_at' => now()->subMinutes($menit)])->save();
    }

    private function panggilMe(string $bearer)
    {
        return $this->withHeader('Authorization', "Bearer {$bearer}")->getJson('/api/auth/me');
    }

    private function buatStaff(): User
    {
        return User::create([
            'nama' => 'Petugas Gate',
            'email' => 'staff'.uniqid().'@nontix.id',
            'password' => 'staff123',
            'peran' => 'staff',
            'status' => 'aktif',
        ]);
    }

    public function test_token_menganggur_dicabut_dan_balas_401(): void
    {
        [$user] = $this->createPartner();
        $bearer = $this->masukSebagai($user);

        $this->anggurkan($user, 121); // batas partner = 120 menit

        $this->panggilMe($bearer)
            ->assertStatus(401)
            ->assertJson(['code' => 'SESSION_IDLE_EXPIRED']);

        $this->assertSame(0, PersonalAccessToken::count(), 'Token menganggur harus dicabut dari database.');
    }

    public function test_token_masih_aktif_lolos_dan_waktunya_diperbarui(): void
    {
        [$user] = $this->createPartner();
        $bearer = $this->masukSebagai($user);

        $this->anggurkan($user, 5);

        $this->panggilMe($bearer)->assertOk();

        $token = PersonalAccessToken::firstOrFail();
        $this->assertTrue(
            $token->last_used_at->gt(now()->subMinute()),
            'last_used_at harus diperbarui setiap request yang lolos.',
        );
    }

    public function test_token_baru_yang_belum_pernah_dipakai_tidak_dicabut(): void
    {
        [$user] = $this->createPartner();
        $bearer = $this->masukSebagai($user);

        $this->assertNull(PersonalAccessToken::firstOrFail()->last_used_at);

        $this->panggilMe($bearer)->assertOk();
    }

    /** Admin platform: batas 30 menit → 40 menit menganggur sudah dicabut. */
    public function test_admin_menganggur_40_menit_dicabut(): void
    {
        $admin = $this->createAdmin();
        $bearer = $this->masukSebagai($admin);

        $this->anggurkan($admin, 40);

        $this->panggilMe($bearer)->assertStatus(401);
    }

    /**
     * Staff (petugas scan): batas 480 menit → 40 menit menganggur masih aman.
     * Ini yang menjaga petugas tidak ter-logout di tengah antrean scan.
     */
    public function test_staff_menganggur_40_menit_masih_bisa_masuk(): void
    {
        $staff = $this->buatStaff();
        $bearer = $this->masukSebagai($staff);

        $this->anggurkan($staff, 40);

        $this->panggilMe($bearer)->assertOk();
    }

    public function test_token_lewat_batas_absolut_ditolak(): void
    {
        [$user] = $this->createPartner();
        $bearer = $this->masukSebagai($user);

        // Token berumur 31 hari, batas absolut 30 hari (NONTIX_SESSION_ABSOLUTE_DAYS).
        $token = $user->tokens()->latest('id')->firstOrFail();
        $token->forceFill(['created_at' => now()->subDays(31)])->save();

        $this->panggilMe($bearer)->assertStatus(401);
    }
}
