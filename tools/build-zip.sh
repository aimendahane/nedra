#!/bin/sh
# Construit le zip installable du thème : dist/protecta-suivi-<version>.zip
# (le dossier tests/ n'est pas inclus). Aucune compilation : copie des fichiers.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n 's/^Version: *//p' "$ROOT/protecta-suivi/style.css" | tr -d '\r')"
PHPVER="$(sed -n "s/.*define( 'PS_VERSION', '\(.*\)' );/\1/p" "$ROOT/protecta-suivi/inc/config.php")"
if [ "$VERSION" != "$PHPVER" ]; then
	echo "Erreur : style.css ($VERSION) et PS_VERSION ($PHPVER) diffèrent." >&2
	exit 1
fi
mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/protecta-suivi-$VERSION.zip"
rm -f "$OUT"
cd "$ROOT"
zip -r -q -X "$OUT" protecta-suivi -x 'protecta-suivi/tests/*' -x '*.DS_Store'
echo "$OUT"
