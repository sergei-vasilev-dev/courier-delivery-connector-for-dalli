#!/usr/bin/env bash
# Regenerate languages/*.pot with WP-CLI (Docker). Then update the .po files and compile the .mo files:
#   msgmerge --update languages/dalli-delivery-for-woocommerce-ru_RU.po languages/dalli-delivery-for-woocommerce.pot
#   msgfmt -o languages/dalli-delivery-for-woocommerce-ru_RU.mo languages/dalli-delivery-for-woocommerce-ru_RU.po
set -euo pipefail
cd "$(dirname "$0")/.."
docker run --rm --user 0:0 -v "$PWD":/plugin -w /plugin wordpress:cli-php8.5 \
	wp i18n make-pot . languages/dalli-delivery-for-woocommerce.pot --allow-root \
	--slug=dalli-delivery-for-woocommerce --domain=dalli-delivery-for-woocommerce \
	--exclude=vendor,tests,docker,docs,node_modules,bin,.github,build,dist \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/sergei-vasilev-dev/dalli-delivery-for-woocommerce/issues","Last-Translator":"Sergey Vasilev"}'
