<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Screens and exports are only needed on wp-admin pages, admin-post.php and the Overview refresh —
// never for the staff app's own admin-ajax.php calls.
if ( is_admin() && ( ! wp_doing_ajax() || ( isset( $_REQUEST['action'] ) && 'rar_wso_admin_live' === $_REQUEST['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    require_once RAR_WSO_PATH . 'includes/class-rar-wso-admin-views.php';
    require_once RAR_WSO_PATH . 'includes/class-rar-wso-export.php';
}

/**
 * WooCommerce → Stock & Order: the Control Center.
 *
 * Controller only — menu, assets, settings, form handlers and the live-refresh endpoint.
 * Screens are rendered by RAR_WSO_Admin_Views. Every form posts to admin-post.php with its
 * own nonce and capability check, then redirects back (no state changes on GET).
 */
class RAR_WSO_Admin {
    const PAGE = 'rar-wso';

    private $hook = '';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ), 80 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_post_rar_wso_add_staff', array( $this, 'add_staff' ) );
        add_action( 'admin_post_rar_wso_staff_action', array( $this, 'staff_action' ) );
        add_action( 'admin_post_rar_wso_staff_update', array( $this, 'staff_update' ) );
        add_action( 'admin_post_rar_wso_tool', array( $this, 'tool' ) );
        add_action( 'admin_post_rar_wso_export', array( $this, 'export' ) );
        add_action( 'wp_ajax_rar_wso_admin_live', array( $this, 'live' ) );
        add_action( 'update_option_rar_wso_settings', array( $this, 'audit_settings' ), 20, 2 );
        add_filter( 'admin_body_class', array( $this, 'body_class' ) );
        // Flush once after activation/upgrade/slug change, after the /staff/ rule is registered on init.
        add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
    }

    public static function url( $tab = 'overview', $args = array() ) {
        $base = array( 'page' => self::PAGE );
        if ( 'overview' !== $tab ) {
            $base['tab'] = $tab;
        }
        return add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) );
    }

    public static function tabs() {
        return array(
            'overview' => array( __( 'Overview', 'rar-woo-stock-order' ), 'dashboard' ),
            'staff'    => array( __( 'Staff', 'rar-woo-stock-order' ), 'users' ),
            'activity' => array( __( 'Activity', 'rar-woo-stock-order' ), 'activity' ),
            'settings' => array( __( 'Settings', 'rar-woo-stock-order' ), 'sliders' ),
            'security' => array( __( 'Security & Health', 'rar-woo-stock-order' ), 'shield' ),
            'tools'    => array( __( 'Tools', 'rar-woo-stock-order' ), 'wrench' ),
            'help'     => array( __( 'Help', 'rar-woo-stock-order' ), 'help' ),
        );
    }

    public static function current_tab() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
    }

    public function menu() {
        $this->hook = (string) add_submenu_page(
            'woocommerce',
            __( 'RAR Woo Stock & Order', 'rar-woo-stock-order' ),
            __( 'Stock & Order', 'rar-woo-stock-order' ),
            'manage_woocommerce',
            self::PAGE,
            array( $this, 'render' )
        );
        // A small counter on the menu item when something needs attention (cached figures only).
        $n = RAR_WSO_Plugin::attention_count();
        if ( $n > 0 ) {
            global $submenu;
            foreach ( (array) ( $submenu['woocommerce'] ?? array() ) as $i => $item ) {
                if ( self::PAGE === ( $item[2] ?? '' ) ) {
                    $submenu['woocommerce'][ $i ][0] .= ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count">' . esc_html( $n > 99 ? '99+' : (string) $n ) . '</span></span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                }
            }
        }
    }

    public function body_class( $classes ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        return ( $screen && $this->hook && $screen->id === $this->hook ) ? $classes . ' rarx-screen' : $classes;
    }

    public function assets( $hook ) {
        if ( $hook !== $this->hook ) {
            return;
        }
        $v = RAR_WSO_VERSION;
        wp_enqueue_style( 'rar-wso-admin', RAR_WSO_URL . 'assets/css/admin.css', array(), $v );
        wp_enqueue_script( 'rar-wso-admin', RAR_WSO_URL . 'assets/js/admin.js', array(), $v, true );
        if ( 'settings' === self::current_tab() && current_user_can( 'upload_files' ) ) {
            wp_enqueue_media();
        }
        wp_localize_script(
            'rar-wso-admin',
            'RARWSOAdmin',
            array(
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'rar_wso_admin' ),
                'staffUrl' => RAR_WSO_Plugin::staff_url(),
                'business' => RAR_WSO_Plugin::business_name(),
                'title'    => RAR_WSO_Plugin::settings()['dashboard_title'],
                'brand'    => RAR_WSO_Plugin::brand_color(),
                'tab'      => self::current_tab(),
                'i18n'     => array(
                    'copied'    => __( 'Copied', 'rar-woo-stock-order' ),
                    'copyFail'  => __( 'Copy failed — select the text and press Ctrl+C.', 'rar-woo-stock-order' ),
                    'unsaved'   => __( 'You have unsaved changes', 'rar-woo-stock-order' ),
                    'updated'   => __( 'Updated', 'rar-woo-stock-order' ),
                    'offline'   => __( 'Could not refresh — check the connection.', 'rar-woo-stock-order' ),
                    'pickLogo'  => __( 'Choose a logo', 'rar-woo-stock-order' ),
                    'useLogo'   => __( 'Use this logo', 'rar-woo-stock-order' ),
                    'noMatch'   => __( 'No staff match this search.', 'rar-woo-stock-order' ),
                    'tooLong'   => __( 'This link is too long for a QR code.', 'rar-woo-stock-order' ),
                ),
            )
        );
    }

    /* --------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    public function register_settings() {
        register_setting( 'rar_wso_group', 'rar_wso_settings', array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
    }

    private static function flag( $input, $key, $old, $from_form ) {
        if ( ! array_key_exists( $key, $input ) ) {
            // A form leaves unticked boxes out; an import / programmatic save keeps the old value.
            return $from_form ? 'no' : ( 'yes' === ( $old[ $key ] ?? 'no' ) ? 'yes' : 'no' );
        }
        $v = $input[ $key ];
        if ( is_bool( $v ) ) {
            return $v ? 'yes' : 'no';
        }
        return in_array( strtolower( trim( (string) $v ) ), array( '1', 'yes', 'on', 'true' ), true ) ? 'yes' : 'no';
    }

    private static function lines( $raw, $max_lines, $max_len ) {
        $out = array();
        foreach ( (array) preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
            $line = trim( sanitize_text_field( $line ) );
            if ( '' === $line ) {
                continue;
            }
            $line = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, $max_len ) : substr( $line, 0, $max_len );
            if ( ! in_array( $line, $out, true ) ) {
                $out[] = $line;
            }
        }
        return implode( "\n", array_slice( $out, 0, $max_lines ) );
    }

    /** Non-negative amount; '' stays '' (means "not set"). */
    private static function money_in( $raw ) {
        $raw = trim( (string) ( is_scalar( $raw ) ? $raw : '' ) );
        if ( '' === $raw || ! is_numeric( $raw ) ) {
            return '' === $raw ? '' : '0';
        }
        return wc_format_decimal( (string) max( 0, min( 9999999, (float) $raw ) ) );
    }

    private static function int_in( $input, $key, $old, $min, $max ) {
        if ( ! isset( $input[ $key ] ) || '' === trim( (string) $input[ $key ] ) ) {
            return (string) $old[ $key ];
        }
        return (string) min( $max, max( $min, absint( $input[ $key ] ) ) );
    }

    public function sanitize( $input ) {
        // options.php has already unslashed the posted values.
        $input = is_array( $input ) ? $input : array();
        $old   = RAR_WSO_Plugin::settings();
        $form  = ! empty( $input['_form'] );
        $get   = static function ( $key ) use ( $input, $old ) {
            return array_key_exists( $key, $input ) ? $input[ $key ] : $old[ $key ];
        };
        $out = RAR_WSO_Plugin::defaults();

        // General.
        foreach ( array( 'enabled', 'allow_price_override', 'staff_view_orders', 'digest_enabled', 'admin_bar_link', 'dashboard_widget', 'order_column' ) as $k ) {
            $out[ $k ] = self::flag( $input, $k, $old, $form );
        }
        $out['staff_slug']           = sanitize_title( (string) $get( 'staff_slug' ) );
        $out['dashboard_title']      = sanitize_text_field( (string) $get( 'dashboard_title' ) );
        $out['default_order_status'] = RAR_WSO_Ajax::order_status_setting( (string) $get( 'default_order_status' ) );
        $out['staff_max_discount']   = (string) min( 100, max( 0, (float) $get( 'staff_max_discount' ) ) );
        $out['default_shipping']     = self::money_in( $get( 'default_shipping' ) );
        $out['shipping_dhaka']       = self::money_in( $get( 'shipping_dhaka' ) );
        $out['shipping_outside']     = self::money_in( $get( 'shipping_outside' ) );
        $out['free_shipping_over']   = (string) ( self::money_in( $get( 'free_shipping_over' ) ) ?: '0' );
        $out['low_stock_threshold']  = (string) max( 1, absint( $get( 'low_stock_threshold' ) ) );
        $out['business_name']        = sanitize_text_field( (string) $get( 'business_name' ) );
        $out['slip_footer']          = sanitize_text_field( (string) $get( 'slip_footer' ) );
        $out['slip_phone']           = substr( sanitize_text_field( (string) $get( 'slip_phone' ) ), 0, 40 );
        $out['slip_address']         = function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( (string) $get( 'slip_address' ) ), 0, 140 ) : substr( sanitize_text_field( (string) $get( 'slip_address' ) ), 0, 140 );
        // Only an Administrator may let Shop Managers add staff accounts.
        $out['managers_add_staff']   = current_user_can( 'promote_users' ) ? self::flag( $input, 'managers_add_staff', $old, $form ) : $old['managers_add_staff'];

        // Branding.
        $color              = sanitize_hex_color( (string) $get( 'brand_color' ) );
        $out['brand_color'] = ( $color && 7 === strlen( $color ) ) ? strtolower( $color ) : $old['brand_color'];
        $logo               = absint( $get( 'logo_id' ) );
        $out['logo_id']     = ( $logo && wp_attachment_is_image( $logo ) ) ? (string) $logo : '0';

        // Payments.
        $pm = $get( 'payment_methods' );
        $pm = is_array( $pm ) ? $pm : explode( ',', (string) $pm );
        if ( $form && ! array_key_exists( 'payment_methods', $input ) ) {
            $pm = array();
        }
        $out['payment_methods'] = implode( ',', array_values( array_intersect( RAR_WSO_Ajax::builtin_payment_keys(), array_map( 'sanitize_key', $pm ) ) ) );
        $out['payment_extra']   = self::lines( $get( 'payment_extra' ), 8, 40 );
        $out['payment_default'] = sanitize_key( (string) $get( 'payment_default' ) );

        // Stock.
        $reasons              = self::lines( $get( 'stock_reasons' ), 20, 60 );
        $out['stock_reasons'] = '' !== $reasons ? $reasons : RAR_WSO_Plugin::defaults()['stock_reasons'];

        // Access & security.
        $out['login_user_limit'] = self::int_in( $input, 'login_user_limit', $old, 3, 50 );
        $out['login_ip_limit']   = self::int_in( $input, 'login_ip_limit', $old, 5, 200 );
        $out['lockout_minutes']  = self::int_in( $input, 'lockout_minutes', $old, 5, 1440 );
        $out['session_days']     = self::int_in( $input, 'session_days', $old, 1, 90 );

        // Reports & integrations.
        $emails = array();
        foreach ( preg_split( '/[\s,;]+/', (string) $get( 'digest_email' ) ) as $e ) {
            $e = sanitize_email( $e );
            if ( is_email( $e ) ) {
                $emails[] = $e;
            }
        }
        $out['digest_email']       = implode( ', ', array_slice( array_unique( $emails ), 0, 10 ) );
        $out['digest_hour']        = self::int_in( $input, 'digest_hour', $old, 0, 23 );
        $keep                      = absint( $get( 'log_retention_days' ) );
        $out['log_retention_days'] = (string) ( $keep ? min( 3650, max( 30, $keep ) ) : 0 );

        if ( '' === $out['staff_slug'] ) {
            $out['staff_slug'] = 'staff';
        }
        $taken = $out['staff_slug'] !== $old['staff_slug'] && get_page_by_path( $out['staff_slug'], OBJECT, array( 'page', 'post', 'product' ) );
        if ( $taken || in_array( $out['staff_slug'], array( 'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'wp-json', 'wc-api', 'wc-auth', 'xmlrpc', 'shop', 'cart', 'checkout', 'my-account', 'product', 'product-category', 'feed', 'category', 'tag', 'page', 'search', 'author' ), true ) ) {
            add_settings_error( 'rar_wso_settings', 'slug', __( 'That staff link is used by WordPress or WooCommerce; the previous one was kept.', 'rar-woo-stock-order' ) );
            $out['staff_slug'] = $old['staff_slug'];
        }
        if ( $old['staff_slug'] !== $out['staff_slug'] ) {
            update_option( 'rar_wso_flush_rewrite', 1, true );
        }
        return $out;
    }

    /** Writes "Settings saved: changed keys" to the audit log for changes made in wp-admin. */
    public function audit_settings( $old, $new ) {
        if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! is_admin() || ! is_user_logged_in() || ! is_array( $old ) || ! is_array( $new ) ) {
            return;
        }
        $changed = array();
        foreach ( $new as $k => $v ) {
            if ( (string) ( $old[ $k ] ?? '' ) !== (string) $v ) {
                $changed[] = $k;
            }
        }
        if ( $changed && ! did_action( 'rar_wso_settings_import' ) ) {
            RAR_WSO_Audit::add( 'settings_saved', 'Changed: ' . implode( ', ', $changed ) );
        }
    }

    public function maybe_flush_rewrite() {
        if ( get_option( 'rar_wso_flush_rewrite' ) ) {
            // Keep the option (value 0, autoloaded) so later page loads don't query for a missing option.
            update_option( 'rar_wso_flush_rewrite', 0, true );
            flush_rewrite_rules( false );
        }
    }

    /* --------------------------------------------------------------------
     * Helpers for the form handlers
     * ------------------------------------------------------------------ */

    private function back( $tab, $anchor = '', $args = array() ) {
        wp_safe_redirect( self::url( $tab, $args ) . ( $anchor ? '#' . $anchor : '' ) );
        exit;
    }

    public static function flash( $type, $text, $link = '' ) {
        set_transient( 'rar_wso_flash_' . get_current_user_id(), array( 'type' => $type, 'text' => $text, 'link' => $link ), 10 * MINUTE_IN_SECONDS );
    }

    private function deny( $message ) {
        wp_die( esc_html( $message ), esc_html__( 'Not allowed', 'rar-woo-stock-order' ), array( 'response' => 403, 'back_link' => true ) );
    }

    /** Only plain staff accounts (never Shop Managers / Administrators, never yourself). */
    private static function manageable_staff( $user_id ) {
        $user = get_userdata( $user_id );
        return ( $user && self::is_plain_staff( $user ) && get_current_user_id() !== (int) $user_id ) ? $user : null;
    }

    /**
     * Role is exactly Staff AND the account has no extra admin powers (a role editor plugin
     * could add capabilities to one user). Such accounts are managed under Users instead.
     */
    public static function is_plain_staff( $user ) {
        return $user instanceof WP_User
            && array_values( (array) $user->roles ) === array( 'rar_wso_staff' )
            && RAR_WSO_Security::is_staff_only( $user )
            && ! user_can( $user, 'list_users' )
            && ! user_can( $user, 'edit_users' )
            && ! user_can( $user, 'promote_users' );
    }

    /** One-time "set your password" link (valid for 24 hours), also emailed to the staff member. */
    private function password_link( WP_User $user, $send_mail ) {
        $key = get_password_reset_key( $user );
        if ( is_wp_error( $key ) ) {
            return '';
        }
        $link = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
        if ( $send_mail && $user->user_email ) {
            $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
            wp_mail(
                $user->user_email,
                /* translators: %s site name */
                sprintf( __( '[%s] Your staff app account', 'rar-woo-stock-order' ), $site ),
                sprintf(
                    /* translators: 1: name, 2: username, 3: link, 4: staff app URL */
                    __( "Hello %1\$s,\n\nA staff account was created for you.\nUsername: %2\$s\n\nSet your password (link works for 24 hours):\n%3\$s\n\nThen open the staff app on your phone:\n%4\$s\n", 'rar-woo-stock-order' ),
                    $user->display_name,
                    $user->user_login,
                    $link,
                    RAR_WSO_Plugin::staff_url()
                )
            );
        }
        return $link;
    }

    /* --------------------------------------------------------------------
     * Staff accounts (created here by an Administrator — no public registration)
     * ------------------------------------------------------------------ */

    public function add_staff() {
        if ( ! RAR_WSO_Plugin::can_manage_staff() ) {
            $this->deny( __( 'You do not have permission to add staff accounts.', 'rar-woo-stock-order' ) );
        }
        check_admin_referer( 'rar_wso_add_staff' );

        $login  = sanitize_user( wp_unslash( $_POST['user_login'] ?? '' ), true );
        $email  = sanitize_email( wp_unslash( $_POST['user_email'] ?? '' ) );
        $name   = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
        $branch = sanitize_text_field( wp_unslash( $_POST['branch'] ?? '' ) );
        $disc   = trim( sanitize_text_field( wp_unslash( $_POST['max_discount'] ?? '' ) ) );
        $mail   = ! empty( $_POST['send_email'] ) || ! isset( $_POST['send_email_shown'] );

        if ( '' === $login || strlen( $login ) < 3 ) {
            self::flash( 'error', __( 'Enter a username of at least 3 letters or numbers.', 'rar-woo-stock-order' ) );
            $this->back( 'staff', 'rarx-add' );
        }
        if ( ! is_email( $email ) ) {
            self::flash( 'error', __( 'Enter a valid email address for the staff member.', 'rar-woo-stock-order' ) );
            $this->back( 'staff', 'rarx-add' );
        }
        if ( username_exists( $login ) || email_exists( $email ) ) {
            self::flash( 'error', __( 'That username or email already has an account. Use a different one, or change the existing user\'s role under Users.', 'rar-woo-stock-order' ) );
            $this->back( 'staff', 'rarx-add' );
        }

        RAR_WSO_Security::$creating_staff = true;
        $user_id = wp_insert_user(
            array(
                'user_login'   => $login,
                'user_email'   => $email,
                'display_name' => '' !== $name ? $name : $login,
                'first_name'   => $name,
                'user_pass'    => wp_generate_password( 32, true, true ),
                'role'         => 'rar_wso_staff',
            )
        );
        RAR_WSO_Security::$creating_staff = false;

        if ( is_wp_error( $user_id ) ) {
            self::flash( 'error', $user_id->get_error_message() );
            $this->back( 'staff', 'rarx-add' );
        }

        update_user_meta( $user_id, 'rar_wso_added_by', get_current_user_id() );
        if ( '' !== $branch ) {
            update_user_meta( $user_id, 'rar_wso_branch', function_exists( 'mb_substr' ) ? mb_substr( $branch, 0, 60 ) : substr( $branch, 0, 60 ) );
        }
        if ( '' !== $disc && is_numeric( $disc ) ) {
            update_user_meta( $user_id, 'rar_wso_max_discount', (string) min( self::personal_limit_cap(), max( 0, (float) $disc ) ) );
        }
        $link = $this->password_link( get_userdata( $user_id ), $mail );
        RAR_WSO_Audit::add( 'staff_created', sprintf( '%s (%s)', '' !== $name ? $name : $login, $login ), $user_id );
        self::flash(
            'success',
            $mail
                /* translators: %s username */
                ? sprintf( __( 'Staff account "%s" created. A set-password email was sent. You can also send this one-time link on WhatsApp (valid 24 hours, share only with this person):', 'rar-woo-stock-order' ), $login )
                /* translators: %s username */
                : sprintf( __( 'Staff account "%s" created. Send this one-time set-password link to the person (valid 24 hours, share only with them):', 'rar-woo-stock-order' ), $login ),
            $link
        );
        $this->back( 'staff', 'rarx-staff-' . $user_id );
    }

    public function staff_action() {
        if ( ! RAR_WSO_Plugin::can_manage_staff() ) {
            $this->deny( __( 'You do not have permission to manage staff accounts.', 'rar-woo-stock-order' ) );
        }
        $user_id = absint( $_POST['user_id'] ?? 0 );
        $do      = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
        check_admin_referer( 'rar_wso_staff_' . $user_id );

        $user = self::manageable_staff( $user_id );
        if ( ! $user ) {
            self::flash( 'error', __( 'Only Woo Stock & Order Staff accounts can be managed here.', 'rar-woo-stock-order' ) );
            $this->back( 'staff' );
        }

        switch ( $do ) {
            case 'pause':
                update_user_meta( $user_id, 'rar_wso_suspended', '1' );
                WP_Session_Tokens::get_instance( $user_id )->destroy_all();
                RAR_WSO_Audit::add( 'staff_paused', $user->display_name, $user_id );
                /* translators: %s name */
                self::flash( 'success', sprintf( __( '%s is paused and signed out on every device.', 'rar-woo-stock-order' ), $user->display_name ) );
                break;
            case 'resume':
                delete_user_meta( $user_id, 'rar_wso_suspended' );
                RAR_WSO_Audit::add( 'staff_resumed', $user->display_name, $user_id );
                /* translators: %s name */
                self::flash( 'success', sprintf( __( '%s can sign in again.', 'rar-woo-stock-order' ), $user->display_name ) );
                break;
            case 'signout':
                WP_Session_Tokens::get_instance( $user_id )->destroy_all();
                RAR_WSO_Audit::add( 'staff_signout', $user->display_name, $user_id );
                /* translators: %s name */
                self::flash( 'success', sprintf( __( '%s was signed out on every phone and computer.', 'rar-woo-stock-order' ), $user->display_name ) );
                break;
            case 'link':
                $link = $this->password_link( $user, true );
                RAR_WSO_Audit::add( 'staff_link', $user->display_name, $user_id );
                /* translators: %s name */
                self::flash( 'success', sprintf( __( 'New set-password link for %s (emailed too; valid 24 hours, share only with this person):', 'rar-woo-stock-order' ), $user->display_name ), $link );
                break;
        }
        $this->back( 'staff', 'rarx-staff-' . $user_id );
    }

    /** Administrators may set any personal limit; a Shop Manager cannot go above the shop-wide one. */
    private static function personal_limit_cap() {
        if ( current_user_can( 'manage_options' ) ) {
            return 100.0;
        }
        $s = RAR_WSO_Plugin::settings();
        return (float) min( 100, max( 0, (float) $s['staff_max_discount'] ) );
    }

    /** Capabilities a single staff member can be blocked from (the Staff role allows all of them). */
    public static function staff_caps() {
        return array(
            'rar_wso_manage_stock'  => __( 'Update stock', 'rar-woo-stock-order' ),
            'rar_wso_create_orders' => __( 'Create orders', 'rar-woo-stock-order' ),
            'rar_wso_adjust_price'  => __( 'Change item rates while billing', 'rar-woo-stock-order' ),
        );
    }

    public function staff_update() {
        if ( ! RAR_WSO_Plugin::can_manage_staff() ) {
            $this->deny( __( 'You do not have permission to manage staff accounts.', 'rar-woo-stock-order' ) );
        }
        $user_id = absint( $_POST['user_id'] ?? 0 );
        check_admin_referer( 'rar_wso_staff_update_' . $user_id );
        $user = self::manageable_staff( $user_id );
        if ( ! $user ) {
            self::flash( 'error', __( 'Only Woo Stock & Order Staff accounts can be managed here.', 'rar-woo-stock-order' ) );
            $this->back( 'staff' );
        }

        $allowed = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['caps'] ) ) : array();
        $summary = array();
        foreach ( array_keys( self::staff_caps() ) as $cap ) {
            if ( in_array( $cap, $allowed, true ) ) {
                $user->remove_cap( $cap ); // back to the Staff role (allowed)
            } else {
                $user->add_cap( $cap, false ); // blocked for this person only
                $summary[] = 'no ' . str_replace( 'rar_wso_', '', $cap );
            }
        }

        $view = sanitize_key( wp_unslash( $_POST['view_orders'] ?? '' ) );
        if ( in_array( $view, array( 'yes', 'no' ), true ) ) {
            update_user_meta( $user_id, 'rar_wso_view_orders', $view );
            $summary[] = 'order lists ' . $view;
        } else {
            delete_user_meta( $user_id, 'rar_wso_view_orders' );
        }

        $disc = trim( sanitize_text_field( wp_unslash( $_POST['max_discount'] ?? '' ) ) );
        if ( '' !== $disc && is_numeric( $disc ) ) {
            $disc = (string) min( self::personal_limit_cap(), max( 0, (float) $disc ) );
            update_user_meta( $user_id, 'rar_wso_max_discount', $disc );
            $summary[] = 'discount ' . $disc . '%';
        } else {
            delete_user_meta( $user_id, 'rar_wso_max_discount' );
        }

        $branch = sanitize_text_field( wp_unslash( $_POST['branch'] ?? '' ) );
        $branch = function_exists( 'mb_substr' ) ? mb_substr( $branch, 0, 60 ) : substr( $branch, 0, 60 );
        if ( '' !== $branch ) {
            update_user_meta( $user_id, 'rar_wso_branch', $branch );
            $summary[] = 'branch ' . $branch;
        } else {
            delete_user_meta( $user_id, 'rar_wso_branch' );
        }

        $name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
        if ( '' !== $name && $name !== $user->display_name ) {
            wp_update_user( array( 'ID' => $user_id, 'display_name' => $name ) );
            $summary[] = 'name';
        }

        RAR_WSO_Audit::add( 'staff_updated', $user->display_name . ( $summary ? ': ' . implode( ', ', $summary ) : ': all defaults' ), $user_id );
        /* translators: %s name */
        self::flash( 'success', sprintf( __( 'Saved %s\'s permissions. They apply the next time the app loads.', 'rar-woo-stock-order' ), $user->display_name ) );
        $this->back( 'staff', 'rarx-staff-' . $user_id );
    }

    /* --------------------------------------------------------------------
     * Tools & emergency actions
     * ------------------------------------------------------------------ */

    private static function pure_staff_ids() {
        $ids = array();
        foreach ( get_users( array( 'role' => 'rar_wso_staff', 'number' => 1000 ) ) as $u ) {
            if ( self::is_plain_staff( $u ) && get_current_user_id() !== (int) $u->ID ) {
                $ids[] = (int) $u->ID;
            }
        }
        return $ids;
    }

    public static function clear_caches() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_rar\\_wso\\_c\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rar\\_wso\\_c\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        foreach ( array( 'stock', 'overview', 'admin', 'period_today', 'period_7d', 'period_month', 'report_7', 'report_30', 'report_90' ) as $name ) {
            delete_transient( 'rar_wso_c_' . $name );
        }
        update_option( 'rar_wso_report_ver', (string) microtime( true ), false );
        update_option( 'rar_wso_stock_ver', (string) microtime( true ), false );
    }

    public function tool() {
        $do = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
        check_admin_referer( 'rar_wso_tool_' . $do );
        $need = array(
            'clear_cache'     => 'manage_woocommerce',
            'flush_rewrite'   => 'manage_woocommerce',
            'test_digest'     => 'manage_woocommerce',
            'clear_lockouts'  => 'staff',
            'signout_all'     => 'staff',
            'pause_all'       => 'staff',
            'app_off'         => 'manage_options',
            'app_on'          => 'manage_options',
            'prune'           => 'manage_options',
            'reset_settings'  => 'manage_options',
            'import_settings' => 'manage_options',
        );
        if ( ! isset( $need[ $do ] ) ) {
            $this->deny( __( 'Unknown action.', 'rar-woo-stock-order' ) );
        }
        $ok = 'staff' === $need[ $do ] ? RAR_WSO_Plugin::can_manage_staff() : current_user_can( $need[ $do ] );
        if ( ! $ok ) {
            $this->deny( __( 'You do not have permission to do this.', 'rar-woo-stock-order' ) );
        }
        $tab = sanitize_key( wp_unslash( $_POST['back'] ?? 'tools' ) );
        $tab = isset( self::tabs()[ $tab ] ) ? $tab : 'tools';

        switch ( $do ) {
            case 'clear_cache':
                self::clear_caches();
                RAR_WSO_Audit::add( 'tool', 'Dashboard caches cleared' );
                self::flash( 'success', __( 'Dashboard figures will be recalculated on the next load.', 'rar-woo-stock-order' ) );
                break;
            case 'flush_rewrite':
                flush_rewrite_rules( false );
                RAR_WSO_Audit::add( 'tool', 'Staff link repaired (rewrite rules flushed)' );
                /* translators: %s URL */
                self::flash( 'success', sprintf( __( 'Staff link repaired. Open %s to check it.', 'rar-woo-stock-order' ), RAR_WSO_Plugin::staff_url() ) );
                break;
            case 'test_digest':
                $sent = RAR_WSO_Digest::send( true );
                self::flash(
                    $sent ? 'success' : 'error',
                    $sent
                        /* translators: %s emails */
                        ? sprintf( __( 'Test summary sent to %s. Check the inbox (and spam folder).', 'rar-woo-stock-order' ), implode( ', ', RAR_WSO_Digest::recipients() ) )
                        : __( 'The email could not be sent. WordPress mail is not working on this server — install an SMTP plugin (for example with your Hostinger email) and try again.', 'rar-woo-stock-order' )
                );
                break;
            case 'clear_lockouts':
                RAR_WSO_Security::clear_all_lockouts();
                RAR_WSO_Audit::add( 'tool', 'All staff login locks cleared' );
                self::flash( 'success', __( 'All staff login locks were cleared.', 'rar-woo-stock-order' ) );
                break;
            case 'signout_all':
                $ids = self::pure_staff_ids();
                foreach ( $ids as $id ) {
                    WP_Session_Tokens::get_instance( $id )->destroy_all();
                }
                RAR_WSO_Audit::add( 'emergency', sprintf( 'Signed out all staff (%d accounts)', count( $ids ) ) );
                /* translators: %d count */
                self::flash( 'success', sprintf( _n( '%d staff account was signed out everywhere.', '%d staff accounts were signed out everywhere.', count( $ids ), 'rar-woo-stock-order' ), count( $ids ) ) );
                break;
            case 'pause_all':
                $ids = self::pure_staff_ids();
                foreach ( $ids as $id ) {
                    update_user_meta( $id, 'rar_wso_suspended', '1' );
                    WP_Session_Tokens::get_instance( $id )->destroy_all();
                }
                RAR_WSO_Audit::add( 'emergency', sprintf( 'Paused all staff (%d accounts)', count( $ids ) ) );
                /* translators: %d count */
                self::flash( 'success', sprintf( _n( '%d staff account is paused. Resume people one by one in the Staff tab.', '%d staff accounts are paused. Resume people one by one in the Staff tab.', count( $ids ), 'rar-woo-stock-order' ), count( $ids ) ) );
                break;
            case 'app_off':
            case 'app_on':
                $s            = RAR_WSO_Plugin::settings();
                $s['enabled'] = 'app_on' === $do ? 'yes' : 'no';
                do_action( 'rar_wso_settings_import' );
                update_option( 'rar_wso_settings', $s );
                RAR_WSO_Audit::add( 'emergency', 'app_on' === $do ? 'Staff app switched on' : 'Staff app switched off' );
                self::flash( 'success', 'app_on' === $do ? __( 'The staff app is on again.', 'rar-woo-stock-order' ) : __( 'The staff app is off. Nobody can sign in or save until you switch it back on.', 'rar-woo-stock-order' ) );
                break;
            case 'prune':
                $days = absint( $_POST['days'] ?? 0 );
                if ( $days < 30 ) {
                    self::flash( 'error', __( 'Keep at least the last 30 days of history.', 'rar-woo-stock-order' ) );
                    break;
                }
                $a = RAR_WSO_Log::prune( $days );
                $b = RAR_WSO_Audit::prune( max( 90, $days ) );
                RAR_WSO_Audit::add( 'tool', sprintf( 'History older than %d days deleted (%d stock rows, %d audit rows)', $days, $a, $b ) );
                /* translators: 1: rows, 2: rows, 3: days */
                self::flash( 'success', sprintf( __( 'Deleted %1$d stock history rows and %2$d audit rows older than %3$d days.', 'rar-woo-stock-order' ), $a, $b, $days ) );
                break;
            case 'reset_settings':
                $s        = RAR_WSO_Plugin::settings();
                $defaults = RAR_WSO_Plugin::defaults();
                $defaults['staff_slug'] = $s['staff_slug'];
                $defaults['enabled']    = $s['enabled'];
                do_action( 'rar_wso_settings_import' );
                update_option( 'rar_wso_settings', $defaults );
                RAR_WSO_Audit::add( 'settings_reset', 'Settings reset to defaults (staff link and on/off kept)' );
                self::flash( 'success', __( 'Settings are back to the defaults. The staff link and the on/off switch were kept.', 'rar-woo-stock-order' ) );
                break;
            case 'import_settings':
                $file = $_FILES['settings_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 200 * KB_IN_BYTES ) {
                    self::flash( 'error', __( 'Choose a settings file (.json) exported from this plugin.', 'rar-woo-stock-order' ) );
                    break;
                }
                $parsed = RAR_WSO_Export::parse_settings( file_get_contents( $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                if ( is_wp_error( $parsed ) ) {
                    self::flash( 'error', $parsed->get_error_message() );
                    break;
                }
                do_action( 'rar_wso_settings_import' );
                update_option( 'rar_wso_settings', $parsed );
                RAR_WSO_Audit::add( 'settings_import', 'Settings restored from a backup file' );
                self::flash( 'success', __( 'Settings restored from the backup file.', 'rar-woo-stock-order' ) );
                break;
        }
        $this->back( $tab );
    }

    /* --------------------------------------------------------------------
     * Exports (CSV downloads)
     * ------------------------------------------------------------------ */

    /** Activity filters from a request, in the shape the queries expect. */
    public static function filters( $src ) {
        $src = array_map(
            static function ( $v ) {
                return is_scalar( $v ) ? $v : '';
            },
            (array) $src
        );
        $day = static function ( $v, $end ) {
            $v = sanitize_text_field( (string) $v );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
                return '';
            }
            try {
                $d = new DateTimeImmutable( $v . ( $end ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
            } catch ( Exception $e ) {
                return '';
            }
            return RAR_WSO_Reports::gmt( $d );
        };
        return array(
            'search'  => sanitize_text_field( wp_unslash( (string) ( $src['s'] ?? '' ) ) ),
            'from'    => $day( wp_unslash( $src['from'] ?? '' ), false ),
            'to'      => $day( wp_unslash( $src['to'] ?? '' ), true ),
            'user_id' => absint( $src['user_f'] ?? 0 ),
            'source'  => sanitize_key( wp_unslash( (string) ( $src['source_f'] ?? '' ) ) ),
            'status'  => sanitize_key( wp_unslash( (string) ( $src['status_f'] ?? '' ) ) ),
            'action'  => sanitize_key( wp_unslash( (string) ( $src['action_f'] ?? '' ) ) ),
        );
    }

    public function export() {
        check_admin_referer( 'rar_wso_export' );
        $type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
        if ( ! current_user_can( 'settings' === $type ? 'manage_options' : 'manage_woocommerce' ) ) {
            $this->deny( __( 'You do not have permission to export this.', 'rar-woo-stock-order' ) );
        }
        $f = self::filters( $_POST );
        switch ( $type ) {
            case 'stock':
                RAR_WSO_Export::stock();
                break;
            case 'movements':
                RAR_WSO_Export::movements( $f );
                break;
            case 'orders':
                RAR_WSO_Export::orders( $f );
                break;
            case 'audit':
                RAR_WSO_Export::audit( $f );
                break;
            case 'settings':
                RAR_WSO_Export::settings_json();
                break;
        }
        $this->deny( __( 'Unknown export.', 'rar-woo-stock-order' ) );
    }

    /* --------------------------------------------------------------------
     * Live refresh (Overview)
     * ------------------------------------------------------------------ */

    public function live() {
        nocache_headers();
        if ( ! check_ajax_referer( 'rar_wso_admin', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expired. Reload the page.', 'rar-woo-stock-order' ) ), 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not allowed.', 'rar-woo-stock-order' ) ), 403 );
        }
        ob_start();
        RAR_WSO_Admin_Views::overview_live();
        wp_send_json_success( array( 'html' => ob_get_clean(), 'at' => time() ) );
    }

    /* --------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------ */

    public function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'rar-woo-stock-order' ) );
        }
        RAR_WSO_Admin_Views::page( self::current_tab() );
    }
}
