<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class TicketTargetedVoucherTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    public function test_partner_can_persist_list_and_clear_ticket_targets(): void
    {
        [$user, $organizer] = $this->createPartner();
        Sanctum::actingAs($user);
        $event = $this->createEvent($organizer);
        $a = $this->createTicketType($event, ['nama_tiket' => 'Early Bird']);
        $b = $this->createTicketType($event, ['nama_tiket' => 'VIP']);
        $ids = [$a->id, $b->id];
        $url = '/api/partner/vouchers';

        $id = $this->postJson($url, $this->voucherPayload() + [
            'event_id' => $event->id,
            'ticket_type_ids' => [(string) $a->id, $b->id],
        ])->assertCreated()->assertJsonPath('data.ticket_type_ids', $ids)->json('data.id');

        $this->getJson($url)->assertOk()->assertJsonPath('data.0.ticket_type_ids', $ids)
            ->assertJsonPath('data.0.ticket_types', [
                ['id' => $a->id, 'nama_tiket' => 'Early Bird'],
                ['id' => $b->id, 'nama_tiket' => 'VIP'],
            ]);
        $this->putJson("$url/$id", ['kuota' => 5])->assertOk()->assertJsonPath('data.ticket_type_ids', $ids);
        $this->putJson("$url/$id", ['event_id' => null, 'ticket_type_ids' => null])->assertOk()
            ->assertJsonPath('data.ticket_type_ids', null)
            ->assertJsonPath('data.ticket_types', []);
    }

    public function test_partner_cannot_save_invalid_targets_or_move_a_voucher_without_retargeting(): void
    {
        [$user, $organizer] = $this->createPartner();
        [, $otherOrganizer] = $this->createPartner();
        Sanctum::actingAs($user);
        $event = $this->createEvent($organizer);
        $anotherEvent = $this->createEvent($organizer);
        $a = $this->createTicketType($event, []);
        $b = $this->createTicketType($anotherEvent, []);
        $foreign = $this->createTicketType($this->createEvent($otherOrganizer), []);
        $url = '/api/partner/vouchers';
        $base = $this->voucherPayload() + ['event_id' => $event->id];

        $this->postJson($url, $this->voucherPayload() + ['ticket_type_ids' => [$a->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->postJson($url, $base + ['ticket_type_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('ticket_type_ids');
        $this->postJson($url, $base + ['ticket_type_ids' => [$a->id, (string) $a->id]])->assertUnprocessable();
        $this->postJson($url, $base + ['ticket_type_ids' => [$b->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('ticket_type_ids');
        $this->postJson($url, $base + ['ticket_type_ids' => [$foreign->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('ticket_type_ids');
        $this->assertDatabaseCount('vouchers', 0);

        $id = $this->postJson($url, $base + ['ticket_type_ids' => [$a->id]])->assertCreated()->json('data.id');
        $this->putJson("$url/$id", ['event_id' => $anotherEvent->id])
            ->assertUnprocessable()->assertJsonValidationErrors('ticket_type_ids');
        $this->putJson("$url/$id", ['event_id' => null])->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->putJson("$url/$id", ['event_id' => $anotherEvent->id, 'ticket_type_ids' => [$b->id]])
            ->assertOk()->assertJsonPath('data.ticket_type_ids', [$b->id]);
    }

    public function test_preview_and_checkout_discount_only_the_eligible_subtotal(): void
    {
        [$event, $eligible, $ineligible, $voucher] = $this->scenario();
        $items = [$this->item($eligible, 2), $this->item($ineligible)];
        config()->set('nontix.gateways', 'mayar');
        config()->set('nontix.mayar.mode', 'fake');

        $this->preview($event, $items)->assertOk()->assertJsonPath('diskon', 20000);
        $this->assertSame(0, $voucher->fresh()->terpakai);
        $this->assertSame(10, $eligible->fresh()->sisa_kuota);
        $this->checkout($event, $items)->assertCreated()
            ->assertJsonPath('data.subtotal', 500000)
            ->assertJsonPath('data.diskon', 20000)
            ->assertJsonPath('data.total_harga', 480000);
        $this->assertDatabaseHas('orders', ['voucher_id' => $voucher->id, 'diskon' => 20000, 'total_harga' => 480000]);
        $this->assertDatabaseHas('payments', ['jumlah' => 480000]);
        $this->assertSame(1, $voucher->fresh()->terpakai);
    }

    public function test_nominal_discount_is_once_per_order_and_capped_at_eligible_subtotal(): void
    {
        [$event, $eligible, $ineligible, $voucher] = $this->scenario();
        $items = [$this->item($eligible, 2), $this->item($ineligible)];
        $voucher->update(['tipe_diskon' => 'nominal', 'nilai' => 75000]);
        $this->preview($event, $items)->assertOk()->assertJsonPath('diskon', 75000);
        $voucher->update(['nilai' => 900000]);
        $this->preview($event, $items)->assertOk()->assertJsonPath('diskon', 200000);
    }

    public function test_ineligible_cart_or_unmet_eligible_minimum_does_not_consume_voucher_or_stock(): void
    {
        [$event, $eligible, $ineligible] = $this->scenario(['min_pembelian' => 150000]);
        foreach ([[$this->item($ineligible)], [$this->item($eligible), $this->item($ineligible)]] as $items) {
            $this->preview($event, $items)->assertUnprocessable()->assertJsonValidationErrors('kode');
            $this->checkout($event, $items)->assertUnprocessable()->assertJsonValidationErrors('kode');
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('vouchers', ['kode' => 'TARGET', 'terpakai' => 0]);
        $this->assertSame(10, $eligible->fresh()->sisa_kuota);
        $this->assertSame(10, $ineligible->fresh()->sisa_kuota);
    }

    public function test_scoped_voucher_rejects_subtotal_only_and_foreign_cart_items(): void
    {
        [$event] = $this->scenario();
        $otherEvent = $this->createEvent($event->organizer);
        $otherTicket = $this->createTicketType($otherEvent, []);

        $this->postJson('/api/vouchers/validate', ['event_id' => $event->id, 'kode' => 'TARGET', 'subtotal' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->preview($event, [$this->item($otherTicket)])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_deleting_the_only_target_does_not_turn_the_voucher_into_an_all_ticket_voucher(): void
    {
        [$event, $eligible, $ineligible] = $this->scenario();
        Sanctum::actingAs($event->organizer->user);
        $this->deleteJson("/api/partner/ticket-types/{$eligible->id}")->assertOk();
        $this->preview($event, [$this->item($ineligible)])->assertUnprocessable()->assertJsonValidationErrors('kode');
        $this->checkout($event, [$this->item($ineligible)])->assertUnprocessable()->assertJsonValidationErrors('kode');
    }

    public function test_unscoped_voucher_still_supports_legacy_subtotal_and_prices_items_when_provided(): void
    {
        [$event, $eligible, $ineligible] = $this->scenario(['ticket_type_ids' => null]);
        $this->postJson('/api/vouchers/validate', ['event_id' => $event->id, 'kode' => 'TARGET', 'subtotal' => 500000])
            ->assertOk()->assertJsonPath('diskon', 50000);
        $this->preview($event, [$this->item($eligible), $this->item($ineligible)])
            ->assertOk()->assertJsonPath('diskon', 40000);
    }

    private function voucherPayload(): array
    {
        return ['kode' => 'TARGET', 'tipe_diskon' => 'persen', 'nilai' => 10, 'status' => 'aktif'];
    }

    private function scenario(array $overrides = []): array
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $eligible = $this->createTicketType($event, ['harga' => 100000]);
        $ineligible = $this->createTicketType($event, ['harga' => 300000]);
        $voucher = Voucher::create($overrides + $this->voucherPayload() + [
            'event_id' => $event->id,
            'organizer_id' => $organizer->id,
            'ticket_type_ids' => [$eligible->id],
        ]);

        return [$event, $eligible, $ineligible, $voucher];
    }

    private function item(TicketType $ticket, int $quantity = 1): array
    {
        return ['ticket_type_id' => $ticket->id, 'jumlah' => $quantity];
    }

    private function preview(Event $event, array $items)
    {
        return $this->postJson('/api/vouchers/validate', [
            'event_id' => $event->id, 'kode' => 'TARGET', 'items' => $items, 'subtotal' => 999999,
        ]);
    }

    private function checkout(Event $event, array $items)
    {
        return $this->postJson('/api/orders', [
            'event_id' => $event->id, 'voucher_code' => 'TARGET', 'items' => $items,
            'nama' => 'Budi', 'email' => 'budi@example.com', 'whatsapp' => '08123456789',
        ]);
    }
}
