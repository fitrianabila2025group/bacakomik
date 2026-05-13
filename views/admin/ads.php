<?php /** @var array $slots */ ?>
<h1 class="page-title">Ads</h1>
<form method="post" action="/admin/ads/save" class="ads-form">
  <?= \App\Csrf::field() ?>
  <div class="ads-grid">
    <?php foreach ($slots as $s): ?>
      <div class="card ad-slot-edit">
        <div class="ad-head">
          <h3><?= htmlspecialchars($s['slot_name']) ?></h3>
          <label class="switch">
            <input type="checkbox" name="is_active[<?= $s['id'] ?>]" <?= $s['is_active'] ? 'checked' : '' ?>>
            <span>Aktif</span>
          </label>
        </div>
        <p class="muted">Slot key: <code><?= htmlspecialchars($s['slot_key']) ?></code></p>
        <textarea name="ad_code[<?= $s['id'] ?>]" rows="6" placeholder="Tempel kode iklan HTML/JS (AdSense, MGID, Adsterra, Monetag, custom)..."><?= htmlspecialchars((string)$s['ad_code']) ?></textarea>
        <details>
          <summary>Preview (placeholder, tidak mengeksekusi script)</summary>
          <pre><?= htmlspecialchars((string)$s['ad_code']) ?: '— kosong —' ?></pre>
        </details>
      </div>
    <?php endforeach; ?>
  </div>
  <button class="btn-primary" type="submit">Simpan Semua</button>
</form>
