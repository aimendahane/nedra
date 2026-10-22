#!/bin/sh
# Lance TOUS les tests (développement local) : PHP, puis navigateur sur un site de test neuf.
# step6 = version 1.0.1 (tâches libres, e-mail) ; step7 = 1.0.2 (notifications) ; step8 = 1.2.0 (vocal, Telegram) ; step9 = 1.3.0 (langues) ; step10 = 1.4.0 ; step11 = 1.5.0.
#   sh tests/run-all.sh            (depuis le dossier du thème)
# Variables : WP=/srv/wp, SHOTS=/tmp/ps-shots (captures d'écran)
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
WP="${WP:-/srv/wp}"
SHOTS="${SHOTS:-/tmp/ps-shots}"
mkdir -p "$SHOTS"
export NODE_PATH="${NODE_PATH:-/opt/node22/lib/node_modules}"
FAIL=0
# Signale la commande qui échoue (au lieu d'un simple FAIL=1).
fail() { echo "✘ ÉCHEC : $1"; FAIL=1; }

echo "######## Tests PHP ########"
(cd "$WP" && sudo -u www-data env PS_TEST=1 php "$DIR/run-tests.php") || fail "tests PHP"

echo "\n######## Navigateur : fondations (étape 1) ########"
(cd "$WP" && sudo -u www-data env PS_TEST=1 php "$DIR/e2e-setup.php") >/dev/null || fail "préparation du site de test"
BASE_URL=http://localhost:8081 SHOTS="$SHOTS" node "$DIR/e2e-smoke.js" || fail "navigateur étape 1"

echo "\n######## Navigateur : fonctionnalités (étapes 2-5) ########"
(cd "$WP" && sudo -u www-data env PS_TEST=1 php "$DIR/e2e-setup.php") >/dev/null || fail "préparation du site de test"
ONLY=step2 BASE_URL=http://localhost:8081 SHOTS="$SHOTS" node "$DIR/e2e-app.js" || fail "navigateur étape 2"

# Chaque étape repart d'un site neuf avec le jeu de démonstration (les étapes modifient les données).
for STEP in step3 step4 step5 step6 step7 step8 step9 step11 step12; do
	echo "\n######## Navigateur : $STEP (jeu de démonstration) ########"
	(cd "$WP" && sudo -u www-data env PS_TEST=1 DEMO=1 php "$DIR/e2e-setup.php") >/dev/null || fail "préparation démo $STEP"
	ONLY=$STEP BASE_URL=http://localhost:8081 SHOTS="$SHOTS" node "$DIR/e2e-app.js" || fail "navigateur $STEP"
done

echo "\n######## Navigateur : step10 = 1.4.0 (import Excel, comptes, Telegram) ########"
(cd "$WP" && sudo -u www-data env PS_TEST=1 TELEGRAM=1 php "$DIR/e2e-setup.php") >/dev/null || fail "préparation step10"
ONLY=step10 BASE_URL=http://localhost:8081 SHOTS="$SHOTS" node "$DIR/e2e-app.js" || fail "navigateur step10"

echo
[ $FAIL -eq 0 ] && echo "=== TOUS LES TESTS SONT PASSÉS ===" || echo "=== ÉCHECS : voir ci-dessus ==="
exit $FAIL
