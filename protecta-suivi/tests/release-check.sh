#!/bin/sh
# Contrôles de livraison (développement local) sur deux WordPress vierges :
#   1. installation du zip final (Téléverser un thème) → connexion, saisie d'un projet, Recap ;
#   2. installation de 0.1.0 + données, puis mise à jour par le zip final → aucune donnée perdue.
# Usage : sh tests/release-check.sh <zip final> <zip 0.1.0>
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
NEW="$1"; OLD="$2"; SNAP=/tmp/ps-release-snapshot.ser
export NODE_PATH="${NODE_PATH:-/opt/node22/lib/node_modules}"
run() { (cd "$1" && sudo -u www-data php "$DIR/release-check.php" "$2" "$1" "$3" "${4:-}"); }
FAIL=0
echo "######## 1. Installation vierge : $NEW ########"
run /srv/wpf install 8082 "$NEW" || FAIL=1
curl -s -o /dev/null http://localhost:8082/   # premier chargement (activation)
echo "######## 2. Mise à jour depuis 0.1.0 avec données ########"
run /srv/wpu install 8083 "$OLD" || FAIL=1
curl -s -o /dev/null http://localhost:8083/
run /srv/wpu seed 8083 || FAIL=1
run /srv/wpu snapshot 8083 "$SNAP" || FAIL=1
run /srv/wpu upgrade 8083 "$NEW" || FAIL=1
curl -s -o /dev/null http://localhost:8083/   # premier chargement après mise à jour
run /srv/wpu compare 8083 "$SNAP" || FAIL=1
echo "######## Navigateur ########"
FRESH=http://localhost:8082 UPGRADE=http://localhost:8083 SHOTS="${SHOTS:-/tmp}" node "$DIR/e2e-release.js" || FAIL=1
[ $FAIL -eq 0 ] && echo "=== LIVRAISON VÉRIFIÉE ===" || echo "=== ÉCHEC DE LIVRAISON ==="
exit $FAIL
