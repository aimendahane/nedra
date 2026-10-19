#!/bin/sh
# Lance TOUS les tests (développement local) : PHP, puis navigateur sur un site de test neuf.
#   sh tests/run-all.sh            (depuis le dossier du thème)
# Variables : WP=/srv/wp, SHOTS=/tmp/ps-shots (captures d'écran)
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
WP="${WP:-/srv/wp}"
SHOTS="${SHOTS:-/tmp/ps-shots}"
mkdir -p "$SHOTS"
export NODE_PATH="${NODE_PATH:-/opt/node22/lib/node_modules}"
FAIL=0

echo "######## Tests PHP ########"
(cd "$WP" && sudo -u www-data env PS_TEST=1 php "$DIR/run-tests.php") || FAIL=1

echo "\n######## Navigateur : fondations (étape 1) ########"
(cd "$WP" && sudo -u www-data env PS_TEST=1 php "$DIR/e2e-setup.php") >/dev/null || FAIL=1
BASE_URL=http://localhost:8081 SHOTS="$SHOTS" node "$DIR/e2e-smoke.js" || FAIL=1

echo "\n######## Navigateur : fonctionnalités (étapes 2-5) ########"
(cd "$WP" && sudo -u www-data env PS_TEST=1 php "$DIR/e2e-setup.php") >/dev/null || FAIL=1
BASE_URL=http://localhost:8081 SHOTS="$SHOTS" node "$DIR/e2e-app.js" || FAIL=1

echo
[ $FAIL -eq 0 ] && echo "=== TOUS LES TESTS SONT PASSÉS ===" || echo "=== ÉCHECS : voir ci-dessus ==="
exit $FAIL
