<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Places where the plugin meets the rest of WordPress / WooCommerce:
 * admin bar shortcut, WP dashboard widget, "Staff" column on the orders list,
 * a "Staff app" box on the order screen and a stock-history box on the product screen.
 * Each one can be switched off in the settings.
 */
class RAR_WSO_Integrations {

    public static function hooks() {
        add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
        // Orders list: HPOS and legacy screens.
        add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'order_columns' ), 20 );
        add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'order_column_hpos' ), 10, 2 );
        add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'order_columns' ), 20 );
        add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'order_column_legacy' ), 10, 2 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ), 30, 2 );
    }

    private static function on( $key ) {
        $s = RAR_WSO_Plugin::settings();
        return 'yes' === ( $s[ $key ] ?? 'no' );
    }

    /* ---------------- Admin bar ---------------- */

    public static function admin_bar( $bar ) {
        if ( ! self::on( 'admin_bar_link' ) || ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $s = RAR_WSO_Plugin::settings();
        $bar->add_node(
            array(
                'id'    => 'rar-wso',
                'title' => '<span class="ab-icon dashicons dashicons-smartphone" aria-hidden="true" style="top:2px"></span><span class="ab-label">' . esc_html__( 'Staff App', 'rar-woo-stock-order' ) . '</span>',
                'href'  => admin_url( 'admin.php?page=rar-wso' ),
                'meta'  => array( 'title' => __( 'RAR Woo Stock & Order', 'rar-woo-stock-order' ) ),
            )
        );
        $bar->add_node( array( 'parent' => 'rar-wso', 'id' => 'rar-wso-cc', 'title' => esc_html__( 'Control Center', 'rar-woo-stock-order' ), 'href' => admin_url( 'admin.php?page=rar-wso' ) ) );
        if ( 'yes' === $s['enabled'] ) {
            $bar->add_node( array( 'parent' => 'rar-wso', 'id' => 'rar-wso-open', 'title' => esc_html__( 'Open staff app ↗', 'rar-woo-stock-order' ), 'href' => RAR_WSO_Plugin::staff_url(), 'meta' => array( 'target' => '_blank', 'rel' => 'noopener' ) ) );
        }
        $bar->add_node( array( 'parent' => 'rar-wso', 'id' => 'rar-wso-staff', 'title' => esc_html__( 'Staff accounts', 'rar-woo-stock-order' ), 'href' => admin_url( 'admin.php?page=rar-wso&tab=staff' ) ) );
        $bar->add_node( array( 'parent' => 'rar-wso', 'id' => 'rar-wso-activity', 'title' => esc_html__( 'Activity log', 'rar-woo-stock-order' ), 'href' => admin_url( 'admin.php?page=rar-wso&tab=activity' ) ) );
    }

    /* ---------------- Dashboard widget ---------------- */

    public static function dashboard_widget() {
        if ( ! self::on( 'dashboard_widget' ) || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        wp_add_dashboard_widget( 'rar_wso_dashboard', __( 'Stock & Order — today', 'rar-woo-stock-order' ), array( __CLASS__, 'render_widget' ) );
    }

    public static function render_widget() {
        $today = RAR_WSO_Reports::period_stats( 'today' );
        $ov    = RAR_WSO_Reports::admin_overview();
        $stock = RAR_WSO_Ajax::$instance ? RAR_WSO_Ajax::$instance->dashboard_stock() : array( 'counts' => array() );
        $c     = $stock['counts'] + array( 'low' => 0, 'out' => 0, 'negative' => 0 );
        $cur   = $today['cur'];
        $prev  = $today['prev'];
        $delta = $prev['sales'] > 0 ? ( $cur['sales'] - $prev['sales'] ) / $prev['sales'] * 100 : null;
        $cell  = static function ( $label, $value, $sub = '', $tone = '' ) {
            printf(
                '<div style="background:#f6f7fb;border-radius:8px;padding:10px 12px"><div style="font-size:12px;color:#646970">%1$s</div><div style="font-size:18px;font-weight:700;margin-top:2px;%4$s">%2$s</div><div style="font-size:12px;color:#646970">%3$s</div></div>',
                esc_html( $label ),
                esc_html( $value ),
                esc_html( $sub ),
                $tone ? 'color:' . esc_attr( $tone ) : ''
            );
        };
        echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px">';
        $cell( __( 'Sales today', 'rar-woo-stock-order' ), html_entity_decode( wp_strip_all_tags( wc_price( $cur['sales'], array( 'decimals' => 0 ) ) ), ENT_QUOTES, 'UTF-8' ), null === $delta ? __( 'no sales yesterday by now', 'rar-woo-stock-order' ) : sprintf( /* translators: %s percent */ __( '%s vs same time yesterday', 'rar-woo-stock-order' ), ( $delta >= 0 ? '▲ ' : '▼ ' ) . number_format_i18n( abs( $delta ), 0 ) . '%' ) );
        $cell( __( 'Orders today', 'rar-woo-stock-order' ), number_format_i18n( $cur['sale_orders'] ), sprintf( /* translators: %d count */ __( '%d from the staff app', 'rar-woo-stock-order' ), $ov['tot']['today']['staff_n'] ) );
        $cell( __( 'Out of stock', 'rar-woo-stock-order' ), number_format_i18n( $c['out'] ), $c['negative'] ? sprintf( /* translators: %d count */ __( '%d below zero', 'rar-woo-stock-order' ), $c['negative'] ) : '', $c['out'] ? '#b32d2e' : '' );
        $cell( __( 'Low stock', 'rar-woo-stock-order' ), number_format_i18n( $c['low'] ), sprintf( /* translators: %d threshold */ __( '1 to %d left', 'rar-woo-stock-order' ), RAR_WSO_Plugin::low_threshold() ), $c['low'] ? '#b26200' : '' );
        echo '</div><p style="margin:12px 0 0;display:flex;gap:8px;flex-wrap:wrap">';
        echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=rar-wso' ) ) . '">' . esc_html__( 'Open Control Center', 'rar-woo-stock-order' ) . '</a>';
        echo '<a class="button" href="' . esc_url( RAR_WSO_Plugin::staff_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Staff app ↗', 'rar-woo-stock-order' ) . '</a>';
        echo '</p>';
    }

    /* ---------------- Orders list column ---------------- */

    public static function order_columns( $columns ) {
        if ( ! self::on( 'order_column' ) ) {
            return $columns;
        }
        $out = array();
        foreach ( $columns as $key => $label ) {
            $out[ $key ] = $label;
            if ( 'order_status' === $key ) {
                $out['rar_wso_staff'] = __( 'Staff', 'rar-woo-stock-order' );
            }
        }
        if ( ! isset( $out['rar_wso_staff'] ) ) {
            $out['rar_wso_staff'] = __( 'Staff', 'rar-woo-stock-order' );
        }
        return $out;
    }

    private static $names = array();

    private static function staff_cell( $order ) {
        if ( ! $order ) {
            return;
        }
        $uid = (int) $order->get_meta( '_rar_wso_created_by' );
        if ( ! $uid ) {
            echo '<span aria-hidden="true" style="color:#a7aaad">—</span>';
            return;
        }
        if ( ! isset( self::$names[ $uid ] ) ) {
            $u                  = get_userdata( $uid );
            self::$names[ $uid ] = $u ? $u->display_name : '#' . $uid;
        }
        echo '<span class="dashicons dashicons-smartphone" aria-hidden="true" style="font-size:16px;width:16px;height:16px;color:#2271b1;vertical-align:-3px"></span> ' . esc_html( self::$names[ $uid ] );
    }

    public static function order_column_hpos( $column, $order ) {
        if ( 'rar_wso_staff' === $column ) {
            self::staff_cell( $order );
        }
    }

    public static function order_column_legacy( $column, $post_id ) {
        if ( 'rar_wso_staff' === $column ) {
            self::staff_cell( wc_get_order( $post_id ) );
        }
    }

    /* ---------------- Meta boxes ---------------- */

    public static function meta_boxes( $screen_id, $object = null ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
        if ( in_array( $screen_id, array( $order_screen, 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
            $order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
            if ( $order && (int) $order->get_meta( '_rar_wso_created_by' ) ) {
                add_meta_box( 'rar-wso-order', __( 'Staff app', 'rar-woo-stock-order' ), array( __CLASS__, 'order_box' ), $screen_id, 'side', 'default' );
            }
        }
        if ( 'product' === $screen_id ) {
            add_meta_box( 'rar-wso-product', __( 'Stock history', 'rar-woo-stock-order' ), array( __CLASS__, 'product_box' ), 'product', 'side', 'low' );
        }
    }

    public static function order_box( $object ) {
        $order = $object instanceof WC_Order ? $object : wc_get_order( $object instanceof WP_Post ? $object->ID : 0 );
        if ( ! $order ) {
            return;
        }
        $uid  = (int) $order->get_meta( '_rar_wso_created_by' );
        $user = $uid ? get_userdata( $uid ) : null;
        $rows = array(
            __( 'Created by', 'rar-woo-stock-order' ) => $user ? $user->display_name . ' (' . $user->user_login . ')' : '#' . $uid,
        );
        $branch = $uid ? (string) get_user_meta( $uid, 'rar_wso_branch', true ) : '';
        if ( '' !== $branch ) {
            $rows[ __( 'Branch / territory', 'rar-woo-stock-order' ) ] = $branch;
        }
        $disc = (float) $order->get_meta( '_rar_wso_discount' );
        if ( $disc > 0 ) {
            $rows[ __( 'Discount given', 'rar-woo-stock-order' ) ] = html_entity_decode( wp_strip_all_tags( wc_price( $disc ) ), ENT_QUOTES, 'UTF-8' );
        }
        $changed = array();
        foreach ( $order->get_items() as $item ) {
            $list = $item->get_meta( '_rar_wso_list_price', true );
            $rate = $item->get_meta( '_rar_wso_price_override', true );
            if ( '' !== (string) $list && '' !== (string) $rate ) {
                $changed[] = wp_strip_all_tags( $item->get_name() ) . ': ' . wc_format_localized_price( $list ) . ' → ' . wc_format_localized_price( $rate );
            }
        }
        echo '<table class="widefat striped" style="border:0"><tbody>';
        foreach ( $rows as $k => $v ) {
            echo '<tr><th style="width:40%">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( $changed ) {
            echo '<p style="margin:10px 0 4px"><strong>' . esc_html__( 'Rates changed while billing', 'rar-woo-stock-order' ) . '</strong></p><ul style="margin:0 0 0 16px;list-style:disc">';
            foreach ( $changed as $line ) {
                echo '<li>' . esc_html( $line ) . '</li>';
            }
            echo '</ul>';
        }
        if ( $uid ) {
            echo '<p style="margin:10px 0 0"><a href="' . esc_url( admin_url( 'admin.php?page=rar-wso&tab=activity&view=orders&user_f=' . $uid ) ) . '">' . esc_html__( 'All orders by this staff member →', 'rar-woo-stock-order' ) . '</a></p>';
        }
    }

    public static function product_box( $post ) {
        $product = wc_get_product( $post );
        if ( ! $product ) {
            return;
        }
        $ids = array_merge( array( $product->get_id() ), $product->is_type( 'variable' ) ? $product->get_children() : array() );
        $log = RAR_WSO_Log::query( array( 'product_ids' => $ids, 'per_page' => 8 ) );
        if ( ! $log['items'] ) {
            echo '<p class="description">' . esc_html__( 'No stock changes recorded yet. Changes made in the staff app and by orders appear here.', 'rar-woo-stock-order' ) . '</p>';
            return;
        }
        echo '<ol style="margin:0;padding:0;list-style:none">';
        foreach ( $log['items'] as $x ) {
            $from  = null === $x['from'] ? '—' : wc_stock_amount( $x['from'] );
            $to    = null === $x['to'] ? '—' : wc_stock_amount( $x['to'] );
            $delta = ( null === $x['from'] || null === $x['to'] ) ? '' : ( $x['to'] - $x['from'] );
            printf(
                '<li style="padding:6px 0;border-bottom:1px solid #f0f0f1"><strong>%1$s → %2$s</strong> %3$s<br><span class="description">%4$s · %5$s%6$s</span></li>',
                esc_html( $from ),
                esc_html( $to ),
                '' === $delta ? '' : '<span style="color:' . ( $delta >= 0 ? '#1e8a45' : '#b32d2e' ) . '">(' . esc_html( ( $delta > 0 ? '+' : '' ) . wc_stock_amount( $delta ) ) . ')</span>',
                esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $x['time'] ) ),
                esc_html( $x['reason'] ),
                $x['user'] ? ' · ' . esc_html( $x['user'] ) : ''
            );
        }
        echo '</ol><p style="margin:8px 0 0"><a href="' . esc_url( admin_url( 'admin.php?page=rar-wso&tab=activity&view=stock&s=' . rawurlencode( $product->get_sku() ? $product->get_sku() : $product->get_name() ) ) ) . '">' . esc_html__( 'Full stock history →', 'rar-woo-stock-order' ) . '</a></p>';
    }
}
