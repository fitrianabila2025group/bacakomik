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
  echo "[entrypoint] Running install.php (AUTO_INSTALL=1)..."
  php /var/www/html/install.php || echo "[entrypoint] install.php returned non-zero (probably already installed)"
fi

exec "$@"
