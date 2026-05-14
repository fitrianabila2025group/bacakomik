# Panduan Instalasi BacaKomik di cPanel (Tanpa SSH)

Panduan ini khusus untuk hosting yang **tidak menyediakan akses SSH / Terminal** —
semuanya cukup lewat **File Manager** dan browser. Tested di cPanel, DirectAdmin,
dan Plesk dengan PHP 8.1 – 8.3 + MariaDB 10.4+.

---

## Ringkasan Singkat

1. Upload `bacakomik.zip` ke `public_html` lalu **Extract**.
2. Buat database & user di **MySQL® Databases**.
3. Buka `https://domainanda.com/install.php` di browser.
4. Isi form → klik **Install**.
5. **Hapus** `install.php` lewat File Manager.
6. Login admin → ganti password.

Selesai. Tidak perlu sentuh terminal sama sekali.

---

## 1. Prasyarat

| Komponen | Minimum | Catatan |
|---|---|---|
| PHP | 8.1 | Aktifkan ekstensi `pdo_mysql`, `mbstring`, `curl`, `dom`, `fileinfo`, `json`. |
| MySQL / MariaDB | 5.7 / 10.3 | Buat database baru di langkah 3. |
| Apache | 2.4 + `mod_rewrite` | Default di cPanel. |
| Disk | 2 GB minimum | Tiap chapter ~1-3 MB. |

> **Tips:** di cPanel, ekstensi PHP diatur di **Select PHP Version → Extensions**.
> Centang minimal: `pdo_mysql`, `mbstring`, `curl`, `dom`, `fileinfo`, `json`.

---

## 2. Upload Source via File Manager

1. Login ke **cPanel** → buka **File Manager**.
2. Masuk ke folder **`public_html`** (atau folder root domain Anda).
3. Klik **Upload** → upload `bacakomik.zip` (download dari halaman Releases di GitHub).
4. Setelah selesai, klik kanan file zip → **Extract** ke `public_html`.
5. Hapus file `bacakomik.zip` setelah ekstrak selesai.

Struktur akhir di `public_html/`:

```
public_html/
├── .htaccess          ← redirect ke /public
├── app/
├── config/
├── database/
├── install.php        ← installer (HAPUS setelah selesai!)
├── public/
│   ├── .htaccess      ← rewrite rules
│   └── index.php
├── storage/
└── vendor/            ← sudah include di rilis zip
```

> **Rekomendasi keamanan (opsional):** ubah _Document Root_ domain ke
> `public_html/public` lewat **Domains → Manage**. Folder `app/`, `storage/`,
> `config/` jadi tidak bisa diakses langsung dari web.

---

## 3. Buat Database

1. cPanel → **MySQL® Databases**.
2. **Create New Database** → nama: `cpaneluser_bacakomik` (catat nama lengkapnya).
3. **Add New User** → username: `cpaneluser_bacauser`, password kuat (catat!).
4. **Add User to Database** → centang **All Privileges**.

> Catatan: shared hosting biasanya memberi prefix otomatis (mis. `cpaneluser_`).
> Gunakan nama lengkap dengan prefix saat mengisi installer.

---

## 4. Jalankan Web Installer

Buka di browser:

```
https://domainanda.com/install.php
```

Anda akan melihat halaman installer dengan:

- **Pemeriksaan server** (PHP version, ekstensi, permission file).
- **Form konfigurasi database** (host, name, user, password).
- **Form konfigurasi aplikasi** (URL situs, nama, email & password admin).

Isi semua field, lalu klik **▶ Install Sekarang**. Installer otomatis akan:

- Menulis ulang `config/database.php` dan `config/app.php`.
- Membuat database (jika belum ada) + import schema & seed.
- Membuat / reset akun admin.
- Membuat folder `storage/` dan sub-foldernya.

Jika sukses, halaman akan menampilkan **✓ Installasi selesai**.

### Jika ada error pemeriksaan server

| Error | Solusi via cPanel |
|---|---|
| `pdo_mysql` non-aktif | **Select PHP Version → Extensions** → centang `pdo_mysql`. |
| `config/*.php` tidak writable | File Manager → klik kanan folder `config` → **Permissions** → set `755`. File `.php` di dalamnya: `644`. |
| `storage/` tidak writable | File Manager → klik kanan folder `storage` → **Permissions** → set `755` atau `775`, centang **Recurse into subdirectories**. |

### Jika error "CREATE DATABASE not allowed"

Beberapa shared hosting tidak izinkan PHP membuat database. Solusinya:
buat database manualnya dulu di **MySQL® Databases** (langkah 3), lalu
isi nama database persis sama di form installer.

---

## 5. HAPUS install.php (WAJIB!)

Setelah installer sukses:

1. Buka **File Manager** → masuk ke `public_html`.
2. Klik kanan `install.php` → **Delete**.

> Installer juga membuat file `storage/.installed` yang akan memblokir
> akses ulang ke `install.php` — tapi tetap **hapus filenya** demi keamanan.

---

## 6. Login & Setup Awal

1. Buka `https://domainanda.com/login`.
2. Login dengan email & password admin yang Anda set di installer.
3. Buka **Admin → Settings** → atur Site Name, Logo, Meta SEO.
4. Buka **Admin → Import → Auto-Crawl Seluruh Situs** untuk impor konten.

---

## 7. Cron Job (Opsional — Update Otomatis)

Di cPanel → **Cron Jobs**, tambahkan job harian:

```
0 3 * * * /usr/local/bin/php /home/USERNAME/public_html/bin/crawl.php >/dev/null 2>&1
```

> Sesuaikan path PHP — cek dulu via **MultiPHP Manager** atau tanya hosting.
> Cron akan crawl ulang sitemap setiap jam 03:00 dan auto-import chapter baru.

Jika hosting Anda **tidak punya cron**, jalankan crawl manual via menu
**Admin → Import** sesekali.

---

## 8. SEO & Search Console

Daftarkan situs di [Google Search Console](https://search.google.com/search-console)
dan submit sitemap:

```
https://domainanda.com/sitemap.xml
```

Sitemap di-generate **realtime dari database** (tidak perlu regenerate manual).

---

## 9. Troubleshooting

| Gejala | Solusi |
|---|---|
| HTTP 500 di semua page | Cek **Errors** di cPanel atau `error_log` di `public_html`. Biasanya `pdo_mysql` belum aktif. |
| 404 untuk semua URL kecuali `/` | `mod_rewrite` non-aktif. Pastikan `.htaccess` ada di root **dan** di `public/`. |
| _"could not find driver"_ | Aktifkan `pdo_mysql` di **Select PHP Version → Extensions**. |
| Storage tidak bisa ditulis | Set permission folder `storage/` ke `775` (Recurse). |
| Image komik tidak muncul | Pastikan `allow_url_fopen=On` di **MultiPHP INI Editor**. |
| Installer tampil halaman putih | PHP version < 8.1 — naikkan via **Select PHP Version**. |
| "BacaKomik sudah terinstall" tapi mau install ulang | Buka `install.php?force=1`, atau hapus file `storage/.installed` via File Manager. |
| Scraper PHP terus gagal / Cloudflare block / timeout | Aktifkan **Remote Scraper Service** — lihat **Bagian 11** di bawah. |

---

## 11. Remote Scraper Service (Cloudflare Bypass)

Jika scraper bawaan PHP gagal terus di shared hosting (Cloudflare 403,
exec/curl di-disable, request panjang di-kill LiteSpeed, dll), pasang
**Scraper Service Python** terpisah lalu tinggal hubungkan via API.

Semua source ada di folder [`scraper-service/`](scraper-service/) repo ini,
lengkap dengan `Dockerfile`, `railway.json`, dan `docker-compose.yml`.

### 11.1 Deploy ke Railway (paling cepat, gratis untuk start)

1. Push folder `scraper-service/` ke GitHub (boleh repo terpisah, atau pakai repo BacaKomik dan set **Root Directory = scraper-service**).
2. Login ke <https://railway.app> → **New Project → Deploy from GitHub repo** → pilih repo tadi.
3. Tab **Variables**, tambahkan:
   - `SCRAPER_API_KEY` = string acak panjang, mis. hasil `openssl rand -hex 32`
   - `SCRAPER_MODE` = `request` (default; ganti `browser` kalau target pakai CF JS-challenge)
   - `SCRAPER_WHITELIST` = `komiku.org,komiku.id,img.komiku.org,mangaku.top,img.mangaku.top,cover.mangaku.top`
   - `CORS_ORIGINS` = `https://domain-shared-hosting-anda.com`
4. Deploy → Railway memberi domain `https://xxxx.up.railway.app`.
5. Tes: buka `https://xxxx.up.railway.app/health` → harus tampil `{"ok": true, ...}`.

### 11.2 Deploy ke VPS (Docker)

```bash
git clone https://github.com/USERNAME/bacakomik.git
cd bacakomik/scraper-service
cp .env.example .env
nano .env                     # isi SCRAPER_API_KEY (wajib)
docker compose up -d --build
curl http://SERVER_IP:8080/health
```

Pasang reverse-proxy + TLS (Caddy paling singkat):

```caddyfile
scraper.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

### 11.3 Hubungkan dari shared hosting

1. Login admin BacaKomik → **Settings**.
2. Scroll ke section **Scraper API (Remote – Railway / VPS)**.
3. Isi:
   - ✅ **Pakai Remote Scraper API**
   - **API URL** = `https://xxxx.up.railway.app` (tanpa `/` di akhir)
   - **API Key** = sama dengan `SCRAPER_API_KEY` di service
   - **API Timeout** = `120` (default oke)
4. Klik **Test Connection** — harus muncul `OK — mode: request` (hijau).
5. **Simpan**.

Buka **Admin → Import** → badge atas berubah jadi **REMOTE API**. Semua tombol (preview, import 1 komik, bulk, auto-crawl seluruh situs) dan CLI `php bin/crawl.php` otomatis memakai service tersebut. Tidak ada perubahan skema DB.

### 11.4 Mode bypass Cloudflare

| `SCRAPER_MODE` | Backend | Kapan dipakai |
|---|---|---|
| `request` (default) | `botasaurus_requests` (TLS-fingerprint Chrome) | CF level standar — ringan, cepat, **tidak butuh Chrome**. |
| `browser` | `botasaurus.browser` (Chromium headless) | Jika muncul "Just a moment..." atau Turnstile. Lebih berat (RAM ≥ 1 GB). |

Detail troubleshooting & list endpoint lengkap ada di
[`scraper-service/README.md`](scraper-service/README.md).

---

## 12. Update Aplikasi

1. Backup folder `storage/` dan file `config/database.php`, `config/app.php`.
2. Hapus semua file di `public_html` **kecuali** `storage/` dan `config/`.
3. Upload + extract zip versi baru.
4. (Opsional) Buka `install.php?force=1` lalu klik Install lagi — aman, idempotent. Lalu hapus lagi.

---

## Lampiran: Install via SSH (alternatif untuk power user)

Jika hosting Anda **kebetulan** punya SSH, alur lebih cepat:

```bash
cd ~/public_html
php install.php       # versi CLI tidak lagi tersedia — gunakan web installer.
```

Sejak versi terbaru, `install.php` **hanya berbentuk web installer**.
Buka di browser meski via SSH-pun tetap pakai cara web di atas.

Selesai 🎉
