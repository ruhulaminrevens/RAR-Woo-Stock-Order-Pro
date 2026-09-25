# RAR Woo Stock & Order v1.2.2

**Release date:** 2026-09-25

v1.2.2 hardens orders, permissions and reports on top of v1.2.1's phone-app fixes. An external code review found six issues. Each one was confirmed in the code, fixed, and covered by an automated test.

| # | Review finding | Status in v1.2.2 |
|---|---|---|
| 1 | Concurrent duplicate orders; same product on several lines not summed for stock | Fixed: atomic lock, persistent request lookup, combined stock check |
| 2 | App "disabled" but backend actions still worked | Fixed: every staff AJAX action is refused while the app is off |
| 3 | Discount not permission-limited (staff could give 100%) | Fixed: new *Staff discount limit* setting (default 20%), enforced on the server |
| 4 | Pending/returned counted as sales; partial refunds not subtracted | Fixed: net sales, pending counted separately |
| 5 | "Refunded" could be set from the app without a real refund | Fixed: blocked, WooCommerce refund screen required |
| 6 | No automated tests / CI in this repo | Fixed: `tests/smoke.php` + GitHub Actions workflow |

## Validation

Tested on WordPress 7.0.2 + WooCommerce 11.1.2 + PHP 8.4:

- `tests/smoke.php`: **33/33 passed with HPOS on, 33/33 with legacy order storage**.
- 5 simultaneous identical order requests created exactly 1 order and reduced stock by 1. The test site uses SQLite, which serialises writes. The lock itself (`add_option` on a unique key) is also tested directly.
- Mobile browser test (Pixel 7 emulation): login, dashboard, stale-session renewal, Create Order shortcut, offline screen, logout. Chrome reports no installability errors.
- Simulated LiteSpeed-style CSS/JS optimizer: the dashboard stays styled and working.

The GitHub Actions workflow is included, but it hasn't run on GitHub yet. It will run after this commit is uploaded.

## Install / Upgrade

1. Take a full site backup.
2. Plugins → Add New → Upload Plugin → `rar-woo-stock-order-v1.2.2.zip` → **Replace current with uploaded**.
3. WooCommerce → Stock & Order → check **Staff discount limit** (default 20%).
4. LiteSpeed Cache → Toolbox → **Purge All**.
5. Open `/staff/` on the phone once.

Settings, WooCommerce products and orders are preserved.
