# WooCommerce Marketplace submission checklist

What remains before this plugin can be submitted to the Woo Marketplace, and what is uncertain. Sources (read November 2026):

* [Submitting your product to the Woo Marketplace](https://developer.woocommerce.com/docs/woo-marketplace/submitting-your-product/)
* [Getting started on the Woo Marketplace](https://developer.woocommerce.com/docs/woo-marketplace/getting-started/)
* [Monetization expectations for Marketplace plugins](https://developer.woocommerce.com/docs/woo-marketplace/monetization-expectations/)
* [Marketplace overview](https://woocommerce.com/document/marketplace-overview) and the [Marketplace Vendor Agreement](https://woocommerce.com/woo-vendor-agreement/)
* [Extension development best practices](https://developer.woocommerce.com/docs/extensions/best-practices-extensions/extension-development-best-practices/) and [UX guidelines](https://developer.woocommerce.com/docs/extensions/ux-guidelines-extensions/best-practices/)

## Honest assessment: blockers that are not technical

| Topic | What the docs say | Consequence for this plugin |
| --- | --- | --- |
| **Free listing** | "The Marketplace doesn't accept free plugins without a monetization path" (paid license, freemium, billing API or a Partnership Agreement). | A purely free GPL plugin has **no accepted path** unless it is freemium or covered by a Partnership Agreement with Dalli. Decide the model first. |
| **Integrations** | For integration plugins "the company that operates the integrated system should ideally build and maintain the plugin"; a standard paid license is not allowed for services that bill merchants themselves (Billing API or Partnership Agreement instead). | This plugin is **unofficial**. Woo may ask Dalli to build/endorse it. Ask Dalli for a written partnership/endorsement before applying. |
| **Trademark** | Product names must follow Woo's trademark guidelines and be unique. | "Kurvio Courier Connector for Dalli" uses the carrier's name descriptively. Get Dalli's written permission to use "Dalli" in the name, or rename (e.g. "Courier Delivery for WooCommerce - Dalli connector"). Never use Dalli logos without permission. |
| **Revenue share / pricing** | Products share revenue with Woo (70 % to the vendor, per the vendor pages - check the current Vendor Agreement); the listed price must be <= the price charged elsewhere. | Decide the commercial model. |
| **No outside promotion** | No spam, affiliate or upsell links to products outside the Marketplace. | The plugin contains none; keep it that way. |

Alternative that needs none of the above: publish the free plugin on **WordPress.org** (the code is written to that standard) and/or GitHub releases.

## Vendor side (not in this repository)

- [ ] Apply for a **Woo partner / vendor account** at <https://woocommerce.com/partners> (company or individual details, tax and payout information are collected there; the docs I could read do not specify SLA, tax or payment requirements - see the Vendor Agreement).
- [ ] Choose the product type (Extension) in *Submissions > Submit Product*; freemium products need **two** submissions that are merged later.
- [ ] Provide **merchant support** (the vendor pages require "high-quality merchant support" after launch). Decide: support channel (helpdesk/e-mail), languages (Russian + English), response-time target, hours. Put the policy in the product page and in `README`. The exact SLA is not published in the pages I read - ask the onboarding team.
- [ ] Mind the account-level **security** expectations: 2FA on the vendor and GitHub accounts, private security reporting (`SECURITY.md` exists).

## Technical requirements and where this plugin stands

| Requirement (from the docs) | Status |
| --- | --- |
| PHP 7.4+ (8.3+ recommended) | Code runs on 7.4-8.5; CI matrix 7.4/8.1/8.3/8.5; unit tests green on 7.4/8.1/8.3/8.5. |
| Two latest majors of WooCommerce and WordPress | Developed/tested on WP 7.1 / WC 11.3. `Requires at least: 6.4`, `WC requires at least: 8.5` - re-check what "two latest majors" means at submission time; raising the minimums is allowed. |
| HPOS compatible (mandatory for new submissions) | Declared; order CRUD only; smoke-tested with HPOS enabled (WC 11 default). |
| Cart & Checkout **blocks** supported, shortcode checkout too | Declared; pickup point/interval widget implemented for both; verified in a real browser against WC 11.3 (see `docs/testing.md`). |
| Accessibility (WordPress Accessibility Coding Standards) | Picker uses a native `<dialog>`, labelled controls, `aria-live` regions, keyboard operable. **Needs a formal audit** (screen reader pass, colour contrast of the theme-dependent parts). |
| UX: single purpose, works out of the box with minimal configuration | Needs only a token to work; defaults documented. Review the settings page length against the UX guidelines (the 30-row status map is collapsible content candidate). |
| Must not break Bookings, Subscriptions, Product Add-Ons, Product Bundles, Min/Max Quantities, Shipment Tracking | **Not tested.** Needs a manual regression pass with those extensions (they are paid; use the vendor test licenses). |
| Security best practices | Nonces + capability checks on every state-changing endpoint, escaped output, prepared SQL only in `uninstall.php`, token masked and redacted, no telemetry. Run QIT *Security* to confirm. |
| Uninstall cleanup | `uninstall.php` removes options, transients and scheduled actions; order meta is kept by design (documented). |

## QIT (Quality Insights Toolkit)

QIT is only available to **approved vendors**; the Marketplace runs these suites on submission: **API, End-to-end, Activation, Security, PHPCompatibility, Malware, Validation**.

- [ ] Run each locally once the vendor account exists (`composer require --dev woocommerce/qit-cli`, then `qit run:activation` etc.). I could not run QIT here (no vendor account).
- What I ran instead (documented in `docs/testing.md`): PHPCS (WordPress + WooCommerce + PHPCompatibilityWP 8.1-), PHPStan level 6, 97 PHPUnit tests, the official **Plugin Check**, and a Docker smoke test (classic + Blocks checkout, admin screens, order sync).
- Likely QIT *E2E* gap: the E2E suite exercises Woo core flows with your plugin active (not your own features). Make sure activation does not add notices, redirects or fatal errors on a fresh site - verified on a fresh install with `WP_DEBUG` on (no PHP notices).
- *Validation* checks plugin headers/readme: `readme.txt` is WordPress.org style and the main file carries `Requires Plugins: woocommerce`, `WC requires at least`, `WC tested up to`.

## Listing materials to prepare

- [ ] Unique product name (see Trademark) and a **icon (1:1 vector/PNG) and banner** - must not use Dalli logos; create neutral artwork.
- [ ] Short and long description (copy can start from `readme.txt`), **screenshots** (`docs/screenshots.md` lists the 10 planned shots), a demo/test-instructions note for the reviewers.
- [ ] **Test instructions for reviewers**: the plugin needs a Dalli token. Provide a Dalli test-platform token (the shared test client) and steps (see `README.md`, section "Test platform"). Warn that the platform is shared.
- [ ] **Documentation**: the submission page does not list documentation requirements, but the vendor pages ask for "well-documented" products. Prepare a docs site/page: installation, getting a token, shipping zone setup, pickup points, order creation modes, tracking, troubleshooting, FAQ. `README.md` + `docs/` are the source material.
- [ ] Changelog and versioning policy (SemVer, Keep a Changelog - done), update/maintenance commitment.
- [ ] Privacy policy text for the product page (the plugin sends customer name, phone, e-mail, address and item data to Dalli - see `readme.txt` "External services").
- [ ] Translations: Russian is complete (`languages/`); regenerate the `.pot` before each release (`bin/make-pot.sh`).

## Pre-submission technical to-do

- [ ] Re-run Plugin Check on the release zip (`bin/build-zip.sh`).
- [ ] Verify against the **real Dalli API** (see "What could not be verified" in `README.md`/`docs/api-notes.md`) with a real or test token: town matching in `pointsInfo`, `autosend` initial status, error messages, `cancelorder` edge cases, label/act PDFs.
- [ ] Manual test with real themes (a classic theme and a block theme) in Russian locale; mobile layouts of the picker dialog.
- [ ] Update `WC tested up to` / `Tested up to` to the current releases immediately before submitting.
- [ ] Decide the free/paid model and licensing statement for the listing (the code is GPL-2.0-or-later; Woo's marketplace expects GPL-compatible code).
