# Nontix Backend (Laravel 12 REST API)

Backend REST API untuk platform tiket **Nontix**: autentikasi & otorisasi per peran (admin platform, partner, staff), logika order + voucher + pembayaran (Mayar), e-ticket dengan QR per hari, check-in/scan per sesi, laporan, serta Queue & Scheduler untuk email dan pembatalan order otomatis.

## Tech Stack

- Laravel 12 (PHP ^8.2)
- MySQL / MariaDB
- Laravel Sanctum (token API)
- Queue driver `database`, Scheduler (`routes/console.php`)
- Integrasi: Mayar Invoice API (fake/live), Mailketing (fake/live)

## Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Siapkan database MySQL
# CREATE DATABASE nontix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

php artisan migrate:fresh --seed
php artisan storage:link   # wajib: agar gambar tiket/event/logo bisa diakses publik
```

Konfigurasi penting di `.env`:

| Key | Default | Keterangan |
| --- | --- | --- |
| `DB_*` | mysql / nontix | koneksi database |
| `NONTIX_SERVICE_FEE` | 2000 | biaya layanan per tiket |
| `NONTIX_ORDER_EXPIRY_MINUTES` | 60 | batas bayar order |
| `NONTIX_MIN_PAYOUT` | 50000 | minimal saldo bersih untuk pengajuan pencairan partner |
| `MAYAR_MODE` | fake | `fake` = simulasi, `live` = panggil API Mayar |
| `MAILKETING_MODE` | fake | `fake` = tulis log, `live` = kirim via SMTP |

### Menjalankan

```bash
php artisan serve                 # API di http://localhost:8000
php artisan queue:work            # worker email invoice/e-ticket
php artisan schedule:work         # scheduler pembatalan order kadaluarsa
```

## Akun Demo (hasil seeder)

| Peran | Email | Password |
| --- | --- | --- |
| Admin Platform | `admin@nontix.id` | `admin123` |
| Partner (Suara Nusantara Live) | `partner@nontix.id` | `demo123` |
| Partner (Arena Sports ID) | `arena@nontix.id` | `demo123` |
| Partner (Kampus Kreatif) | `hello@kampuskreatif.id` | `demo123` |

## Autentikasi

Token via Sanctum. Kirim header `Authorization: Bearer <token>`.

```
POST /api/auth/partner/register   {nama,email,password,nama_penyelenggara,telepon?}
POST /api/auth/login              {email,password,peran?}
GET  /api/auth/me
POST /api/auth/logout
```

## Endpoint Publik

```
GET  /api/events                          # daftar event aktif (?q=&kategori=&lokasi=)
GET  /api/events/{slug}                   # detail event + sessions + tiket
POST /api/vouchers/validate               # {kode,event_id,subtotal?}
POST /api/orders                          # checkout
GET  /api/orders/{kodeOrder}
GET  /api/tickets?kode_order= | ?email=
POST /api/tickets/resend                  # kirim ulang e-ticket
POST /api/payments/callback               # webhook Mayar
GET  /api/payments/{kodeOrder}/status
GET  /api/payments/{kodeOrder}/fake       # simulasi bayar (mode fake)
```

Contoh checkout:

```json
{
  "event_id": 1,
  "nama": "Budi",
  "email": "budi@mail.com",
  "whatsapp": "08123456789",
  "voucher_code": "HEMAT10",
  "items": [
    { "ticket_type_id": 7, "jumlah": 1 },
    { "ticket_type_id": 2, "jumlah": 2 }
  ]
}
```

## Endpoint Partner (role: partner)

```
GET/POST/PUT/DELETE /api/partner/events[/{event}]
POST   /api/partner/events/{event}/sessions
PUT    /api/partner/events/{event}/sessions/{session}
DELETE /api/partner/events/{event}/sessions/{session}

GET/POST/PUT/DELETE /api/partner/ticket-types[/{ticketType}]
PUT    /api/partner/ticket-types/{ticketType}/sessions   # atur relasi ke banyak sesi (bundle)

GET/POST/PUT/DELETE /api/partner/vouchers[/{voucher}]
GET/POST/PUT/DELETE /api/partner/gates[/{gate}]
GET/POST/PUT/DELETE /api/partner/staffs[/{staff}]
GET/POST/PUT/DELETE /api/partner/form-fields[/{formField}]

GET/PUT /api/partner/events/{event}/seat-plan
GET/PUT /api/partner/profile
PUT     /api/partner/profile/legal

GET /api/partner/analytics
GET /api/partner/buyers
GET /api/partner/finance           # ringkasan + transaksi lunas + info payout
GET /api/partner/sales             # rekap tiket terjual per jenis tiket (hanya lunas)

GET  /api/partner/payouts          # riwayat pengajuan pencairan
POST /api/partner/payouts          # {jumlah,catatan?} ajukan pencairan (validasi saldo & rekening)
DELETE /api/partner/payouts/{payout}

POST /api/partner/uploads          # multipart: file, folder (tickets|events|logos|seat-plans|proofs)

GET  /api/partner/scan/sessions/{event}
POST /api/partner/scan                    # {event_id,session_id,kode_qr,gate_id?,staff_id?}
GET  /api/partner/attendance              # ?event_id=&session_id=
```

## Endpoint Staff (role: staff/partner)

```
GET  /api/staff/scan/sessions/{event}
POST /api/staff/scan
GET  /api/staff/attendance
```

## Endpoint Admin Platform (role: admin_platform)

```
GET /api/admin/dashboard
GET /api/admin/partners
GET /api/admin/partners/{organizer}
PUT /api/admin/partners/{organizer}       # verifikasi/nonaktifkan mitra
GET /api/admin/events
GET /api/admin/revenue
GET /api/admin/reports
GET /api/admin/payouts                     # daftar pengajuan pencairan mitra
PUT /api/admin/payouts/{payout}            # {status:diproses|selesai|ditolak, catatan_admin?, bukti_transfer_url?}
POST /api/admin/uploads                    # multipart: file (bukti transfer)
```

## Arsitektur Modul

| Modul | Lokasi |
| --- | --- |
| Enum peran/status | `app/Enums` |
| Service order + voucher + kuota | `app/Services/OrderService.php` |
| Service pembayaran Mayar | `app/Services/MayarService.php` |
| Service email Mailketing | `app/Services/MailketingService.php` |
| Service check-in per sesi | `app/Services/CheckinService.php` |
| Laporan/analitik | `app/Services/ReportService.php` |
| Job email invoice/e-ticket | `app/Jobs/SendOrderPaidEmail.php`, `SendResendTicketEmail.php` |
| Job/scheduler kadaluarsa | `app/Jobs/CancelExpiredOrders.php`, command `nontix:expire-orders` |

### Queue & Scheduler

- **Email invoice + e-ticket** dikirim lewat queue (`SendOrderPaidEmail`) saat order ditandai lunas.
- **Kirim ulang tiket** lewat queue (`SendResendTicketEmail`).
- **Pembatalan otomatis** order yang melewati `batas_bayar` dijalankan tiap menit oleh scheduler (`nontix:expire-orders`) yang mengembalikan kuota dan pemakaian voucher.

Produksi (cron):

```
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

## Integrasi Pembayaran (Mayar + Xendit)

Sistem mendukung **dua gateway dengan fallback otomatis**. Urutan diatur lewat
`NONTIX_GATEWAYS` (default `mayar,xendit`):

- Saat checkout, pembeli bisa memilih gateway (bila keduanya aktif).
- Bila gateway pilihan gagal membuat transaksi, otomatis dicoba gateway berikutnya.
- Bila tidak ada yang tersedia, checkout ditolak dengan pesan jelas (order dibatalkan).

### Xendit (Payment Session v3)

- `POST /sessions` mode `PAYMENT_LINK` → mengembalikan `payment_link_url`.
- `GET /sessions/{id}` → verifikasi status (`COMPLETED` + `payment_id`) sebelum menandai lunas.
- `POST /sessions/{id}/cancel` → menutup sesi saat order kadaluarsa.

Aktifkan dengan mengisi `.env`:

```env
NONTIX_GATEWAYS=mayar,xendit
XENDIT_BASE_URL=https://api.xendit.co
XENDIT_SECRET_KEY=xnd_development_...   # test | xnd_production_... untuk live
XENDIT_MODE=test                        # test | live
XENDIT_WEBHOOK_TOKEN=token-rahasia
```

> Mode `live` mensyaratkan kunci `xnd_production_*` — kunci development otomatis
> dianggap nonaktif di mode live.

Daftarkan webhook di **Xendit Dashboard → Settings → Webhooks**:

| Webhook | URL |
| --- | --- |
| Payment Session | `https://apitix.diamcreative.com/api/payments/callback` |

Untuk verifikasi, set **Webhook Verification Token** di dashboard sama dengan
`XENDIT_WEBHOOK_TOKEN`. Token dikirim lewat header `x-callback-token` dan
diverifikasi (juga mendukung `?token=`).

Event yang ditangani: `payment_session.completed` (lunas, diverifikasi ulang ke API)
dan `payment_session.expired` (diabaikan — order tetap pending sampai expiry internal).

## Integrasi Pembayaran Mayar

Implementasi mengikuti [Mayar Invoice API](https://docs.mayar.id/api-reference/invoice/create):

- `POST {MAYAR_BASE_URL}/invoice/create` — membuat invoice saat checkout.
- `GET {MAYAR_BASE_URL}/invoice/{id}` — cek status invoice (dipakai untuk rekonsiliasi).
- Webhook `payment.received` → `POST /api/payments/callback`.

### Mengaktifkan mode live

1. Isi `.env`:
   ```env
   MAYAR_BASE_URL=https://api.mayar.id/hl/v1   # sandbox: https://api.mayar.io/hl/v1
   MAYAR_API_KEY=isi-api-key-mayar
   MAYAR_MODE=live
   ```
2. Daftarkan URL webhook di dashboard Mayar (Integration → Webhook):
   ```
   https://<domain-backend>/api/payments/callback
   ```
3. Jalankan queue + scheduler:
   ```bash
   php artisan queue:work
   php artisan schedule:work
   ```

### Alur

1. Checkout membuat order **pending** + invoice Mayar, mengembalikan `payment_url`, dan **mereservasi kuota** (tiket + QR belum dibuat).
2. Frontend mengalihkan pembeli ke `payment_url` (halaman Mayar).
3. Setelah bayar, Mayar mengirim webhook `payment.received` → order ditandai **lunas**, **tiket + QR dibuat**, lalu email invoice/e-ticket dikirim lewat queue.
4. Bila webhook tidak sampai, pembeli diarahkan kembali ke `/pembayaran/berhasil?order=KODE` yang memanggil `GET /api/payments/{kode}/sync` untuk cek status langsung ke Mayar.
5. Scheduler `nontix:sync-payments` (tiap 2 menit) merekonsiliasi order pending sebagai jaring pengaman.
6. Order yang melewati `batas_bayar` dibatalkan otomatis (`nontix:expire-orders`) dan kuota dikembalikan.

> **Penting:** seluruh laporan (analitik, penjualan, keuangan, dashboard admin) hanya menghitung order berstatus **lunas**. Check-in juga menolak tiket dari order yang belum lunas.

### Pencairan dana partner

- Platform memotong biaya layanan per tiket; sisanya (`pendapatan_bersih`) menjadi saldo partner.
- Partner menyimpan data rekening di menu Profil, lalu mengajukan pencairan (minimal `NONTIX_MIN_PAYOUT`).
- Admin memproses di menu Pencairan Mitra: **diajukan → diproses → selesai** (unggah bukti transfer) atau **ditolak**.
- Saldo tersedia = pendapatan bersih − pengajuan aktif − yang sudah dicairkan.

### Mapping webhook → order

Karena payload webhook tidak selalu memuat kode order, pencocokan dilakukan berurutan:
1. `extraData.noCustomer` (= `kode_order`, dikirim saat create invoice).
2. `paymentLinkId` / invoice id (dicocokkan ke kolom `payments.mayar_invoice_id`).
3. Fallback: `customerEmail` + `amount`.

### Keamanan webhook (token)

Set `MAYAR_CALLBACK_TOKEN` di `.env`. URL webhook otomatis menyertakan token sebagai query string:

```
https://<domain>/api/payments/callback?token=<MAYAR_CALLBACK_TOKEN>
```

Token diverifikasi dari header `X-Callback-Token`, query `?token=`, atau body `token`. Bila token salah → `401`.

### Auto-register webhook (API v2)

Daftarkan URL webhook tanpa membuka dashboard:

```
GET  /api/admin/mayar/webhook     # lihat URL webhook yang akan didaftarkan
POST /api/admin/mayar/webhook     # daftarkan ke Mayar (opsional body: { "url": "..." })
```

Contoh:
```bash
curl -X POST https://<domain>/api/admin/mayar/webhook \
  -H "Authorization: Bearer <token-admin>"
```

### Pembatalan invoice

Saat order dibatalkan/kadaluarsa (`nontix:expire-orders`), invoice Mayar ikut dinonaktifkan dengan mengubah `expiredAt` ke waktu sekarang melalui endpoint `POST /invoice/edit` (`MayarService::voidInvoice`).

## Deploy ke cPanel

### 1. Kebutuhan server
- PHP **8.2+** (set di cPanel → MultiPHP Manager).
- Ekstensi: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `ctype`, `tokenizer`, `xml`, `json`, `bcmath`.
- MySQL/MariaDB (buat database + user di cPanel → MySQL Databases).

### 2. Upload kode
Struktur yang disarankan (aplikasi di luar `public_html`):

```
/home/USER/nontix-backend/     <-- seluruh project Laravel (kecuali public)
/home/USER/public_html/        <-- isi dari folder public/  (atau subdomain: /home/USER/api.domain.com/)
```

Dua cara:
- **A (disarankan):** set Document Root domain/subdomain ke `/home/USER/nontix-backend/public` (cPanel → Domains). Lalu upload project apa adanya.
- **B:** upload semua project ke luar `public_html`, lalu salin isi `public/` ke `public_html/` dan edit `public_html/index.php`:
  ```php
  require __DIR__.'/../nontix-backend/vendor/autoload.php';
  $app = require_once __DIR__.'/../nontix-backend/bootstrap/app.php';
  ```

> `vendor/` boleh di-upload langsung, atau jalankan `composer install --no-dev --optimize-autoloader` via Terminal cPanel bila tersedia.

### 3. Konfigurasi `.env`
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.domainmu.com
APP_KEY=base64:...            # php artisan key:generate

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=cpaneluser_nontix
DB_USERNAME=cpaneluser_nontix
DB_PASSWORD=...

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

NONTIX_FRONTEND_URL=https://domainmu.com
CORS_ALLOWED_ORIGINS=https://domainmu.com,https://www.domainmu.com
NONTIX_MIN_PAYOUT=50000

MAYAR_MODE=live
MAYAR_BASE_URL=https://api.mayar.id/hl/v1
MAYAR_API_KEY=...
MAYAR_CALLBACK_TOKEN=...

MAIL_MAILER=smtp
MAIL_HOST=...        # SMTP Mailketing
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAILKETING_MODE=live
```

### 4. Migrasi & optimize
```bash
php artisan migrate --force      # termasuk pembersihan tiket order lama yang belum lunas
php artisan storage:link         # wajib agar gambar tiket/event/logo tampil publik
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
Pastikan folder `storage/` dan `bootstrap/cache/` writable (permission 755/775).

### 5. Cron (Queue + Scheduler)
Shared hosting tidak punya daemon `queue:work`. Tambahkan 2 cron job di cPanel (ganti path PHP sesuai MultiPHP, mis. `/opt/cpanel/ea-php82/root/usr/bin/php`):

```cron
* * * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/USER/nontix-backend/artisan schedule:run >> /dev/null 2>&1
* * * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/USER/nontix-backend/artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

### 6. Webhook Mayar
Setelah domain aktif, daftarkan webhook:
```bash
curl -X POST https://api.domainmu.com/api/admin/mayar/webhook \
  -H "Authorization: Bearer <token-admin>"
```

### 7. Frontend
Build SPA dengan base URL API produksi, lalu upload isi `dist/` ke `public_html`:
```bash
# di folder frontend
echo "VITE_API_URL=https://api.domainmu.com/api" > .env.production
npm run build
```
Pastikan `CORS_ALLOWED_ORIGINS` memuat domain frontend.

## Testing

```bash
php artisan test
```

Mencakup: auth & otorisasi per peran, checkout (kuota, max/order, voucher), tiket + QR per hari, pembayaran & callback, pembatalan otomatis, scan per sesi, laporan partner & admin.
