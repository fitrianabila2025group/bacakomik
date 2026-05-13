<?php
/** @var string $content */
/** @var array $settings */
$siteName = $settings['site_name'] ?? 'BacaKomik';
$metaTitle = $title ?? ($settings['meta_title'] ?? $siteName);
$metaDesc  = $meta['description'] ?? ($settings['meta_description'] ?? '');
$user = \App\Auth::user();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#6366F1">
<title><?= htmlspecialchars($metaTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($metaDesc) ?>">
<meta property="og:title" content="<?= htmlspecialchars($metaTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($metaDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php $favicon = $settings['site_favicon'] ?? '/favicon.svg'; ?>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($favicon) ?>">
<link rel="alternate icon" type="image/x-icon" href="/favicon.ico">
<link rel="apple-touch-icon" href="/favicon.svg">
<link rel="sitemap" type="application/xml" href="/sitemap.xml">
<link rel="canonical" href="<?= htmlspecialchars(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<script>
const stored = localStorage.getItem('theme'); if (stored) document.documentElement.dataset.theme = stored;
</script>
</head>
<body>
<header class="topbar">
  <div class="container topbar-inner">
    <a href="/" class="brand">
      <?php if (!empty($settings['site_logo'])): ?>
        <img src="<?= htmlspecialchars($settings['site_logo']) ?>" alt="<?= htmlspecialchars($siteName) ?>" class="brand-logo">
      <?php else: ?>
        <span class="brand-mark">B</span>
      <?php endif; ?>
      <span class="brand-name"><?= htmlspecialchars($siteName) ?></span>
    </a>
    <button class="nav-toggle" aria-label="Buka menu" aria-expanded="false" onclick="document.body.classList.toggle('nav-open')">☰</button>
    <nav class="nav-main">
      <a href="/">Home</a>
      <a href="/series">Series</a>
      <a href="/popular">Popular</a>
      <a href="/library">Library</a>
    </nav>
    <div class="nav-actions">
      <form action="/search" method="get" class="search-form">
        <input type="search" name="q" placeholder="Cari komik..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
        <button type="submit" aria-label="Cari">⌕</button>
      </form>
      <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle theme">◐</button>
      <?php if ($user): ?>
        <div class="user-menu">
          <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
          <?php if ($user['role'] === 'admin'): ?><a href="/admin">Admin</a><?php endif; ?>
          <a href="/logout">Keluar</a>
        </div>
      <?php else: ?>
        <a href="/login" class="btn-ghost">Masuk</a>
        <a href="/register" class="btn-primary">Daftar</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main class="main-content">
  <?= $content ?>
</main>

<footer class="site-footer">
  <div class="container">
    <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?>. All rights reserved.</p>
    <nav>
      <a href="/page/about">About</a>
      <a href="/page/dmca">DMCA</a>
      <a href="/page/privacy-policy">Privacy</a>
      <a href="/page/terms">Terms</a>
      <a href="/page/contact">Contact</a>
    </nav>
  </div>
</footer>

<script src="/assets/js/app.js"></script>
</body>
</html>
