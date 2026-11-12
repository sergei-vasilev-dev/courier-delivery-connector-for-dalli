# Delivery Integration for Dalli

[![CI](https://github.com/sergei-vasilev-dev/dalli-delivery-for-woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/sergei-vasilev-dev/dalli-delivery-for-woocommerce/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)

WooCommerce integration with the Russian courier service [Dalli](https://dalli-service.com/): live shipping rates, partner pickup points,
automatic order creation, status tracking and labels. Русская версия: [README.ru.md](README.ru.md).

> **Disclaimer.** This is an **unofficial** third-party plugin. It is **not affiliated with or endorsed by Dalli**. "Dalli" is a trademark of its
> owner and is used here only to describe what the plugin connects to. The plugin contains no Dalli logos or images. You need your own Dalli
> contract and API token.

| | |
| --- | --- |
| Requires | WordPress 6.4+, WooCommerce 8.5+, PHP 8.1+ (tested with PHP 8.1 - 8.5, WordPress 7.1, WooCommerce 11.3) |
| Compatibility | HPOS (custom order tables), Cart & Checkout blocks, classic shortcode checkout |
| License | GPL-2.0-or-later |
| Status | 1.0.0 release candidate; behaviour against the live Dalli API still needs verification with a real token (see [Limitations](#limitations-and-what-is-not-verified)) |

## Features

* **Live rates** (price + delivery period) as a WooCommerce shipping method for shipping zones: Dalli own courier/express/pickup point and partners
  **CDEK, Yandex Delivery, 5Post, Magnit**. One cached API request per carrier.
* **Pickup points**: accessible modal with search (no map, no third-party requests), for the classic checkout **and** the Checkout block. Selection is
  validated server-side, saved on the order and shown in admin, thank-you page, My Account and e-mails. Points are filtered by cart weight; prepaid-only
  points are checked against the payment method.
* **Delivery date/time** for Dalli couriers (from the `intervals` API).
* **Order creation** in Dalli on "Processing", on "Completed" or manually. Cash-on-delivery arithmetic is built to satisfy Dalli's validation, with retries
  (Action Scheduler) and duplicate protection.
* **Tracking**: periodic status sync (per order or via the "changes" feed), history, optional Dalli-status to Woo-status mapping, tracking info for customers.
* **Admin**: order meta box (send, refresh, cancel, sticker), bulk actions, orders column, transfer act download, connection test, system status section.
* **Pricing**: per-zone markup (percent + fixed), free-shipping threshold, fixed price, rounding.
* **Security/privacy**: capability + nonce checks, masked token, `DALLI_TOKEN` constant support, token redacted from logs, no telemetry, no external calls from the browser.
* English + complete Russian translation.

## Installation

1. Download the zip from [Releases](https://github.com/sergei-vasilev-dev/dalli-delivery-for-woocommerce/releases) (or `bin/build-zip.sh`), upload it in
   *Plugins → Add New → Upload Plugin* and activate. WooCommerce must be active.
2. *WooCommerce → Settings → Shipping → **Dalli***: choose the branch (Moscow / St. Petersburg), paste the token, press **Test connection**.
3. *WooCommerce → Settings → Shipping → (zone)*: add **Dalli delivery**, pick carriers/types, optional price rules.
4. In the Dalli settings, under *Payment methods*, mark the gateways that are cash/card **on receipt**; everything else is treated as prepaid.
5. Place a test order. Done.

The token can instead be defined in `wp-config.php`:

```php
define( 'DALLI_TOKEN', 'your-live-token' );
define( 'DALLI_TEST_TOKEN', 'token-of-the-test-platform' ); // optional
```

### Test platform

Dalli publishes a shared test client (credentials are in the [API documentation](https://api.dalli-service.com/doc/v1/)). Log into its personal
account, copy the token into **Test API token** and switch **Mode** to *Test platform*. It is visible to everyone: never use real customer data.
The plugin's own automated tests do **not** use it (fixtures + a local mock server).

## Settings overview

| Where | What |
| --- | --- |
| Shipping → Dalli | branch, mode, token(s), sender code, default weight/dimensions, packaging, declared value, rounding, payment mapping, order trigger, order number prefix, VAT, partial redemption, default time window, interval selector, sticker format, sync (enabled/interval/method), tracking link, status mapping, debug log |
| Shipping zone → Dalli delivery | carriers, delivery types, markup %, fixed markup, free shipping over, fixed price, show period |
| Product | native weight/dimensions; SKU is sent as the item article/barcode |

## For developers

Filters: `dalli_delivery_api_url`, `dalli_delivery_rates_ttl`, `dalli_delivery_pvz_ttl`, `dalli_delivery_rate_args`, `dalli_delivery_package_spec`,
`dalli_delivery_order_payload`, `dalli_delivery_order_number`, `dalli_delivery_sync_max_age_days`. Actions: `dalli_delivery_order_sent`,
`dalli_delivery_status_changed`. Templates can be overridden in `yourtheme/woocommerce/dalli-delivery/`.

Order meta (kept on uninstall): `_dalli_delivery`, `_dalli_pvz`, `_dalli_interval`, `_dalli_number`, `_dalli_barcode`, `_dalli_status*`, `_dalli_courier`, `_dalli_sent_at`, `_dalli_error`.

Architecture: `src/Api` (XML builder/parser/HTTP client, no WordPress state), `src/Domain` (value objects, reference data), `src/Shipping` (rates,
parcel, price rules, pickup points), `src/Orders` (payload, sync, status), `src/Frontend` (checkout, AJAX, Blocks), `src/Admin`. Own PSR-4 autoloader; Composer is
only used for development tools. API findings and quirks: [docs/api-notes.md](docs/api-notes.md).

```bash
bin/qa.sh          # phpcs + phpstan (level 6) + phpunit, in Docker
bin/build-zip.sh   # clean release zip in dist/
bash docker/setup.sh   # throwaway WordPress + WooCommerce + mock Dalli API (see docs/testing.md)
```

## Limitations and what is not verified

* **Not verified against the real Dalli API** (no token was available during development). Everything follows the public documentation; see the
  "UNVERIFIED" items in [docs/api-notes.md](docs/api-notes.md) (pickup point lookup by town name, initial status after `autosend`, some error edge cases).
* Russian Post, KGT/marketplace services and multi-place parcels are not supported in 1.0. One parcel per order.
* Rates do not include possible cash-on-delivery commissions (the payment method is unknown in the cart).
* No map in the pickup point picker (a list with search, to avoid third-party tile servers).
* Stickers and acts exist only for orders sent *today* (Dalli behaviour).

## Support, contributing, security

Questions and bugs: [GitHub issues](https://github.com/sergei-vasilev-dev/dalli-delivery-for-woocommerce/issues). Please read
[CONTRIBUTING.md](CONTRIBUTING.md) and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Report vulnerabilities privately, see [SECURITY.md](SECURITY.md). Contact: yurich.dev@gmail.com.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE). Copyright (C) 2026 Sergey Vasilev.
