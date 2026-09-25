<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAR_WSO_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ), 80 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        // Flush once after activation/upgrade/slug change, after the /staff/ rule is registered on init.
        add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
    }

    public function menu() {
        add_submenu_page(
            'woocommerce',
            __( 'RAR Woo Stock & Order', 'rar-woo-stock-order' ),
            __( 'Stock & Order', 'rar-woo-stock-order' ),
            'manage_woocommerce',
            'rar-wso',
            array( $this, 'render' )
        );
    }

    public function register_settings() {
        register_setting( 'rar_wso_group', 'rar_wso_settings', array( $this, 'sanitize' ) );
    }

    public function sanitize( $input ) {
        $old = RAR_WSO_Plugin::settings();
        $out = RAR_WSO_Plugin::defaults();
        $out['enabled']              = ! empty( $input['enabled'] ) ? 'yes' : 'no';
        $out['staff_slug']           = sanitize_title( isset( $input['staff_slug'] ) ? $input['staff_slug'] : 'staff' );
        $out['dashboard_title']      = sanitize_text_field( isset( $input['dashboard_title'] ) ? $input['dashboard_title'] : 'Woo Stock & Order' );
        $out['default_order_status'] = sanitize_key( isset( $input['default_order_status'] ) ? $input['default_order_status'] : 'processing' );
        $out['allow_price_override'] = ! empty( $input['allow_price_override'] ) ? 'yes' : 'no';
        $out['staff_max_discount']   = (string) min( 100, max( 0, (float) ( isset( $input['staff_max_discount'] ) ? $input['staff_max_discount'] : 20 ) ) );
        $out['default_shipping']     = wc_format_decimal( isset( $input['default_shipping'] ) ? $input['default_shipping'] : '0' );
        $out['shipping_dhaka']       = wc_format_decimal( isset( $input['shipping_dhaka'] ) ? $input['shipping_dhaka'] : '' );
        $out['shipping_outside']     = wc_format_decimal( isset( $input['shipping_outside'] ) ? $input['shipping_outside'] : '' );
        $out['low_stock_threshold']  = (string) max( 1, absint( isset( $input['low_stock_threshold'] ) ? $input['low_stock_threshold'] : 10 ) );
        $out['staff_view_orders']    = ! empty( $input['staff_view_orders'] ) ? 'yes' : 'no';
        $out['business_name']        = sanitize_text_field( isset( $input['business_name'] ) ? $input['business_name'] : '' );
        $out['slip_footer']          = sanitize_text_field( isset( $input['slip_footer'] ) ? $input['slip_footer'] : '' );

        if ( empty( $out['staff_slug'] ) ) {
            $out['staff_slug'] = 'staff';
        }
        if ( $old['staff_slug'] !== $out['staff_slug'] ) {
            update_option( 'rar_wso_flush_rewrite', 1, false );
        }
        return $out;
    }

    public function maybe_flush_rewrite() {
        if ( get_option( 'rar_wso_flush_rewrite' ) ) {
            delete_option( 'rar_wso_flush_rewrite' );
            flush_rewrite_rules( false );
        }
    }

    public function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'rar-woo-stock-order' ) );
        }

        $s = RAR_WSO_Plugin::settings();
        $statuses = wc_get_order_statuses();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'RAR Woo Stock & Order', 'rar-woo-stock-order' ); ?></h1>
            <p><?php esc_html_e( 'Mobile-first staff PWA for stock updates and fast WooCommerce order creation.', 'rar-woo-stock-order' ); ?></p>

            <div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #198754;padding:14px 16px;margin:16px 0;max-width:980px">
                <strong><?php esc_html_e( 'Staff App URL:', 'rar-woo-stock-order' ); ?></strong>
                <a href="<?php echo esc_url( RAR_WSO_Plugin::staff_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( RAR_WSO_Plugin::staff_url() ); ?></a>
                <p style="margin-bottom:0"><?php esc_html_e( 'Open this link on Chrome and use Add to Home Screen for an app-like phone experience.', 'rar-woo-stock-order' ); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'rar_wso_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr><th><?php esc_html_e( 'Enable staff app', 'rar-woo-stock-order' ); ?></th><td><label><input type="checkbox" name="rar_wso_settings[enabled]" value="1" <?php checked( $s['enabled'], 'yes' ); ?>> <?php esc_html_e( 'Enable private staff PWA', 'rar-woo-stock-order' ); ?></label></td></tr>
                    <tr><th><label for="rar-wso-title"><?php esc_html_e( 'App title', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-title" class="regular-text" type="text" name="rar_wso_settings[dashboard_title]" value="<?php echo esc_attr( $s['dashboard_title'] ); ?>"></td></tr>
                    <tr><th><label for="rar-wso-slug"><?php esc_html_e( 'Staff URL slug', 'rar-woo-stock-order' ); ?></label></th><td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input id="rar-wso-slug" type="text" name="rar_wso_settings[staff_slug]" value="<?php echo esc_attr( $s['staff_slug'] ); ?>" style="width:180px"><code>/</code></td></tr>
                    <tr><th><?php esc_html_e( 'Default new order status', 'rar-woo-stock-order' ); ?></th><td><select name="rar_wso_settings[default_order_status]"><?php foreach ( $statuses as $key => $label ) : $slug = str_replace( 'wc-', '', $key ); ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['default_order_status'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Order price override', 'rar-woo-stock-order' ); ?></th><td><label><input type="checkbox" name="rar_wso_settings[allow_price_override]" value="1" <?php checked( $s['allow_price_override'], 'yes' ); ?>> <?php esc_html_e( 'Allow staff to change item price while billing', 'rar-woo-stock-order' ); ?></label></td></tr>
                    <tr><th><label for="rar-wso-maxdisc"><?php esc_html_e( 'Staff discount limit', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-maxdisc" type="number" min="0" max="100" step="0.5" name="rar_wso_settings[staff_max_discount]" value="<?php echo esc_attr( $s['staff_max_discount'] ); ?>"> % <span class="description"><?php esc_html_e( 'Largest discount a staff user can give on one order, as a percent of the items subtotal. 0 = staff cannot give discounts. Shop Managers and Administrators are not limited.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><label for="rar-wso-ship-dhaka"><?php esc_html_e( 'Shipping — Inside Dhaka', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-ship-dhaka" type="number" min="0" step="0.01" name="rar_wso_settings[shipping_dhaka]" value="<?php echo esc_attr( $s['shipping_dhaka'] ); ?>"> <span class="description"><?php esc_html_e( 'Filled in automatically when the District is Dhaka. Staff can still change it per order.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><label for="rar-wso-ship-out"><?php esc_html_e( 'Shipping — Outside Dhaka', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-ship-out" type="number" min="0" step="0.01" name="rar_wso_settings[shipping_outside]" value="<?php echo esc_attr( $s['shipping_outside'] ); ?>"> <span class="description"><?php esc_html_e( 'Used for every other district.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><label for="rar-wso-shipping"><?php esc_html_e( 'Default shipping charge', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-shipping" type="number" min="0" step="0.01" name="rar_wso_settings[default_shipping]" value="<?php echo esc_attr( $s['default_shipping'] ); ?>"> <span class="description"><?php esc_html_e( 'Used before a district is chosen, or when the two fields above are empty.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><label for="rar-wso-low"><?php esc_html_e( 'Low stock level', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-low" type="number" min="1" step="1" name="rar_wso_settings[low_stock_threshold]" value="<?php echo esc_attr( $s['low_stock_threshold'] ); ?>"> <span class="description"><?php esc_html_e( 'Quantities from 1 up to this number show orange (low). Above it shows green; 0 shows red.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><?php esc_html_e( 'Staff order lists', 'rar-woo-stock-order' ); ?></th><td><label><input type="checkbox" name="rar_wso_settings[staff_view_orders]" value="1" <?php checked( $s['staff_view_orders'], 'yes' ); ?>> <?php esc_html_e( 'Let staff open today / 7-day / month order lists (view only). Status changes, All Orders, Live Orders and sales reports stay Shop Manager only.', 'rar-woo-stock-order' ); ?></label></td></tr>
                    <tr><th><label for="rar-wso-bname"><?php esc_html_e( 'Business name on slip', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-bname" class="regular-text" type="text" name="rar_wso_settings[business_name]" value="<?php echo esc_attr( $s['business_name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>"></td></tr>
                    <tr><th><label for="rar-wso-footer"><?php esc_html_e( 'Slip footer line', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-footer" class="regular-text" type="text" name="rar_wso_settings[slip_footer]" value="<?php echo esc_attr( $s['slip_footer'] ); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Staff permissions', 'rar-woo-stock-order' ); ?></h2>
            <p><?php esc_html_e( 'Assign the “Woo Stock & Order Staff” role from Users → All Users. Staff can use the private app, update stock and create WooCommerce orders. Item-price override remains optional.', 'rar-woo-stock-order' ); ?></p>
            <p><strong><?php esc_html_e( 'Shop Manager tools:', 'rar-woo-stock-order' ); ?></strong> <?php esc_html_e( 'Administrator and Shop Manager users also get Order Control (All Orders, Live Orders, Processing with status changes) and the Sales & Growth report inside the staff app.', 'rar-woo-stock-order' ); ?></p>
            <p><strong><?php esc_html_e( 'Catalog management:', 'rar-woo-stock-order' ); ?></strong> <?php esc_html_e( 'Product creation, editing and deletion intentionally stay in WooCommerce → Products for Administrator / Shop Manager users. The staff app does not duplicate those controls.', 'rar-woo-stock-order' ); ?></p>
        </div>
        <?php
    }
}
