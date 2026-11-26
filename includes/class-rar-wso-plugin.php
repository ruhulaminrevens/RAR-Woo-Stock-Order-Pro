<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WSO_Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-lock.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-security.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-audit.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-log.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-reports.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-health.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-digest.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-integrations.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-shipping.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-xlsx.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-report-builder.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-branding.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-admin.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-ajax.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-pwa.php';

        new RAR_WSO_Admin();
        new RAR_WSO_Ajax();
        new RAR_WSO_PWA();
        RAR_WSO_Log::hooks();
        RAR_WSO_Reports::hooks();
        RAR_WSO_Security::hooks();
        RAR_WSO_Digest::hooks();
        RAR_WSO_Integrations::hooks();
        RAR_WSO_Branding::hooks();

        add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );
        add_filter( 'plugin_action_links_' . plugin_basename( RAR_WSO_FILE ), array( $this, 'plugin_action_links' ) );
    }

    public function plugin_action_links( $links ) {
        $url = admin_url( 'admin.php?page=rar-wso' );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'rar-woo-stock-order' ) . '</a>' );
        return $links;
    }

    public static function defaults() {
        return array(
            'enabled'              => 'yes',
            'staff_slug'           => 'staff',
            'default_order_status' => 'processing',
            'allow_price_override' => 'yes',
            'staff_max_discount'   => '20',
            'default_shipping'     => '0',
            'shipping_dhaka'       => '60',
            'shipping_outside'     => '120',
            'low_stock_threshold'  => '10',
            'staff_view_orders'    => 'yes',
            'business_name'        => '',
            'slip_footer'          => 'Thank you for shopping with us!',
            'dashboard_title'      => 'Woo Stock & Order',
            'managers_add_staff'   => 'no',
            // v1.4.0 — branding.
            'brand_color'          => '#15234a',
            'logo_id'              => '0',
            'slip_phone'           => '',
            'slip_address'         => '',
            'wa_message'           => 'short',
            // v1.4.0 — orders & stock.
            'payment_methods'      => 'cod,bkash,nagad,cash',
            'payment_extra'        => '',
            'payment_default'      => 'cod',
            'free_shipping_over'   => '0',
            // v1.5.0 — couriers.
            'courier_mode'         => 'smart',
            'follow_wc_free'       => 'yes',
            'stock_reasons'        => "Restock — new shipment\nCount correction\nDamaged / expired\nCustomer return\nTransfer in\nTransfer out",
            // v1.4.0 — access & security.
            'login_user_limit'     => '6',
            'login_ip_limit'       => '10',
            'lockout_minutes'      => '15',
            'session_days'         => '14',
            // v1.4.0 — reports & integrations.
            'digest_enabled'       => 'no',
            'digest_email'         => '',
            'digest_hour'          => '21',
            'log_retention_days'   => '0',
            'admin_bar_link'       => 'yes',
            'dashboard_widget'     => 'yes',
            'order_column'         => 'yes',
            // v1.6.0 — app identity & appearance.
            'app_short_name'       => '',
            'app_icon_id'          => '0',
            'app_theme'            => 'system',
            // v1.6.0 — reports for staff.
            'staff_reports'        => 'yes',
            'staff_export'         => 'yes',
            // v1.6.0 — Shop Manager access control (Administrators are never limited).
            'mgr_manage_stock'     => 'yes',
            'mgr_add_products'     => 'yes',
            'mgr_create_orders'    => 'yes',
            'mgr_adjust_price'     => 'yes',
            'mgr_manage_orders'    => 'yes',
            'mgr_reports'          => 'yes',
            'mgr_export'           => 'yes',
            'mgr_view_cost'        => 'yes',
        );
    }

    /** Logical app permission => Shop Manager setting key. */
    public static function manager_perms() {
        return array(
            'rar_wso_manage_stock'  => 'mgr_manage_stock',
            'rar_wso_add_products'  => 'mgr_add_products',
            'rar_wso_create_orders' => 'mgr_create_orders',
            'rar_wso_adjust_price'  => 'mgr_adjust_price',
            'rar_wso_manage_orders' => 'mgr_manage_orders',
            'rar_wso_reports'       => 'mgr_reports',
            'rar_wso_export'        => 'mgr_export',
            'rar_wso_view_cost'     => 'mgr_view_cost',
        );
    }

    /** Labels for the Shop Manager permission matrix (admin) and the per-person panel. */
    public static function manager_perm_labels() {
        return array(
            'rar_wso_manage_stock'  => __( 'Update and add stock', 'rar-woo-stock-order' ),
            'rar_wso_add_products'  => __( 'Add new products from the app', 'rar-woo-stock-order' ),
            'rar_wso_create_orders' => __( 'Create orders', 'rar-woo-stock-order' ),
            'rar_wso_adjust_price'  => __( 'Change item rates while billing', 'rar-woo-stock-order' ),
            'rar_wso_manage_orders' => __( 'Order control: all orders, status changes, sales & growth', 'rar-woo-stock-order' ),
            'rar_wso_reports'       => __( 'Reports (daily, monthly, custom — all staff)', 'rar-woo-stock-order' ),
            'rar_wso_export'        => __( 'Export / share reports (Excel, PDF, image)', 'rar-woo-stock-order' ),
            'rar_wso_view_cost'     => __( 'See stock value and cost figures', 'rar-woo-stock-order' ),
        );
    }

    /** App name shown on the phone (home-screen label, header, install prompt). */
    public static function app_name() {
        $s    = self::settings();
        $name = trim( (string) $s['dashboard_title'] ); // Settings → General → App name
        return '' !== $name ? $name : 'Stock & Order';
    }

    /** Short home-screen label (max 12 characters work best). */
    public static function app_short_name() {
        $s    = self::settings();
        $name = trim( (string) $s['app_short_name'] );
        if ( '' === $name ) {
            $name = self::app_name();
        }
        return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 24 ) : substr( $name, 0, 24 );
    }

    /** system | light | dark — the default for phones that have not chosen their own. */
    public static function app_theme() {
        $s = self::settings();
        return in_array( $s['app_theme'], array( 'light', 'dark' ), true ) ? $s['app_theme'] : 'system';
    }

    /**
     * Menu badge: stock below zero + orders waiting over 24h. Read from one small autoloaded
     * option that is refreshed whenever those figures are recalculated (never recalculates here).
     */
    public static function attention_count() {
        $a = get_option( 'rar_wso_attention', array() );
        // Figures older than an hour are not shown (they refresh when a dashboard is opened).
        if ( ! is_array( $a ) || time() - (int) ( $a['at'] ?? 0 ) > HOUR_IN_SECONDS ) {
            return 0;
        }
        return (int) ( $a['negative'] ?? 0 ) + (int) ( $a['stale'] ?? 0 );
    }

    /** Stores one badge figure; writes only when it changed. */
    public static function set_attention( $key, $value ) {
        $a = get_option( 'rar_wso_attention', array() );
        $a = is_array( $a ) ? $a : array();
        // Write when the figure changed, or at most every 10 minutes to keep it fresh.
        if ( (int) ( $a[ $key ] ?? -1 ) !== (int) $value || time() - (int) ( $a['at'] ?? 0 ) > 10 * MINUTE_IN_SECONDS ) {
            $a[ $key ] = (int) $value;
            $a['at']   = time();
            update_option( 'rar_wso_attention', $a, true );
        }
    }

    /** Brand colour as a safe #rrggbb value. */
    public static function brand_color() {
        $settings = self::settings();
        $color    = sanitize_hex_color( (string) $settings['brand_color'] );
        return $color && 7 === strlen( $color ) ? strtolower( $color ) : '#15234a';
    }

    /** Logo image URL (Media Library), or '' when none is set. */
    public static function logo_url( $size = 'medium' ) {
        $settings = self::settings();
        $id       = absint( $settings['logo_id'] );
        if ( ! $id ) {
            return '';
        }
        $url = wp_get_attachment_image_url( $id, $size );
        return $url ? (string) $url : '';
    }

    /** Preset reasons for stock changes, one per line in the settings. */
    public static function stock_reasons() {
        $settings = self::settings();
        $lines    = preg_split( '/\r\n|\r|\n/', (string) $settings['stock_reasons'] );
        $out      = array();
        foreach ( (array) $lines as $line ) {
            $line = trim( sanitize_text_field( $line ) );
            if ( '' !== $line && ! in_array( $line, $out, true ) ) {
                $out[] = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 60 ) : substr( $line, 0, 60 );
            }
        }
        return array_slice( $out, 0, 20 );
    }

    /**
     * Staff accounts only (never Shop Managers / Administrators): per-person settings
     * saved from WooCommerce → Stock & Order → Staff.
     */
    public static function staff_override( $key, $user_id = 0 ) {
        $user_id = $user_id ? $user_id : get_current_user_id();
        return $user_id ? (string) get_user_meta( $user_id, 'rar_wso_' . $key, true ) : '';
    }

    public static function settings() {
        $defaults = self::defaults();
        $stored   = (array) get_option( 'rar_wso_settings', array() );
        $stored   = array_intersect_key( $stored, $defaults );
        return array_merge( $defaults, $stored );
    }

    public static function staff_url() {
        $settings = self::settings();
        $slug     = sanitize_title( $settings['staff_slug'] );
        return home_url( '/' . ( $slug ? $slug : 'staff' ) . '/' );
    }

    /** Products with more than this quantity are "healthy"; 1..threshold is "low"; 0 or less is "out". */
    public static function low_threshold() {
        $settings = self::settings();
        return max( 1, absint( $settings['low_stock_threshold'] ) );
    }

    public static function business_name() {
        $settings = self::settings();
        $name     = trim( (string) $settings['business_name'] );
        return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    }

    public static function activate() {
        self::register_roles_and_caps();
        if ( ! get_option( 'rar_wso_settings', false ) ) {
            add_option( 'rar_wso_settings', self::defaults() );
        } else {
            update_option( 'rar_wso_settings', self::settings() );
        }
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-security.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-audit.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-log.php';
        RAR_WSO_Log::install();
        add_option( 'rar_wso_attention', array(), '', true );
        update_option( 'rar_wso_version', RAR_WSO_VERSION, true );
        update_option( 'rar_wso_flush_rewrite', 1, true );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'rar_wso_daily_digest' );
        wp_clear_scheduled_hook( 'rar_wso_housekeeping' );
        flush_rewrite_rules();
    }

    public function maybe_upgrade() {
        $installed = (string) get_option( 'rar_wso_version', '0' );
        if ( version_compare( $installed, RAR_WSO_VERSION, '>=' ) ) {
            return;
        }
        // One request upgrades; others arriving at the same moment carry on without waiting.
        if ( ! RAR_WSO_Lock::acquire( 'upgrade', 0 ) ) {
            return;
        }

        $stored = (array) get_option( 'rar_wso_settings', array() );

        // v1.2.0 introduced district-based shipping. If the store already used a flat
        // default shipping charge, keep charging that amount everywhere after the upgrade.
        if ( ! isset( $stored['shipping_dhaka'] ) && isset( $stored['default_shipping'] ) && (float) $stored['default_shipping'] > 0 ) {
            $stored['shipping_dhaka']   = (string) $stored['default_shipping'];
            $stored['shipping_outside'] = (string) $stored['default_shipping'];
            update_option( 'rar_wso_settings', $stored );
        }

        self::register_roles_and_caps();
        RAR_WSO_Log::install();
        update_option( 'rar_wso_settings', self::settings() );
        // Read on every page load: keep them in the autoloaded options (no extra query per request).
        update_option( 'rar_wso_version', RAR_WSO_VERSION, true );
        update_option( 'rar_wso_flush_rewrite', 1, true );
        // Locks are MySQL named locks since 1.3.0; remove leftovers of the old option-row locks.
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rar\\_wso\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        // Menu badge figures live in one small autoloaded option (no query per admin page).
        add_option( 'rar_wso_attention', array(), '', true );
        RAR_WSO_Digest::reschedule();
    }

    public static function register_roles_and_caps() {
        $staff_caps = array(
            'read'                  => true,
            'rar_wso_access'        => true,
            'rar_wso_manage_stock'  => true,
            'rar_wso_create_orders' => true,
            'rar_wso_adjust_price'  => true,
        );

        add_role( 'rar_wso_staff', __( 'Woo Stock & Order Staff', 'rar-woo-stock-order' ), $staff_caps );

        $staff_role = get_role( 'rar_wso_staff' );
        if ( $staff_role ) {
            foreach ( $staff_caps as $cap => $grant ) {
                if ( $grant ) {
                    $staff_role->add_cap( $cap );
                }
            }
            // Remove legacy catalog-management capabilities from pre-1.1 installs.
            $staff_role->remove_cap( 'rar_wso_add_products' );
            $staff_role->remove_cap( 'rar_wso_delete_products' );
        }

        foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( array( 'rar_wso_access', 'rar_wso_manage_stock', 'rar_wso_create_orders', 'rar_wso_adjust_price', 'rar_wso_manage_orders', 'rar_wso_add_products', 'rar_wso_reports', 'rar_wso_export', 'rar_wso_view_cost' ) as $cap ) {
                $role->add_cap( $cap );
            }
            $role->remove_cap( 'rar_wso_delete_products' );
        }
    }

    /**
     * Does this person hold an app permission?
     *
     * - Administrators: always.
     * - Shop Managers: the shop-wide "Shop Manager access" settings, unless a personal
     *   override (Staff tab → Manage) says otherwise.
     * - Staff: the Staff role's capabilities (blocked per person from the Staff tab);
     *   reports and export follow the "Staff reports" settings.
     */
    public static function can( $cap ) {
        $uid = get_current_user_id();
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        if ( current_user_can( 'manage_woocommerce' ) ) {
            if ( 'rar_wso_access' === $cap ) {
                return true;
            }
            $map = self::manager_perms();
            if ( ! isset( $map[ $cap ] ) ) {
                return true;
            }
            $own = $uid ? (string) get_user_meta( $uid, 'rar_wso_perm_' . $map[ $cap ], true ) : '';
            if ( 'yes' === $own || 'no' === $own ) {
                return 'yes' === $own;
            }
            $settings = self::settings();
            return 'yes' === $settings[ $map[ $cap ] ];
        }
        if ( RAR_WSO_Security::is_paused( $uid ) ) {
            return false;
        }
        // Staff: reports (own figures only), export, and never cost / product creation.
        if ( in_array( $cap, array( 'rar_wso_reports', 'rar_wso_export' ), true ) ) {
            if ( ! current_user_can( 'rar_wso_access' ) ) {
                return false;
            }
            $key      = 'rar_wso_reports' === $cap ? 'staff_reports' : 'staff_export';
            $settings = self::settings();
            return 'yes' === $settings[ $key ];
        }
        if ( in_array( $cap, array( 'rar_wso_add_products', 'rar_wso_view_cost' ), true ) ) {
            return false;
        }
        return current_user_can( $cap );
    }

    /** Reports of every person (Shop Manager level) rather than only the user's own. */
    public static function can_report_all() {
        return current_user_can( 'manage_woocommerce' ) && self::can( 'rar_wso_reports' );
    }

    /** Who may add / pause staff accounts from WooCommerce → Stock & Order. */
    public static function can_manage_staff() {
        if ( current_user_can( 'create_users' ) && current_user_can( 'promote_users' ) ) {
            return true;
        }
        $settings = self::settings();
        return 'yes' === $settings['managers_add_staff'] && current_user_can( 'manage_woocommerce' );
    }

    /** Shop Manager level: order control, status changes and sales reports. */
    public static function is_manager() {
        return self::can( 'rar_wso_manage_orders' );
    }

    /**
     * Largest order discount (percent of the items subtotal) this user may give.
     * Shop Managers / Administrators are not limited; staff use the setting.
     */
    public static function max_discount_percent() {
        if ( self::is_manager() ) {
            return 100.0;
        }
        // A personal limit set for this staff member wins over the shop-wide one.
        $own = self::staff_override( 'max_discount' );
        if ( '' !== $own && is_numeric( $own ) ) {
            return (float) min( 100, max( 0, (float) $own ) );
        }
        $settings = self::settings();
        return (float) min( 100, max( 0, (float) $settings['staff_max_discount'] ) );
    }

    public static function can_view_orders() {
        if ( self::is_manager() ) {
            return true;
        }
        if ( ! self::can( 'rar_wso_access' ) ) {
            return false;
        }
        $own = self::staff_override( 'view_orders' );
        if ( 'yes' === $own || 'no' === $own ) {
            return 'yes' === $own;
        }
        $settings = self::settings();
        return 'yes' === $settings['staff_view_orders'];
    }
}
