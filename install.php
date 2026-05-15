<?php
/**
 * BacaKomik — Web Installer (cPanel friendly).
 *
 * Cara pakai:
 *   1. Upload semua file ke public_html (atau document root domain Anda).
 *   2. Buka di browser:  https://domainanda.com/install.php
 *   3. Isi form (DB host, name, user, password, URL situs).
 *   4. Klik "Install".
 *   5. Setelah sukses, HAPUS file install.php ini lewat File Manager cPanel.
 *
 * Installer ini akan:
 *   - Memvalidasi koneksi & ekstensi PHP.
 *   - Menulis ulang config/database.php dan config/app.php.
 *   - Membuat database (jika belum ada) dan import schema + seed.
 *   - Reset password admin (admin@example.com / admin12345).
 *   - Membuat folder storage/ + sub-foldernya.
 *
 * Tidak butuh SSH / composer / php-cli.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
@set_time_limit(0);

// --- Cegah akses ulang setelah selesai ----------------------------------
$lockFile = __DIR__ . '/storage/.installed';
if (is_file($lockFile) && !isset($_GET['force'])) {
    http_response_code(403);
    render_page(
        'Sudah Terinstall',
        '<div class="alert err">'
        . '<strong>BacaKomik sudah terinstall.</strong><br>'
        . 'Demi keamanan, <b>HAPUS file <code>install.php</code></b> sekarang juga lewat File Manager cPanel.<br><br>'
        . 'Jika benar-benar ingin install ulang, tambahkan <code>?force=1</code> di URL.'
        . '</div>'
        . '<a class="btn" href="' . htmlspecialchars(detect_base_url()) . '/">Buka Situs</a>'
    );
    exit;
}

// --- Router sederhana ---------------------------------------------------
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'install' : 'form';

if ($action === 'install') {
    handle_install();
} else {
    show_form();
}

// =========================================================================
// HANDLERS
// =========================================================================

function show_form(array $errors = [], array $old = []): void
{
    $checks = run_preflight_checks();
    $defaultUrl = detect_base_url();

    $val = function (string $k, string $d = '') use ($old) {
        return htmlspecialchars($old[$k] ?? $d, ENT_QUOTES);
    };

    ob_start(); ?>
    <h1>BacaKomik — Installer</h1>
    <p class="muted">Installer berbasis web. Tidak butuh SSH / Terminal.</p>

    <h2>1. Pemeriksaan Server</h2>
    <table class="checks">
      <?php foreach ($checks as $name => $info): ?>
        <tr class="<?= $info['ok'] ? 'ok' : 'fail' ?>">
          <td><?= htmlspecialchars($name) ?></td>
          <td><?= htmlspecialchars($info['msg']) ?></td>
          <td><?= $info['ok'] ? '✓' : '✗' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <?php if ($errors): ?>
      <div class="alert err">
        <strong>Gagal install:</strong>
        <ul><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
      </div>
    <?php endif; ?>

    <h2>2. Konfigurasi Database</h2>
    <p class="muted">Buat dulu database & user MySQL di cPanel → <em>MySQL® Databases</em>, lalu isi di sini.</p>

    <form method="post" autocomplete="off">
      <label>DB Host
        <input name="db_host" value="<?= $val('db_host', 'localhost') ?>" required>
        <small>Biasanya <code>localhost</code> atau <code>127.0.0.1</code>.</small>
      </label>

      <label>DB Port
        <input name="db_port" value="<?= $val('db_port', '3306') ?>" required>
      </label>

      <label>Nama Database
        <input name="db_name" value="<?= $val('db_name') ?>" required placeholder="contoh: cpaneluser_bacakomik">
      </label>

      <label>DB Username
        <input name="db_user" value="<?= $val('db_user') ?>" required placeholder="contoh: cpaneluser_bacauser">
      </label>

      <label>DB Password
        <input name="db_pass" type="password" value="<?= $val('db_pass') ?>">
      </label>

      <h2>3. Konfigurasi Aplikasi</h2>

      <label>URL Situs (tanpa trailing slash)
        <input name="app_url" value="<?= $val('app_url', $defaultUrl) ?>" required>
        <small>Contoh: <code>https://komikku.com</code></small>
      </label>

      <label>Nama Situs
        <input name="app_name" value="<?= $val('app_name', 'BacaKomik') ?>" required>
      </label>

      <label>Email Admin
        <input name="admin_email" type="email" value="<?= $val('admin_email', 'admin@example.com') ?>" required>
      </label>

      <label>Password Admin
        <input name="admin_pass" type="text" value="<?= $val('admin_pass', 'admin12345') ?>" required minlength="8">
        <small>Minimal 8 karakter. Ganti segera setelah login.</small>
      </label>

      <h2>4. Scraper API <small style="font-weight:400">(opsional — bisa diisi nanti di /admin/settings)</small></h2>

      <label>Scraper API URL
        <input name="scraper_api_url" value="<?= $val('scraper_api_url', '') ?>" placeholder="https://your-scraper.up.railway.app">
        <small>Kosongkan jika belum punya. Tanpa scraper API, fitur import komik <b>tidak akan jalan</b> di shared hosting.</small>
      </label>

      <label>Scraper API Key
        <input name="scraper_api_key" value="<?= $val('scraper_api_key', '') ?>" placeholder="X-API-Key value">
      </label>

      <button class="btn primary" type="submit">▶ Install Sekarang</button>
    </form>
    <?php
    render_page('Installer BacaKomik', ob_get_clean());
}

function handle_install(): void
{
    $errors = [];
    $in = [
        'db_host'     => trim((string)($_POST['db_host'] ?? '')),
        'db_port'     => trim((string)($_POST['db_port'] ?? '3306')),
        'db_name'     => trim((string)($_POST['db_name'] ?? '')),
        'db_user'     => trim((string)($_POST['db_user'] ?? '')),
        'db_pass'     => (string)($_POST['db_pass'] ?? ''),
        'app_url'     => rtrim(trim((string)($_POST['app_url'] ?? '')), '/'),
        'app_name'    => trim((string)($_POST['app_name'] ?? 'BacaKomik')),
        'admin_email' => trim((string)($_POST['admin_email'] ?? '')),
        'admin_pass'  => (string)($_POST['admin_pass'] ?? ''),
        'scraper_api_url' => rtrim(trim((string)($_POST['scraper_api_url'] ?? '')), '/'),
        'scraper_api_key' => trim((string)($_POST['scraper_api_key'] ?? '')),
    ];

    foreach (['db_host','db_name','db_user','app_url','app_name','admin_email','admin_pass'] as $req) {
        if ($in[$req] === '') $errors[] = "Field $req wajib diisi.";
    }
    if (strlen($in['admin_pass']) < 8) $errors[] = 'Password admin minimal 8 karakter.';
    if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email admin tidak valid.';

    foreach (run_preflight_checks() as $name => $info) {
        if (!$info['ok']) $errors[] = "Cek server gagal: $name — {$info['msg']}";
    }

    if ($errors) { show_form($errors, $in); return; }

    // 1) Test koneksi DB
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $in['db_host'], $in['db_port']),
            $in['db_user'], $in['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $e) {
        show_form(['Tidak bisa konek ke MySQL: ' . $e->getMessage()], $in); return;
    }

    // 2) Create DB if missing & switch
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$in['db_name']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $pdo->exec("USE `{$in['db_name']}`;");
    } catch (Throwable $e) {
        show_form([
            "Gagal membuat / memilih database `{$in['db_name']}`. "
            . "Beberapa hosting tidak mengizinkan CREATE DATABASE — buat manual di cPanel dulu lalu retry. ({$e->getMessage()})"
        ], $in); return;
    }

    // 3) Import schema + seed
    try {
        $schema = (string)file_get_contents(__DIR__ . '/database/schema.sql');
        $seed   = (string)file_get_contents(__DIR__ . '/database/seed.sql');
        if ($schema === '') throw new RuntimeException('database/schema.sql tidak ditemukan.');

        run_sql_script($pdo, $schema);
        if ($seed !== '') run_sql_script($pdo, $seed);
    } catch (Throwable $e) {
        show_form(['Import SQL gagal: ' . $e->getMessage()], $in); return;
    }

    // 4) Update / insert admin
    try {
        $hash = password_hash($in['admin_pass'], PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$in['admin_email']]);
        if ($stmt->fetchColumn()) {
            $u = $pdo->prepare('UPDATE users SET password_hash = ?, role = "admin", status = "active" WHERE email = ?');
            $u->execute([$hash, $in['admin_email']]);
        } else {
            $u = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, status) VALUES (?,?,?,?,?)');
            $u->execute(['Administrator', $in['admin_email'], $hash, 'admin', 'active']);
        }
    } catch (Throwable $e) {
        show_form(['Gagal set akun admin: ' . $e->getMessage()], $in); return;
    }

    // 4b) Seed default settings (scraper + comments) supaya fitur import &
    //     komentar langsung aktif tanpa perlu klik manual di /admin/settings.
    try {
        $useApi = ($in['scraper_api_url'] !== '') ? '1' : '0';
        $defaults = [
            'scraper_use_api'     => $useApi,
            'scraper_api_url'     => $in['scraper_api_url'],
            'scraper_api_key'     => $in['scraper_api_key'],
            'scraper_api_timeout' => '30',
            'scraper_remote_storage' => '1',
            'scraper_proxy_public'   => '1',
            'comments_enabled'    => '1',
            'comments_on_comic'   => '1',
            'comments_on_chapter' => '1',
            'comments_api_url'    => '',
        ];
        $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($defaults as $k => $v) { $ins->execute([$k, $v]); }
    } catch (Throwable $e) {
        // Non-fatal: admin bisa konfigurasi manual nanti.
    }

    // 5) Tulis config files
    try {
        write_config_db($in);
        write_config_app($in);
    } catch (Throwable $e) {
        show_form(['Gagal menulis file config (cek permission): ' . $e->getMessage()], $in); return;
    }

    // 6) Buat folder storage
    foreach (['storage','storage/comics','storage/covers','storage/cache','storage/settings'] as $dir) {
        $full = __DIR__ . '/' . $dir;
        if (!is_dir($full)) @mkdir($full, 0775, true);
        @chmod($full, 0775);
    }

    // 7) Lock file (mencegah re-install tidak sengaja)
    @file_put_contents(__DIR__ . '/storage/.installed', date('c'));

    // Done
    $siteUrl = htmlspecialchars($in['app_url']);
    $email   = htmlspecialchars($in['admin_email']);
    $body = '<div class="alert ok"><strong>✓ Installasi selesai!</strong></div>'
        . '<h2>Langkah Selanjutnya</h2>'
        . '<ol>'
        . '<li><b>HAPUS file <code>install.php</code></b> dari File Manager cPanel sekarang juga.</li>'
        . '<li>Buka <a href="' . $siteUrl . '/login">' . $siteUrl . '/login</a></li>'
        . '<li>Login sebagai <code>' . $email . '</code> dengan password yang baru saja Anda set.</li>'
        . '<li>Buka <b>Admin → Settings</b> untuk mengatur logo, SEO, dll.</li>'
        . '<li>Buka <b>Admin → Import → Auto-Crawl</b> untuk impor konten.</li>'
        . '</ol>'
        . '<a class="btn primary" href="' . $siteUrl . '/login">Buka Halaman Login</a>';
    render_page('Selesai', $body);
}

// =========================================================================
// HELPERS
// =========================================================================

function run_preflight_checks(): array
{
    $checks = [];
    $checks['PHP >= 8.1'] = [
        'ok'  => version_compare(PHP_VERSION, '8.1.0', '>='),
        'msg' => 'Versi terdeteksi: ' . PHP_VERSION,
    ];
    foreach (['pdo_mysql','mbstring','curl','dom','fileinfo','json'] as $ext) {
        $checks["Ext: $ext"] = [
            'ok'  => extension_loaded($ext),
            'msg' => extension_loaded($ext) ? 'aktif' : 'TIDAK aktif — aktifkan di cPanel → Select PHP Version → Extensions',
        ];
    }
    foreach (['config/database.php','config/app.php'] as $f) {
        $path = __DIR__ . '/' . $f;
        $checks["Writable: $f"] = [
            'ok'  => is_writable($path) || is_writable(dirname($path)),
            'msg' => is_writable($path) ? 'OK' : 'Set permission folder config/ ke 755 dan file ke 644 lewat File Manager.',
        ];
    }
    $checks['Writable: storage/'] = [
        'ok'  => is_dir(__DIR__ . '/storage') ? is_writable(__DIR__ . '/storage') : is_writable(__DIR__),
        'msg' => 'Folder storage/ harus writable (775).',
    ];
    return $checks;
}

function run_sql_script(PDO $pdo, string $sql): void
{
    // Eksekusi multi-statement. Kita pakai exec() langsung karena schema/seed
    // kita bukan input user. Jika driver tidak mendukung multi-statement,
    // fallback ke pemecahan per-statement.
    try {
        $pdo->exec($sql);
        return;
    } catch (Throwable $_) {
        // fallthrough ke parser sederhana
    }

    // Parser sederhana — buang komentar baris (-- ...) lalu split berdasar ;\n
    $clean = preg_replace('/^\s*--.*$/m', '', $sql);
    $stmts = preg_split('/;\s*\n/', (string)$clean);
    foreach ($stmts as $s) {
        $s = trim((string)$s);
        if ($s === '') continue;
        $pdo->exec($s);
    }
}

function write_config_db(array $in): void
{
    $tpl = "<?php\n/**\n * Database configuration. Auto-generated by install.php.\n */\nreturn [\n"
        . "    'host'     => getenv('DB_HOST') ?: " . var_export($in['db_host'], true) . ",\n"
        . "    'port'     => getenv('DB_PORT') ?: " . var_export($in['db_port'], true) . ",\n"
        . "    'database' => getenv('DB_NAME') ?: " . var_export($in['db_name'], true) . ",\n"
        . "    'username' => getenv('DB_USER') ?: " . var_export($in['db_user'], true) . ",\n"
        . "    'password' => getenv('DB_PASS') ?: " . var_export($in['db_pass'], true) . ",\n"
        . "    'charset'  => 'utf8mb4',\n"
        . "];\n";
    if (file_put_contents(__DIR__ . '/config/database.php', $tpl) === false) {
        throw new RuntimeException('config/database.php tidak bisa ditulis.');
    }
}

function write_config_app(array $in): void
{
    $tpl = "<?php\n/**\n * Application configuration. Auto-generated by install.php.\n */\nreturn [\n"
        . "    'name'      => " . var_export($in['app_name'], true) . ",\n"
        . "    'url'       => getenv('APP_URL') ?: " . var_export($in['app_url'], true) . ",\n"
        . "    'env'       => getenv('APP_ENV') ?: 'production',\n"
        . "    'debug'     => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),\n"
        . "    'storage'   => __DIR__ . '/../storage',\n"
        . "    'public'    => __DIR__ . '/../public',\n"
        . "    'upload_max' => 2 * 1024 * 1024,\n"
        . "    'allowed_image_ext' => ['jpg', 'jpeg', 'png', 'webp'],\n"
        . "];\n";
    if (file_put_contents(__DIR__ . '/config/app.php', $tpl) === false) {
        throw new RuntimeException('config/app.php tidak bisa ditulis.');
    }
}

function detect_base_url(): string
{
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host;
}

function render_page(string $title, string $body): void
{
    $t = htmlspecialchars($title);
    echo <<<HTML
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{$t} · BacaKomik</title>
<style>
  * { box-sizing: border-box; }
  body { font: 15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
         background:#f4f6fa; color:#1f2937; margin:0; padding:32px 16px; }
  .wrap { max-width:720px; margin:0 auto; background:#fff; border-radius:14px;
          padding:28px 32px; box-shadow:0 6px 24px rgba(0,0,0,.06); }
  h1 { margin:0 0 6px; font-size:26px; }
  h2 { margin-top:28px; font-size:18px; border-top:1px solid #eef0f4; padding-top:18px; }
  .muted { color:#6b7280; }
  label { display:block; margin:14px 0; font-weight:600; font-size:14px; }
  input { display:block; width:100%; padding:10px 12px; border:1px solid #d1d5db;
          border-radius:8px; font-size:14px; margin-top:6px; font-family:inherit; }
  input:focus { outline:2px solid #6366f1; border-color:#6366f1; }
  small { display:block; color:#6b7280; font-weight:400; margin-top:4px; }
  .btn { display:inline-block; padding:12px 22px; background:#e5e7eb; color:#111;
         text-decoration:none; border:0; border-radius:10px; font-weight:600;
         cursor:pointer; font-size:15px; margin-top:16px; }
  .btn.primary { background:#4f46e5; color:#fff; }
  .btn.primary:hover { background:#4338ca; }
  .alert { padding:12px 14px; border-radius:8px; margin:16px 0; }
  .alert.ok { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
  .alert.err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
  table.checks { width:100%; border-collapse:collapse; margin-top:8px; font-size:13px; }
  table.checks td { padding:6px 8px; border-bottom:1px solid #f1f3f7; }
  table.checks tr.ok td:last-child { color:#059669; font-weight:700; }
  table.checks tr.fail td:last-child { color:#dc2626; font-weight:700; }
  code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:13px; }
  ol li { margin:6px 0; }
</style>
</head>
<body>
  <div class="wrap">
    {$body}
  </div>
</body>
</html>
HTML;
}
