<?php

namespace Tests\Feature;

use App\Enums\PayoutStatus;
use App\Enums\VoucherStatus;
use App\Enums\VoucherType;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class PartnerSalesPayoutTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
    }

    private function makePaidOrder($event, $ticket, int $jumlah = 2): Order
    {
        Queue::fake();

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $ticket->id, 'jumlah' => $jumlah]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        return Order::where('kode_order', $kode)->firstOrFail();
    }

    public function test_sales_summary_counts_only_paid_orders(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['harga' => 100000, 'kuota' => 10, 'sisa_kuota' => 10]);

        // Order pending: reserved stock, belum lunas.
        $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Ani',
            'email' => 'ani@mail.com',
            'whatsapp' => '0812',
            'items' => [['ticket_type_id' => $ticket->id, 'jumlah' => 1]],
        ])->assertCreated();

        // Order lunas: 2 tiket.
        $this->makePaidOrder($event, $ticket, 2);

        Sanctum::actingAs($user);

        $this->getJson('/api/partner/sales')
            ->assertOk()
            ->assertJsonPath('summary.total_terjual', 2)
            ->assertJsonPath('summary.total_pendapatan', 200000);

        // Ringkasan keuangan juga menampilkan pending terpisah.
        $this->getJson('/api/partner/finance')
            ->assertOk()
            ->assertJsonPath('summary.tiket_terjual', 2)
            ->assertJsonPath('summary.order_pending', 1);
    }

    public function test_sales_revenue_follows_the_discount_applied_to_orders(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['harga' => 100000, 'kuota' => 20, 'sisa_kuota' => 20]);

        // Voucher 100%: pembeli tidak membayar apa pun, jadi pendapatannya Rp0.
        Voucher::create([
            'organizer_id' => $organizer->id,
            'event_id' => $event->id,
            'kode' => 'GRATIS',
            'tipe_diskon' => VoucherType::PERCENT,
            'nilai' => 100,
            'kuota' => 10,
            'status' => VoucherStatus::ACTIVE,
        ]);

        Queue::fake();

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Sinta',
            'email' => 'sinta@mail.com',
            'whatsapp' => '0812',
            'voucher_code' => 'GRATIS',
            'items' => [['ticket_type_id' => $ticket->id, 'jumlah' => 2]],
        ])->assertCreated()->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertSame(200000.0, (float) $order->subtotal);
        $this->assertSame(200000.0, (float) $order->diskon);
        $this->assertSame(0.0, (float) $order->total_harga);

        // Order berbayar biasa sebagai pembanding: 1 tiket, tanpa voucher.
        $this->makePaidOrder($event, $ticket, 1);

        Sanctum::actingAs($user);

        $sales = $this->getJson('/api/partner/sales')->assertOk();

        // 3 tiket terjual; nilainya hanya tiket yang benar-benar dibayar.
        $sales->assertJsonPath('summary.total_terjual', 3)
            ->assertJsonPath('summary.total_pendapatan', 100000)
            ->assertJsonPath('summary.total_diskon', 200000)
            ->assertJsonPath('data.0.terjual', 3)
            ->assertJsonPath('data.0.pendapatan', 100000)
            ->assertJsonPath('data.0.diskon', 200000);

        // Harus sama dengan halaman keuangan supaya klien tidak bingung.
        $this->getJson('/api/partner/finance')
            ->assertOk()
            ->assertJsonPath('summary.pendapatan_kotor', $sales->json('summary.total_pendapatan'));
    }

    public function test_partner_can_request_payout_only_with_bank_and_sufficient_balance(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['harga' => 100000, 'kuota' => 10, 'sisa_kuota' => 10]);
        $this->makePaidOrder($event, $ticket, 2);

        Sanctum::actingAs($user);

        // Tanpa rekening: ditolak.
        $this->postJson('/api/partner/payouts', ['jumlah' => 100000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rekening');

        $organizer->update([
            'nama_bank' => 'BCA',
            'nomor_rekening' => '1234567890',
            'nama_rekening' => 'Test Organizer',
        ]);

        // Saldo bersih = 200.000 - (2 x 2.000) = 196.000.
        $this->getJson('/api/partner/payouts')
            ->assertOk()
            ->assertJsonPath('ringkasan.saldo_tersedia', 196000);

        $this->postJson('/api/partner/payouts', ['jumlah' => 50000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'diajukan');

        // Melebihi saldo: ditolak.
        $this->postJson('/api/partner/payouts', ['jumlah' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('jumlah');
    }

    public function test_admin_can_complete_payout_and_partner_sees_history(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['harga' => 100000, 'kuota' => 10, 'sisa_kuota' => 10]);
        $this->makePaidOrder($event, $ticket, 2);

        $organizer->update([
            'nama_bank' => 'BCA',
            'nomor_rekening' => '1234567890',
            'nama_rekening' => 'Test Organizer',
        ]);

        Sanctum::actingAs($user);
        $payoutId = $this->postJson('/api/partner/payouts', ['jumlah' => 50000])->json('data.id');

        Sanctum::actingAs($this->createAdmin());
        $this->getJson('/api/admin/payouts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $payoutId);

        $this->putJson("/api/admin/payouts/{$payoutId}", [
            'status' => 'selesai',
            'catatan_admin' => 'Transfer via BCA',
        ])->assertOk();

        $this->assertSame(PayoutStatus::SELESAI, Payout::find($payoutId)->status);
    }

    public function test_partner_can_upload_image_to_public_storage(): void
    {
        Storage::fake('public');
        [$user] = $this->createPartner();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/partner/uploads', [
            'file' => UploadedFile::fake()->image('tiket.jpg', 640, 360),
            'folder' => 'tickets',
        ])->assertCreated();

        $path = $res->json('data.path');
        $this->assertStringStartsWith('tickets/', $path);
        Storage::disk('public')->assertExists($path);
    }
}