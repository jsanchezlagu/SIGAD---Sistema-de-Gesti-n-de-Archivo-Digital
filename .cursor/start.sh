#!/usr/bin/env bash
# Per-boot startup for SIGAD: ensure MariaDB is up and ready.
# Idempotent: does nothing if the server is already accepting connections.
set -euo pipefail

echo "==> Ensuring MariaDB is running"
if ! sudo mysqladmin ping >/dev/null 2>&1; then
  sudo service mariadb start
fi

for i in $(seq 1 30); do
  if sudo mysqladmin ping >/dev/null 2>&1; then
    echo "    MariaDB is ready."
    exit 0
  fi
  sleep 1
done

echo "MariaDB did not become ready in time" >&2
exit 1
