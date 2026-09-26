<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAR_WSO_PWA {
    /** Per-response nonce for the Content-Security-Policy (only our own inline scripts run). */
    private $csp_nonce = '';

    public function __construct() {
        add_action( 'init', array( $this, 'rewrites' ) );
        add_filter( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'template_redirect', array( $this, 'route' ), 0 );
        add_action( 'wp', array( $this, 'early_flags' ), 0 );
    }

    public function rewrites() {
        $settings = RAR_WSO_Plugin::settings();
        $slug     = sanitize_title( $settings['staff_slug'] );

        if ( ! $slug ) {
            $slug = 'staff';
        }

        add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?rar_wso_app=1', 'top' );
        add_rewrite_rule( '^rar-wso-manifest\.webmanifest$', 'index.php?rar_wso_manifest=1', 'top' );
        add_rewrite_rule( '^rar-wso-sw\.js$', 'index.php?rar_wso_sw=1', 'top' );
        add_rewrite_rule( '^rar-wso-icon\.svg$', 'index.php?rar_wso_icon=1', 'top' );
    }

    public function query_vars( $vars ) {
        $vars[] = 'rar_wso_app';
        $vars[] = 'rar_wso_manifest';
        $vars[] = 'rar_wso_sw';
        $vars[] = 'rar_wso_icon';
        $vars[] = 'rar_wso_offline';
        return $vars;
    }

    /**
     * PWA helper URLs. These use plain query strings on the home URL instead of
     * pretty ".js" / ".webmanifest" paths, so they work even when rewrite rules
     * were not flushed and are never mistaken for static files by LiteSpeed /
     * Hostinger CDN caching.
     */
    public static function manifest_url() {
        return add_query_arg( array( 'rar_wso_manifest' => 1, 'v' => RAR_WSO_VERSION ), home_url( '/' ) );
    }

    public static function sw_url() {
        return add_query_arg( 'rar_wso_sw', 1, home_url( '/' ) );
    }

    public static function offline_url() {
        return add_query_arg( array( 'rar_wso_offline' => 1, 'v' => RAR_WSO_VERSION ), home_url( '/' ) );
    }

    public static function icon_url( $file ) {
        return RAR_WSO_URL . 'assets/icons/' . $file . '?ver=' . rawurlencode( RAR_WSO_VERSION );
    }

    /**
     * Keep page caches AND front-end optimizers away from the app screens.
     * LiteSpeed Cache / Hostinger / WP Rocket / W3TC / Autoptimize can minify, combine,
     * defer or delay the app's CSS/JS, which leaves the dashboard unstyled or stuck
     * on loading cards right after login.
     */
    private function no_page_cache() {
        foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY', 'DONOTCDN', 'DONOTROCKETOPTIMIZE', 'DONOTASYNCCSS', 'DONOTDELAYJS', 'LITESPEED_NO_OPTM', 'LITESPEED_NO_LAZY', 'LITESPEED_NO_CACHE' ) as $const ) {
            if ( ! defined( $const ) ) {
                define( $const, true );
            }
        }
        do_action( 'litespeed_control_set_nocache', 'rar-wso staff app' );
        do_action( 'litespeed_disable_all', 'rar-wso staff app' );
        add_filter( 'autoptimize_filter_noptimize', '__return_true' );
        add_filter( 'rocket_override_donotcachepage', '__return_false' );
        add_filter( 'sgo_css_combine_exclude', '__return_true' );
        nocache_headers();
        if ( ! headers_sent() ) {
            header( 'X-LiteSpeed-Cache-Control: no-cache' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, private' );
        }
    }

    private function nonce() {
        if ( '' === $this->csp_nonce ) {
            $this->csp_nonce = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
        }
        return $this->csp_nonce;
    }

    /** nonce="…" attribute for inline / own script tags. */
    private function nonce_attr() {
        return ' nonce="' . esc_attr( $this->nonce() ) . '"';
    }

    /** Set no-cache / no-optimize flags before cache and optimizer plugins start output buffering. */
    public function early_flags() {
        foreach ( array( 'rar_wso_app', 'rar_wso_manifest', 'rar_wso_sw', 'rar_wso_offline' ) as $var ) {
            if ( get_query_var( $var ) ) {
                $this->no_page_cache();
                return;
            }
        }
    }

    /**
     * Cache/optimizer plugins (LiteSpeed Cache, WP Rocket, Autoptimize, Hostinger tools...)
     * wrap the whole page in an output buffer and rewrite the HTML when it is sent:
     * they swap the stylesheet for a combined file and turn scripts into delayed
     * "litespeed/javascript". For the logged-in app screen that left the dashboard
     * unstyled with an empty clock and no cards. Closing those buffers before we
     * print means our HTML goes straight to the browser untouched.
     */
    private function bypass_output_buffers() {
        while ( ob_get_level() > 0 ) {
            if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                break;
            }
        }
    }

    public function route() {
        if ( get_query_var( 'rar_wso_manifest' ) ) {
            $this->manifest();
        }
        if ( get_query_var( 'rar_wso_sw' ) ) {
            $this->service_worker();
        }
        if ( get_query_var( 'rar_wso_icon' ) ) {
            $this->icon();
        }
        if ( get_query_var( 'rar_wso_offline' ) ) {
            $this->offline_page();
        }
        if ( get_query_var( 'rar_wso_app' ) ) {
            $this->app();
        }
    }

    /** Sign in without leaving the app scope (wp-login.php would open outside the installed app). */
    private function handle_login() {
        $login    = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Bot trap: a hidden field people never fill in.
        if ( ! empty( $_POST['rar_wso_website'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            RAR_WSO_Security::record_failure( $login );
            return __( 'Login failed. Please try again.', 'rar-woo-stock-order' );
        }

        // Login CSRF protection. The form is never cached, so the token is always fresh.
        $token = isset( $_POST['rar_wso_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rar_wso_login_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $token, 'rar_wso_login' ) ) {
            return __( 'The login page was open too long. Please enter your password again.', 'rar-woo-stock-order' );
        }

        $wait = RAR_WSO_Security::locked_minutes( $login );
        if ( $wait ) {
            status_header( 429 );
            /* translators: %d minutes */
            return sprintf( _n( 'Too many wrong passwords. Try again in %d minute, or ask your Shop Manager for a new password link.', 'Too many wrong passwords. Try again in %d minutes, or ask your Shop Manager for a new password link.', $wait, 'rar-woo-stock-order' ), $wait );
        }

        if ( '' === $login || '' === $password ) {
            return __( 'Enter your username and password.', 'rar-woo-stock-order' );
        }

        $user = wp_signon(
            array(
                'user_login'    => $login,
                'user_password' => $password,
                'remember'      => ! empty( $_POST['rememberme'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ),
            is_ssl()
        );

        if ( is_wp_error( $user ) ) {
            $code = $user->get_error_code();
            if ( 'rar_wso_paused' !== $code ) {
                RAR_WSO_Security::record_failure( $login );
            }
            if ( in_array( $code, array( 'invalid_username', 'invalid_email', 'incorrect_password', 'empty_username', 'empty_password' ), true ) ) {
                return __( 'Wrong username or password. Please try again.', 'rar-woo-stock-order' );
            }
            $message = wp_strip_all_tags( $user->get_error_message() );
            return '' !== $message ? $message : __( 'Login failed. Please try again.', 'rar-woo-stock-order' );
        }

        RAR_WSO_Security::clear_failures( $login );
        wp_safe_redirect( RAR_WSO_Plugin::staff_url() );
        exit;
    }

    private function handle_logout() {
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( is_user_logged_in() && wp_verify_nonce( $nonce, 'rar_wso_logout' ) ) {
            wp_logout();
        }
        wp_safe_redirect( RAR_WSO_Plugin::staff_url() );
        exit;
    }

    public static function logout_url() {
        return wp_nonce_url( add_query_arg( 'rar_wso_logout', 1, RAR_WSO_Plugin::staff_url() ), 'rar_wso_logout' );
    }

    /** Shared <head> tags for every screen inside the app scope. */
    private function head_tags( $title ) {
        ?>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?php echo esc_attr( RAR_WSO_Plugin::brand_color() ); ?>">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( self::short_name() ); ?>">
<meta name="format-detection" content="telephone=no">
<title><?php echo esc_html( $title ); ?></title>
<link rel="manifest" href="<?php echo esc_url( self::manifest_url() ); ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( self::icon_url( 'icon-192.png' ) ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( self::icon_url( 'apple-touch-icon.png' ) ); ?>">
<link rel="stylesheet" data-no-optimize="1" data-no-minify="1" data-noptimize="1" href="<?php echo esc_url( RAR_WSO_URL . 'assets/css/staff.css?ver=' . rawurlencode( RAR_WSO_VERSION ) ); ?>">
<?php if ( '#15234a' !== RAR_WSO_Plugin::brand_color() ) : ?>
<style>:root{--top:<?php echo esc_html( RAR_WSO_Plugin::brand_color() ); ?>}.mark{color:<?php echo esc_html( RAR_WSO_Plugin::brand_color() ); ?>}</style>
<?php endif; ?>
        <?php
    }

    /** Header mark: the shop logo if one is set, otherwise the first letter of the business name. */
    private static function mark_html() {
        $logo = RAR_WSO_Plugin::logo_url( 'thumbnail' );
        if ( $logo ) {
            return '<img src="' . esc_url( $logo ) . '" alt="">';
        }
        $name = RAR_WSO_Plugin::business_name();
        $char = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
        return esc_html( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $char ) : strtoupper( $char ) );
    }

    private static function short_name() {
        $settings = RAR_WSO_Plugin::settings();
        $title    = trim( (string) $settings['dashboard_title'] );
        if ( '' === $title ) {
            $title = 'Stock & Order';
        }
        return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 24 ) : substr( $title, 0, 24 );
    }

    private function icon() {
        header( 'Cache-Control: public, max-age=604800' );
        header( 'Content-Type: image/svg+xml; charset=utf-8' );
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect x="28" y="28" width="456" height="456" rx="96" fill="#15234a"/><path d="M150 210h212v166H150zM150 210l106-58 106 58M256 152v224" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/><circle cx="375" cy="377" r="58" fill="#fff"/><path d="M347 377h56M375 349v56" stroke="#15234a" stroke-width="18" stroke-linecap="round"/></svg>';
        exit;
    }

    private function manifest() {
        $this->bypass_output_buffers();
        $settings   = RAR_WSO_Plugin::settings();
        $staff_url  = RAR_WSO_Plugin::staff_url();
        $staff_path = trailingslashit( wp_parse_url( $staff_url, PHP_URL_PATH ) );

        $this->no_page_cache();
        header( 'Content-Type: application/manifest+json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );

        echo wp_json_encode(
            array(
                'id'               => $staff_path,
                'name'             => $settings['dashboard_title'],
                'short_name'       => self::short_name(),
                'description'      => __( 'Stock and order app for shop staff.', 'rar-woo-stock-order' ),
                'lang'             => str_replace( '_', '-', get_locale() ),
                'dir'              => is_rtl() ? 'rtl' : 'ltr',
                'start_url'        => $staff_url,
                'scope'            => $staff_path,
                'display'          => 'standalone',
                'display_override' => array( 'standalone', 'minimal-ui' ),
                'orientation'      => 'portrait',
                'background_color' => '#f5f7f8',
                'theme_color'      => RAR_WSO_Plugin::brand_color(),
                'categories'       => array( 'business', 'productivity' ),
                'icons'            => array(
                    array( 'src' => self::icon_url( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
                    array( 'src' => self::icon_url( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
                    array( 'src' => self::icon_url( 'maskable-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
                ),
                'shortcuts'        => array(
                    array( 'name' => 'Create Order', 'short_name' => 'New order', 'url' => $staff_url . '#create', 'icons' => array( array( 'src' => self::icon_url( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png' ) ) ),
                    array( 'name' => 'Stock Manager', 'short_name' => 'Stock', 'url' => $staff_url . '#stock', 'icons' => array( array( 'src' => self::icon_url( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png' ) ) ),
                ),
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    private function service_worker() {
        $this->bypass_output_buffers();
        $this->no_page_cache();
        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Service-Worker-Allowed: /' );

        $staff_path = trailingslashit( wp_parse_url( RAR_WSO_Plugin::staff_url(), PHP_URL_PATH ) );
        $assets     = array(
            RAR_WSO_URL . 'assets/css/staff.css?ver=' . rawurlencode( RAR_WSO_VERSION ),
            RAR_WSO_URL . 'assets/js/staff.js?ver=' . rawurlencode( RAR_WSO_VERSION ),
            self::icon_url( 'icon-192.png' ),
            self::icon_url( 'apple-touch-icon.png' ),
            self::offline_url(),
        );

        echo "/* RAR Woo Stock & Order service worker v" . esc_js( RAR_WSO_VERSION ) . " */\n";
        echo "const CACHE='rar-wso-assets-" . esc_js( RAR_WSO_VERSION ) . "-r2';\n";
        echo 'const STAFF_PATH=' . wp_json_encode( $staff_path ) . ";\n";
        echo 'const OFFLINE=' . wp_json_encode( self::offline_url(), JSON_UNESCAPED_SLASHES ) . ";\n";
        echo 'const ASSETS=' . wp_json_encode( array_values( $assets ), JSON_UNESCAPED_SLASHES ) . ";\n";
        ?>
self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(c => Promise.all(ASSETS.map(u => c.add(new Request(u, { cache: 'reload', credentials: 'same-origin' })).catch(() => null)))));
});
self.addEventListener('activate', e => {
  e.waitUntil(Promise.all([
    caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('rar-wso-') && k !== CACHE).map(k => caches.delete(k)))),
    self.registration.navigationPreload ? self.registration.navigationPreload.enable().catch(() => null) : null,
    self.clients.claim()
  ]));
});
self.addEventListener('message', e => { if (e.data === 'skipWaiting') self.skipWaiting(); });
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const u = new URL(req.url);
  if (u.origin !== location.origin) return;
  // App screens: always fresh from the network (they carry the login session and nonce).
  // Only when the phone is offline, show the cached offline screen instead of a browser error page.
  if (req.mode === 'navigate') {
    if (!u.pathname.startsWith(STAFF_PATH)) return;
    e.respondWith((async () => {
      try {
        const pre = await e.preloadResponse;
        if (pre) return pre;
        return await fetch(req);
      } catch (err) {
        const hit = await caches.match(OFFLINE);
        return hit || new Response('<h1>Offline</h1><p>Check the internet connection and try again.</p>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      }
    })());
    return;
  }
  if (!ASSETS.includes(req.url)) return;
  e.respondWith(caches.match(req).then(hit => hit || fetch(req).then(r => {
    if (r && r.ok) { const copy = r.clone(); caches.open(CACHE).then(c => c.put(req, copy)).catch(() => null); }
    return r;
  })));
});
<?php
        exit;
    }

    private function offline_page() {
        $this->bypass_output_buffers();
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'Cache-Control: public, max-age=86400' );
        RAR_WSO_Security::send_headers( $this->nonce() );
        $settings = RAR_WSO_Plugin::settings();
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
        <?php $this->head_tags( $settings['dashboard_title'] ); ?>
</head>
<body class="rar-login-page">
<div class="rar-login-card rar-offline">
    <div class="rar-kicker">OFFLINE</div>
    <h1>ইন্টারনেট সংযোগ নেই</h1>
    <p>No internet connection. Check Wi-Fi or mobile data, then try again.</p>
    <button type="button" class="rar-primary" id="rar-retry">Try again</button>
</div>
<script data-no-optimize="1" data-cfasync="false"<?php echo $this->nonce_attr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>document.getElementById('rar-retry').addEventListener('click',function(){location.reload();});addEventListener('online',function(){location.reload();});</script>
</body>
</html>
        <?php
        exit;
    }

    private function app() {
        $settings = RAR_WSO_Plugin::settings();

        if ( 'yes' !== $settings['enabled'] ) {
            status_header( 404 );
            exit;
        }

        $this->no_page_cache();

        if ( isset( $_GET['rar_wso_logout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $this->handle_logout();
        }

        $login_error = '';
        if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) && ! empty( $_POST['rar_wso_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $login_error = $this->handle_login();
        }

        $this->bypass_output_buffers();
        RAR_WSO_Security::send_headers( $this->nonce() );

        if ( ! is_user_logged_in() ) {
            $this->login_screen( $login_error );
        }

        if ( ! RAR_WSO_Plugin::can( 'rar_wso_access' ) ) {
            status_header( 403 );
            $this->simple_page(
                __( 'Access denied', 'rar-woo-stock-order' ),
                __( 'Your account does not have permission to use the staff app.', 'rar-woo-stock-order' )
            );
        }

        $districts = array();
        foreach ( (array) WC()->countries->get_states( 'BD' ) as $code => $label ) {
            $districts[] = array( 'code' => $code, 'name' => trim( wp_strip_all_tags( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) ) ) );
        }

        $user     = wp_get_current_user();
        $manager  = RAR_WSO_Plugin::is_manager();
        $currency = trim( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ), " \t\n\r\0\x0B\xC2\xA0" );
        $tz       = wp_timezone();
        $config   = array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'rar_wso_nonce' ),
            'currency'         => $currency,
            'currencyPosition' => get_option( 'woocommerce_currency_pos', 'left' ),
            'decimals'         => wc_get_price_decimals(),
            'decimalSep'       => wc_get_price_decimal_separator(),
            'thousandSep'      => wc_get_price_thousand_separator(),
            'districts'        => wp_list_pluck( $districts, 'name' ),
            'districtList'     => $districts,
            'canStock'         => RAR_WSO_Plugin::can( 'rar_wso_manage_stock' ),
            'canOrder'         => RAR_WSO_Plugin::can( 'rar_wso_create_orders' ),
            'canViewOrders'    => RAR_WSO_Plugin::can_view_orders(),
            'isManager'        => $manager,
            'allowPrice'       => 'yes' === $settings['allow_price_override'] && RAR_WSO_Plugin::can( 'rar_wso_adjust_price' ),
            'shipping'         => (float) $settings['default_shipping'],
            'shippingDhaka'    => '' === (string) $settings['shipping_dhaka'] ? null : (float) $settings['shipping_dhaka'],
            'shippingOutside'  => '' === (string) $settings['shipping_outside'] ? null : (float) $settings['shipping_outside'],
            'threshold'        => RAR_WSO_Plugin::low_threshold(),
            'statuses'         => RAR_WSO_Ajax::all_statuses(),
            'changeStatuses'   => array_keys( RAR_WSO_Ajax::changeable_statuses() ),
            'maxDiscount'      => RAR_WSO_Plugin::max_discount_percent(),
            'liveStatuses'     => RAR_WSO_Ajax::live_statuses(),
            'payments'         => RAR_WSO_Ajax::enabled_payment_options(),
            'payDefault'       => RAR_WSO_Ajax::default_payment(),
            'stockReasons'     => RAR_WSO_Plugin::stock_reasons(),
            'freeShipOver'     => (float) $settings['free_shipping_over'],
            'brand'            => RAR_WSO_Plugin::brand_color(),
            'logo'             => RAR_WSO_Plugin::logo_url( 'medium' ),
            'slipPhone'        => (string) $settings['slip_phone'],
            'slipAddress'      => (string) $settings['slip_address'],
            'timezone'         => wp_timezone_string(),
            'tzOffset'         => (int) $tz->getOffset( new DateTime( 'now', $tz ) ),
            'business'         => RAR_WSO_Plugin::business_name(),
            'slipFooter'       => (string) $settings['slip_footer'],
            'site'             => wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
            'userName'         => $user->display_name,
            'userId'           => (int) $user->ID,
            'roleLabel'        => $manager ? __( 'Shop Manager', 'rar-woo-stock-order' ) : __( 'Staff', 'rar-woo-stock-order' ),
            'adminOrdersUrl'   => current_user_can( 'manage_woocommerce' ) ? admin_url( 'admin.php?page=wc-orders' ) : '',
            'staffUrl'         => RAR_WSO_Plugin::staff_url(),
            'swUrl'            => self::sw_url(),
            'loginUrl'         => RAR_WSO_Plugin::staff_url(),
            'version'          => RAR_WSO_VERSION,
        );
        $icon = static function ( $path ) {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
        };
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<?php $this->head_tags( $settings['dashboard_title'] ); ?>
</head>
<body class="<?php echo $manager ? 'rar-is-manager' : 'rar-is-staff'; ?>">
<header class="topbar">
    <div class="wrap tb-in">
        <div class="brand">
            <span class="mark" aria-hidden="true"><?php echo self::mark_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in mark_html() ?></span>
            <div><b><?php echo esc_html( $settings['dashboard_title'] ); ?></b><small><?php echo esc_html( $user->display_name ); ?> · <?php echo $manager ? esc_html__( 'Shop Manager', 'rar-woo-stock-order' ) : esc_html__( 'Staff', 'rar-woo-stock-order' ); ?></small></div>
        </div>
        <a class="tb-out" href="<?php echo esc_url( self::logout_url() ); ?>"><?php esc_html_e( 'Log out', 'rar-woo-stock-order' ); ?></a>
    </div>
</header>

<main class="wrap" id="rar-main">
    <section class="hero" aria-label="<?php esc_attr_e( 'Today', 'rar-woo-stock-order' ); ?>">
        <div>
            <div class="eyebrow"><span class="live-dot" aria-hidden="true"></span><?php esc_html_e( "Today's Date", 'rar-woo-stock-order' ); ?></div>
            <div class="clock" id="rar-clock">&nbsp;</div>
            <div class="greet" id="rar-greet">&nbsp;</div>
        </div>
        <div class="quick">
            <?php if ( RAR_WSO_Plugin::can( 'rar_wso_create_orders' ) ) : ?>
                <button type="button" class="qa qa-order" data-open="create"><span class="qa-ico"><?php echo $icon( '<path d="M12 5v14M5 12h14"/>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span><b>Create Order</b><small>নতুন অর্ডার নিন</small></span></button>
            <?php endif; ?>
            <?php if ( RAR_WSO_Plugin::can( 'rar_wso_manage_stock' ) ) : ?>
                <button type="button" class="qa qa-stock" data-open="stockmgr"><span class="qa-ico"><?php echo $icon( '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span><b>Stock Manager</b><small>স্টক দেখুন ও আপডেট করুন</small></span></button>
            <?php endif; ?>
        </div>
    </section>

    <section class="sec" aria-labelledby="h-orders">
        <div class="sec-h">
            <h2 id="h-orders"><?php esc_html_e( 'Orders', 'rar-woo-stock-order' ); ?></h2>
            <div class="seg" role="group" aria-label="<?php esc_attr_e( 'Period', 'rar-woo-stock-order' ); ?>" id="rar-period">
                <button type="button" data-period="today" aria-pressed="true"><?php esc_html_e( 'Today', 'rar-woo-stock-order' ); ?></button>
                <button type="button" data-period="7d" aria-pressed="false"><?php esc_html_e( '7 days', 'rar-woo-stock-order' ); ?></button>
                <button type="button" data-period="month" aria-pressed="false"><?php esc_html_e( 'This month', 'rar-woo-stock-order' ); ?></button>
            </div>
        </div>
        <div class="kpis" id="rar-kpis"><div class="card skel"></div><div class="card skel"></div><div class="card skel"></div><div class="card skel"></div></div>
    </section>

    <section class="sec" aria-labelledby="h-stock">
        <div class="sec-h">
            <h2 id="h-stock"><?php esc_html_e( 'Stock', 'rar-woo-stock-order' ); ?></h2>
            <div class="legend" id="rar-legend"></div>
        </div>
        <div class="trio" id="rar-stock-cards"><div class="card wide skel"></div><div class="card skel"></div><div class="card skel"></div></div>
    </section>

    <?php if ( $manager ) : ?>
    <section class="sec" aria-labelledby="h-mgr">
        <div class="sec-h"><h2 id="h-mgr"><?php esc_html_e( 'Order Control', 'rar-woo-stock-order' ); ?> <span class="badge"><?php esc_html_e( 'Shop Manager', 'rar-woo-stock-order' ); ?></span></h2></div>
        <div class="trio" id="rar-mgr-cards"><div class="card wide skel"></div><div class="card skel"></div><div class="card skel"></div></div>
    </section>
    <section class="sec" aria-labelledby="h-growth">
        <div class="sec-h">
            <h2 id="h-growth"><?php esc_html_e( 'Sales & Growth', 'rar-woo-stock-order' ); ?> <span class="badge"><?php esc_html_e( 'Shop Manager', 'rar-woo-stock-order' ); ?></span></h2>
            <div class="seg" role="group" aria-label="<?php esc_attr_e( 'Report range', 'rar-woo-stock-order' ); ?>" id="rar-range">
                <button type="button" data-days="7" aria-pressed="false"><?php esc_html_e( '7 days', 'rar-woo-stock-order' ); ?></button>
                <button type="button" data-days="30" aria-pressed="true"><?php esc_html_e( '30 days', 'rar-woo-stock-order' ); ?></button>
                <button type="button" data-days="90" aria-pressed="false"><?php esc_html_e( '90 days', 'rar-woo-stock-order' ); ?></button>
            </div>
        </div>
        <div id="rar-growth"><div class="panel skel tall"></div></div>
    </section>
    <?php endif; ?>

    <section class="sec lower">
        <div class="panel chart" id="rar-sales7"><div class="skel-line"></div></div>
        <div class="panel" id="rar-attn"><div class="skel-line"></div></div>
    </section>
    <?php if ( RAR_WSO_Plugin::can_view_orders() ) : ?>
    <section class="sec"><div class="panel recent" id="rar-recent"><div class="skel-line"></div></div></section>
    <?php endif; ?>
    <div class="rar-install-tip" id="rar-install-tip">
        <div class="rit-text"><strong><?php esc_html_e( 'Install on phone:', 'rar-woo-stock-order' ); ?></strong> <span id="rar-install-how">Chrome menu ⋮ → <b>Install app</b> / <b>Add to Home screen</b>.</span></div>
        <button type="button" class="rar-install-btn" id="rar-install-btn" hidden><?php esc_html_e( 'Install app', 'rar-woo-stock-order' ); ?></button>
    </div>
    <div class="rar-app-meta">Secure staff workspace · v<?php echo esc_html( RAR_WSO_VERSION ); ?></div>
</main>

<div class="sheet-wrap" id="rar-sheet-wrap" hidden>
    <div class="backdrop" id="rar-backdrop"></div>
    <div class="sheet" id="rar-sheet" role="dialog" aria-modal="true" aria-labelledby="rar-sheet-title">
        <div class="sh-head">
            <button type="button" class="icon-btn" id="rar-sh-back" aria-label="<?php esc_attr_e( 'Back', 'rar-woo-stock-order' ); ?>" hidden></button>
            <span class="sh-ico" id="rar-sh-ico" aria-hidden="true"></span>
            <div class="sh-titles"><h3 id="rar-sheet-title"></h3><p id="rar-sheet-sub"></p></div>
            <button type="button" class="icon-btn" id="rar-sh-close" aria-label="<?php esc_attr_e( 'Close', 'rar-woo-stock-order' ); ?>"></button>
        </div>
        <div class="sh-tools" id="rar-sh-tools" hidden></div>
        <div class="sh-body" id="rar-sh-body"></div>
        <div class="sh-foot" id="rar-sh-foot" hidden></div>
    </div>
</div>
<div class="toasts" id="rar-toasts" aria-live="polite"></div>
<noscript><p class="rar-noscript"><?php esc_html_e( 'Please enable JavaScript to use the staff app.', 'rar-woo-stock-order' ); ?></p></noscript>
<script data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" data-noptimize="1"<?php echo $this->nonce_attr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>window.RARWSO=<?php echo wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
<script data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" data-noptimize="1"<?php echo $this->nonce_attr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> src="<?php echo esc_url( RAR_WSO_URL . 'assets/js/staff.js?ver=' . rawurlencode( RAR_WSO_VERSION ) ); ?>"></script>
</body>
</html>
<?php
        exit;
    }

    private function login_screen( $error = '' ) {
        $settings = RAR_WSO_Plugin::settings();
        $action   = RAR_WSO_Plugin::staff_url();
        $user     = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( '' !== $error && 429 !== http_response_code() ) {
            status_header( 401 );
        }
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
        <?php $this->head_tags( $settings['dashboard_title'] . ' — Login' ); ?>
</head>
<body class="rar-login-page">
<div class="rar-login-card">
    <?php $rar_logo = RAR_WSO_Plugin::logo_url( 'thumbnail' ); ?>
    <?php if ( $rar_logo ) : ?><img class="rar-login-logo" src="<?php echo esc_url( $rar_logo ); ?>" alt="" width="56" height="56"><?php endif; ?>
    <div class="rar-kicker"><?php echo esc_html( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( RAR_WSO_Plugin::business_name() ) : strtoupper( RAR_WSO_Plugin::business_name() ) ); ?></div>
    <h1><?php echo esc_html( $settings['dashboard_title'] ); ?></h1>
    <p>Staff login</p>
    <?php if ( '' !== $error ) : ?>
        <div class="rar-login-error" role="alert"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url( $action ); ?>" id="rar-loginform" autocomplete="on">
        <input type="hidden" name="rar_wso_login" value="1">
        <?php wp_nonce_field( 'rar_wso_login', 'rar_wso_login_nonce', false ); ?>
        <p class="rar-hp" aria-hidden="true"><label for="rar_wso_website">Website</label><input type="text" name="rar_wso_website" id="rar_wso_website" value="" tabindex="-1" autocomplete="off"></p>
        <p class="login-username">
            <label for="user_login"><?php esc_html_e( 'Username or Email Address', 'rar-woo-stock-order' ); ?></label>
            <input type="text" name="log" id="user_login" class="input" value="<?php echo esc_attr( $user ); ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required<?php echo '' === $user ? ' autofocus' : ''; ?>>
        </p>
        <p class="login-password">
            <label for="user_pass"><?php esc_html_e( 'Password', 'rar-woo-stock-order' ); ?></label>
            <span class="rar-pass"><input type="password" name="pwd" id="user_pass" class="input" autocomplete="current-password" required<?php echo '' !== $user ? ' autofocus' : ''; ?>><button type="button" class="rar-eye" id="rar-eye" aria-label="<?php esc_attr_e( 'Show password', 'rar-woo-stock-order' ); ?>" aria-pressed="false"><?php esc_html_e( 'Show', 'rar-woo-stock-order' ); ?></button></span>
        </p>
        <p class="login-remember"><label><input name="rememberme" type="checkbox" id="rememberme" value="forever" checked> <?php esc_html_e( 'Keep me signed in on this phone', 'rar-woo-stock-order' ); ?></label></p>
        <p class="login-submit"><input type="submit" name="wp-submit" id="wp-submit" class="button button-primary" value="<?php esc_attr_e( 'Log In', 'rar-woo-stock-order' ); ?>"></p>
    </form>
    <p class="rar-login-alt"><a href="<?php echo esc_url( wp_login_url( RAR_WSO_Plugin::staff_url() ) ); ?>"><?php esc_html_e( 'Having trouble? Use the WordPress login page', 'rar-woo-stock-order' ); ?></a></p>
</div>
<script data-no-optimize="1" data-no-defer="1" data-cfasync="false"<?php echo $this->nonce_attr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
(function () {
    var eye = document.getElementById('rar-eye'), pass = document.getElementById('user_pass'), form = document.getElementById('rar-loginform');
    if (eye && pass) eye.addEventListener('click', function () { var show = pass.type === 'password'; pass.type = show ? 'text' : 'password'; eye.setAttribute('aria-pressed', String(show)); eye.textContent = show ? <?php echo wp_json_encode( __( 'Hide', 'rar-woo-stock-order' ) ); ?> : <?php echo wp_json_encode( __( 'Show', 'rar-woo-stock-order' ) ); ?>; pass.focus(); });
    if (form) form.addEventListener('submit', function () { var b = document.getElementById('wp-submit'); if (b) { setTimeout(function () { b.disabled = true; b.value = <?php echo wp_json_encode( __( 'Signing in…', 'rar-woo-stock-order' ) ); ?>; }, 0); } });
})();
if ('serviceWorker' in navigator) { addEventListener('load', function () { navigator.serviceWorker.register(<?php echo wp_json_encode( self::sw_url(), JSON_UNESCAPED_SLASHES ); ?>, { scope: <?php echo wp_json_encode( trailingslashit( wp_parse_url( RAR_WSO_Plugin::staff_url(), PHP_URL_PATH ) ), JSON_UNESCAPED_SLASHES ); ?>, updateViaCache: 'none' }).catch(function () {}); }); }
</script>
</body>
</html>
<?php
        exit;
    }

    private function simple_page( $title, $message ) {
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
        <?php $this->head_tags( $title ); ?>
</head>
<body class="rar-login-page">
<div class="rar-login-card">
    <h1><?php echo esc_html( $title ); ?></h1>
    <p><?php echo esc_html( $message ); ?></p>
    <a class="rar-primary" href="<?php echo esc_url( self::logout_url() ); ?>">Log out</a>
</div>
</body>
</html>
<?php
        exit;
    }
}
