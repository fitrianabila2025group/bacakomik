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

  <button class="btn-primary" type="submit">Simpan</button>
</form>
