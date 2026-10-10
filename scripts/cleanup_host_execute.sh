#!/usr/bin/env bash
# TrueNAS-host: gecontroleerde opschoning van inactieve leerinhoud.
# Alleen uitvoeren tijdens onderhoudsvenster, zonder gelijktijdige wijzigingen in de website.
set -euo pipefail
ROOT="/mnt/POOL/WEBSITES/leren.eem64.nl"
BACKUP_DIR="/mnt/POOL/BACKUPS/leren"
DB="$BACKUP_DIR/leren_20261011_000117.sql"
UPLOADS="$BACKUP_DIR/uploads_20261011_000009.tar.gz"
LOG_DIR="$BACKUP_DIR/cleanup-logs"
cd "$ROOT"
echo "=== Controle back-ups ==="
test -s "$DB" || { echo "STOP: DB-back-up ontbreekt"; exit 1; }
test -s "$UPLOADS" || { echo "STOP: uploadback-up ontbreekt"; exit 1; }
grep -aq '^-- Dump completed on ' "$DB" || { echo "STOP: DB-dump onvolledig"; exit 1; }
gzip -t "$UPLOADS" || { echo "STOP: uploadarchief beschadigd"; exit 1; }
tar -tzf "$UPLOADS" | grep '^uploads/questions/' >/dev/null || { echo "STOP: vragen ontbreken in archief"; exit 1; }
echo "Back-ups aanwezig en archief leesbaar."
echo "=== Laatste database-dry-run ==="
docker exec leren-web php /var/www/html/scripts/cleanup_execute.php --dry-run
echo
echo "LET OP: 86 tests, 1328 vragen, 21 resultaten, 5 samenvattingen en 3 topics worden verwijderd."
echo "156 AI-koppelingen worden verwijderd. 108 afbeeldingen gaan naar quarantaine."
echo "AI-broncollecties en originele AI-bronfoto's blijven behouden."
echo "De SQL-back-up is niet via een restore-test gevalideerd."
echo
read -r -p 'Typ exact VERWIJDER INACTIEVE INHOUD om door te gaan: ' CONFIRM
if [[ "$CONFIRM" != 'VERWIJDER INACTIEVE INHOUD' ]]; then
    echo "Geannuleerd: niets verwijderd."
    exit 1
fi
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/cleanup_$(date +%Y%m%d_%H%M%S).log"
echo "=== Uitvoering; log: $LOG ==="
docker exec -e LEREN_HOST_BACKUP_VERIFIED=YES-20261011 leren-web \
    php /var/www/html/scripts/cleanup_execute.php --execute --confirm=DELETE-INACTIVE 2>&1 | tee "$LOG"
echo "=== Controle na afloop ==="
docker exec leren-web php /var/www/html/scripts/cleanup_prepare.php | grep -E '"controle":"(topics|tests|questions|attempts|summaries|ai_question_links|ai_summary_links|orphan_ai_question_links|orphan_ai_summary_links)"'
echo "KLAAR. Bewaar de back-ups en quarantainemap voor herstel."
