# RAR Woo Stock & Order v1.2.1

**Release date:** 2026-09-25

v1.2.1 is a phone-app (PWA) fix release on top of v1.2.0. Dashboard, stock, order and report features are unchanged.

## What was wrong

- After login, the app could break (no design, cards stuck loading) because LiteSpeed/Hostinger CSS-JS optimization changed its files.
- Login and logout went to `wp-login.php`, outside the installed app. Android opened a browser tab, and on iPhone the home-screen app could get stuck in a login loop.
- When the app stayed in the background for hours, its security nonce expired and every action failed until a manual reload.
- There was only an SVG icon. Many Android launchers and all iPhones need PNG icons.
- The manifest and service worker used pretty `.webmanifest` and `.js` URLs. These could 404 when rewrite rules weren't flushed, or get cached by LiteSpeed or the Hostinger CDN.
- With no internet, the browser's own error page appeared.

## What changed

- App screens switch off page caching and CSS/JS optimization (LiteSpeed, WP Rocket, W3TC, Autoptimize, SiteGround Optimizer, Cloudflare Rocket Loader).
- In-app login and logout on `/staff/`, with "Keep me signed in on this phone" on by default. A fallback link to the normal WordPress login stays available for sites with captcha or 2FA plugins.
- The app renews the nonce automatically and retries the failed request once.
- PNG icons: 192, 512, maskable 512 and Apple touch 180.
- Cache-safe `/?rar_wso_manifest=1` and `/?rar_wso_sw=1` URLs with `no-cache` and `X-LiteSpeed-Cache-Control: no-cache` headers. The legacy URLs still respond.
- Offline screen that reloads itself when the connection returns.
- **Install app** button on Android, Share → Add to Home Screen steps on iPhone, and *Create Order* / *Stock Manager* home-screen shortcuts.

## Validation

Tested on WordPress 7.0.2 + WooCommerce 11.1.2, in Chromium with Pixel 7 and iPhone 14 emulation:

- Chrome reports no installability errors.
- The manifest parses without errors, and all PNG icons load.
- The service worker registers with scope `/staff/` and controls the page.
- In-app login works, and a wrong password shows an error.
- The dashboard loads for Staff and Administrator.
- A stale nonce is renewed automatically, the request is retried, and no error toast appears.
- The `#create` shortcut opens Create Order.
- Offline navigation shows the offline screen, and the app reloads itself when back online.
- In-app logout returns to the login screen.
- No JavaScript errors.

## Install / Upgrade

1. Take a full site backup.
2. Plugins → Add New → Upload Plugin → `rar-woo-stock-order-v1.2.1.zip` → **Replace current with uploaded**.
3. If you use LiteSpeed Cache, go to LiteSpeed Cache → Toolbox → **Purge All**.
4. On each phone, open `/staff/` in Chrome (Android) or Safari (iPhone) and log in again.
5. If the app was installed before, remove the old home-screen icon, then install it again: **Install app** on Android, Share → **Add to Home Screen** on iPhone.

Settings, WooCommerce products and orders are preserved.
