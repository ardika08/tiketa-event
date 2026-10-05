# Nontix — Platform Tiket Event

Platform jual-beli tiket event: React (frontend) + Laravel 12 REST API (backend) + MySQL.
Pembayaran via **Mayar Invoice API**, email invoice/e-ticket via **Mailketing SMTP**.

```
tiketa-event/
├── backend/         # Laravel 12 API
├── frontend/        # React + Vite (dist/ ikut di-commit agar server tak perlu Node)
├── deploy.sh        # Update server: git pull + composer + migrate + sync frontend
├── setup-server.sh  # Setup server sekali jalan
└── build-update.ps1 # (opsional) build paket .tar.gz manual
```

## Alur Deploy via GitHub

### Setup awal server (sekali saja)

```bash
git clone https://github.com/ardika08/tiketa-event.git ~/nontix-repo
cd ~/nontix-repo
bash setup-server.sh
```

> Server menarik repo **private** — saat clone pertama, masukkan GitHub
> username + Personal Access Token sebagai password (Settings → Developer settings →
> Personal access tokens → repo scope).

### Update rutin

Di komputer:
```bash
cd E:\Aplikasi\nontix\tiketa-event
# (build frontend bila ada perubahan UI)
cd frontend; npm run build; cd ..
git add -A
git commit -m "pesan perubahan"
git push
```

Di server:
```bash
cd ~/nontix-repo
bash deploy.sh
```

Selesai. `deploy.sh` otomatis: `git pull` → sinkron kode ke folder live
(`~/apitix.diamcreative.com` & `~/tiket.diamcreative.com`) → `composer install` →
`migrate` → cache → health check. File `.env` dan folder `vendor`/`storage`
di server **tidak tersentuh**.

## Struktur Live Server

| Host | Folder |
| --- | --- |
| `apitix.diamcreative.com` | `~/apitix.diamcreative.com/public` |
| `tiket.diamcreative.com` | `~/tiket.diamcreative.com` |

## Akun Demo (jika seeder dijalankan)

| Peran | Email | Password |
| --- | --- | --- |
| Admin Platform | `admin@nontix.id` | `admin123` |
| Partner | `partner@nontix.id` | `demo123` |

## Dokumentasi API

Lihat [`backend/README.md`](backend/README.md).