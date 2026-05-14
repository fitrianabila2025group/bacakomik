<?php /** @var array $jobs @var array $logs @var bool $apiMode @var string $apiUrl */ ?>
<h1 class="page-title">Import dari Komiku</h1>

<div class="card" style="border-left:4px solid <?= $apiMode ? '#16a34a' : '#9ca3af' ?>;">
  <strong>Mode scraper:</strong>
  <?php if ($apiMode): ?>
    <span style="color:#16a34a;font-weight:600;">REMOTE API</span> &mdash;
    <code><?= htmlspecialchars($apiUrl) ?></code>
    <small class="muted">(scraping dilakukan di service Python eksternal, tahan Cloudflare)</small>
  <?php else: ?>
    <span style="color:#9ca3af;font-weight:600;">LOCAL (PHP curl)</span>
    <small class="muted">&mdash; aktifkan Remote API di <a href="/admin/settings">Settings &rarr; Scraper API</a> bila gagal terus.</small>
  <?php endif; ?>
</div>


<div class="grid-2">
  <div class="card">
    <h3>Import 1 Komik (URL detail)</h3>
    <form id="form-preview">
      <?= \App\Csrf::field() ?>
      <input type="url" name="url" required placeholder="https://komiku.org/manga/...">
      <div class="row">
        <button class="btn-ghost" type="button" id="btn-preview">Fetch Preview</button>
        <button class="btn-primary" type="button" id="btn-import-comic">Import This Comic (full)</button>
      </div>
    </form>
    <div id="preview-result" class="muted">Preview akan tampil di sini.</div>
  </div>

  <div class="card">
    <h3>Import 1 Chapter (URL chapter)</h3>
    <form id="form-chapter">
      <?= \App\Csrf::field() ?>
      <input type="url" name="url" required placeholder="https://komiku.id/chapter/...">
      <button class="btn-primary" type="button" id="btn-import-chapter">Import Selected Chapter</button>
    </form>
  </div>
</div>

<div class="card">
  <h3>Bulk Import</h3>
  <form id="form-bulk">
    <?= \App\Csrf::field() ?>
    <textarea name="urls" rows="6" placeholder="Tempel banyak URL detail komik, satu per baris..."></textarea>
    <button class="btn-primary" type="button" id="btn-import-bulk">Jalankan Bulk Import</button>
  </form>
</div>

<div class="card" style="border:2px solid var(--accent,#7c3aed);">
  <h3>🚀 Auto-Crawl Seluruh Situs</h3>
  <p class="muted">Discovery otomatis seluruh komik dari halaman daftar Komiku, lalu import semua chapter + gambar ke storage lokal. Berjalan di background (CLI worker), bisa lama (menit s/d jam tergantung jumlah komik).</p>
  <form id="form-site">
    <?= \App\Csrf::field() ?>
    <div class="row">
      <label>Max komik (0 = tanpa batas)
        <input type="number" name="max_comics" value="0" min="0" style="width:120px">
      </label>
      <label>Max halaman daftar per seed
        <input type="number" name="max_pages" value="300" min="1" style="width:120px">
      </label>
    </div>
    <textarea name="seeds" rows="3" placeholder="Opsional: seed listing URL custom (satu per baris). Kosongkan untuk pakai default komiku.id/daftar-komik/ dan komiku.org/daftar-komik/"></textarea>
    <button class="btn-primary" type="button" id="btn-crawl-site">Mulai Auto-Crawl</button>
    <small class="muted">Pantau progress di tabel "Job Aktif & Riwayat" di bawah (auto-refresh).</small>
  </form>
</div>

<div class="card">
  <h3>Job Aktif & Riwayat</h3>
  <table class="data-table" id="jobs-table">
    <thead><tr><th>ID</th><th>Tipe</th><th>Status</th><th>Progress</th><th>Pesan</th><th>Updated</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr data-id="<?= $j['id'] ?>">
        <td><?= $j['id'] ?></td>
        <td><?= htmlspecialchars($j['type']) ?></td>
        <td><span class="status status-<?= htmlspecialchars($j['status']) ?>"><?= htmlspecialchars($j['status']) ?></span></td>
        <td>
          <progress max="<?= max(1,(int)$j['total']) ?>" value="<?= (int)$j['progress'] ?>"></progress>
          <small><?= (int)$j['progress'] ?>/<?= (int)$j['total'] ?></small>
        </td>
        <td><small><?= htmlspecialchars((string)$j['message']) ?></small></td>
        <td><?= htmlspecialchars($j['updated_at']) ?></td>
        <td>
          <?php if (in_array($j['status'], ['pending','running'], true)): ?>
            <button class="btn-ghost" data-tick="<?= $j['id'] ?>" title="Picu 1 step manual">▶ Run</button>
            <button class="btn-danger" data-cancel="<?= $j['id'] ?>">Cancel</button>
          <?php endif; ?>
          <?php if ($j['status'] === 'failed'): ?>
            <button class="btn-ghost" data-retry="<?= $j['id'] ?>">Retry Failed</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Log Terbaru</h3>
  <ul class="log-list">
    <?php foreach ($logs as $l): ?>
      <li class="log-<?= htmlspecialchars($l['status']) ?>">
        <small><?= htmlspecialchars($l['created_at']) ?></small>
        [<?= htmlspecialchars($l['status']) ?>]
        <?= htmlspecialchars((string)$l['message']) ?>
        <small><?= htmlspecialchars((string)$l['source_url']) ?></small>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
