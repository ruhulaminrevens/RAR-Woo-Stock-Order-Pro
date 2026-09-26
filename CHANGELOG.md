# Changelog

## 1.4.0 — 2026-09-26

Admin **Control Center** release. WooCommerce → Stock & Order is rebuilt from a single settings page into a responsive, tabbed control panel, with new controls for staff, reports, security and branding. Covered by `tests/smoke.php` (102 checks, HPOS on and off, MariaDB and MySQL 8).

### Control Center (WooCommerce → Stock & Order)
- **Overview:** 8 live KPI cards (sales today vs same time yesterday, orders, staff-app sales and share, month to date, orders waiting incl. >24h, stock value, low / out / below-zero stock). They auto-refresh every minute and link to the matching screen. It also has:
  - alert strip
  - 14-day sales chart (staff app vs website)
  - health score
  - team leaderboard (orders, sales, average, discount given)
  - stock watch
  - recent activity timeline
  - quick actions
- **Staff:** responsive list (cards on phones — no more sideways scroll), search and filters (online now, active today, paused, managers), month sales per person. A **Manage** panel per person offers:
  - name
  - branch / territory
  - **personal discount limit**
  - order lists allow/hide
  - block stock updates / order creation / rate changes for that person only
  - Pause / Resume, Sign out everywhere, new password link (copy or WhatsApp)
  - links to that person's orders and stock changes
- **Activity:** stock movements, staff orders and admin & security log, with date / person / source / status / event filters, pagination and **CSV export of the same filters**.
- **Settings:** grouped sections with a side menu and a sticky save bar that warns about unsaved changes. New options:
  - brand colour and logo, with live phone preview
  - phone and address on the slip
  - payment methods on/off, extra methods (Rocket, Upay…), default method
  - free delivery from an amount
  - stock-change reasons list
  - login limits, lock time and staff "keep me signed in" days
  - daily summary email
  - history retention
  - admin bar / dashboard widget / orders column switches
- **Security & Health:** 20+ read-only checks with a score and fix links (HTTPS, permalinks, staff route, stock management, DB locks, WP-Cron, file editor, debug display, "admin" user, application passwords, XML-RPC, PHP version, memory). It also has:
  - login protection stats
  - clear all login locks
  - **Emergency**: sign out all staff, pause all staff, switch the app off/on
- **Tools:** exports, maintenance, settings backup and a system report.
  - Exports: **stock valuation CSV** (qty × selling price, plus cost value when WooCommerce Cost of Goods is on), stock movements, staff orders, audit log. All exports are UTF-8 with a BOM for Excel and protected against formula injection.
  - Maintenance: recalculate dashboard figures, repair the staff link, send a test summary email, delete old history.
  - Settings backup: export / import / reset.
  - System report for support.
- **Help:** a quick start, a roles matrix and an FAQ.
- **QR code poster** so staff scan to install the app. It is printable, downloadable as PNG and generated in the browser (no external service).

### Across WordPress
- "Staff App" menu in the admin bar and a "Stock & Order — today" Dashboard widget.
- **Staff** column on the WooCommerce orders list (HPOS and legacy).
- "Staff app" box on staff orders: who created the order, branch, discount, and rates changed while billing.
- **Stock history** box on the product edit screen.
- Menu badge for stock below zero and orders waiting over 24h. It reads one autoloaded option, so no query is added per admin page.

### Audit & reports
- New audit log table: staff created / changed / paused / resumed / signed out, password links, staff sign-ins, login locks, settings saved (with changed keys), imports, resets, exports, emergency actions, emails.
- Daily summary email (optional, per-recipient) for the full calendar day: sales vs the day before, staff-app sales, month to date, waiting orders, stock alerts, staff table.

### Staff app
- Uses the brand colour (header, phone status bar, slip, manifest) and the logo (header, login page, slip).
- Slip shows the shop phone and address.
- Payment list follows the settings, with the default pre-selected; a switched-off method falls back safely on the server.
- Automatic shipping becomes 0 from the "free delivery" amount (staff can still type a charge).
- Stock Manager reasons come from the settings.

### Fixes and hardening
- The settings sanitizer treats unticked boxes correctly for the form and keeps existing values on import. Staff links used by WordPress / WooCommerce (`wp-json`, `wc-api`, `shop`…) or an existing page are refused. Negative shipping amounts are blocked.
- Staff management acts only on plain Staff accounts without extra admin capabilities. Shop Managers can't set a personal discount above the shop-wide limit.
- The upgrade runs once under a lock. The history tables are created and indexed on the first load after updating.
- Admin screens and exports are loaded only in wp-admin (not for staff-app AJAX calls).

## 1.3.0 — 2026-09-26

Full audit release: concurrency, security, staff accounts and phone UX. Every defect below was first reproduced on WordPress 7.1.2 + WooCommerce 11.1.2, then fixed and covered by `tests/smoke.php` (72 checks, HPOS on and off).

### Orders and stock (data integrity)
- **No more overselling between staff.** Two phones selling the last unit at the same moment both succeeded (stock −1). Each product's stock is now locked (MySQL named lock) while it is checked and taken, and the quantity is re-read straight from the database. Load test: 25 phones × 15 s against 50 units → exactly 50 orders, stock 0 (v1.2.2: 55 orders, stock −5).
- **Duplicate-save lock is truly atomic.** v1.2.2 used `add_option()`, which is `INSERT … ON DUPLICATE KEY UPDATE` and can let two requests through. Replaced by a named lock that is released automatically even if PHP crashes.
- **Stock saves can't overwrite a newer change.** The app now sends the quantity it was showing; if a sale or another update changed it meanwhile, the save is refused (HTTP 409) with the current figure instead of silently wiping out the other change. Bulk saves report per-row conflicts.
- **Shared variation stock is respected.** Updating a variation whose stock is kept on the parent product used to convert it into a separately stocked variation. It now updates the shared parent stock.
- **Stock writes use `wc_update_product_stock()`**: atomic SQL, WooCommerce's out-of-stock threshold, low-stock emails and hooks.
- **Orders are validated before they exist.** A refused order (e.g. discount over the limit) used to be created, announced to other plugins through `woocommerce_new_order`, then deleted. All checks now run first, and the first save happens with every item, address and total in place — so notification, courier and pixel plugins never see an empty or ghost order.
- **Stock is taken while locked, emails run after.** Slow SMTP no longer holds other orders.
- **Re-opening a cancelled order checks stock** before WooCommerce takes it again.
- Max 100 lines per order and 9,999 per line.
- Stores that enter prices including tax no longer get tax added twice on staff orders.
- A failing integration hook after an order is complete no longer deletes the order; a failed order rolls back exactly the stock it took.
- An order is flagged "stock reduced" only when WooCommerce actually reduced stock.
- Stock saves explain when shop-wide stock management is switched off instead of pretending to save.

### Security
- **Price override can't bypass the discount limit.** Staff could sell a ৳1000 item for ৳1. Lower item rates now count toward the Staff discount limit together with the discount (against the list-price subtotal).
- **Staff login page**: CSRF token, bot trap, and a failed-login limit (6 per account — username and email share one counter — / 10 per IP in 15 minutes → 15-minute lock, HTTP 429). The IP is the server's `REMOTE_ADDR`; behind a proxy/CDN use the `rar_wso_client_ip` filter.
- **Security headers on app screens**: strict Content-Security-Policy with per-response nonces, `X-Frame-Options`, `frame-ancestors`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`.
- **Staff see only recent orders**: order details limited to this month / last 7 days, plus orders they created. Shop Managers see everything.
- **No staff accounts from public registration**: the staff, Shop Manager, Editor and Administrator roles can't become the default sign-up role (also when written straight to the database), and a self-registered user that somehow gets one is downgraded to Customer.
- **Paused staff accounts** can't sign in anywhere and are refused by the app backend.
- Default order status can no longer be set to Refunded / Cancelled / Failed.

### Staff accounts (new)
- WooCommerce → Stock & Order → **Staff accounts**: list with last-active time, **Add staff** (the person gets a set-password email; a one-time link can also be shared on WhatsApp), **Pause / Resume**, **Sign out everywhere**, **New password link**.
- Administrators always can; an Administrator can allow Shop Managers.
- **Registration & login safety** panel shows sign-up settings, default role, HTTPS and failed-login count.
- Staff-only accounts that log in at wp-login.php / My Account land in the staff app; wp-admin sends them back to the app.

### Phone app UX
- **Back button / back gesture closes the open panel** instead of leaving the installed app (asks first if a form has unsaved data).
- **Create Order draft is kept on the phone** — a call, reload or closed app no longer loses a half-typed order; restored with a Discard option.
- Requests that hang on weak signal stop after 25–45 s with a clear message; a retried order save never creates a duplicate — the request ID is kept with the draft, across edits and app restarts, until the order is saved or discarded.
- Bulk stock save works for changes staged across several searches.
- Stock conflict and "busy" messages refresh the row with the current figure.
- Card figures never break across lines (whole taka, lakh / crore above ৳1,00,000; exact amount on hover and in lists).
- Negative stock flagged ("Negative stock", "oversold — recount") and listed under Needs attention.
- Price edits show "list ৳X · −Y%"; client checks the same combined limit as the server.
- Shared-stock badge on variations; saving one updates all its siblings in the list.
- Login: show/hide password, autofocus, "Signing in…" state.
- Larger touch targets for period / chip buttons; keyboard focus stays inside open panels; fallbacks for browsers without `color-mix()`.
- Sales & Growth shows delivery charges included in sales. Stock value labelled "at sale price".

### Performance
- Dashboard stock figures cached (2 min, dropped on any stock/product change): dashboard refresh 130 → 94 DB queries, ~105 → ~77 ms.
- Staff order creation: 451 → 358 DB queries, ~222 → ~163 ms.
- 2 fewer DB queries on every page of the website (plugin version and rewrite flag autoloaded).
- Report cache is invalidated once per request instead of on every order hook.

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
