# Changelog

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - Unreleased

First release (release candidate `v1.0.0-rc.1`).

### Added
- Dalli delivery shipping method for shipping zones with live rates (price and period) for Dalli, CDEK, Yandex Delivery, 5Post and Magnit; courier and
  pickup point types; cached `deliverycost` requests; markup, free-shipping threshold, fixed price and rounding rules.
- Pickup point picker (accessible searchable dialog) for the classic checkout and the Cart & Checkout blocks, with server-side validation and
  prepaid/weight constraints; delivery date/time selection for Dalli couriers.
- Order creation in Dalli (on Processing, on Completed or manual) through Action Scheduler with retries, duplicate recovery and consistent
  cash-on-delivery amounts; payment gateway mapping; VAT, declared value and parcel settings.
- Delivery status synchronisation (per order or "changes" feed), status history, optional Dalli to WooCommerce status mapping.
- Admin: order meta box, bulk actions, orders list column, stickers, transfer act, cancel, connection test, Tools page, system status report.
- Customer delivery details on the thank-you page, My Account and e-mails (overridable templates).
- HPOS and Cart & Checkout blocks compatibility declarations; token masking, `DALLI_TOKEN`/`DALLI_TEST_TOKEN` constants, log redaction.
- English and complete Russian translations.
- PHPUnit tests (97), PHPCS (WordPress, WooCommerce, PHPCompatibility 7.4-), PHPStan level 6, Docker smoke test with a mock Dalli API, CI and release workflows.

[1.0.0]: https://github.com/sergei-vasilev-dev/dalli-delivery-for-woocommerce/releases/tag/v1.0.0
