<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Voucher;
use App\Enums\VoucherType;
use App\Enums\VoucherStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class PartnerVoucherTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithNontix;

    private function actingPartner(): array
    {
        [$user, $organizer] = $this->createPartner();
        Sanctum::actingAs($user);

        return [$user, $organizer];
    }

    public function test_partner_can_list_own_vouchers_only(): void
    {
        [$userA, $orgA] = $this->actingPartner();

        Voucher::create([
            'organizer_id' => $orgA->id,
            'kode' => 'PROMOA',
            'tipe_diskon' => VoucherType::NOMINAL,
            'nilai' => 10000,
            'kuota' => 50,
            'status' => VoucherStatus::ACTIVE,
        ]);

        [$userB, $orgB] = $this->createPartner();
        Voucher::create([
            'organizer_id' => $orgB->id,
            'kode' => 'PROMOB',
            'tipe_diskon' => VoucherType::NOMINAL,
            'nilai' => 15000,
            'kuota' => 10,
            'status' => VoucherStatus::ACTIVE,
        ]);

        $res = $this->getJson('/api/partner/vouchers');
        $res->assertOk();
        $data = $res->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('PROMOA', $data[0]['kode']);
    }

    public function test_partner_can_create_voucher(): void
    {
        [$user, $org] = $this->actingPartner();

        $res = $this->postJson('/api/partner/vouchers', [
            'kode' => 'diskon50k',
            'tipe_diskon' => 'nominal',
            'nilai' => 50000,
            'kuota' => 100,
            'status' => 'aktif',
        ]);

        $res->assertCreated();
        $this->assertSame('DISKON50K', $res->json('data.kode'));

        $this->assertDatabaseHas('vouchers', [
            'organizer_id' => $org->id,
            'kode' => 'DISKON50K',
            'nilai' => 50000,
            'kuota' => 100,
        ]);
    }

    public function test_percent_discount_cannot_exceed_100(): void
    {
        $this->actingPartner();

        $res = $this->postJson('/api/partner/vouchers', [
            'kode' => 'DISKON150',
            'tipe_diskon' => 'persen',
            'nilai' => 150,
            'kuota' => 10,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('nilai');
    }

    public function test_duplicate_voucher_code_is_rejected(): void
    {
        [$user, $org] = $this->actingPartner();

        Voucher::create([
            'organizer_id' => $org->id,
            'kode' => 'SAMA10',
            'tipe_diskon' => VoucherType::NOMINAL,
            'nilai' => 10000,
        ]);

        $res = $this->postJson('/api/partner/vouchers', [
            'kode' => 'SAMA10',
            'tipe_diskon' => 'nominal',
            'nilai' => 20000,
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('kode');
    }

    public function test_partner_can_update_own_voucher(): void
    {
        [$user, $org] = $this->actingPartner();

        $voucher = Voucher::create([
            'organizer_id' => $org->id,
            'kode' => 'EDITME',
            'tipe_diskon' => VoucherType::NOMINAL,
            'nilai' => 10000,
            'kuota' => 10,
        ]);

        $res = $this->putJson("/api/partner/vouchers/{$voucher->id}", [
            'kuota' => 50,
            'nilai' => 20000,
            'status' => 'nonaktif',
        ]);

        $res->assertOk();
        $this->assertSame(50, $res->json('data.kuota'));
        $this->assertSame('nonaktif', $res->json('data.status'));
    }

    public function test_partner_cannot_modify_or_delete_other_partner_voucher(): void
    {
        [$userA, $orgA] = $this->actingPartner();

        [$userB, $orgB] = $this->createPartner();
        $voucherB = Voucher::create([
            'organizer_id' => $orgB->id,
            'kode' => 'VOUCHERB',
            'tipe_diskon' => VoucherType::NOMINAL,
            'nilai' => 10000,
        ]);

        $resUpdate = $this->putJson("/api/partner/vouchers/{$voucherB->id}", ['kuota' => 999]);
        $resUpdate->assertStatus(403);

        $resDelete = $this->deleteJson("/api/partner/vouchers/{$voucherB->id}");
        $resDelete->assertStatus(403);
    }

    public function test_global_voucher_cannot_be_used_on_different_organizer_event(): void
    {
        [$userA, $orgA] = $this->actingPartner();

        // Voucher org A tanpa event_id (berlaku semua event org A)
        $voucher = Voucher::create([
            'organizer_id' => $orgA->id,
            'event_id' => null,
            'kode' => 'GLOBAL_A',
            'tipe_diskon' => VoucherType::NOMINAL,
            'nilai' => 10000,
            'status' => VoucherStatus::ACTIVE,
        ]);

        // Event milik org B
        [$userB, $orgB] = $this->createPartner();
        $eventB = $this->createEvent($orgB);

        $this->assertFalse($voucher->isUsable($eventB));

        // Tapi harus bisa dipakai untuk event milik org A sendiri
        $eventA = $this->createEvent($orgA);

        $this->assertTrue($voucher->isUsable($eventA));
    }
}
