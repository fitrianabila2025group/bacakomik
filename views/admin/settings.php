<?php /** @var array $settings */ ?>
<h1 class="page-title">Settings</h1>
<form method="post" action="/admin/settings/save" enctype="multipart/form-data" class="card form">
  <?= \App\Csrf::field() ?>
  <h3>Umum</h3>
  <div class="grid-2">
    <label>Site Name <input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name'] ?? '') ?>"></label>
    <label>Default Theme
      <select name="default_theme">
        <option value="light" <?= ($settings['default_theme'] ?? '') === 'light' ? 'selected' : '' ?>>Light</option>
        <option value="dark"  <?= ($settings['default_theme'] ?? '') === 'dark'  ? 'selected' : '' ?>>Dark</option>
      </select>
    </label>
    <label>Logo <input type="file" name="site_logo" accept="image/*"></label>
    <label>Favicon <input type="file" name="site_favicon" accept="image/*,.ico"></label>
  </div>

  <h3>SEO</h3>
  <label>Meta Title <input type="text" name="meta_title" value="<?= htmlspecialchars($settings['meta_title'] ?? '') ?>"></label>
  <label>Meta Description <textarea name="meta_description" rows="2"><?= htmlspecialchars($settings['meta_description'] ?? '') ?></textarea></label>

  <h3>Akses</h3>
  <div class="row">
    <label class="check"><input type="checkbox" name="maintenance_mode" value="1" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>> Maintenance Mode</label>
    <label class="check"><input type="checkbox" name="allow_registration" value="1" <?= ($settings['allow_registration'] ?? '1') === '1' ? 'checked' : '' ?>> Izinkan Registrasi</label>
  </div>

  <h3>Scraper</h3>
  <div class="grid-2">
    <label>Delay (detik) <input type="number" step="0.1" name="scraper_delay" value="<?= htmlspecialchars($settings['scraper_delay'] ?? '1') ?>"></label>
    <label>Timeout (detik) <input type="number" name="scraper_timeout" value="<?= htmlspecialchars($settings['scraper_timeout'] ?? '30') ?>"></label>
    <label>User Agent <input type="text" name="scraper_user_agent" value="<?= htmlspecialchars($settings['scraper_user_agent'] ?? '') ?>"></label>
    <label>Concurrent Jobs <input type="number" name="scraper_concurrent" value="<?= htmlspecialchars($settings['scraper_concurrent'] ?? '1') ?>"></label>
  </div>
  <label>Whitelist Domain (pisah dengan koma)
    <input type="text" name="scraper_whitelist" value="<?= htmlspecialchars($settings['scraper_whitelist'] ?? '') ?>">
  </label>

  <h3>Scraper API (Remote – Railway / VPS)</h3>
  <p class="muted">Aktifkan supaya semua proses scraping (discovery, metadata, gambar) di-handle oleh service Python
    <code>scraper-service</code> di luar shared hosting (mis. Railway). Cocok bila Cloudflare/host meng-block scraper PHP lokal.</p>
  <div class="grid-2">
    <label class="check"><input type="checkbox" name="scraper_use_api" value="1" <?= ($settings['scraper_use_api'] ?? '0') === '1' ? 'checked' : '' ?>> Pakai Remote Scraper API</label>
    <label>API Timeout (detik)
      <input type="number" name="scraper_api_timeout" value="<?= htmlspecialchars($settings['scraper_api_timeout'] ?? '120') ?>" min="10">
    </label>
  </div>
  <label>API URL
    <input type="url" name="scraper_api_url" placeholder="https://xxxx.up.railway.app" value="<?= htmlspecialchars($settings['scraper_api_url'] ?? '') ?>">
  </label>
  <label>API Key (X-API-Key)
    <input type="text" name="scraper_api_key" autocomplete="off" value="<?= htmlspecialchars($settings['scraper_api_key'] ?? '') ?>">
  </label>

  <div class="grid-2">
    <label class="check" title="Hemat berat shared hosting: gambar TIDAK di-download, hanya simpan URL proxy Railway ke DB. Browser pengunjung load gambar langsung dari Railway.">
      <input type="checkbox" name="scraper_remote_storage" value="1" <?= ($settings['scraper_remote_storage'] ?? '1') === '1' ? 'checked' : '' ?>>
      <strong>Remote storage (thin mode)</strong> — bandwidth + storage di Railway, hosting cuma simpan URL
    </label>
    <label class="check" title="Endpoint /proxy boleh diakses publik tanpa key. Wajib ON kalau Remote storage ON, supaya URL gambar di DB tidak membocorkan API key.">
      <input type="checkbox" name="scraper_proxy_public" value="1" <?= ($settings['scraper_proxy_public'] ?? '1') === '1' ? 'checked' : '' ?>>
      <strong>Proxy public</strong> — URL gambar publik (butuh SCRAPER_PROXY_PUBLIC=1 di service)
    </label>
  </div>
  <p class="muted" style="font-size:.85em">
    💡 Mode <strong>Remote storage</strong> sangat disarankan untuk shared hosting. Tanpa ini, setiap import 1 komik = ratusan curl download yang membuat website blank/timeout.
  </p>

  <div class="row">
    <button class="btn-ghost" type="button" id="btn-test-scraper-api">Test Connection</button>
    <span id="scraper-api-status" class="muted"></span>
  </div>

  <h3>CAPTCHA (anti-spam registrasi & login)</h3>
  <p class="muted">Lindungi form daftar/login dari bot. Pilih satu provider, daftar key di dashboard provider, lalu paste Site Key + Secret Key di sini.</p>
  <?php $cp = $settings['captcha_provider'] ?? 'none'; ?>
  <div class="grid-2">
    <label>Provider
      <select name="captcha_provider">
        <option value="none"          <?= $cp==='none'?'selected':''?>>Nonaktif</option>
        <option value="turnstile"     <?= $cp==='turnstile'?'selected':''?>>Cloudflare Turnstile (gratis, rekomendasi)</option>
        <option value="recaptcha_v2"  <?= $cp==='recaptcha_v2'?'selected':''?>>Google reCAPTCHA v2 (checkbox)</option>
        <option value="recaptcha_v3"  <?= $cp==='recaptcha_v3'?'selected':''?>>Google reCAPTCHA v3 (invisible score)</option>
        <option value="hcaptcha"      <?= $cp==='hcaptcha'?'selected':''?>>hCaptcha</option>
      </select>
    </label>
    <label>Min. Score (reCAPTCHA v3 saja, 0.0–1.0)
      <input type="number" step="0.1" min="0" max="1" name="captcha_score_min" value="<?= htmlspecialchars($settings['captcha_score_min'] ?? '0.5') ?>">
    </label>
  </div>
  <label>Site Key (public)
    <input type="text" name="captcha_site_key" autocomplete="off" value="<?= htmlspecialchars($settings['captcha_site_key'] ?? '') ?>">
  </label>
  <label>Secret Key (rahasia, server-side)
    <input type="text" name="captcha_secret_key" autocomplete="off" value="<?= htmlspecialchars($settings['captcha_secret_key'] ?? '') ?>">
  </label>
  <div class="row">
    <label class="check"><input type="checkbox" name="captcha_on_register" value="1" <?= ($settings['captcha_on_register'] ?? '0')==='1'?'checked':'' ?>> Aktif di /register</label>
    <label class="check"><input type="checkbox" name="captcha_on_login" value="1" <?= ($settings['captcha_on_login'] ?? '0')==='1'?'checked':'' ?>> Aktif di /login</label>
    <label class="check"><input type="checkbox" name="captcha_on_comment" value="1" <?= ($settings['captcha_on_comment'] ?? '0')==='1'?'checked':'' ?>> Aktif di komentar</label>
  </div>
  <p class="muted" style="font-size:.85em">
    📚 Daftar key:
    <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">Turnstile</a> ·
    <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">reCAPTCHA</a> ·
    <a href="https://dashboard.hcaptcha.com/sites" target="_blank" rel="noopener">hCaptcha</a>
  </p>

  <h3>Komentar (service di Railway)</h3>
  <p class="muted">Backend komentar berjalan di Railway (FastAPI). Browser memanggil <code>/api/comments/*</code> di host ini, lalu PHP meneruskan ke Railway dengan API Key yang sama dipakai service Scraper di atas. Pastikan <em>Scraper API URL &amp; API Key</em> di atas sudah benar.</p>
  <div class="row">
    <label class="check"><input type="checkbox" name="comments_enabled" value="1" <?= ($settings['comments_enabled'] ?? '0')==='1'?'checked':'' ?>> Aktifkan komentar</label>
    <label class="check"><input type="checkbox" name="comments_on_comic" value="1" <?= ($settings['comments_on_comic'] ?? '1')==='1'?'checked':'' ?>> Tampilkan di halaman komik</label>
    <label class="check"><input type="checkbox" name="comments_on_chapter" value="1" <?= ($settings['comments_on_chapter'] ?? '1')==='1'?'checked':'' ?>> Tampilkan di halaman chapter</label>
  </div>
  <label>Comments API URL <span class="muted">(opsional — kosongkan untuk pakai Scraper API URL di atas)</span>
    <input type="url" name="comments_api_url" placeholder="https://xxxx.up.railway.app" value="<?= htmlspecialchars($settings['comments_api_url'] ?? '') ?>">
  </label>
  <div class="row">
    <button class="btn-ghost" type="button" id="btn-test-comments-api">Test Connection</button>
    <span id="comments-api-status" class="muted"></span>
  </div>

  <button class="btn-primary" type="submit">Simpan</button>
</form>

<script>
document.getElementById('btn-test-scraper-api')?.addEventListener('click', async () => {
  const url = document.querySelector('[name=scraper_api_url]').value.trim();
  const key = document.querySelector('[name=scraper_api_key]').value.trim();
  const out = document.getElementById('scraper-api-status');
  if (!url) { out.textContent = 'Isi API URL dulu.'; return; }
  out.textContent = 'Menguji...';
  try {
    const r = await fetch(url.replace(/\/$/, '') + '/health', { headers: key ? { 'X-API-Key': key } : {} });
    const j = await r.json();
    out.textContent = r.ok ? ('OK — mode: ' + (j.mode || '?')) : ('Gagal: ' + (j.detail || r.status));
    out.style.color = r.ok ? 'green' : 'crimson';
  } catch (e) { out.textContent = 'Error: ' + e.message; out.style.color = 'crimson'; }
});
document.getElementById('btn-test-comments-api')?.addEventListener('click', async () => {
  const url = document.querySelector('[name=comments_api_url]').value.trim();
  const out = document.getElementById('comments-api-status');
  if (!url) { out.textContent = 'Isi Comments API URL dulu.'; return; }
  out.textContent = 'Menguji...';
  try {
    const r = await fetch(url.replace(/\/$/, '') + '/comments/health');
    const j = await r.json();
    out.textContent = r.ok ? ('OK — db: ' + (j.db || '?') + ', count: ' + (j.count ?? '?')) : ('Gagal: ' + r.status);
    out.style.color = r.ok ? 'green' : 'crimson';
  } catch (e) { out.textContent = 'Error: ' + e.message; out.style.color = 'crimson'; }
});
</script>
