#!/usr/bin/env bash
# Docker entrypoint for BacaKomik.
# - Waits for the database to be reachable (if DB_HOST is set).
# - Optionally runs install.php on first boot when AUTO_INSTALL=1.
set -e

if [[ -n "${DB_HOST:-}" ]]; then
  echo "[entrypoint] Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
  for i in $(seq 1 60); do
    if php -r "try{ new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:3306), getenv('DB_USER'), getenv('DB_PASS')); exit(0); }catch(Throwable \$e){ exit(1); }"; then
      echo "[entrypoint] MySQL reachable."
      break
    fi
    sleep 1
  done
fi

if [[ "${AUTO_INSTALL:-0}" == "1" ]]; then
  # Idempotent schema + seed loader. install.php is web-only (POST), so
  # for unattended docker first-boot we import the SQL ourselves and write
  # the lock file install.php expects.
  LOCK=/var/www/html/storage/.installed
  if [[ ! -f "$LOCK" ]]; then
    echo "[entrypoint] AUTO_INSTALL=1 — bootstrapping schema + seed..."
    mkdir -p /var/www/html/storage/comics /var/www/html/storage/covers \
             /var/www/html/storage/cache  /var/www/html/storage/settings
    chown -R www-data:www-data /var/www/html/storage

    HAS_TABLES=$(php -r "
      try {
        \$db = new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:3306).';dbname='.getenv('DB_NAME').';charset=utf8mb4',
                       getenv('DB_USER'), getenv('DB_PASS'),
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \$n = (int)\$db->query(\"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'\")->fetchColumn();
        echo \$n;
      } catch (Throwable \$e) { echo 0; }
    ")

    if [[ "$HAS_TABLES" == "0" ]]; then
      echo "[entrypoint] Importing database/schema.sql + seed.sql ..."
      ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}" \
      ADMIN_PASS="${ADMIN_PASS:-admin12345}" \
      SCRAPER_API_URL="${SCRAPER_API_URL:-http://scraper:8000}" \
      SCRAPER_API_KEY_ENV="${SCRAPER_API_KEY:-devtest123}" \
      php -r "
        \$db = new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:3306).';dbname='.getenv('DB_NAME').';charset=utf8mb4',
                      getenv('DB_USER'), getenv('DB_PASS'),
                      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['schema','seed'] as \$name) {
          \$sql = file_get_contents('/var/www/html/database/'.\$name.'.sql');
          if (\$sql === false || trim(\$sql) === '') continue;
          \$sql = preg_replace('!/\*.*?\*/!s', '', \$sql);
          // strip whole-line -- comments before splitting on ;
          \$sql = preg_replace('/^[ \t]*--[^\n]*\n/m', '', \$sql);
          foreach (preg_split('/;\s*\n/', \$sql) as \$stmt) {
            \$stmt = trim(\$stmt);
            if (\$stmt === '') continue;
            \$db->exec(\$stmt);
          }
          fwrite(STDERR, '[entrypoint]   loaded '.\$name.'.sql'.PHP_EOL);
        }
        // Seed admin user (if not present)
        \$email = getenv('ADMIN_EMAIL');
        \$pass  = getenv('ADMIN_PASS');
        \$hash  = password_hash(\$pass, PASSWORD_BCRYPT);
        \$st = \$db->prepare('SELECT id FROM users WHERE email = ?');
        \$st->execute([\$email]);
        if (\$st->fetchColumn()) {
          \$db->prepare('UPDATE users SET password_hash=?, role=\"admin\", status=\"active\" WHERE email=?')->execute([\$hash, \$email]);
        } else {
          \$db->prepare('INSERT INTO users (name,email,password_hash,role,status) VALUES (?,?,?,?,?)')
             ->execute(['Administrator', \$email, \$hash, 'admin', 'active']);
        }
        fwrite(STDERR, '[entrypoint]   admin user ready: '.\$email.PHP_EOL);
        // Seed default settings (scraper + comments) so things work out-of-box
        \$defaults = [
          'scraper_use_api'     => '1',
          'scraper_api_url'     => getenv('SCRAPER_API_URL'),
          'scraper_api_key'     => getenv('SCRAPER_API_KEY_ENV'),
          'scraper_api_timeout' => '30',
          'comments_enabled'    => '1',
          'comments_on_comic'   => '1',
          'comments_on_chapter' => '1',
          'comments_api_url'    => '',
        ];
        \$ins = \$db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach (\$defaults as \$k => \$v) { \$ins->execute([\$k, \$v]); }
        fwrite(STDERR, '[entrypoint]   default settings seeded'.PHP_EOL);
      " && touch "$LOCK" && chown www-data:www-data "$LOCK"
      echo "[entrypoint] Install complete."
    else
      echo "[entrypoint] Tables already exist — skipping import."
      touch "$LOCK" && chown www-data:www-data "$LOCK"
    fi
  else
    echo "[entrypoint] Already installed (lock file present)."
  fi
fi

# Idempotent upgrade step: jalan SETIAP boot. Hanya tambah baris yang
# memang belum ada (INSERT IGNORE / ON DUPLICATE KEY). Aman utk DB lama.
if [[ -n "${DB_HOST:-}" ]]; then
  php -r "
    try {
      \$db = new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:3306).';dbname='.getenv('DB_NAME').';charset=utf8mb4',
                    getenv('DB_USER'), getenv('DB_PASS'),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      // Tambah ad slot global utk Adsterra/Monetag tanpa mengganggu yg sudah ada.
      \$rows = [
        ['global_head','Global <head> (Adsterra/Monetag popunder, social-bar, push)'],
        ['global_body_end','Global </body> (direct-link, native banner, in-page)'],
      ];
      \$ins = \$db->prepare('INSERT IGNORE INTO ad_slots (slot_key, slot_name, ad_code, is_active) VALUES (?, ?, \"\", 1)');
      foreach (\$rows as \$r) { \$ins->execute(\$r); }
      fwrite(STDERR, '[entrypoint] upgrade: global ad slots ensured'.PHP_EOL);
    } catch (Throwable \$e) {
      fwrite(STDERR, '[entrypoint] upgrade skipped: '.\$e->getMessage().PHP_EOL);
    }
  " || true
fi

exec "$@"
