#!/usr/bin/env bash
# Regenerate languages/*.pot with WP-CLI (Docker). The Russian source is translations/ru_RU.po (not shipped; translations come from translate.wordpress.org):
#   msgmerge --update translations/ru_RU.po languages/kurvio-courier-connector-for-dalli.pot
set -euo pipefail
cd "$(dirname "$0")/.."
docker run --rm --user 0:0 -v "$PWD":/plugin -w /plugin wordpress:cli-php8.5 \
	wp i18n make-pot . languages/kurvio-courier-connector-for-dalli.pot --allow-root \
	--slug=kurvio-courier-connector-for-dalli --domain=kurvio-courier-connector-for-dalli \
	--exclude=vendor,tests,docker,docs,node_modules,bin,.github,build,dist \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/sergei-vasilev-dev/courier-delivery-connector-for-dalli/issues","Last-Translator":"Sergey Vasilev"}'
