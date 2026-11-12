#!/usr/bin/env bash
# Run the back-end smoke test on another PHP version in an isolated compose project.
# Usage: docker/matrix.sh 7.4 8097 8197     (php version, WordPress port, mock port)
set -euo pipefail
cd "$(dirname "$0")/.."
PHPV="${1:?php version}"; export WP_PORT="${2:-8097}"; export MOCK_PORT="${3:-8197}"
export COMPOSE_PROJECT_NAME="dalli-smoke-${PHPV//./}"
export WP_IMAGE="wordpress:php${PHPV}-apache" WPCLI_IMAGE="wordpress:cli-php${PHPV}"
export COMPOSE_PROGRESS=quiet
COMPOSE=(docker compose -f docker/docker-compose.yml)
bash docker/setup.sh >/dev/null
wp() { "${COMPOSE[@]}" --profile tools run --rm -T wpcli wp "$@" 2>&1 | grep -v 'php-cli-tools'; }
echo "== PHP ${PHPV}: $(wp eval 'echo PHP_VERSION, " / WP ", get_bloginfo("version"), " / WC ", WC_VERSION;')"
wp eval-file /smoke/rates.php | cut -c1-70
wp eval-file /smoke/orders.php | tail -4
echo "-- debug.log lines from the plugin or PHP notices:"
"${COMPOSE[@]}" exec -T wordpress sh -c 'grep -v "php-cli-tools" /var/www/html/wp-content/debug.log 2>/dev/null | grep -iE "notice|warning|deprecated|fatal" | head -10' || true
"${COMPOSE[@]}" --profile tools down -v >/dev/null 2>&1
