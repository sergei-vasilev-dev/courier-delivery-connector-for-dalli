#!/usr/bin/env bash
# Run the quality gates inside Docker (no local PHP needed): phpcs, phpstan, phpunit.
# Usage: bin/qa.sh [php-version]   (default 8.3)
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${1:-8.3}"
run() { docker run --rm -v "$PWD":/app -w /app "php:${PHP}-cli" "$@"; }
[ -d vendor ] || docker run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction --prefer-dist
run vendor/bin/phpcbf >/dev/null 2>&1 || true
run vendor/bin/phpcs
run vendor/bin/phpstan analyse --memory-limit=1G --no-progress
run vendor/bin/phpunit
