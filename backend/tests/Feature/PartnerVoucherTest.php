<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organizer;
use App\Models\TicketType;
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
            'min_pembelian' => 500000,
            'kuota' => 100,
            'status' => 'aktif',
        ]);

        $res->assertCreated();
        $this->assertSame('DISKON50K', $res->json('data.kode'));
        $this->assertEquals(500000, $res->json('data.min_pembelian'));

        $this->assertDatabaseHas('vouchers', [
            'organizer_id' => $org->id,
            'kode' => 'DISKON50K',
            'nilai' => 50000,
            'min_pembelian' => 500000,
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

    public function test_voucher_with_minimum_purchase_validates_subtotal(): void
    {
        [$user, $org] = $this->actingPartner();
        $event = $this->createEvent($org);

        Voucher::create([
            'organizer_id' => $org->id,
            'kode' => 'MIN500',
            'tipe_diskon' => VoucherType::NOMINAL,
            'nilai' => 50000,
            'min_pembelian' => 500000,
            'status' => VoucherStatus::ACTIVE,
        ]);

        // Coba subtotal Rp 300.000 (di bawah syarat) -> ditolak
        $resGagal = $this->postJson('/api/vouchers/validate', [
            'kode' => 'MIN500',
            'event_id' => $event->id,
            'subtotal' => 300000,
        ]);
        $resGagal->assertStatus(422);
        $resGagal->assertJsonValidationErrors('kode');
        $this->assertStringContainsString('500.000', $resGagal->json('errors.kode.0'));

        // Coba subtotal Rp 500.000 (memenuhi syarat) -> berhasil
        $resSukses = $this->postJson('/api/vouchers/validate', [
            'kode' => 'MIN500',
            'event_id' => $event->id,
            'subtotal' => 500000,
        ]);
        $resSukses->assertOk();
        $this->assertEquals(50000, $resSukses->json('diskon'));
    }

    public function test_voucher_index_reports_usage_from_paid_orders_only(): void
    {
        [$user, $org] = $this->actingPartner();
        $event = $this->createEvent($org);

        $voucher = Voucher::create([
            'organizer_id' => $org->id,
            'event_id' => $event->id,
            'kode' => 'PAKAI10',
            'tipe_diskon' => VoucherType::NOMINAL,
            'nilai' => 10000,
            'kuota' => 10,
            'status' => VoucherStatus::ACTIVE,
        ]);

        $tiket = $this->createTicketType($event, ['nama_tiket' => 'Reguler', 'harga' => 100000]);

        // Budi beli 2 tiket, lalu beli lagi 1 tiket -> 1 pembeli, 2 pesanan, 3 tiket.
        $this->buatOrder($event, $tiket, $voucher, OrderStatus::PAID, 'budi@nontix.id', 2);
        $this->buatOrder($event, $tiket, $voucher, OrderStatus::PAID, 'BUDI@nontix.id', 1);

        // Siti 1 pesanan 1 tiket -> pembeli kedua.
        $this->buatOrder($event, $tiket, $voucher, OrderStatus::PAID, 'siti@nontix.id', 1);

        // Pending dan dibatalkan tidak boleh dihitung sebagai pembeli lunas.
        $this->buatOrder($event, $tiket, $voucher, OrderStatus::PENDING, 'tono@nontix.id', 1);
        $this->buatOrder($event, $tiket, $voucher, OrderStatus::CANCELLED, 'ani@nontix.id', 3);

        $res = $this->getJson('/api/partner/vouchers');
        $res->assertOk();

        $pemakaian = $res->json('data.0.pemakaian');

        $this->assertSame(2, $pemakaian['pembeli']);
        $this->assertSame(3, $pemakaian['pesanan_lunas']);
        $this->assertSame(4, $pemakaian['tiket']);
        $this->assertSame(30000.0, (float) $pemakaian['total_diskon']);
        $this->assertSame(1, $pemakaian['menunggu_bayar']);
    }

    public function test_voucher_index_reports_zero_usage_when_never_used(): void
    {
        [$user, $org] = $this->actingPartner();

        Voucher::create([
            'organizer_id' => $org->id,
            'kode' => 'BELUMPAKAI',
            'tipe_diskon' => VoucherType::NOMINAL,
            'nilai' => 5000,
        ]);

        $res = $this->getJson('/api/partner/vouchers');
        $res->assertOk();

        $pemakaian = $res->json('data.0.pemakaian');

        $this->assertSame(0, $pemakaian['pembeli']);
        $this->assertSame(0, $pemakaian['pesanan_lunas']);
        $this->assertSame(0, $pemakaian['tiket']);
        $this->assertEquals(0, $pemakaian['total_diskon']);
        $this->assertSame(0, $pemakaian['menunggu_bayar']);
    }

    private function buatOrder(
        Event $event,
        TicketType $tiket,
        Voucher $voucher,
        OrderStatus $status,
        string $email,
        int $jumlah,
    ): void {
        $subtotal = $jumlah * 100000;

        $order = Order::create([
            'event_id' => $event->id,
            'nama_pembeli' => 'Pembeli '.$email,
            'email' => $email,
            'whatsapp' => '08120000000',
            'voucher_id' => $voucher->id,
            'subtotal' => $subtotal,
            'diskon' => 10000,
            'total_harga' => $subtotal - 10000,
            'biaya_layanan' => 2000 * $jumlah,
            'status' => $status,
            'batas_bayar' => now()->addMinutes(30),
            'paid_at' => $status === OrderStatus::PAID ? now() : null,
        ]);

        $order->items()->create([
            'ticket_type_id' => $tiket->id,
            'jumlah' => $jumlah,
            'harga_satuan' => 100000,
            'subtotal' => $subtotal,
        ]);
    }
}
