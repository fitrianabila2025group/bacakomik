<?php
/** @var array $comic @var array $chapters @var array $genres @var bool $isBookmarked */
$cover = $comic['cover_image'] ? imgproxy($comic['cover_image']) : '/assets/img/placeholder.svg';
?>
<div class="detail-hero" style="background-image:linear-gradient(180deg, rgba(0,0,0,.4), var(--bg)), url('<?= htmlspecialchars($cover) ?>')">
  <div class="container detail-hero-inner">
    <div class="detail-cover">
      <img src="<?= htmlspecialchars($cover) ?>" alt="<?= htmlspecialchars($comic['title']) ?>">
    </div>
    <div class="detail-info">
      <span class="badge"><?= htmlspecialchars($comic['type']) ?></span>
      <h1><?= htmlspecialchars($comic['title']) ?></h1>
      <?php if (!empty($comic['alt_title'])): ?>
        <p class="alt-title"><?= htmlspecialchars($comic['alt_title']) ?></p>
      <?php endif; ?>
      <ul class="detail-meta">
        <li><b>Status</b><?= htmlspecialchars($comic['status']) ?></li>
        <li><b>Author</b><?= htmlspecialchars($comic['author'] ?? '-') ?></li>
        <li><b>Artist</b><?= htmlspecialchars($comic['artist'] ?? '-') ?></li>
        <li><b>Rating</b>★ <?= number_format((float)$comic['rating'], 1) ?></li>
        <li><b>Views</b><?= number_format((int)$comic['views']) ?></li>
        <li><b>Chapters</b><?= count($chapters) ?></li>
      </ul>
      <div class="detail-actions">
        <?php if (!empty($chapters)): $first = end($chapters); reset($chapters); ?>
          <a class="btn-primary" href="/comic/<?= htmlspecialchars($comic['slug']) ?>/chapter/<?= htmlspecialchars($first['slug']) ?>">Mulai Baca</a>
        <?php endif; ?>
        <button class="btn-ghost bookmark-btn" data-comic="<?= (int)$comic['id'] ?>" data-active="<?= $isBookmarked ? '1' : '0' ?>">
          <?= $isBookmarked ? 'Tersimpan' : 'Bookmark' ?>
        </button>
        <button class="btn-ghost" onclick="navigator.share && navigator.share({url:location.href,title:document.title})">Share</button>
      </div>
      <div class="genre-pills">
        <?php foreach ($genres as $g): ?>
          <a href="/series?genre=<?= htmlspecialchars($g['slug']) ?>" class="pill"><?= htmlspecialchars($g['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php ad('detail_top'); ?>

<div class="container detail-body">
  <div class="tabs" data-tabs>
    <button class="tab active" data-tab="chapters">Chapters</button>
    <button class="tab" data-tab="info">Info</button>
  </div>

  <section class="tab-panel active" data-panel="chapters">
    <div class="chapter-toolbar">
      <input type="search" placeholder="Cari chapter..." class="chapter-search">
      <button class="btn-ghost" onclick="document.querySelector('.chapter-list').classList.toggle('reverse')">Sort ↕</button>
    </div>
    <ul class="chapter-list">
      <?php foreach ($chapters as $ch): ?>
        <li>
          <a href="/comic/<?= htmlspecialchars($comic['slug']) ?>/chapter/<?= htmlspecialchars($ch['slug']) ?>">
            <span class="ch-title">Chapter <?= htmlspecialchars($ch['chapter_number']) ?></span>
            <span class="ch-meta"><?= date('d M Y', strtotime($ch['created_at'])) ?> · 👁 <?= number_format((int)$ch['views']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="tab-panel" data-panel="info">
    <h3>Sinopsis</h3>
    <div class="synopsis collapsible">
      <p><?= nl2br(htmlspecialchars((string)$comic['synopsis'])) ?></p>
    </div>
    <button class="show-more" onclick="this.previousElementSibling.classList.toggle('open'); this.textContent = this.previousElementSibling.classList.contains('open') ? 'Show less' : 'Show more'">Show more</button>
  </section>
</div>

<?php ad('detail_bottom'); ?>
