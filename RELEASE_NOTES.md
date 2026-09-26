# RAR Woo Stock & Order v1.3.0

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
