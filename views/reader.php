<?php
/** @var array      $comic */
/** @var array      $chapter */
/** @var array      $images */
/** @var array      $chapterList */
/** @var array|null $prev */
/** @var array|null $next */

$comicUrl = '/comic/' . rawurlencode($comic['slug']);
$chapterNumber = htmlspecialchars((string)$chapter['chapter_number']);
$comicTitle    = htmlspecialchars($comic['title']);
?>
<header class="reader-topbar">
  <div class="reader-topbar-inner">
    <a class="back" href="<?= htmlspecialchars($comicUrl) ?>">
      ← <?= $comicTitle ?> Chapter <?= $chapterNumber ?>
    </a>

    <div class="reader-controls">
      <select onchange="if(this.value) location.href=this.value" aria-label="Pilih chapter">
        <?php foreach ($chapterList as $ch): ?>
          <?php $url = $comicUrl . '/chapter/' . rawurlencode($ch['slug']); ?>
          <option value="<?= htmlspecialchars($url) ?>" <?= ((int)$ch['id'] === (int)$chapter['id']) ? 'selected' : '' ?>>
            Ch. <?= htmlspecialchars((string)$ch['chapter_number']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button type="button" class="btn-ghost" onclick="document.body.classList.toggle('reader-dark')" aria-label="Toggle dark mode">◐</button>
    </div>
  </div>
</header>

<?php ad('reader_top'); ?>

<nav class="reader-nav">
  <div class="reader-nav-inner">
    <?php if ($prev): ?>
      <a class="btn-ghost" href="<?= htmlspecialchars($comicUrl . '/chapter/' . rawurlencode($prev['slug'])) ?>">← Prev</a>
    <?php else: ?>
      <span class="btn-ghost disabled" aria-disabled="true">← Prev</span>
    <?php endif; ?>

    <a class="btn-primary" href="<?= htmlspecialchars($comicUrl) ?>">Detail Komik</a>

    <?php if ($next): ?>
      <a class="btn-ghost" href="<?= htmlspecialchars($comicUrl . '/chapter/' . rawurlencode($next['slug'])) ?>">Next →</a>
    <?php else: ?>
      <span class="btn-ghost disabled" aria-disabled="true">Next →</span>
    <?php endif; ?>
  </div>
</nav>

<main class="reader-stage">
  <?php if (empty($images)): ?>
    <div class="container" style="padding:2rem 1rem;text-align:center">
      <h1>Gambar chapter belum tersedia</h1>
      <p>Chapter ini ada di database, tapi belum memiliki gambar.</p>
      <p><a class="btn-primary" href="<?= htmlspecialchars($comicUrl) ?>">Kembali ke detail komik</a></p>
    </div>
  <?php else: ?>
    <?php foreach ($images as $i => $img): ?>
      <?php
        $src = $img['image_path'] ?? ($img['image_url'] ?? '');
        // CRITICAL: route via imgproxy() -> /img.php on shared host.
        // Railway IP diblok komiku/Cloudflare -> 403.
        $src = imgproxy($src);
      ?>
      <?php if ($src): ?>
        <img
          class="reader-page"
          src="<?= htmlspecialchars($src) ?>"
          alt="<?= $comicTitle ?> Chapter <?= $chapterNumber ?> Page <?= $i + 1 ?>"
          loading="<?= $i < 2 ? 'eager' : 'lazy' ?>"
          decoding="async"
        >
        <?php if ($i === 2): ad('reader_middle'); endif; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<?php ad('reader_bottom'); ?>

<nav class="reader-nav">
  <div class="reader-nav-inner">
    <?php if ($prev): ?>
      <a class="btn-ghost" href="<?= htmlspecialchars($comicUrl . '/chapter/' . rawurlencode($prev['slug'])) ?>">← Prev</a>
    <?php else: ?>
      <span class="btn-ghost disabled" aria-disabled="true">← Prev</span>
    <?php endif; ?>

    <a class="btn-primary" href="<?= htmlspecialchars($comicUrl) ?>">Detail Komik</a>

    <?php if ($next): ?>
      <a class="btn-ghost" href="<?= htmlspecialchars($comicUrl . '/chapter/' . rawurlencode($next['slug'])) ?>">Next →</a>
    <?php else: ?>
      <span class="btn-ghost disabled" aria-disabled="true">Next →</span>
    <?php endif; ?>
  </div>
</nav>

<section class="reader-comments">
  <div class="container" style="max-width:900px">
    <?= \App\Comments::render('chapter', 'chapter:' . $chapter['id']) ?>
  </div>
</section>
