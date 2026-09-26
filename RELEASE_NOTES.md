# RAR Woo Stock & Order v1.4.0

**Release date:** 2026-09-26

v1.4.0 turns **WooCommerce → Stock & Order** into a professional **Control Center**. It has seven tabs: Overview, Staff, Activity, Settings, Security & Health, Tools and Help. It works on phone, tablet and desktop.

## Highlights

| Area | What you get |
|---|---|
| Overview | Live KPI cards (auto-refresh 1 min), alerts, 14-day staff-app vs website chart, health score, team leaderboard, stock watch, activity timeline |
| Staff | Mobile-friendly list, search/filters, per-person **discount limit**, branch, order-list access, block stock / orders / rate change, pause, sign out, password link via WhatsApp |
| Activity | Stock movements, staff orders, admin & security audit log — filters + CSV export |
| Settings | Brand colour + logo (live preview), payment methods + extras (Rocket, Upay…), free delivery amount, stock reasons, login limits, staff session length, daily summary email, history retention |
| Security & Health | 20+ checks with score and fix links, login stats, **emergency: sign out / pause all staff, switch app off** |
| Tools | **Stock valuation CSV** (incl. cost value with WooCommerce COGS), exports, cache / link repair, test email, settings backup / restore / reset, system report |
| Everywhere | Admin-bar menu, Dashboard widget, **Staff** column on orders, order & product side boxes, QR poster for installing the app |

## Validation

- `tests/smoke.php`: **102/102 passed** with HPOS on and with legacy storage (MariaDB), and on a fresh MySQL 8.0 site (CI replica), HPOS on and off.
- All 7 tabs render with no PHP warnings. At 390, 820 and 1440 px there is no horizontal scroll.
- QR codes decode correctly: short and 200-character URLs were checked with OpenCV, and an independent check matched a reference encoder bit for bit.
- An independent code review found one medium issue and several low ones. All were fixed before release.
  - The daily email now reports a full calendar day.
  - Staff management is limited to plain Staff accounts.
  - A slip logo can no longer block the slip.
  - Bangla payment names get stable keys.
  - CSV quoting is fixed, the upgrade runs under a lock, and reserved staff links are refused.

## Install / Upgrade

1. Take a full backup (files + database) — Hostinger → Backups.
2. Plugins → Add New → Upload Plugin → `rar-woo-stock-order-v1.4.0.zip` → **Replace current with uploaded**.
3. Open **WooCommerce → Stock & Order** → **Security & Health**, and fix anything red.
4. **Settings**: brand colour/logo, payment methods, free delivery, daily email → **Save changes**.
5. LiteSpeed Cache → Toolbox → **Purge All**. Open `/staff/` on each phone once.

Settings, staff accounts, WooCommerce products, orders and stock history are preserved. On the first load after updating, the plugin adds one new table (`wp_rar_wso_audit`) and one index on the stock history table.

---

## Previous release: v1.3.0

**Release date:** 2026-09-26

v1.3.0 is a full-audit release. Eleven defects were reproduced on a copy of the live stack (WordPress 7.1.2, WooCommerce 11.1.2, PHP 8.4, MariaDB), fixed, and covered by automated tests.

| # | Finding in v1.2.2 | Proof before | v1.3.0 |
|---|---|---|---|
| 1 | Two staff can sell the same last unit | 2 orders, stock −1; 25-phone rush: 55 orders for 50 units, stock −5 | Per-product lock + fresh DB read: 50 orders, stock 0 |
| 2 | Duplicate-save lock not atomic (`add_option` = INSERT … ON DUPLICATE KEY UPDATE) | second locker gets "success" | MySQL named lock |
| 3 | Price override bypasses the 20% discount limit | ৳1000 item sold for ৳1 | Lower rates count toward the limit |
| 4 | Refused orders are created, announced, then deleted | `woocommerce_new_order` fired for a refused order, with 0 items | Validate first; first save is complete |
| 5 | Stock save overwrites a newer change | 10 → website sale 9 → staff "+5" saved 15 (should be 14) | 409 conflict with current figure |
| 6 | Shared (parent) variation stock converted to own stock | variation S got its own stock 45, parent stayed 40 | Parent stock updated |
| 7 | Staff (view-only) can read any old order's customer data | 200-day-old order readable | This month / 7 days / own orders |
| 8 | Staff login: no CSRF token, no attempt limit, frameable | 12/12 guesses processed | Token, bot trap, 6/10 limit, CSP + frame headers |
| 9 | Staff role could become the public sign-up role | default_role = rar_wso_staff accepted | Blocked + downgrade guard |
| 10 | Re-opening a cancelled order oversells | — | Stock checked first |
| 11 | Tax added twice when prices include tax | ৳115 item → total ৳132.25 | Line totals stored net of tax |

An independent review of the new code found six more issues before release (bulk save across searches, rollback after a completed order, stock-reduced flag under a veto, stock management switched off, duplicate after a timed-out save plus app restart, login-limit IP spoofing). All are fixed and covered by tests.

## Validation

- `tests/smoke.php`: **72/72 passed with HPOS on, 72/72 with legacy order storage.**
- Graduated load (1 → 10 → 25 concurrent, staff-app mix incl. orders and stock saves): 0 errors, 0 server errors.
- Order rush (25 concurrent × 15 s, 50 units): exactly 50 orders, 446 correctly refused, stock 0.
- Browser checks at 360, 390, 768, 1366 and 1920 px: no horizontal scroll, no CSP violations, no console errors; back button closes panels; order draft survives reload.

## Install / Upgrade

1. Take a full site backup (files + database).
2. Plugins → Add New → Upload Plugin → `rar-woo-stock-order-v1.3.0.zip` → **Replace current with uploaded**.
3. WooCommerce → Stock & Order: check **Staff discount limit** (now also covers lower item rates) and the new **Staff accounts** and **Registration & login safety** sections.
4. LiteSpeed Cache → Toolbox → **Purge All**.
5. Open `/staff/` on each phone once.

Settings, WooCommerce products and orders are preserved.
