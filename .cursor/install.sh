#!/usr/bin/env bash
# Idempotent Cloud Agent setup for SIGAD (PHP + MariaDB).
# Installs system packages, provisions the MySQL database/user, imports the
# schema on first run, and prepares the uploads directory. Safe to re-run.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# --- Database credentials (must match config/config.php) ---
DB_NAME="sigad"
DB_USER="sigad_user"
DB_PASS="sigad_pass"

echo "==> Installing system packages (PHP, MariaDB, poppler-utils)"
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -y
sudo apt-get install -y --no-install-recommends \
  php-cli php-mysql php-mbstring \
  mariadb-server \
  poppler-utils

echo "==> Ensuring MariaDB is running (needed to import the schema)"
sudo service mariadb start || true
for i in $(seq 1 30); do
  if sudo mysqladmin ping >/dev/null 2>&1; then break; fi
  sleep 1
done
sudo mysqladmin ping

echo "==> Provisioning database and application user"
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> Importing schema (only if the database has no tables yet)"
TABLE_COUNT="$(sudo mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';")"
if [ "${TABLE_COUNT}" -eq 0 ]; then
  sudo mysql "${DB_NAME}" < db/sigad.sql
  echo "    schema imported (seed data: admin user, pabellones, areas)"
else
  echo "    schema already present (${TABLE_COUNT} tables), skipping import"
fi

echo "==> Preparing uploads directory"
mkdir -p uploads
chmod 775 uploads

echo "==> SIGAD setup complete."
echo "    App user 'admin' / password 'Sigad2026' (change in production)."
