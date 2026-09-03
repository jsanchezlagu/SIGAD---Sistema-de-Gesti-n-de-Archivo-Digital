#!/usr/bin/env bash
# Per-boot startup for SIGAD: ensure MariaDB is up, ready, and reachable by PHP.
# Idempotent: safe to run on every boot.
set -euo pipefail

echo "==> Ensuring MariaDB is running"
if ! sudo mysqladmin ping >/dev/null 2>&1; then
  sudo service mariadb start
fi

ready=false
for _ in $(seq 1 30); do
  if sudo mysqladmin ping >/dev/null 2>&1; then
    ready=true
    break
  fi
  sleep 1
done

if [ "$ready" != true ]; then
  echo "MariaDB did not become ready in time" >&2
  exit 1
fi
echo "    MariaDB is ready."

# The app connects with DB_HOST='localhost', so PDO uses a Unix socket at the
# path in php's pdo_mysql.default_socket (typically /var/run/mysqld/mysqld.sock).
# On some base images /var/run is a real directory rather than a symlink to
# /run, so MariaDB's socket (/run/mysqld/mysqld.sock) is not visible at the path
# PHP expects. Bridge the two paths when — and only when — they differ, so we
# never clobber a socket that is already correct.
PHP_SOCK="$(php -r 'echo ini_get("pdo_mysql.default_socket") ?: "/var/run/mysqld/mysqld.sock";' 2>/dev/null || echo /var/run/mysqld/mysqld.sock)"
REAL_SOCK="$(sudo mysql -N -e 'SELECT @@socket' 2>/dev/null || echo /run/mysqld/mysqld.sock)"
if [ -n "$PHP_SOCK" ] && [ ! -S "$PHP_SOCK" ] && [ -S "$REAL_SOCK" ]; then
  sudo mkdir -p "$(dirname "$PHP_SOCK")"
  sudo ln -sf "$REAL_SOCK" "$PHP_SOCK"
  echo "    Bridged PHP socket $PHP_SOCK -> $REAL_SOCK"
fi

exit 0
