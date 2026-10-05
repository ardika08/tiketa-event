<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    public function test_partner_can_register_and_login(): void
    {
        $this->postJson('/api/auth/partner/register', [
            'nama' => 'Andi',
            'email' => 'andi@nontix.id',
            'password' => 'rahasia123',
            'nama_penyelenggara' => 'Andi Event',
            'telepon' => '0812000',
        ])->assertCreated()->assertJsonPath('user.peran', 'partner');

        $this->assertDatabaseHas('organizers', ['nama_penyelenggara' => 'Andi Event']);

        $this->postJson('/api/auth/login', [
            'email' => 'andi@nontix.id',
            'password' => 'rahasia123',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        [$user] = $this->createPartner();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'salah',
        ])->assertStatus(422);
    }

    public function test_guest_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/partner/events')->assertUnauthorized();
    }

    public function test_role_middleware_blocks_partner_from_admin(): void
    {
        [$user] = $this->createPartner();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_me_returns_authenticated_user(): void
    {
        [$user] = $this->createPartner();
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }
}
