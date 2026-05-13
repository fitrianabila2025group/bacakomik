# Panduan Instalasi BacaKomik di Shared Hosting (cPanel)

Panduan lengkap untuk menginstal BacaKomik di shared hosting standar (cPanel /
DirectAdmin / Plesk). Tested dengan PHP 8.1 – 8.3 dan MariaDB 10.4+.

---

## 1. Prasyarat

| Komponen | Minimum | Catatan |
|---|---|---|
| PHP | 8.1 | Aktifkan ekstensi `pdo_mysql`, `mbstring`, `curl`, `dom`, `fileinfo`, `gd` (opsional). |
| MySQL / MariaDB | 5.7 / 10.3 | Buat database baru (langkah 3). |
| Apache | 2.4 + `mod_rewrite` | Sudah default di mayoritas cPanel. |
| Disk | 2 GB minimum | Setiap chapter ~1-3 MB; full sitemap ~50-100 GB. |

---

## 2. Upload Source

**Cara A — via File Manager cPanel:**

1. Login ke cPanel.
2. Buka **File Manager** → masuk ke folder `public_html` (atau folder root domain Anda).
3. Upload file `bacakomik.zip` (download dari Releases di GitHub) lalu klik kanan → **Extract**.
4. Pastikan struktur akhirnya:
   ```
   public_html/
   ├── .htaccess          ← redirect ke /public
   ├── app/
   ├── config/
   ├── public/
   │   ├── .htaccess      ← rewrite rules
   │   └── index.php
   ├── storage/
   └── ...
   ```

**Cara B — via Git (jika hosting punya `git clone`):**
```bash
cd ~
git clone https://github.com/<USER>/bacakomik.git
mv bacakomik public_html_app   # opsional rename
```
Lalu buat symlink `public_html` → `public_html_app/public` jika diizinkan.

> 💡 **Rekomendasi terbaik:** jika hosting mengizinkan ubah _Document Root_,
> ubah ke `public_html/public`. Lebih aman karena `app/`, `storage/`, dan
> `config/` tidak bisa diakses lewat web.

---

## 3. Buat Database

1. cPanel → **MySQL® Databases**.
2. _Create New Database_ → nama: `username_bacakomik`.
3. _Add New User_ → username: `username_bacauser`, password: kuat (simpan!).
4. _Add User to Database_ → centang **All Privileges**.

---

## 4. Konfigurasi Aplikasi

Edit `config/database.php`:

```php
return [
    'host'     => '127.0.0.1',          // atau 'localhost'
    'port'     => '3306',
    'database' => 'username_bacakomik',
    'username' => 'username_bacauser',
    'password' => 'PASSWORD_DARI_LANGKAH_3',
    'charset'  => 'utf8mb4',
];
```

Edit `config/app.php`:

```php
return [
    'url'   => 'https://domainanda.com',  // tanpa trailing slash
    'name'  => 'BacaKomik',
    'debug' => false,                     // WAJIB false di produksi
];
```

---

## 5. Jalankan Installer

**Via Terminal (cPanel → Terminal / SSH):**
```bash
cd ~/public_html      # sesuaikan path
php install.php
```
Output sukses:
```
✓ Installasi selesai.
  Admin login: admin@example.com / admin12345
```

**Tanpa Terminal (web installer):** buka `https://domainanda.com/install.php`
satu kali, lalu **HAPUS file `install.php`** demi keamanan.

---

## 6. Set Permission Storage

```bash
chmod -R 775 storage/
chown -R $(whoami):nobody storage/   # sesuaikan group ke user web (sering 'nobody' / 'apache')
```
Folder `storage/comics`, `storage/covers`, `storage/cache`, `storage/settings`
harus writable oleh PHP.

---

## 7. Login & Setup Awal

1. Buka `https://domainanda.com/login`.
2. Login sebagai `admin@example.com` / `admin12345`.
3. **GANTI PASSWORD** segera di **Admin → Users**.
4. Buka **Admin → Settings** → atur Site Name, Logo, Meta SEO.
5. Buka **Admin → Import → Auto-Crawl Seluruh Situs** untuk impor konten.

---

## 8. Cron Job (Opsional — untuk update otomatis)

Di cPanel → **Cron Jobs**, tambahkan job harian:

```
0 3 * * * /usr/local/bin/php /home/USER/public_html/bin/crawl.php >/dev/null 2>&1
```
Akan crawl ulang sitemap setiap jam 03:00 — chapter baru otomatis ter-import
(chapter lama di-skip karena sudah ada di DB).

---

## 9. SEO & Search Console

Daftarkan situs di [Google Search Console](https://search.google.com/search-console)
dan submit sitemap:

```
https://domainanda.com/sitemap.xml
```

`/sitemap.xml` adalah **sitemap index** yang otomatis link ke:
- `/sitemap-pages.xml`     → halaman statis
- `/sitemap-genres.xml`    → halaman genre
- `/sitemap-comics-N.xml`  → komik (5000 / file)
- `/sitemap-chapters-N.xml`→ chapter (10000 / file)

Semua sitemap **realtime dari database** (lastmod akurat).

---

## 10. Troubleshooting

| Gejala | Solusi |
|---|---|
| HTTP 500 di semua page | Cek `error_log` di cPanel; biasanya `pdo_mysql` belum aktif → aktifkan via **Select PHP Version → Extensions**. |
| 404 untuk semua URL kecuali `/` | `mod_rewrite` non-aktif atau `AllowOverride None`. Cek `.htaccess` ada di root & di `public/`. |
| _"could not find driver"_ | Aktifkan `pdo_mysql` di **MultiPHP INI Editor** atau **Select PHP Version**. |
| Storage tidak bisa ditulis | `chmod 775 storage/ -R` dan pastikan owner sesuai user web. |
| Crawler lambat / timeout | Tambahkan `set_time_limit(0)` (sudah default). Jalankan via cron, bukan dari browser. |
| Image tidak muncul | Cek `php.ini` → `allow_url_fopen=On` dan firewall keluar mengizinkan ke `*.komiku.org`. |

---

## 11. Update Aplikasi

```bash
cd ~/public_html
git pull origin main           # jika via git
# atau upload ulang file (kecuali config/, storage/, .env)
php install.php                 # idempotent, aman dijalankan ulang
```

Selesai 🎉
