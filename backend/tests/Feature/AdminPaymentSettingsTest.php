<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class AdminPaymentSettingsTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.xendit.mode', 'test');
        config()->set('nontix.xendit.secret_key', 'xnd_development_test');
    }

    private function createOrderFor(string $kode = null): Order
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['harga' => 100000]);

        $res = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $ticket->id, 'jumlah' => 1]],
        ])->assertCreated();

        return Order::where('kode_order', $res->json('data.kode_order'))->firstOrFail();
    }

    public function test_admin_can_set_primary_gateway_and_checkout_follows_it(): void
    {
        Queue::fake();
        Http::fake([
            'api.xendit.co/sessions' => Http::response([
                'payment_session_id' => 'ps-set',
                'payment_link_url' => 'https://xen.to/set',
            ], 201),
        ]);

        Sanctum::actingAs($this->createAdmin());

        // Xendit jadi utama.
        $this->putJson('/api/admin/payment-settings', ['gateways' => ['xendit', 'mayar']])
            ->assertOk();

        $order = $this->createOrderFor();
        $this->assertSame('xendit', $order->gateway);
        $this->assertSame('https://xen.to/set', $order->payments()->latest()->first()->payment_url);
    }

    public function test_checkout_uses_mayar_when_only_mayar_active(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->createAdmin());

        $this->putJson('/api/admin/payment-settings', ['gateways' => ['mayar']])->assertOk();

        $order = $this->createOrderFor();
        $this->assertSame('mayar', $order->gateway);
    }

    public function test_cannot_activate_unavailable_gateway(): void
    {
        Sanctum::actingAs($this->createAdmin());

        // Tidak ada kredensial sama sekali.
        config()->set('nontix.mayar.mode', 'live');
        config()->set('nontix.mayar.api_key', null);
        config()->set('nontix.xendit.secret_key', null);

        $this->putJson('/api/admin/payment-settings', ['gateways' => ['mayar', 'xendit']])
            ->assertStatus(422);
    }

    public function test_payment_settings_lists_availability(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $res = $this->getJson('/api/admin/payment-settings')->assertOk();
        $ids = collect($res->json('gateways'))->pluck('id');

        $this->assertTrue($ids->contains('mayar'));
        $this->assertTrue($ids->contains('xendit'));
    }

    public function test_partner_cannot_access_payment_settings(): void
    {
        [$user] = $this->createPartner();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/payment-settings')->assertForbidden();
    }
}