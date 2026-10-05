<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Services\CheckinService;
use App\Services\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Queue::fake();

        $this->admin();

        [$suara, $suaraUser] = $this->organizer('Suara Nusantara Live', 'partner@nontix.id', 'Rangga Wibowo', 'terverifikasi');
        [$arena, $arenaUser] = $this->organizer('Arena Sports ID', 'arena@nontix.id', 'Dimas Prakoso', 'terverifikasi');
        [$kampus, $kampusUser] = $this->organizer('Kampus Kreatif', 'hello@kampuskreatif.id', 'Sinta Maharani', 'pending');

        $konser = $this->eventKonser($suara);
        $this->eventRun($arena);
        $this->eventSeminar($kampus);
        $festivalKopi = $this->eventFestivalKopi($suara);

        $this->masterData($suara);
        $this->vouchers($suara, $konser);

        $this->orders($konser, $festivalKopi);

        $this->command->info('Seeding selesai.');
    }

    private function admin(): User
    {
        return User::create([
            'nama' => 'Admin Nontix',
            'email' => 'admin@nontix.id',
            'password' => 'admin123',
            'peran' => Role::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);
    }

    /**
     * @return array{0: Organizer, 1: User}
     */
    private function organizer(string $nama, string $email, string $penanggungJawab, string $verifikasi): array
    {
        $organizer = Organizer::create([
            'nama_penyelenggara' => $nama,
            'email' => $email,
            'telepon' => '0812-0000-0000',
            'deskripsi' => "Penyelenggara acara {$nama}.",
            'status_verifikasi' => $verifikasi,
            'status_akun' => 'aktif',
        ]);

        $user = User::create([
            'nama' => $penanggungJawab,
            'email' => $email,
            'password' => 'demo123',
            'peran' => Role::PARTNER,
            'organizer_id' => $organizer->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $organizer->update(['user_id' => $user->id]);

        $organizer->legal()->create([
            'nama_penanggung_jawab' => $penanggungJawab,
            'no_identitas' => '3174'.random_int(1000000000, 9999999999),
            'tipe_dokumen' => 'KTP',
            'status' => $verifikasi === 'terverifikasi' ? 'terverifikasi' : 'pending',
        ]);

        return [$organizer, $user];
    }

    private function eventKonser(Organizer $organizer): Event
    {
        $event = Event::create([
            'organizer_id' => $organizer->id,
            'nama_event' => 'Konser Senja Nusantara 2026',
            'slug' => 'konser-senja-nusantara',
            'kategori' => 'Konser',
            'deskripsi' => 'Malam penuh musik dengan deretan musisi nasional dalam satu panggung megah di bawah langit senja Jakarta.',
            'hero_image_url' => 'https://picsum.photos/seed/konser-senja/1200/700',
            'tanggal_mulai' => now()->addDays(5)->setTime(19, 30),
            'tanggal_selesai' => now()->addDays(6)->setTime(23, 0),
            'lokasi' => 'Istora Senayan, Jakarta',
            'syarat_ketentuan' => "1. Tiket berlaku untuk satu orang.\n2. Dilarang membawa senjata tajam.\n3. E-ticket wajib ditunjukkan saat masuk.",
            'status' => 'aktif',
        ]);

        $day1 = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Malam Pembuka',
            'tanggal_mulai' => now()->addDays(5)->setTime(16, 0),
            'tanggal_selesai' => now()->addDays(5)->setTime(23, 0),
            'lokasi' => 'Istora Senayan, Jakarta', 'kapasitas' => 2920, 'urutan' => 1,
        ]);
        $day2 = $event->sessions()->create([
            'label' => 'Day 2', 'nama_session' => 'Malam Puncak',
            'tanggal_mulai' => now()->addDays(6)->setTime(16, 0),
            'tanggal_selesai' => now()->addDays(6)->setTime(23, 0),
            'lokasi' => 'Istora Senayan, Jakarta', 'kapasitas' => 2920, 'urutan' => 2,
        ]);

        $this->ticket($event, 'Festival Day 1 (Standing)', 250000, 2000, 620, 6, [$day1->id]);
        $this->ticket($event, 'Tribun Day 1 (Seated)', 450000, 800, 145, 4, [$day1->id]);
        $this->ticket($event, 'VIP Day 1 (Front Row)', 1250000, 120, 0, 2, [$day1->id]);
        $this->ticket($event, 'Festival Day 2 (Standing)', 300000, 2000, 880, 6, [$day2->id]);
        $this->ticket($event, 'Tribun Day 2 (Seated)', 500000, 800, 320, 4, [$day2->id]);
        $this->ticket($event, 'VIP Day 2 (Front Row)', 1350000, 120, 36, 2, [$day2->id]);

        $this->ticket($event, '2-Day Pass', 400000, 500, 264, 4, [$day1->id, $day2->id]);
        $this->ticket($event, 'Full Festival Pass', 1500000, 150, 42, 2, [$day1->id, $day2->id]);

        return $event;
    }

    private function eventRun(Organizer $organizer): Event
    {
        $event = Event::create([
            'organizer_id' => $organizer->id,
            'nama_event' => 'Nontix Run 10K & Half Marathon',
            'slug' => 'nontix-run',
            'kategori' => 'Olahraga',
            'deskripsi' => 'Lari bersama ribuan peserta di rute tepi pantai dengan race pack lengkap.',
            'hero_image_url' => 'https://picsum.photos/seed/maraton-run/1200/700',
            'tanggal_mulai' => now()->addDays(14)->setTime(5, 0),
            'tanggal_selesai' => now()->addDays(14)->setTime(11, 0),
            'lokasi' => 'Pantai Indah Ancol, Jakarta',
            'syarat_ketentuan' => "1. Usia minimal 15 tahun.\n2. Wajib mengenakan nomor BIB.",
            'status' => 'aktif',
        ]);

        $this->ticket($event, '10K Run', 175000, 3000, 1180, 5);
        $this->ticket($event, '21K Half Marathon', 325000, 1500, 410, 3);

        return $event;
    }

    private function eventSeminar(Organizer $organizer): Event
    {
        $event = Event::create([
            'organizer_id' => $organizer->id,
            'nama_event' => 'Seminar Digital Marketing & AI 2026',
            'slug' => 'seminar-digital-marketing',
            'kategori' => 'Seminar',
            'deskripsi' => 'Belajar strategi pemasaran digital terbaru bersama praktisi.',
            'hero_image_url' => 'https://picsum.photos/seed/seminar-digital/1200/700',
            'tanggal_mulai' => now()->addDays(21)->setTime(9, 0),
            'tanggal_selesai' => now()->addDays(21)->setTime(16, 0),
            'lokasi' => 'Auditorium Universitas Merdeka, Bandung',
            'syarat_ketentuan' => "1. Tiket termasuk materi dan sertifikat.\n2. Tidak ada pengembalian dana.",
            'status' => 'aktif',
        ]);

        $this->ticket($event, 'Early Bird', 99000, 200, 0, 2);
        $this->ticket($event, 'Reguler', 175000, 500, 233, 4);
        $this->ticket($event, 'Grup (5 orang)', 700000, 60, 18, 1);

        return $event;
    }

    private function eventFestivalKopi(Organizer $organizer): Event
    {
        $event = Event::create([
            'organizer_id' => $organizer->id,
            'nama_event' => 'Festival Kopi Archipelago',
            'slug' => 'festival-kopi-archipelago',
            'kategori' => 'Pameran',
            'deskripsi' => 'Jelajahi puluhan booth kopi nusantara, kelas manual brew, dan talkshow barista juara.',
            'hero_image_url' => 'https://picsum.photos/seed/festival-kopi/1200/700',
            'tanggal_mulai' => now()->addDays(40)->setTime(10, 0),
            'tanggal_selesai' => now()->addDays(42)->setTime(21, 0),
            'lokasi' => 'Grand City Convention Hall, Surabaya',
            'syarat_ketentuan' => "1. Tiket berlaku untuk tanggal terpilih.\n2. Anak di bawah 5 tahun gratis.",
            'status' => 'aktif',
        ]);

        $days = [];
        for ($i = 0; $i < 3; $i++) {
            $days[] = $event->sessions()->create([
                'label' => 'Day '.($i + 1),
                'nama_session' => 'Festival Hari '.($i + 1),
                'tanggal_mulai' => now()->addDays(40 + $i)->setTime(10, 0),
                'tanggal_selesai' => now()->addDays(40 + $i)->setTime(21, 0),
                'lokasi' => 'Grand City Convention Hall, Surabaya',
                'kapasitas' => 1500,
                'urutan' => $i + 1,
            ]);
        }

        $this->ticket($event, 'Harian (Weekday)', 50000, 1500, 1240, 8, [$days[0]->id]);
        $this->ticket($event, 'Harian (Weekend)', 75000, 2000, 1890, 8, [$days[1]->id, $days[2]->id]);
        $this->ticket($event, 'Pass 3 Hari', 150000, 500, 322, 4, array_map(fn ($s) => $s->id, $days));

        return $event;
    }

    /**
     * @param  array<int, int>  $sessionIds
     */
    private function ticket(Event $event, string $nama, float $harga, int $kuota, int $sisa, int $max, array $sessionIds = []): void
    {
        $ticketType = $event->ticketTypes()->create([
            'nama_tiket' => $nama,
            'harga' => $harga,
            'kuota' => $kuota,
            'sisa_kuota' => $sisa,
            'max_per_order' => $max,
            'is_bundle' => count($sessionIds) > 1,
            'status' => 'aktif',
        ]);

        if ($sessionIds) {
            $ticketType->sessions()->sync($sessionIds);
        }
    }

    private function masterData(Organizer $organizer): void
    {
        $gateA = $organizer->gates()->create(['nama_gate' => 'Gate A - Utama', 'status' => 'aktif']);
        $gateB = $organizer->gates()->create(['nama_gate' => 'Gate B - VIP', 'status' => 'aktif']);
        $organizer->gates()->create(['nama_gate' => 'Gate C - Belakang', 'status' => 'nonaktif']);

        $organizer->staffs()->create(['nama' => 'Rizky Pratama', 'email' => 'rizky@nontix.id', 'peran' => 'Koordinator Gate', 'gate_id' => $gateA->id]);
        $organizer->staffs()->create(['nama' => 'Dewi Anggraini', 'email' => 'dewi@nontix.id', 'peran' => 'Scanner', 'gate_id' => $gateA->id]);
        $organizer->staffs()->create(['nama' => 'Bagus Setiawan', 'email' => 'bagus@nontix.id', 'peran' => 'Scanner', 'gate_id' => $gateB->id]);

        foreach ([
            ['label' => 'Instagram', 'key' => 'instagram', 'tipe' => 'sosial', 'urutan' => 1],
            ['label' => 'TikTok', 'key' => 'tiktok', 'tipe' => 'sosial', 'urutan' => 2],
            ['label' => 'Threads', 'key' => 'threads', 'tipe' => 'sosial', 'urutan' => 3],
        ] as $field) {
            $organizer->formFields()->create($field + ['wajib' => false, 'status' => 'aktif']);
        }

        $konser = Event::where('slug', 'konser-senja-nusantara')->first();
        if ($konser) {
            $konser->seatPlan()->create([
                'nama_denah' => 'Istora - Layout Utama',
                'gambar_url' => 'https://picsum.photos/seed/nontix-denah/1200/640',
                'rows' => [
                    ['baris' => 'A', 'kategori' => 'VIP', 'seats' => collect(range(1, 8))->map(fn ($i) => ['kode' => "A{$i}", 'status' => $i % 5 === 0 ? 'terisi' : 'tersedia'])->all()],
                    ['baris' => 'B', 'kategori' => 'Tribun', 'seats' => collect(range(1, 12))->map(fn ($i) => ['kode' => "B{$i}", 'status' => $i % 4 === 0 ? 'terisi' : 'tersedia'])->all()],
                ],
            ]);
        }
    }

    private function vouchers(Organizer $organizer, Event $konser): void
    {
        $organizer->vouchers()->create([
            'kode' => 'NONTIX50', 'tipe_diskon' => 'nominal', 'nilai' => 50000,
            'kuota' => 100, 'terpakai' => 0, 'berlaku_sampai' => now()->addDays(60), 'status' => 'aktif',
        ]);

        $organizer->vouchers()->create([
            'kode' => 'HEMAT10', 'tipe_diskon' => 'persen', 'nilai' => 10,
            'kuota' => 500, 'terpakai' => 0, 'event_id' => $konser->id,
            'berlaku_sampai' => now()->addDays(30), 'status' => 'aktif',
        ]);
    }

    private function orders(Event $konser, Event $festivalKopi): void
    {
        $orderService = app(OrderService::class);
        $checkinService = app(CheckinService::class);

        $day1 = $konser->sessions()->where('label', 'Day 1')->first();
        $day2 = $konser->sessions()->where('label', 'Day 2')->first();
        $gateA = $konser->organizer->gates()->where('nama_gate', 'Gate A - Utama')->first();

        $festivalDay1 = $konser->ticketTypes()->where('nama_tiket', 'Festival Day 1 (Standing)')->first();
        $tribunDay1 = $konser->ticketTypes()->where('nama_tiket', 'Tribun Day 1 (Seated)')->first();
        $bundle = $konser->ticketTypes()->where('nama_tiket', '2-Day Pass')->first();
        $fullPass = $konser->ticketTypes()->where('nama_tiket', 'Full Festival Pass')->first();

        $hemat = $konser->organizer->vouchers()->where('kode', 'HEMAT10')->first();

        // Order 1: 2x Tribun Day 1 dengan voucher, lunas, satu sudah check-in.
        $order1 = $orderService->create($konser, [
            'nama' => 'Andini Putri', 'email' => 'andini@mail.com', 'whatsapp' => '0812-1111-2222',
            'instagram' => '@andini',
        ], [
            ['ticket_type_id' => $tribunDay1->id, 'jumlah' => 2],
        ], $hemat->kode);
        $orderService->markPaid($order1);
        $pass = $order1->fresh()->tickets->first()->passes->first();
        $checkinService->scan($konser, $day1, $pass->kode_qr, $gateA, null);

        // Order 2: Full Festival Pass (bundle), lunas.
        $order2 = $orderService->create($konser, [
            'nama' => 'Bimo Saputra', 'email' => 'bimo@mail.com', 'whatsapp' => '0813-3333-4444',
        ], [
            ['ticket_type_id' => $fullPass->id, 'jumlah' => 1],
        ]);
        $orderService->markPaid($order2);
        $bundlePass = $order2->fresh()->tickets->first()->passes->where('event_session_id', $day1->id)->first();
        if ($bundlePass) {
            $checkinService->scan($konser, $day1, $bundlePass->kode_qr, $gateA, null);
        }

        // Order 3: 2x 2-Day Pass, lunas.
        $order3 = $orderService->create($konser, [
            'nama' => 'Citra Lestari', 'email' => 'citra@mail.com', 'whatsapp' => '0857-5555-6666',
        ], [
            ['ticket_type_id' => $bundle->id, 'jumlah' => 2],
        ]);
        $orderService->markPaid($order3);

        // Order 4: Festival Day 1 (belum dibayar / pending).
        $orderService->create($konser, [
            'nama' => 'Dimas Aditya', 'email' => 'dimas@mail.com', 'whatsapp' => '0878-7777-8888',
        ], [
            ['ticket_type_id' => $festivalDay1->id, 'jumlah' => 2],
        ]);

        // Order 5: Festival Kopi Pass 3 Hari (bundle 3 hari), lunas + check-in hari 1.
        $pass3 = $festivalKopi->ticketTypes()->where('nama_tiket', 'Pass 3 Hari')->first();
        $kopiDay1 = $festivalKopi->sessions()->where('label', 'Day 1')->first();
        $kopiGate = $festivalKopi->organizer->gates()->first();

        $order5 = $orderService->create($festivalKopi, [
            'nama' => 'Eka Wijaya', 'email' => 'eka@mail.com', 'whatsapp' => '0812-9999-0000',
        ], [
            ['ticket_type_id' => $pass3->id, 'jumlah' => 1],
        ]);
        $orderService->markPaid($order5);
        $kopiPass = $order5->fresh()->tickets->first()->passes->where('event_session_id', $kopiDay1->id)->first();
        if ($kopiPass) {
            $checkinService->scan($festivalKopi, $kopiDay1, $kopiPass->kode_qr, $kopiGate, null);
        }
    }
}
