<?php /** @var array $bookmarks @var array $history */ ?>
<section class="container section">
  <h1>Library</h1>
  <h2>Bookmark</h2>
  <div class="comic-grid">
    <?php if (empty($bookmarks)): ?><p class="empty">Belum ada bookmark.</p><?php endif; ?>
    <?php foreach ($bookmarks as $c): ?>
      <a href="/comic/<?= htmlspecialchars($c['slug']) ?>" class="comic-card">
        <div class="cover" style="background-image:url('<?= htmlspecialchars(!empty($c['cover_image']) ? imgproxy($c['cover_image']) : '/assets/img/placeholder.svg') ?>')"></div>
        <div class="meta"><h3><?= htmlspecialchars($c['title']) ?></h3></div>
      </a>
    <?php endforeach; ?>
  </div>

  <h2 style="margin-top:2rem">Riwayat Baca</h2>
  <ul class="history-list">
    <?php if (empty($history)): ?><li>Belum ada riwayat.</li><?php endif; ?>
    <?php foreach ($history as $h): ?>
      <li>
        <a href="/comic/<?= htmlspecialchars($h['slug']) ?>/chapter/<?= htmlspecialchars($h['ch_slug']) ?>">
          <?= htmlspecialchars($h['title']) ?> — Chapter <?= htmlspecialchars($h['chapter_number']) ?>
        </a>
        <small><?= date('d M Y H:i', strtotime($h['last_read_at'])) ?></small>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
