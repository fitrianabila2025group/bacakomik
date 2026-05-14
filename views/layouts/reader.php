<?php
/** @var string $content */
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title ?? 'Reader') ?></title>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../../public/assets/css/style.css') ?>">
<script>
const stored = localStorage.getItem('theme'); if (stored) document.documentElement.dataset.theme = stored;
</script>
</head>
<body class="reader-body">
<?= $content ?>
<script src="/assets/js/reader.js"></script>
</body>
</html>
