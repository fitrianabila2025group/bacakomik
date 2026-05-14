<?php
/** @var array $comic @var array $chapter @var array $images @var array $chapterList @var ?array $prev @var ?array $next */
?>
<header class="reader-topbar">
  <div class="container reader-topbar-inner">
    <a href="/comic/<?= htmlspecialchars($comic['slug']) ?>" class="back">← <?= htmlspecialchars($comic['title']) ?></a>
    <div class="reader-controls">
      <select onchange="if(this.value) location.href=this.value">
        <?php foreach ($chapterList as $c): ?>
          <option value="/comic/<?= htmlspecialchars($comic['slug']) ?>/chapter/<?= htmlspecialchars($c['slug']) ?>"
            <?= $c['id'] == $chapter['id'] ? 'selected' : '' ?>>
            Chapter <?= htmlspecialchars($c['chapter_number']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn-ghost" onclick="document.body.classList.toggle('reader-dark')">🌙</button>
    </div>
  </div>
</header>

<?php ad('reader_top'); ?>

<main class="reader-stage">
  <?php $mid = (int)floor(count($images) / 2); ?>
  <?php foreach ($images as $i => $img): ?>
    <img loading="lazy" src="<?= htmlspecialchars(imgproxy($img['image_path'])) ?>" alt="page <?= $i+1 ?>" class="reader-page">
    <?php if ($i === $mid) ad('reader_middle'); ?>
  <?php endforeach; ?>
</main>

<?php ad('reader_bottom'); ?>

<nav class="reader-nav">
  <div class="container reader-nav-inner">
    <?php if ($prev): ?>
      <a class="btn-ghost" href="/comic/<?= htmlspecialchars($comic['slug']) ?>/chapter/<?= htmlspecialchars($prev['slug']) ?>">← Prev</a>
    <?php else: ?><span></span><?php endif; ?>
    <a class="btn-primary" href="/comic/<?= htmlspecialchars($comic['slug']) ?>">Daftar Chapter</a>
    <?php if ($next): ?>
      <a class="btn-primary" href="/comic/<?= htmlspecialchars($comic['slug']) ?>/chapter/<?= htmlspecialchars($next['slug']) ?>">Next →</a>
    <?php else: ?><span></span><?php endif; ?>
  </div>
</nav>
