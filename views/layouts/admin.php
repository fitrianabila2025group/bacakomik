<?php
/** @var string $content */
$user = \App\Auth::user();
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(($title ?? 'Admin') . ' - BacaKomik Admin') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-brand">
    <span class="brand-mark">B</span>
    <span>BacaKomik</span>
  </div>
  <nav class="sidebar-nav">
    <a href="/admin" class="<?= $_SERVER['REQUEST_URI'] === '/admin' ? 'active' : '' ?>">Overview</a>
    <a href="/admin/library" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/library') ? 'active' : '' ?>">Library</a>
    <a href="/admin/chapters" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/chapters') ? 'active' : '' ?>">Chapters</a>
    <a href="/admin/pages" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/pages') ? 'active' : '' ?>">Pages</a>
    <a href="/admin/reports" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/reports') ? 'active' : '' ?>">Reports</a>
    <a href="/admin/import" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/import') ? 'active' : '' ?>">Import</a>
    <a href="/admin/users" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/users') ? 'active' : '' ?>">Users</a>
    <a href="/admin/settings" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/settings') ? 'active' : '' ?>">Settings</a>
    <a href="/admin/appearance" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/appearance') ? 'active' : '' ?>">Appearance</a>
    <a href="/admin/ads" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/ads') ? 'active' : '' ?>">Ads</a>
    <a href="/admin/license" class="<?= str_contains($_SERVER['REQUEST_URI'], '/admin/license') ? 'active' : '' ?>">License</a>
    <a href="/logout">Sign out</a>
  </nav>
</aside>

<div class="admin-main">
  <header class="admin-topbar">
    <div class="topbar-search">
      <input type="search" placeholder="Cari di admin...">
    </div>
    <div class="topbar-user">
      <span><?= htmlspecialchars($user['name'] ?? '') ?></span>
      <span class="role-pill"><?= htmlspecialchars($user['role'] ?? '') ?></span>
    </div>
  </header>

  <?php if ($flash): ?>
    <div class="flash"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <div class="admin-content">
    <?= $content ?>
  </div>
</div>

<script src="/assets/js/admin.js"></script>
<?php if (str_contains($_SERVER['REQUEST_URI'], '/admin/import')): ?>
<script src="/assets/js/import.js"></script>
<?php endif; ?>
<?php if (str_contains($_SERVER['REQUEST_URI'], '/admin') && $_SERVER['REQUEST_URI'] === '/admin'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php endif; ?>
</body>
</html>
