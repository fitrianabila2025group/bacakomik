# BacaKomik

Website baca komik / manga / manhwa / manhua berbasis **PHP 8.2+ native**, modular ringan ala Laravel, dengan importer otomatis dari **komiku.org** (sumber milik tim sendiri).

> Stack: PHP 8.2 · MySQL/MariaDB · PDO · HTML/CSS/JS · Composer (Guzzle + Symfony DOM Crawler) · Chart.js untuk dashboard.

---

## 1. Struktur Folder

```
/app                  Core PHP (Database, Router, Auth, Csrf, Models, Controllers, Services)
/config               app.php, database.php
/database             schema.sql, seed.sql
/public               Front controller (index.php) + assets css/js/img
/storage              File hasil scrape (covers, comics/{slug}/{chapter}/page-XXX.ext, cache)
/views                Template PHP (frontend, admin, layouts)
install.php           Installer CLI
composer.json         Dependencies
.htaccess             Routing Apache
```

## 2. Instalasi

### Opsi A — Lokal (development)

1. **Clone / unzip** project, install deps opsional:
   ```bash
   composer install   # opsional (Guzzle + DomCrawler)
   ```
2. **Set kredensial DB** lewat env atau edit `config/database.php`:
   ```bash
   cp .env.example .env       # lalu edit
   ```
3. **Buat database & seed**:
   ```bash
   php install.php
   ```
4. **Server lokal** (multi-worker recommended):
   ```bash
   PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:8000 -t public router.php
   ```
5. Buka http://localhost:8000 — login admin: **admin@example.com / admin12345**.

### Opsi B — Docker / Cloud (rekomendasi paling simpel)

```bash
docker compose up -d --build
```

Akan menjalankan:
- `app` — PHP 8.3 + Apache di port **8080**.
- `db`  — MariaDB 11 dengan volume persisten.

Otomatis menjalankan `install.php` saat boot pertama (`AUTO_INSTALL=1`).
Buka http://localhost:8080. Bisa langsung di-deploy ke **Fly.io**, **Render**,
**Railway**, **Coolify**, **Dokku**, atau **Hetzner Cloud** tanpa ubah apa pun.

### Opsi C — Shared Hosting (cPanel)

Lihat panduan lengkap di **[INSTALL.md](INSTALL.md)** — termasuk upload via
File Manager, konfigurasi MySQL, izin folder, cron job, dan troubleshooting.

## 3. Pakai Importer (admin → Import)

1. Login admin → menu **Import**.
2. Tempel URL detail komik dari komiku.org (contoh: `https://komiku.org/manga/one-piece/`).
3. Klik **Fetch Preview** untuk lihat metadata + jumlah chapter.
4. Klik **Import This Comic** → scraper akan:
   - download cover ke `storage/covers/{slug}.{ext}`
   - simpan metadata ke tabel `comics` & `comic_genres`
   - iterasi seluruh chapter (delay diatur di Settings)
   - download semua gambar tiap chapter ke `storage/comics/{slug}/{chapter_slug}/page-XXX.ext`
   - tulis path lokal ke `chapter_images.image_path`
5. Progress dipantau realtime via polling AJAX (`/admin/import/status/{id}`).
6. Untuk satu chapter saja: pakai panel **Import 1 Chapter**.
7. Untuk banyak komik: panel **Bulk Import**, satu URL per baris.

Tombol lain: **Cancel Job**, **Re-download Failed Images** (Retry).

> Domain whitelist scraper diatur di **Settings → Scraper → Whitelist** (default: `komiku.org,komiku.id,img.komiku.org`).

## 4. Mengubah Selector Scraper

Bila layout komiku.org berubah, edit array `selectors` di
`app/Services/Scraper/KomikuScraper.php` (`__construct → $defaults['selectors']`).
Tidak perlu mengubah logic parse — cukup ganti XPath.

Contoh:
```php
'cover'        => '//div[contains(@class,"thumb")]//img/@src',
'title'        => '//h1',
'chapter_rows' => '//div[@id="Daftar_Chapter"]//tr',
'image'        => '//div[@id="Baca_Komik"]//img/@src',
```

Atau, override saat instansiasi:
```php
new KomikuScraper(['selectors' => ['title' => '//h1[@class="entry-title"]']]);
```

## 5. Fitur Utama

### Frontend
- Homepage: hero slider, terbaru, populer, genre, search realtime, dark/light toggle.
- Detail komik: cover + background blur, tab Chapters/Info, sinopsis show more, bookmark.
- Reader: scroll vertikal, lazy load, dropdown chapter, prev/next, keyboard arrow nav, mode gelap.
- Search & filter (judul, tipe, status, genre).
- User: register/login, bookmark, riwayat baca.

### Admin (sidebar fixed kiri)
- **Overview** dengan kartu statistik & chart.js.
- **Library** CRUD komik + upload cover (jpg/png/webp, max 2MB), multi-genre.
- **Chapters** CRUD chapter + upload multi-image atau ZIP (auto-extract).
- **Pages** statis (About, DMCA, Privacy, Terms, Contact).
- **Reports** dari user.
- **Import** scraper komiku.org (full / chapter / bulk).
- **Users** ubah role, enable/disable, hapus.
- **Settings** umum, SEO, akses, scraper.
- **Appearance**: hero layout (Classic / Centered / Slanted / Magazine), card style (Modern / Classic / Spotlight), grid style.
- **Ads**: textarea per slot (Home Top/Bottom, Detail Top/Bottom, Reader Top/Middle/Bottom, Sidebar) + toggle aktif.
- **License**.

## 6. Keamanan

- Semua query memakai PDO prepared statements.
- `password_hash()` + `password_verify()` (bcrypt).
- CSRF token otomatis di semua form admin (`App\Csrf::field()` & `App\Csrf::check()`).
- Middleware admin di `Admin\AdminController::__construct()`.
- Upload image divalidasi ekstensi & ukuran.
- Path traversal protection di endpoint `/storage/...`.
- `display_errors` off ketika `APP_ENV=production`.

## 7. SEO

- Meta title / description dinamis per halaman + Open Graph tags.
- Slug SEO friendly (auto unique).
- **Sitemap index** di `/sitemap.xml` (real-time dari DB) memetakan ke:
  - `/sitemap-pages.xml` — halaman statis & home
  - `/sitemap-genres.xml` — semua halaman genre
  - `/sitemap-comics-N.xml` — komik dipecah 5.000 / file
  - `/sitemap-chapters-N.xml` — chapter dipecah 10.000 / file
- Setiap URL membawa `<lastmod>` akurat & `<image:image>` untuk cover komik.
- `/robots.txt` otomatis (block `/admin`, `/login`, `/register`).
- Favicon SVG + ICO siap di `/favicon.svg` & `/favicon.ico`.

## 8. Catatan Production

- Untuk import skala besar, sebaiknya jalankan job dari CLI worker (panggil
  `App\Services\Scraper\KomikuScraper::importFullComic()`); UI saat ini
  menjalankan job sinkron dengan `set_time_limit(0)`.
- Aktifkan OPCache & kompresi gzip.
- Storage dilayani via PHP route `/storage/...` (sudah ada validasi path
  traversal). Untuk performa, arahkan Nginx/Apache agar men-serve folder
  `storage/` langsung sebagai static (boleh tambah header Cache-Control).
- Untuk Apache: `.htaccess` di root meneruskan request ke `public/`.

---

© BacaKomik — internal build untuk tim Komiku.
