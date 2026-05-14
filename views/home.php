<?php
/** @var array $featured @var array $latest @var array $popular @var array $genres */
$cardStyle = $settings['card_style'] ?? 'modern';
$heroLayout = $settings['hero_layout'] ?? 'classic';
?>
<section class="hero hero-<?= htmlspecialchars($heroLayout) ?>">
  <div class="container">
    <?php if (!empty($featured)): ?>
      <div class="hero-slider">
        <?php foreach (array_slice($featured, 0, 5) as $i => $c): ?>
          <article class="hero-slide <?= $i === 0 ? 'active' : '' ?>" style="--bg:url('<?= htmlspecialchars(imgproxy($c['cover_image'] ?? '')) ?>')">
            <div class="hero-content">
              <span class="badge"><?= htmlspecialchars($c['type']) ?></span>
              <h1><?= htmlspecialchars($c['title']) ?></h1>
              <p><?= htmlspecialchars(mb_substr((string)$c['synopsis'], 0, 220)) ?>...</p>
              <a class="btn-primary" href="/comic/<?= htmlspecialchars($c['slug']) ?>">Mulai Baca</a>
            </div>
            <?php if (!empty($c['cover_image'])): ?>
              <img class="hero-cover" src="<?= htmlspecialchars(imgproxy($c['cover_image'])) ?>" alt="">
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php ad('home_top'); ?>

<section class="section container">
  <div class="section-head">
    <h2>Komik Terbaru</h2>
    <a href="/series" class="link">Lihat semua →</a>
  </div>
  <div class="comic-grid card-style-<?= htmlspecialchars($cardStyle) ?>">
    <?php foreach ($latest as $c): ?>
      <a href="/comic/<?= htmlspecialchars($c['slug']) ?>" class="comic-card">
        <div class="cover" style="background-image:url('<?= htmlspecialchars(!empty($c['cover_image']) ? imgproxy($c['cover_image']) : '/assets/img/placeholder.svg') ?>')"></div>
        <div class="meta">
          <h3><?= htmlspecialchars($c['title']) ?></h3>
          <span class="type"><?= htmlspecialchars($c['type']) ?> · <?= htmlspecialchars($c['status']) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="section container">
  <div class="section-head">
    <h2>Populer</h2>
    <a href="/popular" class="link">Lihat semua →</a>
  </div>
  <div class="comic-grid card-style-<?= htmlspecialchars($cardStyle) ?>">
    <?php foreach ($popular as $c): ?>
      <a href="/comic/<?= htmlspecialchars($c['slug']) ?>" class="comic-card">
        <div class="cover" style="background-image:url('<?= htmlspecialchars(!empty($c['cover_image']) ? imgproxy($c['cover_image']) : '/assets/img/placeholder.svg') ?>')"></div>
        <div class="meta">
          <h3><?= htmlspecialchars($c['title']) ?></h3>
          <span class="type">👁 <?= number_format((int)$c['views']) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="section container">
  <div class="section-head"><h2>Genre</h2></div>
  <div class="genre-pills">
    <?php foreach ($genres as $g): ?>
      <a href="/series?genre=<?= htmlspecialchars($g['slug']) ?>" class="pill"><?= htmlspecialchars($g['name']) ?></a>
    <?php endforeach; ?>
  </div>
</section>

<?php ad('home_bottom'); ?>
