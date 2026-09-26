# RAR Woo Stock & Order Pro

Mobile-first **WooCommerce stock manager and staff order entry app (PWA)** for teams that work mainly from their phones.

![Version](https://img.shields.io/badge/version-1.3.0-15234a) ![WordPress](https://img.shields.io/badge/WordPress-6.3%2B-21759b) ![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-7f54b3) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

## ⬇️ Download

### [Download RAR Woo Stock & Order v1.3.0 (ZIP)](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order-Pro/raw/main/dist/rar-woo-stock-order-v1.3.0.zip)

- **File:** `rar-woo-stock-order-v1.3.0.zip`
- **Install:** WordPress → Plugins → Add New → **Upload Plugin**
- **Upgrade:** choose **Replace current with uploaded**

> ⚠️ Don't use GitHub's green **Code → Download ZIP** button to install the plugin. That archive contains the whole repository, so WordPress would install it as a second, broken plugin. Always use the ZIP link above. It contains the correct `rar-woo-stock-order/` plugin folder.

## Install / Upgrade

1. **Back up the whole site first** (Hostinger → Backups, or a backup plugin).
2. Download the ZIP from the link above.
3. WordPress Admin → **Plugins → Add New → Upload Plugin** → choose the ZIP → **Install Now**.
   - If an older version is installed, choose **Replace current with uploaded**.
4. **Activate** the plugin.
5. If you use LiteSpeed Cache: **LiteSpeed Cache → Toolbox → Purge All**.
6. Check the settings in **WooCommerce → Stock & Order**.
7. Add staff in **WooCommerce → Stock & Order → Staff accounts** (they get a set-password email). Never let staff sign up themselves.

## 📱 Installing the app on a phone

| Phone | Steps |
|---|---|
| **Android (Chrome)** | Open `https://your-site.com/staff/` → log in → tap the **Install app** button at the bottom of the page (or Chrome menu ⋮ → **Install app / Add to Home screen**) |
| **iPhone (Safari)** | Open `https://your-site.com/staff/` **in Safari** → log in → **Share** ⎋ → **Add to Home Screen** |

- If the app was installed before v1.2.1: **delete the old icon from the home screen**, open `/staff/` in the browser and install it again. This gives you the new icon and the fixes.
- Long-press the app icon for the **Create Order** and **Stock Manager** shortcuts (Android).
- The site must be on **HTTPS**, otherwise the phone won't install the app.

## What's new in v1.3.0: concurrency, security, staff accounts

| Problem (v1.2.2) | Fix (v1.3.0) |
|---|---|
| Two staff could sell the last unit at the same time (stock went negative) | Per-product stock lock + fresh database check. 25 phones rushing 50 units → exactly 50 orders |
| Lowering the item rate bypassed the 20% discount limit | Lower rates + discount count together toward the limit |
| A refused order was created and announced to other plugins, then deleted | Everything is checked first; orders are saved complete |
| A stock save could overwrite a sale that happened meanwhile | Save refused with the current figure (conflict) |
| Updating a variation broke shared (parent) stock | Parent stock is updated |
| Staff could read any old order's customer details | Staff see this month / 7 days / their own orders |
| Staff login: unlimited guesses, no CSRF token | Token, bot trap, lockout after 6 wrong passwords; strict security headers |
| No way to add/pause staff; staff role could be handed out by sign-up | **Staff accounts** panel; staff role blocked from public registration |
| Phone back button left the app; half-typed orders were lost | Back closes the panel; order draft autosaves |

Tests: `tests/smoke.php` **72 checks**, HPOS on and off. Full details in [RELEASE_NOTES.md](RELEASE_NOTES.md).

## Earlier in v1.2.2: order and report hardening

| Problem | Fix (v1.2.2) |
|---|---|
| A double-tap or slow network could create the same order twice | Atomic lock + request ID saved on the order. 5 simultaneous saves create exactly 1 order |
| The same product on two lines could sell more than the stock | Stock is checked on the combined quantity |
| Staff could give any discount, even 100% | New **Staff discount limit** setting (default 20%). Shop Managers are not limited |
| "Refunded" could be set in the app without refunding money | Blocked. Use WooCommerce's Refund button |
| With the app turned off, stock/order actions still worked | Everything is blocked while the app is off |
| Pending/returned orders counted in sales; partial refunds not subtracted | Net sales, pending counted separately |
| No automated tests | `tests/smoke.php` (33 checks) + GitHub Actions CI |

## Earlier in v1.2.1: phone app fixes

| Problem (v1.2.0) | Fix (v1.2.1) |
|---|---|
| After login the page broke: no design, cards stuck loading, buttons not working (LiteSpeed/Hostinger CSS-JS optimization) | LiteSpeed, WP Rocket, W3TC, Autoptimize and Cloudflare Rocket Loader caching and optimization are switched off automatically on app pages |
| Login/logout opened `wp-login.php` and left the app. On iPhone it could get stuck in a login loop | Login and logout happen on the `/staff/` screen itself, with "Keep me signed in" on by default |
| After a long time in the background: "session expired", reload needed | The session (nonce) renews itself and the request is retried automatically |
| Only an SVG icon, so the icon was blank or missing on Android/iPhone | PNG icons: 192, 512, maskable and Apple touch icon |
| Manifest/service worker returned 404 or were cached by LiteSpeed/Hostinger | Cache-safe `/?rar_wso_manifest=1` and `/?rar_wso_sw=1` URLs with no-cache headers |
| Browser error page when offline | Bangla/English offline screen that reloads itself when the net comes back |
| Unclear how to install | **Install app** button on Android, Add to Home Screen steps on iPhone |

Full details: [CHANGELOG.md](CHANGELOG.md) · [RELEASE_NOTES.md](RELEASE_NOTES.md)

## Repository structure

```
RAR-Woo-Stock-Order-Pro/
├── README.md                          ← this page
├── .github/workflows/validate.yml    ← automated checks (CI)
├── dist/
│   └── rar-woo-stock-order-v1.3.0.zip ← installable plugin (download this)
├── rar-woo-stock-order.php            ← plugin source code
├── includes/
├── assets/ (css, js, icons)
├── CHANGELOG.md
├── RELEASE_NOTES.md
├── readme.txt
└── tests/smoke.php                    ← runtime tests (72 checks)
```

## What this plugin is for

RAR Woo Stock & Order intentionally focuses on two staff workflows:

1. **Stock Manager** — quickly search products and update stock from a phone.
2. **Create Order** — create real WooCommerce orders for Facebook, Instagram, phone or chat sales.

Product creation, full product editing and deletion stay in the normal **WooCommerce → Products** screens for Administrator / Shop Manager users. This avoids duplicating WooCommerce catalog management inside the staff app.

## Staff App

Default URL:

`https://your-store.com/staff/`

The app is private, capability-protected and marked `noindex,nofollow,noarchive`.


## Dashboard

- Live date and time in the store timezone: `Friday । Sep 25, 2026 । 01:49:02 pm`
- Colourful, clickable cards with Today / 7 days / This month:
  - Today's Orders · Today's Sales · Completed Orders · Returned / Cancelled (each with a comparison to the previous period)
- Stock cards:
  - **All Stock** — every product, highlighted green (above the low level), orange (1 to the low level) and red (0). View only.
  - **Available / Live Stock** — green and orange products, quantities editable in place.
  - **Out of Stock** — red products only, quantities editable in place.
- 7-day sales chart, "Needs attention" list and recent orders

## Stock Manager

- Product name / SKU search, category filter, sort, level chips
- Product image, price, level badge, last update time
- − / + / +5 / +10 quantity controls, bulk **Save all** with a reason (restock, count correction, damaged, return, transfer)
- **Movement log** of every staff change and every WooCommerce order reduction / restock
- Unmanaged products shown as *Not tracked* with an explicit **Set stock** flow
- Protected stock updates through WooCommerce CRUD and WooCommerce logs (`source: rar-wso`)

## Create Order

- Date and order number filled automatically
- Customer Details: Name *, Contact No. * (`+88` + 11 digits, 013–019, Bangla digits accepted), Email (optional — receives WooCommerce order emails)
- Shipping Details: Full Address *, District * (searchable, all 64 districts), Town / City * (searchable list for the district, free text allowed)
- Order Details: colour-coded product search (stock-out products cannot be added), items with Sl No., quantity and optional price override
- Items subtotal → Discount (amount or %) → Shipping (auto Inside / Outside Dhaka, editable) → Total → **In words** (lakh / crore)
- Payment method: Cash on delivery, bKash, Nagad, Cash (paid at shop) — filterable with `rar_wso_payment_options`
- Returning-customer lookup by phone
- **Save & Share**: generates a sales order slip image — Share (phone share sheet: WhatsApp, Messenger, …), WhatsApp message to the customer, download, copy image, copy text

Production protections include Bangladesh district and phone validation on client and server, available-stock validation, duplicate-order retry protection, WooCommerce-native order creation, stock reduction and emails.

## Shop Manager tools

Administrator and Shop Manager users (capability `rar_wso_manage_orders`) also see:

- **Order Control** — All Orders, Live Orders (running orders only, oldest first) and Total Processing, with search, status chips and status changes with Undo
- **Sales & Growth** — 7 / 30 / 90 days: sales, orders, average order and items sold with growth against the previous period, sales trend, orders per day, top products, payment methods, order status mix and sales channels

## Roles

### Woo Stock & Order Staff

Default staff capabilities:

- Access the private staff app
- Search/view products
- Update stock
- Create WooCommerce orders
- Override order-line price when the setting is enabled

The staff app does **not** provide product add/delete controls.

### Administrator / Shop Manager

Continue to use native WooCommerce screens for:

- Add product
- Edit product
- Delete / Trash product
- Full catalog management

## Settings

Open:

**WooCommerce → Stock & Order**

Available settings:

- Enable / disable staff app
- App title
- Staff URL slug
- Default new order status
- Allow item-price override
- Staff discount limit (default 20%; covers discount + lower item rates; Shop Managers not limited)
- Shipping — Inside Dhaka / Outside Dhaka (auto-filled by district)
- Default shipping charge
- Low stock level (default 10)
- Staff order lists (view only)
- Business name and footer line on the sales slip
- Shop Managers may add / pause staff (Administrator only)

## Staff accounts (no public registration)

Staff accounts are created by an Administrator in **WooCommerce → Stock & Order → Staff accounts**:

- **Add staff** — name, username, email. The person gets an email to set their own password; a one-time link (valid 24 hours) is also shown to share on WhatsApp.
- **Pause / Resume** — a paused account can't sign in anywhere and is signed out on every device.
- **Sign out everywhere** and **New password link**.
- **Last active in app** for every staff member.

Public sign-up can never create a staff account: the staff, Shop Manager, Editor and Administrator roles are blocked as the default sign-up role, and a self-registered user that somehow gets one is set back to Customer.

## Integration Hooks

The plugin stays decoupled from courier, payment and workflow add-ons. Integrations can use these hooks:

- `rar_wso_shipping_total`
- `rar_wso_payment_method`
- `rar_wso_payment_method_title`
- `rar_wso_order_created`
- `rar_wso_stock_updated`
- `rar_wso_order_status_changed`
- `rar_wso_live_statuses` (filter)
- `rar_wso_payment_options` (filter)

This keeps RAR Woo Stock & Order focused while allowing other WooCommerce plugins to extend shipping, payment and order workflow behavior.

## Security & Data Safety

- Per-product stock locks and fresh database reads for order creation and stock saves (no overselling, no lost updates)
- Staff login form: CSRF token, bot trap, failed-login limit (HTTP 429)
- Strict Content-Security-Policy (per-response nonce), `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` on app screens
- Staff order details limited to recent / own orders
- WordPress nonce on every AJAX request
- Capability check on every staff action
- No public inventory/order write endpoint
- WooCommerce CRUD APIs instead of direct order-table writes
- HPOS compatibility declared
- Existing WooCommerce products and orders remain the source of truth
- Authenticated `/staff/` HTML, AJAX and `wp-admin` responses are never cached by the service worker or by page caches (LiteSpeed / Hostinger no-cache headers)
- Service worker caches only safe static app assets
- Old RAR WSO caches are removed during service-worker activation
- Uninstall preserves WooCommerce product/order data and plugin operational history

## Compatibility

- WordPress 6.3+ (tested on 7.1.2)
- Tested with automated runtime tests: HPOS on and legacy order storage
- WooCommerce 8.0+ (tested on 11.1.2), HPOS supported
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10+ recommended (named locks); falls back to a row lock on other databases
- Works on Hostinger shared hosting (LiteSpeed). Needs no Node.js or extra server software

## Troubleshooting (phone app)

| Symptom | Fix |
|---|---|
| No **Install app** option | The site must be on HTTPS. Open `/staff/`, log in, wait a few seconds, then check the Chrome menu ⋮ |
| Old icon or old design still showing | Delete the app from the home screen → LiteSpeed **Purge All** → open `/staff/` in the browser and install again |
| Login doesn't work (captcha/2FA plugin) | Use the **"Having trouble? Use the WordPress login page"** link below the login form |
| Page breaks after login (no design, cards stuck loading) | Update to v1.2.1 → **LiteSpeed Cache → Toolbox → Purge All** → close the app completely and open it again. If it's still broken: LiteSpeed Cache → Page Optimization → add `rar-woo-stock-order` to **CSS Excludes** and **JS Excludes** |
| "Too many wrong passwords" | Wait 15 minutes, or ask an Administrator for a **New password link** (Staff accounts) |
| "Stock changed … while you were editing" | Someone sold or updated it meanwhile. Check the new figure on the row and save again |
| `/staff/` shows a 404 | WordPress → **Settings → Permalinks → Save Changes** (without changing anything) |

## License

GPL-2.0-or-later © Ruhul Amin Revens
