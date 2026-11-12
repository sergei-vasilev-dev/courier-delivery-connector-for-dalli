#!/usr/bin/env bash
# Build a clean release zip (WordPress.org style): dist/dalli-delivery-for-woocommerce-<version>.zip
# containing a single top-level folder `dalli-delivery-for-woocommerce/`. Files listed in .distignore are left out;
# the runtime needs no Composer dependencies, so `vendor/` is never shipped.
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="dalli-delivery-for-woocommerce"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SLUG.php" | head -1 | tr -d '[:space:]')"
CONST_VERSION="$(sed -n "s/^define( 'DALLI_DELIVERY_VERSION', '\(.*\)' );/\1/p" "$SLUG.php")"
README_VERSION="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | tr -d '[:space:]')"

if [ -z "$VERSION" ] || [ "$VERSION" != "$CONST_VERSION" ] || [ "$VERSION" != "$README_VERSION" ]; then
	echo "Version mismatch: header=$VERSION constant=$CONST_VERSION readme=$README_VERSION" >&2
	exit 1
fi

OUT="dist"
STAGE="$OUT/$SLUG"
rm -rf "$OUT"
mkdir -p "$STAGE"

rsync -a --delete --exclude-from=.distignore --exclude='dist' ./ "$STAGE/"

# Compile translations if the .mo files are missing or stale and msgfmt is available.
if command -v msgfmt >/dev/null 2>&1; then
	for po in "$STAGE"/languages/*.po; do
		[ -e "$po" ] || continue
		msgfmt -o "${po%.po}.mo" "$po"
	done
fi

( cd "$OUT" && zip -qr "$SLUG-$VERSION.zip" "$SLUG" )
echo "Built $OUT/$SLUG-$VERSION.zip"
unzip -l "$OUT/$SLUG-$VERSION.zip" | tail -n +4 | awk '{print $4}' | sed '/^$/d' | head -80
