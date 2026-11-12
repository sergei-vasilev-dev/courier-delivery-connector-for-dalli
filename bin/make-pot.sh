#!/usr/bin/env bash
# Regenerate languages/*.pot with WP-CLI (Docker). Then update the .po files and compile the .mo files:
#   msgmerge --update languages/courier-delivery-connector-for-dalli-ru_RU.po languages/courier-delivery-connector-for-dalli.pot
#   msgfmt -o languages/courier-delivery-connector-for-dalli-ru_RU.mo languages/courier-delivery-connector-for-dalli-ru_RU.po
set -euo pipefail
cd "$(dirname "$0")/.."
docker run --rm --user 0:0 -v "$PWD":/plugin -w /plugin wordpress:cli-php8.5 \
	wp i18n make-pot . languages/courier-delivery-connector-for-dalli.pot --allow-root \
	--slug=courier-delivery-connector-for-dalli --domain=courier-delivery-connector-for-dalli \
	--exclude=vendor,tests,docker,docs,node_modules,bin,.github,build,dist \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/sergei-vasilev-dev/courier-delivery-connector-for-dalli/issues","Last-Translator":"Sergey Vasilev"}'
