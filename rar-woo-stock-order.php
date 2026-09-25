<?php
/**
 * Plugin Name: RAR Woo Stock & Order
 * Plugin URI: https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order-Pro
 * Description: Mobile-first WooCommerce PWA for controlled stock updates and fast staff order creation.
 * Version: 1.2.2
 * Author: Ruhul Amin Revens
 * Author URI: https://github.com/ruhulaminrevens
 * Text Domain: rar-woo-stock-order
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * Update URI: https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order-Pro
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RAR_WSO_VERSION', '1.2.2' );
define( 'RAR_WSO_FILE', __FILE__ );
define( 'RAR_WSO_PATH', plugin_dir_path( __FILE__ ) );
define( 'RAR_WSO_URL', plugin_dir_url( __FILE__ ) );

require_once RAR_WSO_PATH . 'includes/class-rar-wso-plugin.php';

register_activation_hook( __FILE__, array( 'RAR_WSO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RAR_WSO_Plugin', 'deactivate' ) );

add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function () {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-error"><p><strong>RAR Woo Stock &amp; Order</strong> requires WooCommerce to be installed and active.</p></div>';
            }
        } );
        return;
    }
    RAR_WSO_Plugin::instance();
}, 20 );
