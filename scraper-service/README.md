# BacaKomik Scraper Service

API HTTP berbasis **FastAPI + Botasaurus** yang menjalankan scraping
[komiku.org](https://komiku.org) (dan mirror seperti `mangaku.top`) di luar
shared hosting. Tujuannya: tembus proteksi **Cloudflare** dan menyelesaikan
masalah scraper PHP yang gagal jalan di shared hosting (eksekusi panjang
di-kill, tidak bisa headless browser, IP shared di-block CF, dll).

Arsitekturnya:

```
Shared hosting (PHP)  ──HTTPS──►  Scraper Service (Python)  ──►  komiku.org
        ▲                                  │
        └── /proxy?url=…  download cover & gambar (juga lewat CF bypass)
```

Shared hosting hanya meng-_consume_ JSON. Tidak ada lagi job CLI di sana.

---

## 1. Setup lokal

```bash
cd scraper-service
cp .env.example .env
# edit .env, isi SCRAPER_API_KEY (random panjang)

python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8080
```

Tes:

```bash
curl -H "X-API-Key: $SCRAPER_API_KEY" \
  "http://localhost:8080/discover/sitemap"
```

## 2. Mode bypass Cloudflare

Set `SCRAPER_MODE` di env:

| Mode | Backend | Kapan dipakai |
|---|---|---|
| `request` (default) | `botasaurus_requests` (TLS-fingerprint Chrome) | CF level standar (kebanyakan kasus komiku.org). Ringan, cepat, **tidak butuh Chrome**. |
| `browser` | `botasaurus.browser` (Chromium headless) | CF JS-challenge / Turnstile. Butuh Chrome (sudah include di Dockerfile). |

Tip: mulai dari `request`. Kalau muncul HTML berisi "Just a moment...", barulah pindah ke `browser`.

## 3. Deploy ke Railway

1. Push folder repo ini ke GitHub (folder `scraper-service/` saja juga boleh
   sebagai repo terpisah).
2. Di Railway: **New Project → Deploy from GitHub repo**.
3. Pilih root = `scraper-service`. Railway akan baca `railway.json` + `Dockerfile`.
4. Tambahkan env variables di tab **Variables**:
   - `SCRAPER_API_KEY` — sama dengan yang nanti di-paste ke admin BacaKomik.
   - `SCRAPER_MODE` — `request` atau `browser`.
   - `SCRAPER_WHITELIST` — biarkan default kalau scrape komiku saja.
   - `CORS_ORIGINS` — `https://domainshared-hosting-anda.com`.
5. Setelah deploy, Railway memberi domain `https://xxxx.up.railway.app`.
6. Cek: `https://xxxx.up.railway.app/health` → `{"ok": true, "mode": "..."}`.

## 4. Deploy ke VPS (Docker)

```bash
git clone <repo> bacakomik-scraper && cd bacakomik-scraper/scraper-service
cp .env.example .env && nano .env
docker compose up -d --build
# health
curl http://SERVER_IP:8080/health
```

Pasang reverse-proxy (Caddy / Nginx) + TLS untuk akses publik via HTTPS.

Contoh Caddyfile:

```
scraper.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

## 5. Konek dari shared hosting (BacaKomik)

Login admin → **Settings → Scraper API**, isi:

| Field | Nilai |
|---|---|
| Scraper API URL | `https://xxxx.up.railway.app` (tanpa trailing slash) |
| Scraper API Key | Sama dengan `SCRAPER_API_KEY` |
| Use Remote Scraper | ✅ aktif |

Setelah disimpan, semua tombol di **Admin → Import** otomatis memakai service
ini, **tanpa mengubah workflow user**. CLI `php bin/crawl.php` juga otomatis
ikut memakai service.

## 6. Endpoints

Semua butuh header `X-API-Key: <key>` (atau `?key=<key>`).

| Method | Path | Body / Query | Output |
|---|---|---|---|
| GET  | `/health` | — | `{ok, mode, whitelist}` |
| GET  | `/discover` | `?seeds=`, `?sitemap=`, `?max_pages=`, `?max_comics=` | `{count, urls}` |
| GET  | `/discover/sitemap` | `?urls=` | `{count, urls}` |
| GET  | `/discover/listing` | `?seeds=`, `?max_pages=`, `?max_comics=` | `{count, urls}` |
| POST | `/scrape/comic` | `{ "url": "..." }` | metadata komik |
| POST | `/scrape/chapters` | `{ "url": "..." }` | `{count, chapters[]}` |
| POST | `/scrape/images` | `{ "url": "..." }` | `{count, images[]}` |
| GET  | `/proxy` | `?url=...&referer=...` | binary stream gambar |

## 7. Troubleshooting

* **401 Invalid API key** → header `X-API-Key` salah / belum di-set.
* **403 Host tidak ada di whitelist** → tambahkan domain target ke `SCRAPER_WHITELIST`.
* **502 dengan pesan HTTP 403** → Cloudflare mendeteksi bot.
  - Coba ganti `SCRAPER_MODE=browser`.
  - Pastikan IP server tidak di-blacklist (cek dari `curl ifconfig.me`).
* **OOM saat browser mode di Railway free** → naikkan plan, atau pakai `request` mode.
* **Cache stale** → hapus folder `cache/` atau set `SCRAPER_CACHE_TTL=0`.
