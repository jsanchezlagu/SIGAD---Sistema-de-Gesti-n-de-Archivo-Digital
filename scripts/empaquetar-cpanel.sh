#!/bin/bash
# Empaqueta SIGAD para subir a cPanel (sin .git, tests ni PDFs de prueba).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-/opt/cursor/artifacts/sigad-cpanel.zip}"
mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
cd "$ROOT"
zip -r "$OUT" . \
  -x '.git/*' \
  -x 'sigad_instalable/*' \
  -x 'tests/*' \
  -x 'uploads/*.pdf' \
  -x 'tmp_sess/*' \
  -x '.cursor/*' \
  -x '*.zip'
echo "Paquete: $OUT"
unzip -l "$OUT" | tail -5
