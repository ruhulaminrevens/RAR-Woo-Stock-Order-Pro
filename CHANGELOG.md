# Changelog

## 1.2.2 — 2026-09-25

Hardening release from an external code review. All items were confirmed in the code and are now covered by automated tests.

### Orders
- **Duplicate orders from double-taps / retries.** An atomic per-request lock stops two identical saves from creating two orders. The request ID is also stored on the order, so a retry finds the existing order even after the 15-minute cache expires. Verified: 5 simultaneous identical saves produced exactly 1 order and 1 stock reduction.
- **Combined stock check.** Stock is now checked on the total quantity per product (including variations sharing the parent's stock), so the same product added on two lines can't oversell.
- **Staff discount limit.** New setting *Staff discount limit* (default 20% of the items subtotal; 0 = no discounts). It is enforced on the server and shown in the app ("Discount (max 20%)"). Shop Managers and Administrators are not limited.
- **"Refunded" can't be set from the app.** Setting it doesn't return any money. Refunds stay in the WooCommerce order screen, so money, stock and reports stay correct.

### Security
- **App switched off = backend switched off.** When the staff app is disabled, every staff AJAX action (stock, orders, reports, session refresh) is refused, not just the /staff/ page.

### Reports
- **Pending payment** orders and **Returned** statuses no longer count as sales. Pending orders are counted separately.
- **Partial refunds are subtracted.** Sales figures are now net of WooCommerce refunds (full and partial). Gross and refund totals are available in the report data.

### Quality
- New `tests/smoke.php` runtime test (33 checks) and a GitHub Actions workflow that runs PHP 7.4/8.1/8.3 lint, JS syntax, and the runtime tests on WordPress + WooCommerce with HPOS on and off, then builds the installable ZIP.


## 1.2.1 — 2026-09-25

### Phone app (PWA) fixes
- **Dashboard broken after login.** The app screens now close any cache or optimizer output buffer before printing, so their HTML reaches the phone untouched. They also switch off page caching and CSS/JS optimization from LiteSpeed Cache (Hostinger), WP Rocket, W3 Total Cache, Autoptimize, SiteGround Optimizer and Cloudflare Rocket Loader. Before, minify, combine and "load JS deferred/delayed" could change the app's CSS/JS, leaving the dashboard unstyled, stuck on loading cards, or with buttons that didn't respond.
- **In-app login and logout.** Staff now sign in on the `/staff/` screen itself instead of `wp-login.php`. Before, login and logout left the installed app: Android opened a browser tab, and on iPhone the home-screen app could get stuck in a login loop. "Keep me signed in on this phone" is on by default.
- **Session auto-renew.** If the app sat in the background long enough for the security nonce to expire, every action failed with "session expired" until a manual reload. The app now fetches a fresh nonce and retries automatically; only a real logout asks the user to sign in again.
- **PNG app icons.** Added 192 px, 512 px, maskable 512 px and a 180 px Apple touch icon. Before, there was only an SVG icon, which some Android launchers and every iPhone ignore, leaving a blank or generic icon.
- **Cache-safe PWA URLs.** The manifest and service worker are now served from `/?rar_wso_manifest=1` and `/?rar_wso_sw=1`, with `no-cache` and LiteSpeed no-cache headers. The old `.webmanifest` and `.js` pretty URLs could return 404 when rewrite rules weren't flushed, or get cached by LiteSpeed or the Hostinger CDN. The old URLs still work for existing installs.
- **Offline screen.** With no internet, the app shows a Bangla/English offline screen with *Try again*, and reloads itself when the connection returns. Before, the browser's own error page appeared.
- **Install button.** On Android Chrome the app shows an **Install app** button. On iPhone it shows the Safari *Share → Add to Home Screen* steps. The install tip is hidden once the app is installed.
- **Home-screen shortcuts.** Long-press the app icon for *Create Order* or *Stock Manager*.
- Richer manifest: `id`, portrait orientation, language, description and categories. Added iOS standalone meta tags.
- The app shows *You are offline* and *Back online* notices, and network error messages are clearer.
- Login fields use 16 px text so iPhone Safari doesn't zoom into the form.


## 1.2.0 — 2026-09-25

### Dashboard
- Redesigned the staff app: colourful, clickable cards on a mobile-first layout, live date and time in the store timezone (e.g. `Friday । Sep 25, 2026 । 01:49:02 pm`).
- Order cards for Today / 7 days / This month: orders, sales, completed, returned/cancelled — each with a comparison to the previous period and each opening its order list.
- Stock cards: All Stock, Available/Live Stock and Out of Stock, with a colour bar for healthy (green, above the low level), low (orange, 1 to the low level) and out (red, 0) products.
- 7-day sales chart, "Needs attention" list (low stock, out of stock, orders waiting 24h+, on-hold orders) and recent orders.

### Stock
- All Stock shows every product highlighted green / orange / red (read only); Live Stock and Out of Stock allow quantity updates in place.
- Stock Manager: search, category filter, sort, level chips, +5 / +10 buttons, save many changes at once with a reason, and a Movement log.
- New stock movement log table records staff updates (with reason) and WooCommerce order reductions and restocks.
- Configurable low stock level (default 10).

### Create Order
- Order date and number shown automatically; Contact No. with a +88 prefix and strict Bangladesh mobile validation (11 digits, 013–019, Bangla digits accepted) on client and server.
- Searchable District list (all 64 districts) and searchable Town / City list for the chosen district, with free text allowed.
- Colour-coded product search; stock-out products cannot be added.
- Items with Sl No., discount (amount or %), district-based shipping (Inside / Outside Dhaka), total and amount in words (lakh / crore).
- Payment method (Cash on delivery, bKash, Nagad, Cash paid) — filterable.
- Returning-customer lookup by phone.
- Save & Share: a sales order slip image is generated after saving, with Share (phone share sheet), WhatsApp, download, copy image and copy text.

### Shop Manager
- Order Control: All Orders, Live Orders (running orders only) and Processing, with search, status chips and in-place status changes with Undo.
- Sales & Growth report for 7 / 30 / 90 days: growth against the previous period, sales trend, orders per day, top products, payment methods, order status mix and sales channels.
- New `rar_wso_manage_orders` capability for Administrator and Shop Manager roles.

### Fixes
- Currency symbols such as ৳ no longer show as `&#2547;&nbsp;`.
- Order totals returned to the app are plain text instead of HTML.
- `/staff/` works right after activation or upgrade without first visiting wp-admin.
- Order search now finds orders by phone number in both HPOS and legacy storage.

### Compatibility
- Dashboard and report figures are read with lightweight SQL for both HPOS and legacy order storage, cached briefly with a fixed set of transients.
- New hooks: `rar_wso_live_statuses`, `rar_wso_payment_options`, `rar_wso_order_status_changed`.

## 1.1.0 — 2026-09-25

### Focused staff workflow
- Removed staff product creation/deletion from the PWA and settings.
- Product lifecycle management now stays in native WooCommerce for Administrator / Shop Manager users.
- Added upgrade migration to remove legacy RAR WSO add/delete capabilities and old settings.

### Stock Manager
- Fixed malformed WooCommerce currency/HTML output.
- Reworked product search to use WooCommerce product data-store search.
- Added stale-response protection for rapid product searches.
- Fixed unmanaged-stock UX so an unmanaged product is not presented as quantity zero.
- Added explicit stock-management activation through the stock update flow.
- Preserved WooCommerce CRUD writes and stock-change logging.

### Create Order
- Added strict Bangladesh district validation on client and server.
- Added available-stock validation before order creation when backorders are disabled.
- Added duplicate-order retry/idempotency protection for network/retry scenarios.
- Added safer staff session-expiry handling.
- Hid WooCommerce admin order links from users without WooCommerce management permission.
- Added extension hooks for shipping, payment, created orders and stock updates.

### PWA / Security
- Prevented authenticated staff HTML and wp-admin requests from being cached by the service worker.
- Added old RAR WSO service-worker cache cleanup.
- Added automatic plugin upgrade/migration routine.
- Added production plugin metadata for WooCommerce dependency, Update URI and GPL licensing.

### QA / Release
- Added PHP, JavaScript and shell syntax validation.
- Added WordPress + WooCommerce runtime smoke tests.
- Added migration, stock, search, order, duplicate-retry, dashboard and PWA endpoint tests.
- Added release-package structure validation.
- Added canonical installable release ZIP packaging.

## 1.0.0 — 2026-09-24

- Added private mobile-first `/staff/` PWA.
- Added dashboard cards for today's orders/sales, low stock and out-of-stock counts.
- Added searchable Stock Manager with protected stock updates.
- Added POS-style manual/social Create Order workflow.
- Added website-price defaults and optional staff line-price override.
- Added customer address, district, shipping charge and order notes.
- Added WooCommerce-native order creation and stock reduction.
- Added dedicated `Woo Stock & Order Staff` role.
- Added HPOS compatibility declaration.
- Added installable manifest/icon/service worker PWA shell.
- Added WooCommerce stock-change logging.
- Added Settings link on the WordPress Plugins screen.
