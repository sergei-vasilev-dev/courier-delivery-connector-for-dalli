# Contributing

Thanks for helping! This is an unofficial plugin; please keep it that way (no Dalli logos, no claims of endorsement).

## Getting started

```bash
git clone https://github.com/sergei-vasilev-dev/courier-delivery-connector-for-dalli.git
cd kurvio-courier-connector-for-dalli
bin/qa.sh                 # installs dev tools via Docker if needed, runs phpcs + phpstan + phpunit
bash docker/setup.sh      # optional: throwaway WordPress + WooCommerce + mock Dalli API on http://localhost:8099
```

Docker is the only prerequisite. Composer is used for **development** tools only; the shipped plugin has no runtime dependencies and uses its own PSR-4 autoloader.

## Rules of the road

* Follow the WordPress Coding Standards (enforced by `phpcs.xml.dist`) and keep PHP 8.1 compatibility.
* Every user-facing string is English with the `kurvio-courier-connector-for-dalli` text domain, translator comments for placeholders, no sentence concatenation.
  Regenerate the template with `bin/make-pot.sh` and update `languages/*-ru_RU.po` (compile `.mo` with `msgfmt`).
* Escape late, sanitise early. State-changing endpoints need a nonce **and** a capability check. Never log or print the token.
* Use WooCommerce CRUD APIs (`$order->update_meta_data()`, ...), never direct post meta, so HPOS keeps working.
* New behaviour needs tests. API behaviour must be backed by the documentation or by a fixture; record doubts in `docs/api-notes.md`.
* Never use a real token or real customer data in tests, fixtures, issues or pull requests.

## Commits and pull requests

* Small commits in [Conventional Commits](https://www.conventionalcommits.org/) style (`feat:`, `fix:`, `docs:`, `test:`, `chore:`, `refactor:`), written in English.
* Update `CHANGELOG.md` (Keep a Changelog) for user-visible changes.
* Fill in the pull request template; CI must be green (phpcs, phpstan, phpunit on PHP 8.1/8.3/8.5, Plugin Check).

## Releasing (maintainers)

1. Bump the version in `kurvio-courier-connector-for-dalli.php` (header and constant), `readme.txt` (stable tag) and `CHANGELOG.md`.
2. `bin/make-pot.sh`, update translations, `bin/qa.sh`, `bin/build-zip.sh`, run Plugin Check on the zip.
3. Tag `vX.Y.Z`; the release workflow builds the zip and attaches it to the GitHub release.
