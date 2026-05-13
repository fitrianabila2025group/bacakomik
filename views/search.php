<?php /** @var array $comics @var array $genres @var array $filters @var int $page */ ?>
<section class="container section">
  <h1>Telusuri Komik</h1>
  <form method="get" action="/series" class="filter-bar">
    <input type="search" name="q" placeholder="Cari judul/author..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
    <select name="type">
      <option value="">Semua Tipe</option>
      <?php foreach (['Manga','Manhwa','Manhua'] as $t): ?>
        <option value="<?= $t ?>" <?= ($filters['type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">Semua Status</option>
      <?php foreach (['Ongoing','Completed','Hiatus'] as $s): ?>
        <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <select name="genre">
      <option value="">Semua Genre</option>
      <?php foreach ($genres as $g): ?>
        <option value="<?= htmlspecialchars($g['slug']) ?>" <?= ($_GET['genre'] ?? '') === $g['slug'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($g['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn-primary" type="submit">Filter</button>
  </form>

  <div class="comic-grid">
    <?php foreach ($comics as $c): ?>
      <a href="/comic/<?= htmlspecialchars($c['slug']) ?>" class="comic-card">
        <div class="cover" style="background-image:url('<?= htmlspecialchars($c['cover_image'] ?? '/assets/img/placeholder.svg') ?>')"></div>
        <div class="meta">
          <h3><?= htmlspecialchars($c['title']) ?></h3>
          <span class="type"><?= htmlspecialchars($c['type']) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$comics): ?>
      <p class="empty">Tidak ada komik ditemukan.</p>
    <?php endif; ?>
  </div>

  <div class="pagination">
    <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Sebelumnya</a><?php endif; ?>
    <span>Halaman <?= $page ?></span>
    <?php if (count($comics) >= 24): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Berikutnya →</a><?php endif; ?>
  </div>
</section>
