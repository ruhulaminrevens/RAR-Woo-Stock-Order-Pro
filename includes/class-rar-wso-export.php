<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CSV exports (Excel-ready, UTF-8 with BOM so Bangla text opens correctly) and
 * settings backup / restore for the Control Center Tools screen.
 * Every export is read-only and written to the audit log.
 */
class RAR_WSO_Export {
    const MAX_ROWS = 50000;

    /** Stops spreadsheet formula injection: text starting with = + - @ is prefixed with '. */
    private static function cell( $v ) {
        if ( is_string( $v ) && '' !== $v && ! is_numeric( $v ) && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            return "'" . $v;
        }
        return $v;
    }

    private static function open( $name ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        while ( ob_get_level() > 0 ) {
            @ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name . '-' . wp_date( 'Y-m-d-His' ) . '.csv' ) . '"' );
        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        return $out;
    }

    private static function row( $out, $cells ) {
        fputcsv( $out, array_map( array( __CLASS__, 'cell' ), $cells ), ',', '"', '' );
    }

    /** Frees product objects kept in the in-memory cache during long exports (never touches a persistent cache). */
    private static function free_memory() {
        if ( ! wp_using_ext_object_cache() && function_exists( 'wp_cache_flush_runtime' ) ) {
            wp_cache_flush_runtime();
        }
    }

    private static function local( $ts ) {
        return $ts ? wp_date( 'Y-m-d H:i', (int) $ts ) : '';
    }

    public static function cogs_enabled() {
        return 'yes' === get_option( 'woocommerce_feature_cost_of_goods_sold_enabled' ) && method_exists( 'WC_Product', 'get_cogs_total_value' );
    }

    /* ---------------- Stock valuation ---------------- */

    public static function stock() {
        global $wpdb;
        $ajax = RAR_WSO_Ajax::$instance;
        if ( ! $ajax ) {
            wp_die( esc_html__( 'Stock data is not available.', 'rar-woo-stock-order' ) );
        }
        $ids  = array_map( 'absint', (array) $wpdb->get_col( 'SELECT p.ID ' . $ajax->product_base_sql() . ' ORDER BY COALESCE(pp.post_title, p.post_title) ASC, p.menu_order ASC, p.ID ASC LIMIT ' . self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $cogs = self::cogs_enabled();
        $out  = self::open( 'stock-valuation' );
        $head = array( 'Product ID', 'Parent ID', 'SKU', 'Product', 'Category', 'Type', 'Stock tracked', 'Stock qty', 'Stock status', 'Level', 'Regular price', 'Sale price', 'Selling price', 'Value at selling price' );
        if ( $cogs ) {
            $head[] = 'Unit cost (COGS)';
            $head[] = 'Value at cost';
        }
        $head[] = 'Last stock change';
        self::row( $out, $head );

        $threshold = RAR_WSO_Plugin::low_threshold();
        $totals    = array( 'qty' => 0.0, 'value' => 0.0, 'cost' => 0.0 );
        foreach ( array_chunk( $ids, 200 ) as $chunk ) {
            _prime_post_caches( $chunk );
            $last = RAR_WSO_Log::last_times( $chunk );
            foreach ( $chunk as $id ) {
                $p = wc_get_product( $id );
                if ( ! $p ) {
                    continue;
                }
                $parent_id = $p->is_type( 'variation' ) ? $p->get_parent_id() : 0;
                $cat_src   = $parent_id ? $parent_id : $id;
                $terms     = get_the_terms( $cat_src, 'product_cat' );
                $cats      = ( $terms && ! is_wp_error( $terms ) ) ? implode( ' | ', wp_list_pluck( $terms, 'name' ) ) : '';
                $tracked   = (bool) $p->managing_stock();
                $qty       = $tracked ? (float) $p->get_stock_quantity() : null;
                $level     = null === $qty ? ( 'outofstock' === $p->get_stock_status() ? 'out' : 'untracked' ) : ( $qty < 0 ? 'negative' : ( 0.0 === $qty ? 'out' : ( $qty <= $threshold ? 'low' : 'ok' ) ) );
                $price     = (float) $p->get_price();
                $value     = ( null !== $qty && $qty > 0 ) ? $qty * $price : 0.0;
                $name      = wp_strip_all_tags( $p->get_name() );
                if ( $parent_id ) {
                    $parent = wc_get_product( $parent_id );
                    if ( $parent ) {
                        $name = wp_strip_all_tags( $parent->get_name() ) . ' — ' . wc_get_formatted_variation( $p, true, false, false );
                    }
                }
                $row = array( $id, $parent_id ? $parent_id : '', $p->get_sku(), $name, wp_specialchars_decode( $cats, ENT_QUOTES ), $p->get_type(), $tracked ? 'yes' : 'no', null === $qty ? '' : wc_stock_amount( $qty ), $p->get_stock_status(), $level, $p->get_regular_price(), $p->get_sale_price(), wc_format_decimal( $price ), wc_format_decimal( $value, 2 ) );
                if ( $cogs ) {
                    $unit   = (float) $p->get_cogs_total_value();
                    $cost   = ( null !== $qty && $qty > 0 ) ? $qty * $unit : 0.0;
                    $row[]  = wc_format_decimal( $unit, 2 );
                    $row[]  = wc_format_decimal( $cost, 2 );
                    $totals['cost'] += $cost;
                }
                $row[] = self::local( $last[ $id ] ?? 0 );
                self::row( $out, $row );
                $totals['qty']   += ( null !== $qty && $qty > 0 ) ? $qty : 0;
                $totals['value'] += $value;
            }
            self::free_memory();
        }
        $tail = array( '', '', '', 'TOTAL (positive stock only)', '', '', '', wc_stock_amount( $totals['qty'] ), '', '', '', '', '', wc_format_decimal( $totals['value'], 2 ) );
        if ( $cogs ) {
            $tail[] = '';
            $tail[] = wc_format_decimal( $totals['cost'], 2 );
        }
        self::row( $out, $tail );
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        RAR_WSO_Audit::add( 'export', sprintf( 'Stock valuation CSV (%d products)', count( $ids ) ) );
        exit;
    }

    /* ---------------- Stock movements ---------------- */

    public static function movements( $args ) {
        $out = self::open( 'stock-movements' );
        self::row( $out, array( 'Date', 'Product ID', 'SKU', 'Product', 'From', 'To', 'Change', 'Reason', 'Source', 'Order ID', 'By' ) );
        $n    = 0;
        $page = 1;
        do {
            $res = RAR_WSO_Log::query( array_merge( $args, array( 'page' => $page, 'per_page' => 1000 ) ) );
            foreach ( $res['items'] as $x ) {
                $delta = ( null === $x['from'] || null === $x['to'] ) ? '' : wc_stock_amount( $x['to'] - $x['from'] );
                self::row( $out, array( self::local( $x['time'] ), $x['product_id'], $x['sku'], $x['product'], null === $x['from'] ? '' : wc_stock_amount( $x['from'] ), null === $x['to'] ? '' : wc_stock_amount( $x['to'] ), $delta, $x['reason'], $x['source'], $x['order_id'] ? $x['order_id'] : '', $x['user'] ) );
                $n++;
            }
            $page++;
            self::free_memory();
        } while ( $res['items'] && $n < $res['total'] && $n < self::MAX_ROWS );
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        RAR_WSO_Audit::add( 'export', sprintf( 'Stock movements CSV (%d rows)', $n ) );
        exit;
    }

    /* ---------------- Staff orders ---------------- */

    public static function orders( $args ) {
        $out = self::open( 'staff-orders' );
        self::row( $out, array( 'Order', 'Order ID', 'Date', 'Status', 'Staff', 'Branch', 'Customer', 'Phone', 'District', 'City / town', 'Lines', 'Units', 'Items subtotal', 'Discount', 'Shipping', 'Tax', 'Order total', 'Refunded', 'Net total', 'Payment', 'Rates changed' ) );
        $n      = 0;
        $page   = 1;
        $names  = array();
        $states = WC()->countries ? (array) WC()->countries->get_states( 'BD' ) : array();
        do {
            $res = RAR_WSO_Reports::staff_orders( array_merge( $args, array( 'page' => $page, 'per_page' => 200 ) ) );
            foreach ( $res['ids'] as $id ) {
                $o = wc_get_order( $id );
                if ( ! $o ) {
                    continue;
                }
                $uid = (int) $o->get_meta( '_rar_wso_created_by' );
                if ( ! isset( $names[ $uid ] ) ) {
                    $u             = get_userdata( $uid );
                    $names[ $uid ] = array( $u ? $u->display_name : '#' . $uid, (string) get_user_meta( $uid, 'rar_wso_branch', true ) );
                }
                $units   = 0;
                $changed = false;
                foreach ( $o->get_items() as $item ) {
                    $units  += (float) $item->get_quantity();
                    $changed = $changed || '' !== (string) $item->get_meta( '_rar_wso_price_override', true );
                }
                $neg = 0.0;
                foreach ( $o->get_fees() as $fee ) {
                    if ( (float) $fee->get_total() < 0 ) {
                        $neg += abs( (float) $fee->get_total() );
                    }
                }
                $state   = $o->get_shipping_state() ? $o->get_shipping_state() : $o->get_billing_state();
                $created = $o->get_date_created();
                $refund  = (float) $o->get_total_refunded();
                self::row(
                    $out,
                    array(
                        '#' . $o->get_order_number(),
                        $o->get_id(),
                        $created ? wp_date( 'Y-m-d H:i', $created->getTimestamp() ) : '',
                        wc_get_order_status_name( $o->get_status() ),
                        $names[ $uid ][0],
                        $names[ $uid ][1],
                        trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
                        $o->get_billing_phone(),
                        isset( $states[ $state ] ) ? wp_strip_all_tags( $states[ $state ] ) : $state,
                        $o->get_shipping_city() ? $o->get_shipping_city() : $o->get_billing_city(),
                        count( $o->get_items() ),
                        wc_stock_amount( $units ),
                        wc_format_decimal( $o->get_subtotal(), 2 ),
                        wc_format_decimal( (float) $o->get_discount_total() + $neg, 2 ),
                        wc_format_decimal( $o->get_shipping_total(), 2 ),
                        wc_format_decimal( $o->get_total_tax(), 2 ),
                        wc_format_decimal( $o->get_total(), 2 ),
                        wc_format_decimal( $refund, 2 ),
                        wc_format_decimal( (float) $o->get_total() - $refund, 2 ),
                        $o->get_payment_method_title(),
                        $changed ? 'yes' : 'no',
                    )
                );
                $n++;
            }
            $page++;
            self::free_memory();
        } while ( $res['ids'] && $n < $res['total'] && $n < self::MAX_ROWS );
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        RAR_WSO_Audit::add( 'export', sprintf( 'Staff orders CSV (%d orders)', $n ) );
        exit;
    }

    /* ---------------- Audit log ---------------- */

    public static function audit( $args ) {
        $out = self::open( 'audit-log' );
        self::row( $out, array( 'Date', 'Event', 'Details', 'By', 'IP address' ) );
        $n    = 0;
        $page = 1;
        do {
            $res = RAR_WSO_Audit::query( array_merge( $args, array( 'page' => $page, 'per_page' => 200 ) ) );
            foreach ( $res['items'] as $x ) {
                self::row( $out, array( self::local( $x['time'] ), $x['label'], $x['message'], $x['user'], $x['ip'] ) );
                $n++;
            }
            $page++;
        } while ( $res['items'] && $n < $res['total'] && $n < self::MAX_ROWS );
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        RAR_WSO_Audit::add( 'export', sprintf( 'Audit log CSV (%d rows)', $n ) );
        exit;
    }

    /* ---------------- Settings backup ---------------- */

    public static function settings_json() {
        while ( ob_get_level() > 0 ) {
            @ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'rar-wso-settings-' . wp_parse_url( home_url(), PHP_URL_HOST ) . '-' . wp_date( 'Y-m-d' ) . '.json' ) . '"' );
        echo wp_json_encode(
            array(
                'plugin'   => 'rar-woo-stock-order',
                'version'  => RAR_WSO_VERSION,
                'site'     => home_url( '/' ),
                'exported' => gmdate( 'c' ),
                'settings' => RAR_WSO_Plugin::settings(),
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        RAR_WSO_Audit::add( 'export', 'Settings backup (JSON)' );
        exit;
    }

    /**
     * Validates a settings backup. Returns the settings array or a WP_Error.
     * The values still go through the normal settings sanitizer when saved.
     */
    public static function parse_settings( $json ) {
        $data = json_decode( (string) $json, true );
        if ( ! is_array( $data ) || 'rar-woo-stock-order' !== ( $data['plugin'] ?? '' ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
            return new WP_Error( 'bad_file', __( 'This is not a RAR Woo Stock & Order settings file.', 'rar-woo-stock-order' ) );
        }
        $settings = array_intersect_key( $data['settings'], RAR_WSO_Plugin::defaults() );
        if ( ! $settings ) {
            return new WP_Error( 'empty', __( 'The file has no settings in it.', 'rar-woo-stock-order' ) );
        }
        foreach ( $settings as $k => $v ) {
            if ( ! is_scalar( $v ) ) {
                return new WP_Error( 'bad_value', __( 'The settings file is damaged.', 'rar-woo-stock-order' ) );
            }
            $settings[ $k ] = (string) $v;
        }
        return array_merge( RAR_WSO_Plugin::settings(), $settings );
    }

    /* ---------------- System report ---------------- */

    public static function system_report() {
        global $wpdb;
        $s     = RAR_WSO_Plugin::settings();
        $lines = array(
            '### RAR Woo Stock & Order — system report',
            'Plugin: ' . RAR_WSO_VERSION . ' (log DB ' . get_option( 'rar_wso_log_db' ) . ')',
            'Site: ' . home_url( '/' ),
            'Staff app: ' . RAR_WSO_Plugin::staff_url() . ' (' . ( 'yes' === $s['enabled'] ? 'on' : 'off' ) . ')',
            'WordPress: ' . get_bloginfo( 'version' ) . ( is_multisite() ? ' multisite' : '' ),
            'WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' · HPOS ' . ( RAR_WSO_Reports::hpos() ? 'on' : 'off' ) . ' · stock management ' . get_option( 'woocommerce_manage_stock' ),
            'PHP: ' . PHP_VERSION . ' · memory ' . ini_get( 'memory_limit' ) . ' · max execution ' . ini_get( 'max_execution_time' ) . 's',
            'Database: ' . $wpdb->db_server_info() . ' · named locks ' . ( RAR_WSO_Lock::supported() ? 'yes' : 'no' ),
            'Object cache: ' . ( wp_using_ext_object_cache() ? 'persistent' : 'none' ) . ' · WP-Cron ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'server cron' : 'visitor triggered' ),
            'Timezone: ' . wp_timezone_string() . ' · currency ' . get_woocommerce_currency(),
            'Permalinks: ' . ( get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'plain' ),
            'Theme: ' . wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ),
            'Active plugins:',
        );
        foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
            $path = WP_PLUGIN_DIR . '/' . $file;
            $data = file_exists( $path ) ? get_file_data( $path, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) ) : array( 'Name' => $file, 'Version' => '' );
            $lines[] = '  - ' . ( $data['Name'] ? $data['Name'] : $file ) . ' ' . $data['Version'];
        }
        $lines[] = 'Settings: ' . wp_json_encode( array_diff_key( $s, array_flip( array( 'digest_email' ) ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return implode( "\n", $lines );
    }
}
