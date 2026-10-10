#!/usr/bin/env bash
# Host-side backup preflight. Read-only; never invokes the destructive mode.
set -euo pipefail
ROOT="/mnt/POOL/WEBSITES/leren.eem64.nl"
DB="/mnt/POOL/BACKUPS/leren/leren_20261011_000117.sql"
UPLOADS="/mnt/POOL/BACKUPS/leren/uploads_20261011_000009.tar.gz"
[[ -s "$DB" ]] || { echo "STOP: databaseback-up ontbreekt of is leeg"; exit 1; }
[[ -s "$UPLOADS" ]] || { echo "STOP: uploadback-up ontbreekt of is leeg"; exit 1; }
grep -aq '^-- Dump completed on ' "$DB" || { echo "STOP: dump mist afsluiting"; exit 1; }
gzip -t "$UPLOADS" || { echo "STOP: uploadarchief is beschadigd"; exit 1; }
[[ -d "$ROOT/public/uploads/questions" ]] || { echo "STOP: vragenmap ontbreekt"; exit 1; }
echo "HOST-BACK-UPS: controles geslaagd"
echo "DATABASE: $DB"
echo "UPLOADS: $UPLOADS"
echo "DATABASE-INVENTARISATIE:"
docker exec leren-web php /var/www/html/scripts/cleanup_execute.php --dry-run
echo "KLAAR: geen gegevens gewijzigd"
