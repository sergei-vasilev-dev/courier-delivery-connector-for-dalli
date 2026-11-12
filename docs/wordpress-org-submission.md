# Submitting to the WordPress.org plugin directory

Step-by-step guide for the author. Nothing here has been sent to WordPress.org yet. Sources (checked November 2026):
[Add your plugin](https://wordpress.org/plugins/developers/add/), [Detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
[readme.txt standard](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/), [Plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/),
[Using Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/), [Plugin Check](https://wordpress.org/plugins/plugin-check/).

## 1. Status: what is ready in the repository

| Item | State |
| --- | --- |
| `readme.txt` (header, description, External services with Dalli's user agreement and privacy policy links, FAQ, changelog, screenshot captions 1-9) | done |
| Screenshots `.wordpress-org/screenshot-1..9.png` (1280 px wide, English UI, mock API only, no tokens, no Dalli logos) | done |
| Icon `icon-128x128.png`, `icon-256x256.png`, banner `banner-772x250.png`, `banner-1544x500.png` (neutral artwork; sources in `docs/assets-src/*.svg`) | done |
| `.wordpress-org/` is excluded from the release zip (`.distignore`, `.gitattributes`) | done |
| Zip: `bin/build-zip.sh` -> `dist/dalli-delivery-for-woocommerce-1.0.0.zip` | done |
| QA: phpcs, phpstan, phpunit, Plugin Check on the zip | passing (see `CHANGELOG.md` / the report of the preparation run) |
| Deploy workflow `.github/workflows/wporg-deploy.yml` (switched off until approval) | done |

## 2. Before you submit (manual)

1. Make sure the WordPress.org account (`yurich0dev`) has **2FA enabled** (required for committers) and a verified e-mail address. Submit from the account that will own the plugin.
2. Decide the **trademark question** (section 5). This is the most likely reason for a delay.
3. Re-check `Tested up to` (now 7.1, current WordPress is 7.1.4) and `WC tested up to` (11.3, current WooCommerce is 11.3.0) right before submitting; update the readme and, if you change them, run `bin/build-zip.sh` again.
4. Optional but helpful: tag the release `v1.0.0` on GitHub already exists; the zip on the Releases page is the one to upload (or run `bin/build-zip.sh`).
5. Build and check the zip locally:

   ```bash
   bin/build-zip.sh
   docker compose -f docker/docker-compose.yml --profile tools run --rm -T wpcli sh -c 'wp plugin install plugin-check --activate && wp plugin check dalli-delivery-for-woocommerce'
   ```

   The zip must contain one top-level folder `dalli-delivery-for-woocommerce/` and no `.wordpress-org`, `docs`, `tests`, `vendor`, `bin`, `docker`, `.github`.

## 3. Where to upload

1. Log in at <https://wordpress.org/> and open <https://wordpress.org/plugins/developers/add/>.
2. Upload `dist/dalli-delivery-for-woocommerce-1.0.0.zip`.
3. Fill the form (text below). The **plugin name** and **slug** are derived from the main file header and the zip folder; the slug is **permanent** after approval (the name can be changed, the slug cannot).
4. Press **Submit**. You get an e-mail confirmation; the plugin goes to the review queue. Review time varies (days to a few weeks); check spam for replies from `plugins@wordpress.org`. Reply **in the same e-mail thread**, never by opening a new submission.

### Text for the form fields

* **Plugin name:** Delivery Integration for Dalli
* **Description / what it does (if asked):**
  > WooCommerce shipping integration with the Russian courier service Dalli (and its partners CDEK, Yandex Delivery, 5Post, Magnit) through the carrier's public API: live rates at the cart and checkout, a searchable pickup point picker for the classic and block checkout, automatic or manual order creation, tracking synchronisation with Action Scheduler, admin tools. Unofficial, GPL-2.0-or-later, no tracking, no third-party code loaded in the browser; data is sent to the carrier's API only after the merchant enters their own API token. Details are in the "External services" section of the readme.
* **Contact e-mail for documents / support:** yurich.dev@gmail.com
* **Source code:** https://github.com/sergei-vasilev-dev/dalli-delivery-for-woocommerce (plain PHP/JS/CSS, no build step, no minified files).
* **Test instructions for reviewers** (put in the reply if asked; the plugin needs a Dalli token that I cannot give you):
  > The plugin works against the carrier's API with a personal token, which cannot be shared. Without a token, activation, the settings page (WooCommerce > Settings > Shipping > Dalli) and the shipping method in a zone can still be checked; API calls fail gracefully with an admin notice and no PHP errors. For a full flow the repository ships a mock API: `bash docker/setup.sh` starts WordPress + WooCommerce + the plugin + a mock Dalli server (see `docs/testing.md`).

## 4. How the review goes and what to answer

1. **Automatic pre-checks** run at upload (header, readme, Plugin Check subset). Fix and re-upload if it refuses.
2. A human reviewer e-mails the findings ("Your plugin has been rejected/not approved *yet*" is normal and means "fix and reply"). Fix **everything** in the list, upload a corrected zip through the **link in the e-mail / the "Add your plugin" page of the same submission**, and reply with a short list of what changed. Do not argue; ask if something is unclear.
3. After approval you receive SVN access to `https://plugins.svn.wordpress.org/dalli-delivery-for-woocommerce/` and the plugin page appears (screenshots and icon only after the assets are committed).
4. Passwords: use an SVN password generated in your WordPress.org profile (not the login password).

### Findings that come up most often (and where this plugin stands)

| Typical finding | This plugin |
| --- | --- |
| Trademark / name ("Dalli" in name or slug without permission) | **Real risk**, see section 5. |
| Undocumented external service (must say what is sent, when, and link terms and privacy policy) | `readme.txt` has the "External services" section with the Dalli user agreement and privacy policy links. If Dalli later changes the URLs, update them. |
| Input not sanitised, output not escaped, missing nonce or capability checks | PHPCS WordPress ruleset passes; every state-changing endpoint has a nonce and a capability check. |
| Direct file access (`ABSPATH` guard), generic function/class/option prefixes | Namespaced classes, `dalli_delivery_` options/handles. |
| Libraries or files not allowed (bundled WordPress core libraries, minified code without source, "phoning home", loading remote JS/CSS) | None. Nothing is loaded from third-party servers. |
| Plugin Check errors/warnings (readme headers, `Tested up to`, text domain, `Requires Plugins`) | Zero errors on the release zip. |
| Stable tag does not match the version | `bin/build-zip.sh` refuses to build on a mismatch. |
| `Tested up to` ahead of the released WordPress | 7.1 is released (7.1.4). |
| Promotional links, "upgrade to pro" nags, affiliate links | None. |
| Too many `Tags` (limit 5), banner/icon with others' logos | 5 tags; artwork is neutral. |
| Admin notices that cannot be dismissed, hijacked admin area | Only contextual notices on the plugin's own screens. |

## 5. Trademark risk: "Dalli" in the name and the slug (be honest with yourself)

WordPress.org guideline 17 ("Plugins must respect trademarks, copyrights, and project names") says that a plugin may **not** use another company's trademark or project name **at the start of the slug or the display name** unless the submitter is the legal owner or has permission; the allowed form is the "for X" form, e.g. "Something for X". Reviewers also reject plugins that look **official**. Facts about this plugin:

* Display name "Delivery Integration for Dalli": the trademark comes **after** "for", which is the permitted form. Lower risk, but not zero.
* Slug `dalli-delivery-for-woocommerce`: it **starts with** "dalli". The reviewers usually derive the slug from the name; a slug that begins with a third-party mark is the typical reason for the e-mail "please rename or prove you represent the owner". **Expect this to be raised.** I cannot know whether Dalli has registered "Dalli" as a trademark in Russia (check the Rospatent/FIPS database at <https://www1.fips.ru/> and ask Dalli), and whether a reviewer will accept the descriptive use.
* The readme already says "unofficial, not affiliated, no Dalli logos". Keep that and never use Dalli logos/screenshots from their site.
* What you can do (in order of safety): (a) ask Dalli for a **short written permission** (an e-mail from a company address is usually accepted: "We allow Sergey Vasilev to publish the plugin 'Delivery Integration for Dalli' on WordPress.org") and attach it to the reply; (b) accept a rename (below); (c) submit as-is and see what the reviewer says (cost: one round of e-mails, the slug is not locked until approval).

### Answer to the reviewers if they ask about the trademark

> The plugin is an independent, unofficial integration that connects WooCommerce to the public API of the courier service Dalli. "Dalli" appears only to describe what the plugin connects to ("for Dalli", nominative use); the readme states that the plugin is not affiliated with or endorsed by Dalli, and it contains no Dalli logos or other artwork. I am not the trademark owner. If you consider the slug `dalli-delivery-for-woocommerce` a problem, I can either provide written permission from the owner or change the display name and slug to one of the following: `courier-delivery-connector-for-dalli`, `delivery-connector-for-dalli`, `woo-courier-rates-and-pickup-points`. Please tell me which you prefer.

(Replace the last sentence with a firm choice once you have decided; reviewers prefer a quick, specific answer.)

### Three alternative names and slugs

| # | Name | Slug | Risk | Comment |
| --- | --- | --- | --- | --- |
| 1 | Courier Delivery Connector for Dalli | `courier-delivery-connector-for-dalli` | **Medium-low** | Trademark after "for" in both name and slug (the form the guidelines allow); no longer starts with the mark. Best compromise: still found by people searching "dalli". Recommended first choice. |
| 2 | Delivery Connector for Dalli | `delivery-connector-for-dalli` | Medium-low | Same pattern, shorter. Slightly generic. |
| 3 | Russian Courier Rates and Pickup Points for WooCommerce | `russian-courier-rates-pickup-points` | **Low** | No third-party mark in the slug; only the readme says "works with Dalli". Safest, but harder to find and you lose the clear connection to the carrier. The plugin header can keep "Integration with the Dalli courier service" in the description. |

Avoid: names/slugs starting with `dalli-`, anything with "official", and Dalli's logo or colours.

### What to change when renaming (do **not** do this before the reviewers ask; this repository has not been renamed)

Let `OLD=dalli-delivery-for-woocommerce` and `NEW=<new slug>`. The code prefix `dalli_delivery_` (options, hooks, handles) and the PHP namespace `DalliDelivery` describe the carrier and are **not** the slug; keep them (changing them would break existing installs; there are none yet, so it is allowed but unnecessary).

1. **Main file:** rename `OLD.php` -> `NEW.php`; update `Plugin Name:` (display name), `Text Domain: NEW`, `Plugin URI` if it contains the slug; every `require`/`plugin_basename`/`__FILE__` reference to the old file name (grep for `dalli-delivery-for-woocommerce`).
2. **Text domain:** every `__( ..., 'OLD' )`, `_e`, `esc_html__`, `_n`, `_x` etc. -> `'NEW'` (a sed over `src/`, `templates/`, main file; PHPCS `WordPress.WP.I18n` checks the domain, see `phpcs.xml.dist` `text_domain` property and the `i18n` lint config).
3. **Translations:** rename `languages/OLD.pot` and `OLD-ru_RU.po/.mo` to `NEW...`; regenerate with `bin/make-pot.sh` (update the domain inside it) and recompile the `.mo`; the `Domain:` header inside the `.po` must match.
4. **`readme.txt`:** `=== Name ===` title, short description, links to the plugin page, any slug in FAQ text, the template override path (`yourtheme/woocommerce/dalli-delivery/` can stay).
5. **Build scripts:** `bin/build-zip.sh` (`SLUG=`), `bin/qa.sh` if it mentions the folder, `docker/docker-compose.yml` volume mounts (`.../plugins/OLD`), `docker/setup.sh` (`wp plugin activate OLD`), `docker/smoke/*.php` if they reference the path, `docs/testing.md`.
6. **Workflows:** `.github/workflows/ci.yml`, `release.yml` (zip name), `wporg-deploy.yml` (`SLUG`).
7. **Docs and links:** `README.md`, `README.ru.md`, `CHANGELOG.md` compare/release links, `CONTRIBUTING.md`, `docs/*`. If you also rename the GitHub repository, GitHub redirects the old URL; update `Plugin URI`/`readme.txt` source link.
8. **Update URI / plugin check:** if `Update URI` is set it must not point at the old slug; otherwise nothing.
9. **Release:** bump the version (for example 1.0.1 or keep 1.0.0 if it was never published to WP.org), update `Stable tag`, `CHANGELOG.md`, rebuild the zip, run `bin/qa.sh` and Plugin Check again, and upload the new zip to the **same** submission when the reviewer asks.
10. **Assets:** the banner text ("Unofficial integration for the Dalli courier service") may stay (descriptive text), but re-check it against the reviewer's comments; `.wordpress-org/*` has no slug in it.
11. Existing GitHub release `v1.0.0` keeps the old zip name; either leave it or publish a new tag.

## 6. After approval: SVN (trunk, tags, assets)

You will receive an e-mail with the SVN URL `https://plugins.svn.wordpress.org/<slug>/`. SVN layout: `trunk/` (current code), `tags/<version>/` (releases), `assets/` (screenshots, icon, banner; **not** inside the plugin zip), `branches/`.

```bash
svn checkout https://plugins.svn.wordpress.org/dalli-delivery-for-woocommerce svn-dalli --username yurich0dev
cd svn-dalli

# 1. Plugin files into trunk (the same content as the release zip, i.e. without files from .distignore)
rsync -a --delete --exclude-from=/path/to/repo/.distignore /path/to/repo/ trunk/
svn add --force trunk/* ; svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm   # register new/deleted files

# 2. Assets (screenshots, icons, banners, any size listed in the readme)
cp /path/to/repo/.wordpress-org/*.png assets/
svn add --force assets/* 
svn propset svn:mime-type image/png assets/*.png

# 3. Tag the release (Stable tag in readme.txt must be the same number)
svn cp trunk tags/1.0.0

svn ci -m "Release 1.0.0" --username yurich0dev     # asks for the SVN password
```

Rules: `Stable tag` in `trunk/readme.txt` must name an existing folder under `tags/`; never put assets in `trunk`; update `Tested up to` in trunk's readme after each WordPress release without making a new tag if the code is unchanged. The plugin page and the zip update within minutes to an hour; screenshots show in the order of `readme.txt` captions.

### Automating it with GitHub Actions

`.github/workflows/wporg-deploy.yml` uses the [10up WordPress plugin deploy action](https://github.com/10up/action-wordpress-plugin-deploy) (it follows `.distignore`, copies `.wordpress-org/` to SVN `assets/`, creates the tag). To enable after approval:

1. GitHub repo > Settings > Secrets and variables > Actions: add secrets `SVN_USERNAME` (your WordPress.org login) and `SVN_PASSWORD` (the SVN password, see your WordPress.org profile > Account & Security) and a **variable** `WPORG_DEPLOY` = `true`.
2. Pushing a tag `vX.Y.Z` deploys trunk and `tags/X.Y.Z`; **Run workflow** with "assets only" updates only screenshots and banners.
3. Consider pinning the action to a commit SHA instead of `@stable`.

## 7. What is left for the author

* [ ] Decide on the trademark route (ask Dalli for e-mail permission, or choose a rename from section 5).
* [ ] Re-check `Tested up to` / `WC tested up to` on the day of submission.
* [ ] Submit the zip at <https://wordpress.org/plugins/developers/add/> with the 2FA-protected account and answer the reviewers within a few days.
* [ ] After approval: SVN (section 6) or enable the workflow, then check the plugin page (screenshots, icon, banner).
* [ ] Optionally verify the plugin against the real Dalli API with your own token (`docs/api-notes.md`, "What could not be verified"); the screenshots use the mock API (some carrier/point names and the courier name in screenshot 6 are mock data in Russian).
