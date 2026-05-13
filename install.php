<?php
/**
 * BacaKomik installer.
 * Run from CLI: php install.php
 *
 * - Imports database/schema.sql
 * - Imports database/seed.sql
 * - Recreates the admin user with a freshly generated bcrypt password hash
 *   (default credentials: admin@example.com / admin12345)
 * - Creates required storage directories
 */
declare(strict_types=1);

$config = require __DIR__ . '/config/database.php';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `{$config['database']}`;");

    echo "→ Importing schema...\n";
    $pdo->exec(file_get_contents(__DIR__ . '/database/schema.sql'));
    echo "→ Importing seed...\n";
    $pdo->exec(file_get_contents(__DIR__ . '/database/seed.sql'));

    // Replace admin password with a freshly generated bcrypt hash.
    $hash = password_hash('admin12345', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@example.com'");
    $stmt->execute([$hash]);

    foreach (['storage','storage/comics','storage/covers','storage/cache','storage/settings'] as $dir) {
        $full = __DIR__ . '/' . $dir;
        if (!is_dir($full)) mkdir($full, 0775, true);
    }

    echo "✓ Installasi selesai.\n";
    echo "  Admin login: admin@example.com / admin12345\n";
    echo "  Jalankan: php -S localhost:8000 -t public\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Installasi gagal: " . $e->getMessage() . "\n");
    exit(1);
}
