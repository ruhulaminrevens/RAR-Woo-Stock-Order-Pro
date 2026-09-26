<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only health & security checks for the Control Center.
 * Nothing here changes the site; every check says what to do and where.
 */
class RAR_WSO_Health {

    /**
     * @return array<int, array{id:string, group:string, status:string, label:string, detail:string, url:string, action:string}>
     *         status: ok | warn | fail | info
     */
    private static $cache = null;

    /**
     * @param bool $cached Allow a copy up to 10 minutes old (the Overview auto-refresh uses it,
     *                     so an open tab does not re-run every check each minute).
     */
    public static function checks( $cached = false ) {
        if ( null !== self::$cache ) {
            return self::$cache;
        }
        if ( $cached ) {
            $hit = get_transient( 'rar_wso_health' );
            if ( is_array( $hit ) ) {
                return $hit;
            }
        }
        self::$cache = self::run();
        set_transient( 'rar_wso_health', self::$cache, 10 * MINUTE_IN_SECONDS );
        return self::$cache;
    }

    private static function run() {
        global $wpdb;
        $s      = RAR_WSO_Plugin::settings();
        $reg    = RAR_WSO_Security::registration_status();
        $out    = array();
        $add    = static function ( $id, $group, $status, $label, $detail, $url = '', $action = '' ) use ( &$out ) {
            $out[] = compact( 'id', 'group', 'status', 'label', 'detail', 'url', 'action' );
        };
        $wc_inv = admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' );

        /* ---------------- Staff app ---------------- */
        $add( 'app_on', 'app', 'yes' === $s['enabled'] ? 'ok' : 'warn', __( 'Staff app', 'rar-woo-stock-order' ),
            'yes' === $s['enabled'] ? __( 'Switched on. Staff can sign in at the staff link.', 'rar-woo-stock-order' ) : __( 'Switched off — staff cannot sign in or save anything.', 'rar-woo-stock-order' ),
            admin_url( 'admin.php?page=rar-wso&tab=settings#rarx-sec-general' ) );

        $pretty = '' !== (string) get_option( 'permalink_structure' );
        $add( 'permalinks', 'app', $pretty ? 'ok' : 'fail', __( 'Pretty permalinks', 'rar-woo-stock-order' ),
            $pretty ? __( 'On — the /staff/ link works.', 'rar-woo-stock-order' ) : __( 'Plain permalinks are on, so the /staff/ link shows "not found". Choose "Post name" in Settings → Permalinks.', 'rar-woo-stock-order' ),
            admin_url( 'options-permalink.php' ) );

        $slug  = sanitize_title( $s['staff_slug'] ) ? sanitize_title( $s['staff_slug'] ) : 'staff';
        $rules = (array) get_option( 'rewrite_rules', array() );
        $rule  = isset( $rules[ '^' . preg_quote( $slug, '/' ) . '/?$' ] );
        if ( $pretty ) {
            $add( 'rewrite', 'app', $rule ? 'ok' : 'warn', __( 'Staff link route', 'rar-woo-stock-order' ),
                $rule ? __( 'Registered.', 'rar-woo-stock-order' ) : __( 'The /staff/ route is not in the saved rewrite rules yet. Use "Repair staff link" in Tools.', 'rar-woo-stock-order' ),
                admin_url( 'admin.php?page=rar-wso&tab=tools' ), $rule ? '' : 'flush_rewrite' );
        }

        $https = 0 === strpos( RAR_WSO_Plugin::staff_url(), 'https://' );
        $add( 'https', 'app', $https ? 'ok' : 'fail', __( 'HTTPS', 'rar-woo-stock-order' ),
            $https ? __( 'The staff app is served over HTTPS (needed for install on phones and safe passwords).', 'rar-woo-stock-order' ) : __( 'No HTTPS — passwords travel unencrypted and phones cannot install the app. Enable SSL in hosting and set the site address to https://.', 'rar-woo-stock-order' ),
            admin_url( 'options-general.php' ) );

        $stock_on = 'yes' === get_option( 'woocommerce_manage_stock' );
        $add( 'wc_stock', 'app', $stock_on ? 'ok' : 'fail', __( 'WooCommerce stock management', 'rar-woo-stock-order' ),
            $stock_on ? __( 'On.', 'rar-woo-stock-order' ) : __( 'Off for the whole shop — stock cannot be saved or reduced by orders. Turn on "Enable stock management".', 'rar-woo-stock-order' ),
            $wc_inv );

        $tables = RAR_WSO_Log::table_exists() && RAR_WSO_Log::table_exists( RAR_WSO_Audit::table() );
        $add( 'tables', 'app', $tables ? 'ok' : 'fail', __( 'History tables', 'rar-woo-stock-order' ),
            $tables ? __( 'Stock history and audit log tables are in place.', 'rar-woo-stock-order' ) : __( 'A history table is missing. Deactivate and activate the plugin once to create it.', 'rar-woo-stock-order' ) );

        $locks = RAR_WSO_Lock::supported();
        $add( 'locks', 'app', $locks ? 'ok' : 'warn', __( 'Database locks (no overselling)', 'rar-woo-stock-order' ),
            $locks ? __( 'MySQL named locks work — two phones can never sell the same last unit.', 'rar-woo-stock-order' ) : __( 'Named locks are not available on this database; a slower fallback lock is used.', 'rar-woo-stock-order' ) );

        $cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $late     = self::overdue_cron_events();
        if ( $late > 0 ) {
            $add( 'cron', 'app', 'warn', __( 'Scheduled tasks (WP-Cron)', 'rar-woo-stock-order' ),
                /* translators: %d number of events */
                sprintf( _n( '%d scheduled task is more than an hour late. Emails, stock-hold clean-up and the daily summary may be delayed. Add a real cron job on the hosting panel that opens wp-cron.php every 5–15 minutes.', '%d scheduled tasks are more than an hour late. Emails, stock-hold clean-up and the daily summary may be delayed. Add a real cron job on the hosting panel that opens wp-cron.php every 5–15 minutes.', $late, 'rar-woo-stock-order' ), $late ) );
        } else {
            $add( 'cron', 'app', 'ok', __( 'Scheduled tasks (WP-Cron)', 'rar-woo-stock-order' ),
                $cron_off ? __( 'Run by a server cron job and on time.', 'rar-woo-stock-order' ) : __( 'Running on time.', 'rar-woo-stock-order' ) );
        }

        $add( 'object_cache', 'app', 'info', __( 'Persistent object cache', 'rar-woo-stock-order' ),
            wp_using_ext_object_cache() ? __( 'In use — dashboards read cached figures from memory.', 'rar-woo-stock-order' ) : __( 'Not in use (optional). LiteSpeed / Redis object cache on Hostinger makes busy dashboards faster.', 'rar-woo-stock-order' ) );

        $add( 'hpos', 'app', 'info', __( 'Order storage', 'rar-woo-stock-order' ),
            RAR_WSO_Reports::hpos() ? __( 'High-Performance Order Storage (HPOS) — supported.', 'rar-woo-stock-order' ) : __( 'Legacy posts storage — supported.', 'rar-woo-stock-order' ),
            admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ) );

        /* ---------------- Security ---------------- */
        $risky = in_array( $reg['stored_role'], RAR_WSO_Security::protected_roles(), true );
        $add( 'default_role', 'security', $risky ? 'fail' : 'ok', __( 'Default role for new sign-ups', 'rar-woo-stock-order' ),
            $risky
                /* translators: %s role */
                ? sprintf( __( 'Stored as "%s". This plugin overrides it to Customer, but fix it in Settings → General → New User Default Role.', 'rar-woo-stock-order' ), $reg['stored_role'] )
                /* translators: %s role */
                : sprintf( __( '"%s" — public sign-up can never create staff, managers or admins.', 'rar-woo-stock-order' ), $reg['default_role'] ),
            admin_url( 'options-general.php' ) );

        $add( 'wp_register', 'security', $reg['wp_open'] ? 'warn' : 'ok', __( 'WordPress "Anyone can register"', 'rar-woo-stock-order' ),
            $reg['wp_open'] ? __( 'On — not needed for a WooCommerce shop and attracts spam sign-ups. Turn it off in Settings → General.', 'rar-woo-stock-order' ) : __( 'Off.', 'rar-woo-stock-order' ),
            admin_url( 'options-general.php' ) );

        $add( 'wc_register', 'security', 'info', __( 'Customer sign-up on My Account', 'rar-woo-stock-order' ),
            $reg['wc_account'] ? __( 'On (customers only). If bots keep registering, turn it off — customers can still create an account at checkout.', 'rar-woo-stock-order' ) : __( 'Off.', 'rar-woo-stock-order' ),
            admin_url( 'admin.php?page=wc-settings&tab=account' ) );

        $add( 'login_limit', 'security', 'ok', __( 'Staff login protection', 'rar-woo-stock-order' ),
            sprintf(
                /* translators: 1: attempts per username, 2: attempts per network, 3: minutes */
                __( '%1$d wrong passwords per username or %2$d per network lock the /staff/ form for %3$d minutes. CSRF token and bot trap are on.', 'rar-woo-stock-order' ),
                RAR_WSO_Security::user_limit(),
                RAR_WSO_Security::ip_limit(),
                (int) round( RAR_WSO_Security::window() / 60 )
            ),
            admin_url( 'admin.php?page=rar-wso&tab=settings#rarx-sec-security' ) );

        $today = RAR_WSO_Security::failures_today();
        if ( $today >= 20 ) {
            $add( 'login_attacks', 'security', 'warn', __( 'Wrong passwords today', 'rar-woo-stock-order' ),
                /* translators: %d count */
                sprintf( __( '%d wrong passwords on the staff login today — someone may be guessing. Check the audit log, and make sure staff passwords are long.', 'rar-woo-stock-order' ), $today ),
                admin_url( 'admin.php?page=rar-wso&tab=activity&view=audit&action_f=security' ) );
        }

        $edit_off = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
        $add( 'file_edit', 'security', $edit_off ? 'ok' : 'warn', __( 'Theme / plugin file editor', 'rar-woo-stock-order' ),
            $edit_off ? __( 'Disabled.', 'rar-woo-stock-order' ) : __( 'Enabled — a stolen admin login could change site code. Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php.', 'rar-woo-stock-order' ) );

        $shows = defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
        $add( 'debug_display', 'security', $shows ? 'warn' : 'ok', __( 'Error display', 'rar-woo-stock-order' ),
            $shows ? __( 'PHP errors are shown on pages (WP_DEBUG_DISPLAY). Turn it off on the live site; log errors instead.', 'rar-woo-stock-order' ) : __( 'Errors are not shown to visitors.', 'rar-woo-stock-order' ) );

        $admin_login = username_exists( 'admin' );
        $add( 'admin_user', 'security', $admin_login ? 'warn' : 'ok', __( '"admin" username', 'rar-woo-stock-order' ),
            $admin_login ? __( 'An account named "admin" exists — the first name bots try. Create a new administrator with another name and remove it.', 'rar-woo-stock-order' ) : __( 'Not used.', 'rar-woo-stock-order' ),
            admin_url( 'users.php' ) );

        $admins = count( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 50 ) ) );
        $add( 'admin_count', 'security', $admins > 3 ? 'warn' : 'ok', __( 'Administrator accounts', 'rar-woo-stock-order' ),
            /* translators: %d count */
            sprintf( _n( '%d administrator.', '%d administrators.', $admins, 'rar-woo-stock-order' ), $admins ) . ( $admins > 3 ? ' ' . __( 'Give people the lowest role that fits (Shop Manager or Staff).', 'rar-woo-stock-order' ) : '' ),
            admin_url( 'users.php?role=administrator' ) );

        $app_pw = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value NOT IN ('', 'a:0:{}')", '_application_passwords' ) );
        $add( 'app_passwords', 'security', $app_pw ? 'warn' : 'ok', __( 'Application passwords', 'rar-woo-stock-order' ),
            $app_pw
                /* translators: %d count */
                ? sprintf( _n( '%d account has application passwords (used by apps, AI / MCP tools and integrations). Revoke any you do not recognise in Users → Profile.', '%d accounts have application passwords (used by apps, AI / MCP tools and integrations). Revoke any you do not recognise in Users → Profile.', $app_pw, 'rar-woo-stock-order' ), $app_pw )
                : __( 'None.', 'rar-woo-stock-order' ),
            admin_url( 'users.php' ) );

        $xmlrpc = (bool) apply_filters( 'xmlrpc_enabled', true );
        $add( 'xmlrpc', 'security', $xmlrpc ? 'warn' : 'ok', __( 'XML-RPC', 'rar-woo-stock-order' ),
            $xmlrpc ? __( 'On — a common password-guessing target. Turn it off with a security plugin (or LiteSpeed / Hostinger tools) unless the Jetpack app needs it.', 'rar-woo-stock-order' ) : __( 'Off.', 'rar-woo-stock-order' ) );

        $no_mail = 0;
        foreach ( get_users( array( 'role' => 'rar_wso_staff', 'fields' => array( 'ID', 'user_email' ), 'number' => 500 ) ) as $u ) {
            if ( ! is_email( $u->user_email ) ) {
                $no_mail++;
            }
        }
        if ( $no_mail ) {
            $add( 'staff_email', 'security', 'warn', __( 'Staff without a valid email', 'rar-woo-stock-order' ),
                /* translators: %d count */
                sprintf( _n( '%d staff account has no valid email, so it cannot receive a set-password link.', '%d staff accounts have no valid email, so they cannot receive set-password links.', $no_mail, 'rar-woo-stock-order' ), $no_mail ),
                admin_url( 'admin.php?page=rar-wso&tab=staff' ) );
        }

        /* ---------------- Server ---------------- */
        $php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
        $add( 'php', 'server', $php_ok ? 'ok' : 'warn', __( 'PHP version', 'rar-woo-stock-order' ),
            $php_ok
                /* translators: %s version */
                ? sprintf( __( 'PHP %s.', 'rar-woo-stock-order' ), PHP_VERSION )
                /* translators: %s version */
                : sprintf( __( 'PHP %s no longer gets security fixes. Switch to PHP 8.2 or 8.3 in the hosting panel (test on staging first).', 'rar-woo-stock-order' ), PHP_VERSION ) );

        $add( 'versions', 'server', 'info', __( 'WordPress / WooCommerce', 'rar-woo-stock-order' ),
            sprintf( 'WordPress %s · WooCommerce %s · %s', get_bloginfo( 'version' ), defined( 'WC_VERSION' ) ? WC_VERSION : '?', $wpdb->db_server_info() ) );

        $mem = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
        $add( 'memory', 'server', ( $mem > 0 && $mem < 256 * MB_IN_BYTES ) ? 'warn' : 'ok', __( 'PHP memory limit', 'rar-woo-stock-order' ),
            ( $mem > 0 && $mem < 256 * MB_IN_BYTES )
                /* translators: %s memory */
                ? sprintf( __( '%s — WooCommerce recommends at least 256M.', 'rar-woo-stock-order' ), ini_get( 'memory_limit' ) )
                : (string) ini_get( 'memory_limit' ) );

        return (array) apply_filters( 'rar_wso_health_checks', $out );
    }

    /** Pass rate over the checks that can pass or fail (info rows excluded). */
    public static function score( $checks ) {
        $total = 0;
        $pass  = 0;
        foreach ( $checks as $c ) {
            if ( 'info' === $c['status'] ) {
                continue;
            }
            $total++;
            if ( 'ok' === $c['status'] ) {
                $pass++;
            } elseif ( 'warn' === $c['status'] ) {
                $pass += 0.5;
            }
        }
        return $total ? (int) round( $pass / $total * 100 ) : 100;
    }

    public static function problems( $checks ) {
        $out = array_values( array_filter( $checks, static function ( $c ) { return in_array( $c['status'], array( 'fail', 'warn' ), true ); } ) );
        usort( $out, static function ( $a, $b ) { return ( 'fail' === $a['status'] ? 0 : 1 ) <=> ( 'fail' === $b['status'] ? 0 : 1 ); } );
        return $out;
    }

    private static function overdue_cron_events() {
        $crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
        $late  = 0;
        $cut   = time() - HOUR_IN_SECONDS;
        foreach ( (array) $crons as $ts => $hooks ) {
            if ( ! is_numeric( $ts ) || (int) $ts >= $cut ) {
                continue;
            }
            foreach ( (array) $hooks as $events ) {
                $late += count( (array) $events );
            }
        }
        return $late;
    }
}
