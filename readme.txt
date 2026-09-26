=== RAR Woo Stock & Order ===
Contributors: ruhulaminrevens
Tags: woocommerce, stock, inventory, pwa, order management, staff
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mobile-first WooCommerce staff PWA for controlled stock updates and fast manual/social order creation.

== Description ==

RAR Woo Stock & Order adds a private staff web app (default /staff/) designed for phone-first stock management and manual/social order entry.

Features:
* Colourful clickable dashboard: orders, sales, completed and returned/cancelled for Today / 7 days / This month, with a live store-time clock.
* All Stock, Live Stock and Out of Stock views highlighted green / orange / red with in-place quantity updates.
* Stock Manager with filters, bulk save with a reason and a stock movement log.
* Create Order with +88 Bangladesh phone validation, searchable District and Town/City, discount, shipping, amount in words and a shareable sales order slip image.
* Shop Manager Order Control (All Orders, Live Orders, Processing with status changes) and Sales & Growth reports.
* Stock Manager with item/SKU search, product image, current price and protected stock updates.
* Create Order workflow for customer details, products, quantity, price, shipping and notes.
* Bangladesh district and available-stock validation.
* Optional staff order-line price override.
* Duplicate-order retry protection.
* Dedicated Woo Stock & Order Staff role.
* Product add/edit/delete remains in WooCommerce for Administrator / Shop Manager.
* WooCommerce-native order creation, stock reduction and status/email hooks.
* HPOS compatibility.
* Nonce/capability protected writes.
* Private-safe PWA service-worker caching.
* Upgrade migration for v1.0.0 settings/capabilities.

== Installation ==

1. Download the official installable ZIP from the GitHub Release page.
2. Upload it in WordPress > Plugins > Add New > Upload Plugin.
3. Activate RAR Woo Stock & Order.
4. Open WooCommerce > Stock & Order.
5. Configure the staff app, default order status, price override and default shipping.
6. Assign staff users the role "Woo Stock & Order Staff".
7. Open /staff/ on the phone. Android Chrome: tap "Install app". iPhone Safari: Share > Add to Home Screen.

== Upgrade Notice ==

= 1.3.0 =
Concurrency, security and staff-account release. Take a backup first. After updating, review WooCommerce > Stock & Order: the Staff discount limit now also covers lower item rates, and staff accounts can be added and paused there.

= 1.2.2 =
Order and report hardening. After updating, review the new "Staff discount limit" setting (default 20%) under WooCommerce > Stock & Order.

= 1.2.1 =
Phone app fixes: in-app login, automatic session renewal, PNG icons, offline screen and cache-safe PWA files. After updating, open /staff/ on each phone once. If the app was already installed, remove the old home-screen icon and install again to get the new icon.

= 1.2.0 =
Take a backup, then upload the v1.2.0 ZIP and choose "Replace current with uploaded". Review the new shipping and low stock settings under WooCommerce > Stock & Order.

= 1.1.0 =
If v1.0.0 was installed directly from a GitHub source archive and uses a versioned plugin folder, deactivate/delete the old plugin files first, then install the official v1.1.0 release ZIP. Settings and WooCommerce operational data are preserved.

== Changelog ==

= 1.3.0 =
* No overselling between staff: per-product stock locks with fresh database reads (load test: 50 units → exactly 50 orders).
* Truly atomic duplicate-save protection (MySQL named locks).
* Stock saves refuse to overwrite a newer change (HTTP 409 with the current figure); shared parent stock of variations respected.
* Orders validated before they are created; the first save is complete, so other plugins never see a ghost or empty order.
* Lower item rates count toward the staff discount limit.
* Staff login: CSRF token, bot trap, failed-login limit; strict CSP and anti-framing headers on app screens.
* Staff can open only recent orders or their own.
* Staff accounts panel: add (set-password email / one-time link), pause, sign out everywhere, new password link. No staff accounts from public registration.
* Phone: back button closes panels, Create Order draft autosave, request timeouts, negative-stock flag, no wrapped card figures.
* Faster dashboard refresh and order creation; 2 fewer queries on every site page.

= 1.2.2 =
* Atomic duplicate-order protection and persistent request lookup (5 simultaneous saves create 1 order).
* Stock checked on combined quantity per product across order lines.
* New Staff discount limit setting, enforced on the server.
* Refunded status can no longer be set from the app (use the WooCommerce refund flow).
* Disabling the staff app now also blocks all staff AJAX actions.
* Reports exclude pending payment and returned orders and subtract partial refunds.
* Runtime smoke tests and GitHub Actions CI.

= 1.2.1 =
* Fixed the app breaking after login when LiteSpeed/Hostinger or other optimizers minified, deferred or delayed its CSS/JS.
* In-app login/logout so the installed phone app never leaves to wp-login.php (fixes iPhone login loop).
* Automatic session (nonce) renewal when the app returns from the background.
* PNG app icons (192, 512, maskable, Apple touch icon).
* Manifest and service worker served from cache-safe URLs with LiteSpeed/Hostinger no-cache headers.
* Offline screen, Install app button, iPhone install steps, home-screen shortcuts.

= 1.2.0 =
* New dashboard with clickable order and stock cards, 7-day sales chart and attention list.
* Colour-coded stock views, Stock Manager bulk updates and stock movement log.
* Create Order: +88 phone validation, searchable District and Town/City, discount, payment method, amount in words, Save & Share sales slip.
* Shop Manager Order Control and Sales & Growth report.
* Fixed currency HTML entities, HTML order totals and /staff/ 404 after activation.

= 1.1.0 =
* Focused staff app on stock updates and order entry; product lifecycle management stays in WooCommerce.
* Fixed currency rendering, product search and unmanaged-stock UX.
* Added district/stock validation and duplicate-order protection.
* Hardened private PWA caching and upgrade migration.
* Added integration hooks for shipping/payment/workflow extensions.
* Added WordPress + WooCommerce runtime smoke tests and canonical release packaging.

= 1.0.0 =
* Initial release.
