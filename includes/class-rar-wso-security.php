<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Account and login safety for the staff app.
 *
 * - Staff accounts are created by an Administrator (or an allowed Shop Manager), never
 *   by public sign-up: the staff / manager / admin roles can't become the default
 *   registration role, and a self-registered account that somehow gets one is downgraded.
 * - Paused staff accounts can't sign in anywhere.
 * - The /staff/ login form has a CSRF token, a bot trap and a failed-login limit.
 * - Staff-only accounts land in the staff app instead of wp-admin / My Account.
 */
class RAR_WSO_Security {
    const IP_LIMIT   = 10;
    const USER_LIMIT = 6;
    const WINDOW     = 900; // 15 minutes.

    /** Set while this plugin itself creates a staff account. */
    public static $creating_staff = false;

    public static function hooks() {
        add_filter( 'option_default_role', array( __CLASS__, 'safe_default_role' ) );
        add_filter( 'pre_update_option_default_role', array( __CLASS__, 'guard_default_role' ), 10, 2 );
        add_action( 'user_register', array( __CLASS__, 'guard_new_user' ), 1 );
        add_filter( 'authenticate', array( __CLASS__, 'block_paused' ), 100 );
        add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
        add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'wc_login_redirect' ), 20, 2 );
        add_action( 'admin_init', array( __CLASS__, 'keep_staff_in_app' ), 1 );
    }

    /** Roles that must never be handed out by public registration. */
    public static function protected_roles() {
        return (array) apply_filters( 'rar_wso_protected_roles', array( 'administrator', 'editor', 'shop_manager', 'rar_wso_staff' ) );
    }

    private static function fallback_role() {
        return get_role( 'customer' ) ? 'customer' : 'subscriber';
    }

    public static function safe_default_role( $role ) {
        return in_array( (string) $role, self::protected_roles(), true ) ? self::fallback_role() : $role;
    }

    public static function guard_default_role( $new, $old ) {
        if ( in_array( (string) $new, self::protected_roles(), true ) ) {
            set_transient( 'rar_wso_role_blocked', (string) $new, 10 * MINUTE_IN_SECONDS );
            return in_array( (string) $old, self::protected_roles(), true ) ? self::fallback_role() : $old;
        }
        return $new;
    }

    /** Raw stored value, to warn if something wrote a staff/admin role straight to the database. */
    public static function stored_default_role() {
        global $wpdb;
        return (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'default_role' LIMIT 1" );
    }

    public static function guard_new_user( $user_id ) {
        if ( self::$creating_staff || ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'promote_users' ) ) {
            return;
        }
        $user = get_userdata( $user_id );
        if ( ! $user || ! array_intersect( (array) $user->roles, self::protected_roles() ) ) {
            return;
        }
        $user->set_role( self::fallback_role() );
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning(
                sprintf( 'Blocked a privileged role on self-registered user #%d (%s); set to %s.', $user_id, $user->user_login, self::fallback_role() ),
                array( 'source' => 'rar-wso' )
            );
        }
    }

    public static function is_paused( $user_id ) {
        return $user_id && '1' === (string) get_user_meta( $user_id, 'rar_wso_suspended', true );
    }

    public static function block_paused( $user ) {
        if ( $user instanceof WP_User && self::is_paused( $user->ID ) ) {
            return new WP_Error( 'rar_wso_paused', __( 'This staff account is paused. Please contact your Shop Manager.', 'rar-woo-stock-order' ) );
        }
        return $user;
    }

    /** Staff-only account: uses the app, has no WooCommerce admin or editing rights. */
    public static function is_staff_only( $user ) {
        return $user instanceof WP_User && $user->exists()
            && user_can( $user, 'rar_wso_access' )
            && ! user_can( $user, 'manage_woocommerce' )
            && ! user_can( $user, 'edit_posts' );
    }

    private static function app_on() {
        $s = RAR_WSO_Plugin::settings();
        return 'yes' === $s['enabled'];
    }

    public static function login_redirect( $redirect_to, $requested, $user ) {
        if ( self::app_on() && self::is_staff_only( $user ) && ( '' === (string) $requested || false !== strpos( (string) $requested, '/wp-admin' ) ) ) {
            return RAR_WSO_Plugin::staff_url();
        }
        return $redirect_to;
    }

    public static function wc_login_redirect( $redirect, $user ) {
        return ( self::app_on() && self::is_staff_only( $user ) ) ? RAR_WSO_Plugin::staff_url() : $redirect;
    }

    public static function keep_staff_in_app() {
        if ( wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() || ! self::app_on() ) {
            return;
        }
        $script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
        if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ), true ) ) {
            return;
        }
        if ( self::is_staff_only( wp_get_current_user() ) ) {
            wp_safe_redirect( RAR_WSO_Plugin::staff_url() );
            exit;
        }
    }

    /** Records "last active" at most every 5 minutes (one small write). */
    public static function touch_last_seen() {
        $uid = get_current_user_id();
        if ( ! $uid ) {
            return;
        }
        $last = (int) get_user_meta( $uid, 'rar_wso_last_seen', true );
        if ( time() - $last > 5 * MINUTE_IN_SECONDS ) {
            update_user_meta( $uid, 'rar_wso_last_seen', time() );
        }
    }

    /* --------------------------------------------------------------------
     * Failed-login limit for the /staff/ login form
     * ------------------------------------------------------------------ */

    public static function client_ip() {
        // REMOTE_ADDR can't be forged by the client. Behind a trusted proxy / CDN that hides the
        // visitor's address, return the right header through the rar_wso_client_ip filter.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        return (string) apply_filters( 'rar_wso_client_ip', $ip );
    }

    private static function keys( $login ) {
        // One counter per account, whether the username or the email address is typed.
        $login = strtolower( trim( (string) $login ) );
        $user  = '' !== $login ? ( is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login ) ) : false;
        return array(
            'ip'   => 'rar_wso_lf_ip_' . md5( self::client_ip() ),
            'user' => 'rar_wso_lf_u_' . ( $user ? 'id' . $user->ID : md5( $login ) ),
        );
    }

    /** Minutes until the next attempt is allowed, or 0. */
    public static function locked_minutes( $login ) {
        $k    = self::keys( $login );
        $ip   = get_transient( $k['ip'] );
        $user = '' !== trim( (string) $login ) ? get_transient( $k['user'] ) : false;
        $wait = 0;
        if ( is_array( $ip ) && (int) $ip['n'] >= self::IP_LIMIT ) {
            $wait = max( $wait, (int) $ip['until'] - time() );
        }
        if ( is_array( $user ) && (int) $user['n'] >= self::USER_LIMIT ) {
            $wait = max( $wait, (int) $user['until'] - time() );
        }
        return $wait > 0 ? (int) ceil( $wait / 60 ) : 0;
    }

    public static function record_failure( $login ) {
        foreach ( self::keys( $login ) as $type => $key ) {
            if ( 'user' === $type && '' === trim( (string) $login ) ) {
                continue;
            }
            $row = get_transient( $key );
            $row = is_array( $row ) ? $row : array( 'n' => 0, 'until' => 0 );
            $row['n']++;
            $row['until'] = time() + self::WINDOW;
            set_transient( $key, $row, self::WINDOW );
        }
        $total = (int) get_option( 'rar_wso_login_failures', 0 );
        update_option( 'rar_wso_login_failures', $total + 1, false );
    }

    public static function clear_failures( $login ) {
        foreach ( self::keys( $login ) as $key ) {
            delete_transient( $key );
        }
    }

    /* --------------------------------------------------------------------
     * Response headers for the app screens
     * ------------------------------------------------------------------ */

    private static function origin( $url ) {
        $p = wp_parse_url( $url );
        if ( empty( $p['host'] ) ) {
            return '';
        }
        return ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
    }

    /** Content-Security-Policy for the app's own HTML (the app prints no third-party code). */
    public static function csp( $nonce ) {
        $origins = array_unique( array_filter( array( self::origin( home_url( '/' ) ), self::origin( admin_url( 'admin-ajax.php' ) ), self::origin( RAR_WSO_URL ) ) ) );
        $self    = trim( "'self' " . implode( ' ', $origins ) );
        $policy  = array(
            "default-src 'self'",
            "script-src {$self} 'nonce-{$nonce}'",
            "style-src {$self} 'unsafe-inline'",
            "img-src {$self} data: blob: https:",
            "font-src {$self} data:",
            "connect-src {$self}",
            "manifest-src {$self}",
            "worker-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action {$self}",
            "frame-ancestors 'self'",
        );
        return (string) apply_filters( 'rar_wso_csp', implode( '; ', $policy ), $nonce );
    }

    public static function send_headers( $nonce = '' ) {
        if ( headers_sent() ) {
            return;
        }
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: same-origin' );
        header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()' );
        header( 'Cross-Origin-Opener-Policy: same-origin' );
        $csp = $nonce ? self::csp( $nonce ) : "frame-ancestors 'self'";
        if ( '' !== $csp ) {
            header( 'Content-Security-Policy: ' . $csp );
        }
    }

    /* --------------------------------------------------------------------
     * Registration overview for the settings screen
     * ------------------------------------------------------------------ */

    public static function registration_status() {
        return array(
            'wp_open'      => (bool) get_option( 'users_can_register' ),
            'default_role' => (string) get_option( 'default_role' ),
            'stored_role'  => self::stored_default_role(),
            'wc_account'   => 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ),
            'wc_checkout'  => 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ),
            'failures'     => (int) get_option( 'rar_wso_login_failures', 0 ),
        );
    }
}
