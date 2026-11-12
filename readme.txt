=== Delivery Integration for Dalli ===
Contributors: sergeivasilevdev
Tags: woocommerce, shipping, delivery, courier, pickup points
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
WC requires at least: 8.5
WC tested up to: 11.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unofficial WooCommerce integration with the Dalli courier service: live shipping rates, pickup points, automatic order creation, tracking and labels.

== Description ==

**Delivery Integration for Dalli** connects WooCommerce with the Russian courier service [Dalli](https://dalli-service.com/) through its public API.

> This is an unofficial, third-party plugin. It is **not affiliated with or endorsed by Dalli**. "Dalli" is a trademark of its owner and is used only to describe what the plugin connects to. The plugin does not contain any Dalli logos or images. You need your own Dalli contract and API token.

= Features =

* **Live rates** at the cart and checkout: price and delivery period for Dalli couriers and the partners CDEK, Yandex Delivery, 5Post and Magnit, as a regular WooCommerce shipping method that you add to shipping zones. Results are cached.
* **Pickup points (PVZ)**: an accessible, searchable picker for Dalli's own points and partner points, for both the classic checkout and the Cart & Checkout **blocks**. The chosen point is validated and saved on the order, shown in the admin, the thank-you page, My Account and e-mails.
* **Delivery date and time** selection for Dalli courier delivery.
* **Automatic or manual order creation** in Dalli (on "Processing", on "Completed" or with a button), with correct cash-on-delivery arithmetic, retries and duplicate protection.
* **Tracking**: status synchronisation with Action Scheduler (per order or via the changes feed), status history in the order, optional mapping of Dalli statuses to WooCommerce order statuses, customer-facing delivery details.
* **Admin tools**: order meta box (send, refresh, cancel, sticker), bulk actions (send, print stickers), orders list column, transfer act download, connection test, system status report.
* **Pricing rules** per shipping zone: markup (percent and fixed), free shipping threshold, fixed price, rounding.
* Works with **HPOS** (custom order tables), the **Cart and Checkout blocks** and the classic shortcode checkout.
* The API token can be defined in `wp-config.php` (`DALLI_TOKEN`) and is never printed back in full or written to logs.
* Complete Russian translation included.

= Requirements =

* WooCommerce 8.5 or newer, WordPress 6.4 or newer, PHP 7.4 or newer.
* A Dalli contract and API token (issued in the Dalli personal account, section "Account"). A shared Dalli test platform exists for trying the integration.
* The store currency should be RUB and the delivery country Russia.

== External services ==

This plugin sends data from your server to the Dalli API. Nothing is loaded from third-party servers in the browser, and the plugin has no tracking or telemetry.

* **Service:** Dalli API ([dalli-service.com](https://dalli-service.com/)); endpoint `https://api.dalli-service.com/v1/` (Moscow branch) or `https://spbapi.dalli-service.com/v1/` (St. Petersburg branch), as chosen in the settings.
* **When and what is sent:**
    * At the cart/checkout, to calculate rates: the destination address entered by the customer, the parcel weight and dimensions, the declared value and your API token.
    * When the customer opens the pickup point list: the customer's town (and your token).
    * When an order is created in Dalli: the recipient's name, phone number, e-mail address and delivery address (or the pickup point code), the items with prices, the payment type and amounts, the courier note, the order number.
    * When statuses, stickers, acts or cancellations are requested: order numbers and barcodes.
* **Why:** to calculate delivery cost, deliver the parcel and track it. Data is sent only when you configure the plugin with your own Dalli token.
* Dalli's terms of service and privacy policy apply to the data you send to Dalli; see the Dalli website. As the merchant you are responsible for informing your customers.

== Installation ==

1. Make sure WooCommerce is active, then install and activate the plugin.
2. Go to **WooCommerce → Settings → Shipping → Dalli**, choose your branch, paste the API token and use **Test connection**. Alternatively define `DALLI_TOKEN` in `wp-config.php`.
3. Go to **WooCommerce → Settings → Shipping**, open a shipping zone that covers Russia and add the **Dalli delivery** method. Choose carriers and delivery types and set optional price rules.
4. In **Payment methods** on the Dalli settings page, mark which gateways are cash/card on receipt; all others are treated as prepaid.
5. Place a test order. Orders are created in Dalli when they reach the configured status.

Using the Dalli test platform: log in to the Dalli test personal account (credentials are in the Dalli API documentation), copy its token to **Test API token** and switch the mode to "Test platform". The test platform is shared by all users: never send real customer data to it.

== Frequently Asked Questions ==

= Is this an official Dalli plugin? =

No. It is an independent open-source project and is not affiliated with or endorsed by Dalli.

= Where do I get an API token? =

In your Dalli personal account, section "Account" (Учётная запись). Each account has its own token. Contact Dalli if you do not have a contract yet.

= Why do I not see any rates at the checkout? =

Check: the token and branch are correct (Test connection), the shipping zone contains the **Dalli delivery** method and covers Russia, the customer entered a city (and a postcode and region if your checkout requires them), and the carriers you enabled actually serve that city. Turn on the debug log (WooCommerce → Status → Logs, source `dalli-delivery`) to see requests and answers.

= Can customers pay on receipt? =

Yes. Mark the gateway (for example "Cash on delivery") under **Payment methods** in the Dalli settings. The plugin then sends the cash-on-delivery amount (items plus delivery) that Dalli validates.

= When is the order created in Dalli? =

By default when the order becomes "Processing". You can also choose "Completed" or manual creation with the button on the order screen.

= Are stickers available for any order? =

Dalli only returns stickers and acts for orders sent to it **today**.

= Is Russian Post supported? =

Not in version 1.0. Dalli's Russian Post delivery uses a separate API method.

= Does it work with the Cart and Checkout blocks? =

Yes. The pickup point and delivery time selectors are added under the shipping options of the Checkout block.

= What happens when I uninstall the plugin? =

Settings, cached data and scheduled actions are removed. Order data (tracking numbers, statuses, pickup points) is kept on your orders.

= How do I customise the customer delivery block? =

Copy `templates/order-delivery.php` (and `templates/emails/delivery.php`) to `yourtheme/woocommerce/dalli-delivery/`.

== Screenshots ==

1. Dalli settings page (connection, parcel defaults, payment methods, order creation, tracking).
2. Dalli delivery method in a shipping zone with carriers and price rules.
3. Rates at the classic checkout.
4. Pickup point picker (classic checkout).
5. Cart & Checkout block with pickup point and delivery time selectors.
6. Order meta box with tracking number, status history and actions.
7. Orders list with the Dalli column and bulk actions.
8. Thank-you page and My Account with delivery details.
9. Dalli tools page (transfer act, orders needing attention, diagnostics).

== Changelog ==

= 1.0.0 =
* First release: live rates, pickup points for classic and block checkout, delivery intervals, order creation, status synchronisation, admin tools, customer delivery details, Russian translation.

== Upgrade Notice ==

= 1.0.0 =
First release.
