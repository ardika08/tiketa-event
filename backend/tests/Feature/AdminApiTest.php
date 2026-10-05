<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    public function test_admin_can_view_dashboard_and_manage_partners(): void
    {
        [, $organizer] = $this->createPartner();
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['summary', 'trend']);

        $this->getJson('/api/admin/partners')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->putJson("/api/admin/partners/{$organizer->id}", [
            'status_verifikasi' => 'terverifikasi',
        ])->assertOk();

        $this->assertDatabaseHas('organizers', [
            'id' => $organizer->id,
            'status_verifikasi' => 'terverifikasi',
        ]);
    }
}
