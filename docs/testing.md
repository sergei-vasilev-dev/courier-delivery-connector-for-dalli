# Testing and verification

All commands run through Docker; no local PHP is needed.

```bash
bin/qa.sh            # phpcbf, phpcs (WordPress + WooCommerce + PHPCompatibilityWP 7.4-), phpstan level 6, phpunit
bin/qa.sh 7.4        # the same under another PHP version (7.4, 8.1, 8.3, 8.5 ...)
```

## Unit tests (`tests/Unit`)

PHPUnit 9.6 with Brain Monkey. No WordPress, WooCommerce or network: `wp_remote_post`, options and transients are mocked, and responses come from
`tests/fixtures/*.xml`, which follow the examples in the Dalli documentation. No real token is used anywhere (`FAKE_TOKEN...` placeholders).

Coverage: XML builder (escaping, control characters, number format), parser (every response format, error envelopes, XXE/DOCTYPE rejection, BOM),
HTTP client (endpoint per branch, retry/backoff, no retry on 4xx, token never in messages/logs), rate calculator + cache, price rules + rounding,
parcel builder, order amounts (error 26/31/32 invariants), item splitting, paytype mapping, status mapping, order-number and phone sanitising,
checkout validation, address formatting, masked token handling.

## Docker smoke test (`docker/`, `tests/mock-server/`)

A throwaway WordPress + WooCommerce site (separate project name `dalli-smoke`, port 8099) next to a mock Dalli API (port 8100) that implements the
documented formats and some real validation rules (error 26 price check, 31/32 declared value, 20 card via partners, 4 duplicate number ...).

```bash
bash docker/setup.sh                                   # installs WP + WooCommerce, products, zone, gateways
docker compose -f docker/docker-compose.yml --profile tools run --rm -T wpcli wp eval-file /smoke/orders.php   # back-end assertions
docker compose -f docker/docker-compose.yml --profile tools run --rm -T wpcli wp eval-file /smoke/rates.php    # rates through the WC shipping pipeline
# classic checkout: http://localhost:8099/classic-checkout/   block checkout: http://localhost:8099/checkout/   (admin / admin)
curl localhost:8100/mock/requests                      # what the plugin sent (token redacted)
docker compose -f docker/docker-compose.yml down -v    # remove everything
```

Other PHP versions: `WP_IMAGE=wordpress:php7.4-apache WPCLI_IMAGE=wordpress:cli-php7.4 WP_PORT=8097 docker compose -p dalli-smoke-74 ...` (set `MOCK_PORT` to avoid the published mock port clashing).

### Results recorded for 1.0.0-rc.1

| Check | Result |
| --- | --- |
| WP 7.1 / WooCommerce 11.3 / PHP 8.5, `WP_DEBUG` on | no PHP notices/warnings/deprecations in `debug.log` from the plugin |
| Rates through the shipping zone (8 rates, DS/CDEK/Yandex/5Post/Magnit; courier, express, pickup) | OK |
| **Classic checkout** (real browser): rates, pickup point modal, selection stored, validation message when no point chosen, order placed with cash on delivery, order sent to Dalli (mock) with consistent COD amounts, thank-you page shows carrier/point | OK |
| **Block checkout** (real browser): native rates, widget under the shipping options, delivery date/time selector for own couriers, pickup point modal, client- and server-side validation, order placed | OK |
| Admin: order meta box (send/refresh/sticker/cancel), orders list column, bulk actions, settings page (save, masked token, test connection), Tools page, system status | OK |
| Order sync back-end (`orders.php`, 35 assertions): courier/PVZ/prepaid/COD, fee + odd rounding lines, partner+card downgrade, 503 retry, duplicate number recovery, status sync (per order and changes feed), status mapping, cancel rules, stickers, act | all pass |

Not covered by automation: real Dalli API behaviour, e-mails (the container has no MTA - template output was reviewed in code), visual/mobile design review, screen reader testing.

## Plugin Check

```bash
docker compose -f docker/docker-compose.yml --profile tools run --rm -T wpcli sh -c 'wp plugin install plugin-check --activate && wp plugin check dalli-delivery-for-woocommerce'
```

Findings and fixes are tracked in the repository history (`fix:` commits) and summarised in `CHANGELOG.md`.
