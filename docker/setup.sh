#!/usr/bin/env bash
# Provision the throwaway smoke-test site: WordPress, WooCommerce, the plugin, products, shipping zone, payment gateways,
# a classic-checkout page (/classic-checkout/) and the default block-based checkout (/checkout/).
set -euo pipefail

cd "$(dirname "$0")/.."
export WP_PORT="${WP_PORT:-8099}"
COMPOSE=(docker compose -f docker/docker-compose.yml)
wp() { "${COMPOSE[@]}" --profile tools run --rm -T wpcli wp "$@"; }

"${COMPOSE[@]}" up -d --wait db mock wordpress >/dev/null
echo "Waiting for WordPress files..."
for _ in $(seq 1 60); do
	if curl -s -o /dev/null "http://localhost:${WP_PORT}/wp-login.php" -w '%{http_code}' | grep -qE '200|302'; then break; fi
	sleep 2
done

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="http://localhost:${WP_PORT}" --title="Dalli smoke" --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
fi

# Old PHP-specific images ship an old WordPress: update to the newest release the PHP version supports.
wp core update >/dev/null 2>&1 || true
wp core update-db >/dev/null 2>&1 || true
wp plugin install woocommerce --activate
wp plugin activate dalli-delivery-for-woocommerce
wp language core install ru_RU >/dev/null 2>&1 || true
wp rewrite structure '/%postname%/' --hard >/dev/null

# Store basics.
wp option update woocommerce_default_country RU
wp option update woocommerce_currency RUB
wp option update woocommerce_calc_taxes no
wp option update woocommerce_weight_unit kg
wp option update woocommerce_dimension_unit cm
wp option update woocommerce_enable_guest_checkout yes
wp option update woocommerce_allowed_countries specific
wp option update woocommerce_specific_allowed_countries '["RU"]' --format=json
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
wp option update woocommerce_task_list_hidden yes >/dev/null 2>&1 || true
wp option update woocommerce_coming_soon no
wp option update woocommerce_store_pages_only no
wp wc payment_gateway update cod --enabled=true --user=1 >/dev/null
wp wc payment_gateway update bacs --enabled=true --user=1 >/dev/null

# Plugin settings (the token comes from the DALLI_TOKEN constant of the container).
wp option update dalli_delivery_sender_code ''
wp option update dalli_delivery_debug yes
wp option update dalli_delivery_cod_gateways '["cod"]' --format=json
wp option update dalli_delivery_order_trigger processing

# Products.
if [ -z "$(wp post list --post_type=product --name=tea-box --format=ids)" ]; then
	wp wc product create --name="Tea box" --slug=tea-box --type=simple --regular_price=1000 --sku=TEA-BOX --weight=0.5 --dimensions='{"length":"20","width":"15","height":"8"}' --user=1 >/dev/null
	wp wc product create --name="Mug \"Classic\" & lid" --slug=mug --type=simple --regular_price=499.5 --sku=MUG-1 --weight=0.4 --dimensions='{"length":"10","width":"10","height":"12"}' --user=1 >/dev/null
	wp wc product create --name="Heavy kettle" --slug=kettle --type=simple --regular_price=3500 --sku=KETTLE --weight=2.5 --dimensions='{"length":"25","width":"25","height":"25"}' --user=1 >/dev/null
fi

# Shipping zone "Russia" with the Dalli method.
wp eval-file /smoke/provision-zone.php

# Classic checkout page.
if [ -z "$(wp post list --post_type=page --name=classic-checkout --format=ids)" ]; then
	wp post create --post_type=page --post_status=publish --post_title="Classic checkout" --post_name=classic-checkout --post_content='[woocommerce_checkout]' >/dev/null
fi

echo
echo "Ready: http://localhost:${WP_PORT}/  (admin / admin)"
echo "  block checkout:   http://localhost:${WP_PORT}/checkout/"
echo "  classic checkout: http://localhost:${WP_PORT}/classic-checkout/"
echo "  mock API state:   http://localhost:8100/mock/requests"
