#!/usr/bin/env bash
# Rebuilds docs/10MG-Architecture-and-Database-Design.pdf.
#
#   ./scripts/design-pdf/build.sh              # schema from the DB_DATABASE in .env
#   ./scripts/design-pdf/build.sh 10mg_testing # or any migrated database
#
# The data dictionary is read from information_schema, so run it against a database that
# has every migration applied. Needs: mysql client, python3, google-chrome (or chromium).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
env_value() { grep -E "^$1=" "$ROOT/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB="${1:-$(env_value DB_DATABASE)}"
HOST="$(env_value DB_HOST)"; PORT="$(env_value DB_PORT)"; USER="$(env_value DB_USERNAME)"
export MYSQL_PWD="$(env_value DB_PASSWORD)"
CHROME="$(command -v google-chrome || command -v chromium || command -v chromium-browser)"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
OUT="$ROOT/docs/10MG-Architecture-and-Database-Design.pdf"

q() { mysql -h"${HOST:-127.0.0.1}" -P"${PORT:-3306}" -u"$USER" -N -B -e "$1"; }

q "SELECT table_name, column_name, column_type, is_nullable, column_key, IFNULL(column_default,'')
   FROM information_schema.columns WHERE table_schema='$DB' ORDER BY table_name, ordinal_position" > "$WORK/columns.tsv"
q "SELECT table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index), MAX(non_unique)
   FROM information_schema.statistics WHERE table_schema='$DB' GROUP BY table_name, index_name ORDER BY table_name, index_name" > "$WORK/indexes.tsv"
q "SELECT k.table_name, k.column_name, k.referenced_table_name, r.delete_rule
   FROM information_schema.key_column_usage k
   JOIN information_schema.referential_constraints r ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
   WHERE k.table_schema='$DB' ORDER BY k.table_name, k.column_name" > "$WORK/fks.tsv"

python3 -I "$ROOT/scripts/design-pdf/generate.py" "$WORK/design.html" "$WORK" >/dev/null
"$CHROME" --headless=new --disable-gpu --no-sandbox --no-pdf-header-footer --print-to-pdf="$OUT" "file://$WORK/design.html" 2>/dev/null
echo "wrote $OUT (schema: $DB)"
