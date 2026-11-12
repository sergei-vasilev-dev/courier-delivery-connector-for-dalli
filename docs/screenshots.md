# Screenshots (WordPress.org)

Shots 1-9 are done (`.wordpress-org/screenshot-N.png`, 1280 px wide, English UI, mock API). To redo them: start the site, run `wp eval-file /smoke/seed-screenshots.php` (demo orders with fake customers) and capture the pages with a headless browser (use the Moscow address with the mock API; the bulk-actions menu in shot 7 is a native `<select>` rendered open). Create them on a clean site with the Docker smoke environment (`bash docker/setup.sh`, English and Russian locales). Do not show real tokens or customer data and use no Dalli logos.
Files go to `.wordpress-org/` (WordPress.org: `screenshot-N.png`) and the Marketplace listing; captions match `readme.txt`.

1. Dalli settings page (connection, parcel defaults, payment methods, orders, tracking).
2. Dalli delivery method in a shipping zone with carriers and price rules.
3. Rates at the classic checkout.
4. Pickup point picker dialog (classic checkout), with a search term typed.
5. Checkout block with the pickup point and delivery time selectors.
6. Order meta box with tracking number, status history and the action buttons.
7. Orders list with the Dalli column and the bulk actions menu.
8. Thank-you page / My Account with the delivery block.
9. Dalli tools page (act, failed orders, diagnostics).
10. (Marketplace, not done) 1280x720 hero image. Icon and banners for WordPress.org are in `.wordpress-org/` (SVG sources in `docs/assets-src/`), neutral artwork without Dalli branding.
