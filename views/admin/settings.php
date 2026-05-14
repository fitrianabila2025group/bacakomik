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
  <div class="row">
    <button class="btn-ghost" type="button" id="btn-test-scraper-api">Test Connection</button>
    <span id="scraper-api-status" class="muted"></span>
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
</script>
