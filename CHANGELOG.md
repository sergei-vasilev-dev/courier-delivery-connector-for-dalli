# Changelog

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.3] - 2026-11-13

### Changed
- Renamed to Kurvio Courier Connector for Dalli (slug `kurvio-courier-connector-for-dalli`) after WordPress.org review.
- Removed bundled translation files and `load_plugin_textdomain()`; the Russian source moved to `translations/ru_RU.po`.

## [1.0.0] - 2026-11-12

First release.

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

## [Unreleased]

## [1.0.2] - 2026-11-12

### Changed
- Renamed to "Kurvio Courier Connector for Dalli" (slug and text domain `kurvio-courier-connector-for-dalli`, repository renamed) so the name does not start with a third-party mark. Options, hooks and the shipping method id are unchanged, so existing settings keep working.

## [1.0.1] - 2026-11-12

### Changed
- Minimum PHP version is now 8.1 (PHP 7.4 is end-of-life); development dependencies refreshed (PHPUnit 10, WooCommerce sniffs 2, stubs 11); CI actions updated.

### Added
- WordPress.org submission kit: screenshots, icon and banners (`.wordpress-org/`, SVG sources in `docs/assets-src/`), submission guide with trademark/rename notes (`docs/wordpress-org-submission.md`), disabled-by-default deploy workflow, demo-data seed for screenshots.
- `readme.txt`: links to Dalli's user agreement and privacy policy in "External services", source code FAQ, screenshot captions matched to the files.

[Unreleased]: https://github.com/sergei-vasilev-dev/courier-delivery-connector-for-dalli/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/sergei-vasilev-dev/courier-delivery-connector-for-dalli/releases/tag/v1.0.2
[1.0.1]: https://github.com/sergei-vasilev-dev/courier-delivery-connector-for-dalli/releases/tag/v1.0.1
[1.0.0]: https://github.com/sergei-vasilev-dev/courier-delivery-connector-for-dalli/releases/tag/v1.0.0
