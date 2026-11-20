<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\FormField;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class MultiTicketFormFieldTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    public function test_partner_can_save_multiple_targets_and_switch_between_legacy_and_new_scope(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $a = $this->createTicketType($event, []);
        $b = $this->createTicketType($event, []);
        Sanctum::actingAs($user);

        $url = '/api/partner/form-fields';
        $id = $this->postJson($url, [
            'label' => 'Club', 'tipe' => 'teks', 'event_id' => $event->id,
            'ticket_type_ids' => [$a->id, $b->id],
        ])->assertCreated()->assertJsonPath('data.ticket_type_ids', [$a->id, $b->id])->json('data.id');

        $this->getJson($url)->assertOk()->assertJsonPath('data.0.ticket_type_ids', [$a->id, $b->id]);
        $this->putJson("$url/$id", ['ticket_type_id' => $b->id])->assertOk()
            ->assertJsonPath('data.ticket_type_id', $b->id)->assertJsonPath('data.ticket_type_ids', null);
        $this->putJson("$url/$id", ['ticket_type_ids' => [$a->id, $b->id]])->assertOk()
            ->assertJsonPath('data.ticket_type_id', null)->assertJsonPath('data.ticket_type_ids', [$a->id, $b->id]);
        $this->putJson("$url/$id", ['ticket_type_ids' => null])->assertOk()
            ->assertJsonPath('data.ticket_type_id', null)->assertJsonPath('data.ticket_type_ids', null);
    }

    public function test_partner_validates_ticket_target_shape_and_effective_scope_on_partial_update(): void
    {
        [$user, $organizer] = $this->createPartner();
        [, $otherOrganizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $otherEvent = $this->createEvent($organizer);
        $a = $this->createTicketType($event, []);
        $b = $this->createTicketType($otherEvent, []);
        $foreign = $this->createTicketType($this->createEvent($otherOrganizer), []);
        Sanctum::actingAs($user);

        $url = '/api/partner/form-fields';
        $base = ['label' => 'Club', 'tipe' => 'teks', 'event_id' => $event->id];
        foreach ([[], ['ticket' => $a->id], [$a->id, $a->id], [[$a->id]], [999999]] as $invalid) {
            $this->postJson($url, $base + ['ticket_type_ids' => $invalid])->assertUnprocessable();
        }
        $this->postJson($url, $base + ['ticket_type_ids' => [$a->id, $foreign->id]])->assertForbidden();
        $this->postJson($url, $base + ['ticket_type_ids' => [$a->id, $b->id]])->assertUnprocessable();
        $id = $this->postJson($url, $base + ['ticket_type_ids' => [$a->id]])->assertCreated()->json('data.id');

        $this->putJson("$url/$id", ['ticket_type_ids' => [$b->id]])->assertUnprocessable();
        $this->putJson("$url/$id", ['event_id' => $otherEvent->id])->assertUnprocessable();
        $this->putJson("$url/$id", ['ticket_type_id' => $b->id])->assertUnprocessable();
        $this->putJson("$url/$id", ['event_id' => $otherEvent->id, 'ticket_type_ids' => [$b->id]])
            ->assertOk()->assertJsonPath('data.ticket_type_ids', [$b->id]);
    }

    public function test_partner_requires_an_event_for_new_or_retained_ticket_targets(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, []);
        Sanctum::actingAs($user);

        $url = '/api/partner/form-fields';
        $base = ['label' => 'Club', 'tipe' => 'teks'];
        foreach ([['ticket_type_ids' => [$ticket->id]], ['ticket_type_id' => $ticket->id]] as $targets) {
            $this->postJson($url, $base + $targets)->assertUnprocessable()->assertJsonValidationErrors('event_id');

            $id = $this->postJson($url, $base + $targets + ['event_id' => $event->id])->assertCreated()->json('data.id');
            $this->putJson("$url/$id", ['label' => 'Updated'])->assertOk();
            $this->putJson("$url/$id", ['event_id' => null])->assertUnprocessable()->assertJsonValidationErrors('event_id');
            $this->putJson("$url/$id", ['event_id' => null, 'ticket_type_ids' => null])->assertOk()
                ->assertJsonPath('data.event_id', null)->assertJsonPath('data.ticket_type_id', null)
                ->assertJsonPath('data.ticket_type_ids', null);
        }
    }

    public function test_eventless_legacy_fields_can_be_repaired_or_have_their_scope_cleared(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $otherEvent = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, []);
        Sanctum::actingAs($user);

        foreach ([['ticket_type_id' => $ticket->id], ['ticket_type_ids' => [$ticket->id]]] as $targets) {
            $field = $this->field($event, ['event_id' => null] + $targets);
            $url = "/api/partner/form-fields/{$field->id}";
            $this->putJson($url, ['label' => 'Updated'])->assertUnprocessable()->assertJsonValidationErrors('event_id');
            $this->putJson($url, ['event_id' => $otherEvent->id])->assertUnprocessable();
            $this->putJson($url, ['event_id' => $event->id])->assertOk()->assertJsonPath('data.event_id', $event->id);

            $field = $this->field($event, ['event_id' => null] + $targets);
            $this->putJson("/api/partner/form-fields/{$field->id}", ['ticket_type_ids' => null])->assertOk()
                ->assertJsonPath('data.event_id', null)->assertJsonPath('data.ticket_type_id', null)
                ->assertJsonPath('data.ticket_type_ids', null);
        }
    }

    public function test_ticket_type_can_be_updated_but_cannot_move_to_another_event(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $otherEvent = $this->createEvent($organizer);
        [, $foreignOrganizer] = $this->createPartner();
        $foreignEvent = $this->createEvent($foreignOrganizer);
        $ticket = $this->createTicketType($event, []);
        Sanctum::actingAs($user);

        $url = "/api/partner/ticket-types/{$ticket->id}";
        $this->putJson($url, ['event_id' => (string) $event->id, 'nama_tiket' => 'Updated'])->assertOk();
        foreach ([$otherEvent, $foreignEvent] as $target) {
            $this->putJson($url, ['event_id' => $target->id, 'nama_tiket' => 'Moved'])
                ->assertUnprocessable()->assertJsonValidationErrors('event_id');
        }
        $this->assertDatabaseHas('ticket_types', ['id' => $ticket->id, 'event_id' => $event->id, 'nama_tiket' => 'Updated']);
    }

    public function test_ticket_deletion_prunes_targets_without_turning_fields_into_all_ticket_fields(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $a = $this->createTicketType($event, []);
        $b = $this->createTicketType($event, []);
        $c = $this->createTicketType($event, []);
        $multi = $this->field($event, ['ticket_type_ids' => [$a->id, $b->id]]);
        $single = $this->field($event, ['ticket_type_ids' => [$a->id]]);
        $legacy = $this->field($event, ['ticket_type_id' => $a->id]);
        $wide = $this->field($event, []);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/partner/ticket-types/{$a->id}")->assertOk();
        $this->assertModelMissing($a);
        $this->assertSame([$b->id], $multi->fresh()->ticket_type_ids);
        foreach ([$single, $legacy] as $field) {
            $this->assertSame([], $field->fresh()->ticket_type_ids);
            $this->assertNull($field->fresh()->ticket_type_id);
        }
        $eventUrl = "/api/events/{$event->slug}";
        $this->assertSame([$multi->id, $wide->id], array_column($this->getJson($eventUrl)->assertOk()->json('data.formFields'), 'id'));
        $this->assertSame([$wide->id], array_column($this->getJson("$eventUrl/tiket/{$c->slug}")->assertOk()->json('data.formFields'), 'id'));

        $this->putJson("/api/partner/form-fields/{$single->id}", ['label' => 'Still editable'])->assertOk();
        $this->deleteJson("/api/partner/ticket-types/{$b->id}")->assertOk();
        $this->assertSame([], $multi->fresh()->ticket_type_ids);
        $this->assertSame([$wide->id], array_column($this->getJson($eventUrl)->assertOk()->json('data.formFields'), 'id'));
    }

    public function test_public_event_exposes_scopes_and_direct_checkout_filters_by_ticket(): void
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $a = $this->createTicketType($event, ['sembunyi' => true]);
        $b = $this->createTicketType($event, []);
        $c = $this->createTicketType($event, []);
        $global = $this->field($event, ['event_id' => null, 'urutan' => 1]);
        $multi = $this->field($event, ['ticket_type_ids' => [$a->id, $b->id], 'urutan' => 2]);
        $legacy = $this->field($event, ['ticket_type_id' => $a->id, 'urutan' => 3]);
        $eventWide = $this->field($event, ['urutan' => 4]);
        $this->field($event, ['event_id' => null, 'ticket_type_ids' => [$b->id], 'urutan' => 5]);
        $this->field($event, ['status' => 'nonaktif']);

        $url = "/api/events/{$event->slug}";
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.formFields.1.ticket_type_ids', [$a->id, $b->id])
            ->assertJsonPath('data.formFields.2.ticket_type_id', $a->id)
            ->assertJsonPath('data.formFields.2.ticket_type_ids', null);
        foreach ([
            [$a, [$global->id, $multi->id, $legacy->id, $eventWide->id]],
            [$b, [$global->id, $multi->id, $eventWide->id]],
            [$c, [$global->id, $eventWide->id]],
        ] as [$ticket, $expected]) {
            $response = $this->getJson("$url/tiket/{$ticket->slug}")->assertOk()
                ->assertJsonPath('data.tiket.0.id', $ticket->id);
            $this->assertSame($expected, array_column($response->json('data.formFields'), 'id'));
        }
    }

    public function test_checkout_requires_an_answer_for_each_matching_ticket_type_in_a_mixed_cart(): void
    {
        Queue::fake();
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $a = $this->createTicketType($event, ['harga' => 0]);
        $b = $this->createTicketType($event, ['harga' => 0]);
        $c = $this->createTicketType($event, ['harga' => 0]);
        $unselected = $this->createTicketType($event, ['harga' => 0]);
        $multi = $this->field($event, ['ticket_type_ids' => [$a->id, $b->id, $unselected->id]]);
        $this->field($event, ['ticket_type_id' => $c->id, 'label' => 'Legacy', 'key' => 'legacy']);
        $this->field($event, ['event_id' => null, 'label' => 'Instagram', 'key' => 'instagram', 'tipe' => 'sosial']);

        $payload = [
            'event_id' => $event->id, 'nama' => 'Buyer', 'whatsapp' => '08123456789',
            'email' => 'buyer@example.com', 'instagram' => '@buyer',
            'items' => [
                ['ticket_type_id' => $a->id, 'jumlah' => 2],
                ['ticket_type_id' => $b->id, 'jumlah' => 1],
                ['ticket_type_id' => $c->id, 'jumlah' => 1],
            ],
            'form_data' => ['tickets' => [$a->id => ['Club' => 'Alpha'], $c->id => ['Legacy' => 'Yes']]],
        ];

        $this->postJson('/api/orders', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors("form_data.tickets.{$b->id}.{$multi->id}");
        $this->assertDatabaseCount('orders', 0);
        $payload['form_data']['tickets'][$b->id] = ['Club' => 'Beta'];
        $this->postJson('/api/orders', $payload)->assertCreated()->assertJsonPath('data.status', 'lunas');
        $this->assertEquals($payload['form_data'], Order::sole()->form_data);
    }

    private function field(Event $event, array $attributes): FormField
    {
        return FormField::create($attributes + [
            'organizer_id' => $event->organizer_id, 'event_id' => $event->id,
            'label' => 'Club', 'key' => 'club', 'tipe' => 'teks', 'wajib' => true,
        ]);
    }
}
