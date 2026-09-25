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
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-log.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-reports.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-admin.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-ajax.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-pwa.php';

        new RAR_WSO_Admin();
        new RAR_WSO_Ajax();
        new RAR_WSO_PWA();
        RAR_WSO_Log::hooks();
        RAR_WSO_Reports::hooks();

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
        );
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
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-log.php';
        RAR_WSO_Log::install();
        update_option( 'rar_wso_version', RAR_WSO_VERSION, false );
        update_option( 'rar_wso_flush_rewrite', 1, false );
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function maybe_upgrade() {
        $installed = (string) get_option( 'rar_wso_version', '0' );
        if ( version_compare( $installed, RAR_WSO_VERSION, '>=' ) ) {
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
        update_option( 'rar_wso_version', RAR_WSO_VERSION, false );
        update_option( 'rar_wso_flush_rewrite', 1, false );
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
            foreach ( array( 'rar_wso_access', 'rar_wso_manage_stock', 'rar_wso_create_orders', 'rar_wso_adjust_price', 'rar_wso_manage_orders' ) as $cap ) {
                $role->add_cap( $cap );
            }
            $role->remove_cap( 'rar_wso_add_products' );
            $role->remove_cap( 'rar_wso_delete_products' );
        }
    }

    public static function can( $cap ) {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( $cap );
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
        $settings = self::settings();
        return (float) min( 100, max( 0, (float) $settings['staff_max_discount'] ) );
    }

    public static function can_view_orders() {
        if ( self::is_manager() ) {
            return true;
        }
        $settings = self::settings();
        return 'yes' === $settings['staff_view_orders'] && self::can( 'rar_wso_access' );
    }
}
