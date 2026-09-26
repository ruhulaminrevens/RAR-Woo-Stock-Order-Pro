<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Order input the staff member can fix (reported as HTTP 422, not a server error). */
class RAR_WSO_Input_Exception extends Exception {}

class RAR_WSO_Ajax {
    /** Most lines accepted in one staff order. */
    const MAX_LINES = 100;

    /** Largest quantity for one order line / one stock value. */
    const MAX_QTY = 9999;

    /** Seconds a request waits for another request that is changing the same product's stock. */
    const LOCK_WAIT = 10;

    private static $stock_bumped = false;

    /** The running instance, for the admin screens (stock figures share one cache). */
    public static $instance = null;

    public function __construct() {
        self::$instance = $this;
        // Dashboard stock figures are cached briefly; any stock/product change starts a fresh cache.
        foreach ( array(
            'woocommerce_product_set_stock',
            'woocommerce_variation_set_stock',
            'woocommerce_product_set_stock_status',
            'woocommerce_variation_set_stock_status',
            'woocommerce_new_product',
            'woocommerce_update_product',
            'woocommerce_new_product_variation',
            'woocommerce_update_product_variation',
            'woocommerce_delete_product',
            'woocommerce_trash_product',
            'woocommerce_delete_product_variation',
            'woocommerce_trash_product_variation',
            'update_option_rar_wso_settings',
        ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'bump_stock' ) );
        }

        foreach ( array(
            'stats'        => 'stats',
            'report'       => 'report',
            'products'     => 'products',
            'stock_list'   => 'stock_list',
            'stock_update' => 'stock_update',
            'stock_bulk'   => 'stock_bulk',
            'stock_log'    => 'stock_log',
            'orders'       => 'orders',
            'order_detail' => 'order_detail',
            'order_status' => 'order_status',
            'create_order' => 'create_order',
            'customer'     => 'customer',
        ) as $action => $method ) {
            add_action( 'wp_ajax_rar_wso_' . $action, array( $this, $method ) );
        }

        // Session keep-alive for the installed phone app: returns a fresh nonce
        // while the login cookie is still valid, so an app left in the background
        // for hours keeps working without a manual reload.
        add_action( 'wp_ajax_rar_wso_refresh', array( $this, 'refresh' ) );
        add_action( 'wp_ajax_nopriv_rar_wso_refresh', array( $this, 'refresh' ) );
    }

    public function refresh() {
        nocache_headers();
        $settings = RAR_WSO_Plugin::settings();
        if ( 'yes' !== $settings['enabled'] ) {
            wp_send_json_error( array( 'message' => __( 'The staff app is turned off in WooCommerce → Stock & Order.', 'rar-woo-stock-order' ) ), 403 );
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Please sign in again.', 'rar-woo-stock-order' ), 'login' => true ), 401 );
        }
        if ( ! RAR_WSO_Plugin::can( 'rar_wso_access' ) ) {
            $this->deny();
        }
        wp_send_json_success( array( 'nonce' => wp_create_nonce( 'rar_wso_nonce' ) ) );
    }

    private function guard( $cap = 'rar_wso_access' ) {
        nocache_headers();

        $settings = RAR_WSO_Plugin::settings();
        if ( 'yes' !== $settings['enabled'] ) {
            wp_send_json_error( array( 'message' => __( 'The staff app is turned off in WooCommerce → Stock & Order.', 'rar-woo-stock-order' ) ), 403 );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Please sign in again.', 'rar-woo-stock-order' ), 'login' => true ), 401 );
        }

        if ( ! check_ajax_referer( 'rar_wso_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Session refreshed. Please try again.', 'rar-woo-stock-order' ), 'nonce' => true ), 403 );
        }

        if ( ! RAR_WSO_Plugin::can( $cap ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to perform this action.', 'rar-woo-stock-order' ) ),
                403
            );
        }

        RAR_WSO_Security::touch_last_seen();
    }

    public static function bump_stock() {
        if ( self::$stock_bumped ) {
            return;
        }
        self::$stock_bumped = true;
        update_option( 'rar_wso_stock_ver', (string) microtime( true ), false );
    }

    /** Current stock quantity straight from the database (bypasses object caches). */
    public static function fresh_stock( $product_id ) {
        global $wpdb;
        wp_cache_delete( $product_id, 'post_meta' );
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' LIMIT 1", $product_id ) );
        return ( null === $value || '' === $value ) ? 0.0 : (float) $value;
    }

    private static function release_locks( $keys ) {
        foreach ( (array) $keys as $key ) {
            RAR_WSO_Lock::release( $key );
        }
    }

    private function busy() {
        wp_send_json_error(
            array(
                'message' => __( 'Another sale or stock update is changing this product right now. Please tap Save again in a moment.', 'rar-woo-stock-order' ),
                'busy'    => true,
            ),
            409
        );
    }

    private function deny() {
        wp_send_json_error(
            array( 'message' => __( 'You do not have permission to perform this action.', 'rar-woo-stock-order' ) ),
            403
        );
    }

    private function post( $key, $default = '' ) {
        return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    /* --------------------------------------------------------------------
     * Stock helpers
     * ------------------------------------------------------------------ */

    public static function level_for( $product ) {
        if ( $product->get_manage_stock() ) {
            $qty = (float) $product->get_stock_quantity();
            if ( $qty <= 0 ) {
                return 'out';
            }
            return $qty <= RAR_WSO_Plugin::low_threshold() ? 'low' : 'ok';
        }
        return 'outofstock' === $product->get_stock_status() ? 'out' : 'untracked';
    }

    private function product_payload( $product, $last = null ) {
        if ( ! $product ) {
            return null;
        }

        $image_id = $product->get_image_id();
        if ( ! $image_id && $product->is_type( 'variation' ) ) {
            $parent   = wc_get_product( $product->get_parent_id() );
            $image_id = $parent ? $parent->get_image_id() : 0;
        }
        $image = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
        $name  = $product->get_name();

        if ( $product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $name = $parent->get_name() . ' — ' . wc_get_formatted_variation( $product, true, false, false );
            }
        }

        $cat_source = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
        $terms      = get_the_terms( $cat_source, 'product_cat' );
        $category   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
        $stock      = $product->get_manage_stock() ? $product->get_stock_quantity() : null;
        // A variation whose stock is kept on the parent product shares one quantity with its siblings.
        $shared     = $product->is_type( 'variation' ) && 'parent' === $product->get_manage_stock();

        return array(
            'shared_stock'  => $shared,
            'stock_owner'   => (int) $product->get_stock_managed_by_id(),
            'list_price'    => (float) $product->get_price(),
            'id'            => $product->get_id(),
            'name'          => wp_strip_all_tags( $name ),
            'sku'           => $product->get_sku(),
            'price'         => (float) wc_get_price_to_display( $product ),
            'regular_price' => $product->get_regular_price() === '' ? null : (float) wc_get_price_to_display(
                $product,
                array( 'price' => (float) $product->get_regular_price() )
            ),
            'sale_price'    => $product->get_sale_price() === '' ? null : (float) wc_get_price_to_display(
                $product,
                array( 'price' => (float) $product->get_sale_price() )
            ),
            'stock_qty'     => is_null( $stock ) ? '' : (float) $stock,
            'manage_stock'  => (bool) $product->get_manage_stock(),
            'stock_status'  => $product->get_stock_status(),
            'backorders'    => (bool) $product->backorders_allowed(),
            'level'         => self::level_for( $product ),
            'category'      => wp_specialchars_decode( $category, ENT_QUOTES ),
            'image'         => $image,
            'type'          => $product->get_type(),
            'updated'       => $last ? (int) $last : ( $product->get_date_modified() ? $product->get_date_modified()->getTimestamp() : 0 ),
            'edit_url'      => current_user_can( 'edit_products' ) ? get_edit_post_link( $cat_source, 'raw' ) : '',
        );
    }

    /** Published, stock-holding products and variations (variable parents, grouped and external excluded). */
    public function product_base_sql() {
        global $wpdb;
        $lookup = $wpdb->wc_product_meta_lookup;

        return "FROM {$wpdb->posts} p
            INNER JOIN {$lookup} l ON l.product_id = p.ID
            LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent
            WHERE p.post_status = 'publish'
              AND (
                ( p.post_type = 'product'
                  AND NOT EXISTS ( SELECT 1 FROM {$wpdb->posts} c WHERE c.post_parent = p.ID AND c.post_type = 'product_variation' AND c.post_status = 'publish' )
                  AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr
                        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
                        INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug IN ('grouped','external')
                        WHERE tr.object_id = p.ID ) )
                OR ( p.post_type = 'product_variation' AND pp.post_status = 'publish' )
              )";
    }

    public function level_sql( $level ) {
        $t = (int) RAR_WSO_Plugin::low_threshold();
        switch ( $level ) {
            case 'ok':
                return "l.stock_quantity IS NOT NULL AND l.stock_quantity > {$t}";
            case 'low':
                return "l.stock_quantity IS NOT NULL AND l.stock_quantity > 0 AND l.stock_quantity <= {$t}";
            case 'out':
                return "((l.stock_quantity IS NOT NULL AND l.stock_quantity <= 0) OR (l.stock_quantity IS NULL AND l.stock_status = 'outofstock'))";
            case 'untracked':
                return "(l.stock_quantity IS NULL AND l.stock_status <> 'outofstock')";
            case 'live':
                return "NOT ((l.stock_quantity IS NOT NULL AND l.stock_quantity <= 0) OR (l.stock_quantity IS NULL AND l.stock_status = 'outofstock'))";
        }
        return '1=1';
    }

    private function filter_sql( $search, $category ) {
        global $wpdb;
        $sql = '';
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $sql .= $wpdb->prepare( ' AND (p.post_title LIKE %s OR l.sku LIKE %s OR pp.post_title LIKE %s)', $like, $like, $like );
        }
        if ( $category ) {
            $sql .= $wpdb->prepare(
                " AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr2
                    INNER JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_taxonomy_id = tr2.term_taxonomy_id AND tt2.taxonomy = 'product_cat'
                    WHERE tt2.term_id = %d AND tr2.object_id = IF(p.post_type = 'product_variation', p.post_parent, p.ID) )",
                $category
            );
        }
        return $sql;
    }

    /**
     * Unfiltered dashboard stock figures, cached for up to 2 minutes and dropped as soon as
     * any stock or product changes. Several phones refreshing the dashboard every minute
     * no longer re-run the full catalog queries each time.
     */
    public function dashboard_stock() {
        $ver = (string) get_option( 'rar_wso_stock_ver', '0' ) . '|' . RAR_WSO_Plugin::low_threshold() . '|' . RAR_WSO_VERSION;
        $hit = get_transient( 'rar_wso_c_stock' );
        if ( is_array( $hit ) && ( $hit['ver'] ?? '' ) === $ver && time() - (int) ( $hit['at'] ?? 0 ) < 2 * MINUTE_IN_SECONDS ) {
            return $hit['data'];
        }
        $data = array(
            'counts' => $this->stock_counts(),
            'low'    => $this->stock_names( 'low' ),
            'out'    => $this->stock_names( 'out' ),
        );
        set_transient( 'rar_wso_c_stock', array( 'ver' => $ver, 'at' => time(), 'data' => $data ), 2 * MINUTE_IN_SECONDS );
        RAR_WSO_Plugin::set_attention( 'negative', $data['counts']['negative'] );
        return $data;
    }

    /** Level counts, units in hand and stock value for the given filters. */
    private function stock_counts( $search = '', $category = 0 ) {
        global $wpdb;
        $base = $this->product_base_sql() . $this->filter_sql( $search, $category );
        $sql  = 'SELECT COUNT(*) AS total_count,
            SUM(CASE WHEN ' . $this->level_sql( 'ok' ) . ' THEN 1 ELSE 0 END) AS ok,
            SUM(CASE WHEN ' . $this->level_sql( 'low' ) . ' THEN 1 ELSE 0 END) AS low,
            SUM(CASE WHEN ' . $this->level_sql( 'out' ) . ' THEN 1 ELSE 0 END) AS out_count,
            SUM(CASE WHEN ' . $this->level_sql( 'untracked' ) . ' THEN 1 ELSE 0 END) AS untracked,
            SUM(CASE WHEN l.stock_quantity < 0 THEN 1 ELSE 0 END) AS negative,
            SUM(CASE WHEN l.stock_quantity > 0 THEN l.stock_quantity ELSE 0 END) AS units,
            SUM(CASE WHEN l.stock_quantity > 0 THEN l.stock_quantity * l.max_price ELSE 0 END) AS stock_value ' . $base;
        $row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return array(
            'all'       => (int) ( $row['total_count'] ?? 0 ),
            'ok'        => (int) ( $row['ok'] ?? 0 ),
            'low'       => (int) ( $row['low'] ?? 0 ),
            'out'       => (int) ( $row['out_count'] ?? 0 ),
            'untracked' => (int) ( $row['untracked'] ?? 0 ),
            'negative'  => (int) ( $row['negative'] ?? 0 ),
            'live'      => (int) ( $row['total_count'] ?? 0 ) - (int) ( $row['out_count'] ?? 0 ),
            'units'     => (float) ( $row['units'] ?? 0 ),
            'value'     => (float) ( $row['stock_value'] ?? 0 ),
            'threshold' => RAR_WSO_Plugin::low_threshold(),
        );
    }

    public function stock_names( $level, $limit = 5 ) {
        global $wpdb;
        $sql = 'SELECT p.ID ' . $this->product_base_sql() . ' AND ' . $this->level_sql( $level ) . ' ORDER BY COALESCE(l.stock_quantity, 0) ASC, p.post_title ASC LIMIT ' . (int) $limit;
        $ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $out = array();
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( $product ) {
                $out[] = array( 'name' => wp_strip_all_tags( $product->get_name() ), 'qty' => $product->get_manage_stock() ? (float) $product->get_stock_quantity() : null );
            }
        }
        return $out;
    }

    /* --------------------------------------------------------------------
     * Order helpers
     * ------------------------------------------------------------------ */

    public static function live_statuses() {
        $live = array();
        foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
            $slug = substr( $key, 3 );
            if ( in_array( $slug, array( 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) || preg_match( '/(return|delivered|complete)/', $slug ) ) {
                continue;
            }
            $live[] = $slug;
        }
        return array_values( (array) apply_filters( 'rar_wso_live_statuses', $live ) );
    }

    /** Statuses staff may set from the app. "Refunded" is left to WooCommerce's own refund flow. */
    public static function changeable_statuses() {
        $out = self::all_statuses();
        unset( $out['refunded'] );
        return (array) apply_filters( 'rar_wso_changeable_statuses', $out );
    }

    public static function all_statuses() {
        $out = array();
        foreach ( wc_get_order_statuses() as $key => $label ) {
            $slug = substr( $key, 3 );
            if ( 'checkout-draft' !== $slug ) {
                $out[ $slug ] = $label;
            }
        }
        return $out;
    }

    private function district_label( $country, $state ) {
        if ( ! $state ) {
            return '';
        }
        $states = WC()->countries->get_states( $country ? $country : 'BD' );
        return isset( $states[ $state ] ) ? trim( wp_strip_all_tags( $states[ $state ] ) ) : $state;
    }

    private function order_light( $order ) {
        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        if ( '' === $name ) {
            $name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        }
        $city    = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
        $country = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
        $state   = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
        $created = $order->get_date_created();

        return array(
            'id'           => $order->get_id(),
            'number'       => (string) $order->get_order_number(),
            'time'         => $created ? $created->getTimestamp() : 0,
            'status'       => $order->get_status(),
            'status_label' => wc_get_order_status_name( $order->get_status() ),
            'name'         => '' !== $name ? $name : __( 'Guest', 'rar-woo-stock-order' ),
            'phone'        => $order->get_billing_phone(),
            'city'         => $city,
            'district'     => $this->district_label( $country, $state ),
            'items'        => (int) $order->get_item_count(),
            'total'        => (float) $order->get_total(),
            'payment'      => $order->get_payment_method_title(),
            'channel'      => RAR_WSO_Reports::channel_label( $order->get_created_via() ),
        );
    }

    private function order_full( $order ) {
        $data  = $this->order_light( $order );
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $qty     = (float) $item->get_quantity();
            $product = $item->get_product();
            $items[] = array(
                'name'  => wp_strip_all_tags( $item->get_name() ),
                'sku'   => $product ? $product->get_sku() : '',
                'qty'   => $qty,
                'price' => $qty ? (float) $item->get_subtotal() / $qty : 0.0,
                'total' => (float) $item->get_subtotal(),
            );
        }

        $neg_fees = 0.0;
        $pos_fees = 0.0;
        foreach ( $order->get_fees() as $fee ) {
            $amount = (float) $fee->get_total();
            if ( $amount < 0 ) {
                $neg_fees += abs( $amount );
            } else {
                $pos_fees += $amount;
            }
        }

        $address = trim( $order->get_shipping_address_1() ? $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() : $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
        $creator = (int) $order->get_meta( '_rar_wso_created_by' );
        $user    = $creator ? get_userdata( $creator ) : null;

        $notes = array();
        foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 25 ) ) as $note ) {
            $notes[] = array(
                'time'     => $note->date_created ? $note->date_created->getTimestamp() : 0,
                'text'     => wp_strip_all_tags( $note->content ),
                'by'       => 'system' === $note->added_by ? 'WooCommerce' : (string) $note->added_by,
                'customer' => (bool) $note->customer_note,
            );
        }

        return array_merge(
            $data,
            array(
                'email'      => $order->get_billing_email(),
                'address'    => $address,
                'lines'      => $items,
                'subtotal'   => (float) $order->get_subtotal(),
                'discount'   => (float) $order->get_discount_total() + $neg_fees,
                'fees'       => $pos_fees,
                'shipping'   => (float) $order->get_shipping_total(),
                'tax'        => (float) $order->get_total_tax(),
                'note'       => $order->get_customer_note(),
                'created_by' => $user ? $user->display_name : '',
                'notes'      => array_reverse( $notes ),
                'admin_url'  => current_user_can( 'manage_woocommerce' ) ? $order->get_edit_order_url() : '',
            )
        );
    }

    /* --------------------------------------------------------------------
     * Endpoints
     * ------------------------------------------------------------------ */

    public function stats() {
        $this->guard();

        $period = $this->post( 'period', 'today' );
        if ( ! in_array( $period, array( 'today', '7d', 'month' ), true ) ) {
            $period = 'today';
        }

        $p     = RAR_WSO_Reports::period_stats( $period );
        $today = 'today' === $period ? $p : RAR_WSO_Reports::period_stats( 'today' );
        $dash  = $this->dashboard_stock();
        $stock = $dash['counts'];

        $data = array(
            // v1.1 compatible fields.
            'orders'    => (int) $today['cur']['sale_orders'],
            'sales'     => (float) $today['cur']['sales'],
            'low_stock' => $stock['low'],
            'out_stock' => $stock['out'],
            // v1.2 dashboard.
            'period'    => $p,
            'stock'     => $stock,
            'sales7'    => RAR_WSO_Reports::last7(),
            'low_names' => $dash['low'],
            'out_names' => $dash['out'],
            'now'       => time(),
        );

        if ( RAR_WSO_Plugin::can_view_orders() ) {
            $recent = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC', 'status' => array_map( static function ( $s ) { return 'wc-' . $s; }, array_keys( self::all_statuses() ) ) ) );
            $data['recent'] = array_map( array( $this, 'order_light' ), $recent );
            $live_today = 0;
            $from       = RAR_WSO_Reports::today_start();
            $live       = self::live_statuses();
            foreach ( RAR_WSO_Reports::rows( $from, new DateTimeImmutable( 'now', RAR_WSO_Reports::tz() ) ) as $r ) {
                if ( in_array( $r['status'], $live, true ) ) {
                    $live_today++;
                }
            }
            $data['live_today'] = $live_today;
        }

        if ( RAR_WSO_Plugin::is_manager() ) {
            $data['manager'] = RAR_WSO_Reports::status_overview( self::live_statuses() );
        }

        wp_send_json_success( $data );
    }

    public function report() {
        $this->guard();
        if ( ! RAR_WSO_Plugin::is_manager() ) {
            $this->deny();
        }
        wp_send_json_success( RAR_WSO_Reports::report( absint( $this->post( 'days', '30' ) ) ) );
    }

    /** v1.1 search endpoint, kept for integrations. */
    public function products() {
        $this->guard();

        $search   = $this->post( 'search' );
        $products = array();

        if ( $search !== '' ) {
            $data_store = WC_Data_Store::load( 'product' );
            $ids        = $data_store->search_products( $search, '', true, false, 30 );

            foreach ( $ids as $id ) {
                $product = wc_get_product( $id );
                if ( $product && 'publish' === get_post_status( $product->get_id() ) ) {
                    $products[] = $product;
                }
            }
        } else {
            $products = wc_get_products(
                array(
                    'status'  => 'publish',
                    'limit'   => 30,
                    'orderby' => 'name',
                    'order'   => 'ASC',
                    'return'  => 'objects',
                )
            );
        }

        $seen  = array();
        $items = array();

        foreach ( $products as $product ) {
            if ( ! $product ) {
                continue;
            }

            $expand = array( $product );
            if ( $product->is_type( 'variable' ) ) {
                $expand = array();
                foreach ( $product->get_children() as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( $variation && 'publish' === get_post_status( $variation_id ) ) {
                        $expand[] = $variation;
                    }
                }
            }

            foreach ( $expand as $candidate ) {
                if ( isset( $seen[ $candidate->get_id() ] ) ) {
                    continue;
                }
                $seen[ $candidate->get_id() ] = true;
                $payload                      = $this->product_payload( $candidate );
                if ( $payload ) {
                    $items[] = $payload;
                }
                if ( count( $items ) >= 30 ) {
                    break 2;
                }
            }
        }

        wp_send_json_success( array( 'items' => $items ) );
    }

    public function stock_list() {
        $this->guard();
        global $wpdb;

        $level    = $this->post( 'level', 'all' );
        $search   = $this->post( 'search' );
        $category = absint( $this->post( 'category', '0' ) );
        $sort     = $this->post( 'sort', 'qty_asc' );
        $page     = max( 1, absint( $this->post( 'page', '1' ) ) );
        $per_page = min( 60, max( 5, absint( $this->post( 'per_page', '40' ) ) ) );

        if ( ! in_array( $level, array( 'all', 'ok', 'low', 'out', 'untracked', 'live' ), true ) ) {
            $level = 'all';
        }

        $order_by = array(
            'qty_asc'  => "COALESCE(l.stock_quantity, CASE WHEN l.stock_status = 'outofstock' THEN 0 ELSE 999999999 END) ASC, p.post_title ASC",
            'qty_desc' => "COALESCE(l.stock_quantity, CASE WHEN l.stock_status = 'outofstock' THEN 0 ELSE -1 END) DESC, p.post_title ASC",
            'name'     => 'p.post_title ASC',
            'value'    => '(COALESCE(l.stock_quantity, 0) * l.max_price) DESC, p.post_title ASC',
            'updated'  => 'p.post_modified_gmt DESC',
        );
        $order_sql = isset( $order_by[ $sort ] ) ? $order_by[ $sort ] : $order_by['qty_asc'];

        $where = $this->product_base_sql() . $this->filter_sql( $search, $category );
        if ( 'all' !== $level ) {
            $where .= ' AND ' . $this->level_sql( $level );
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $where );
        $ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT p.ID ' . $where . ' ORDER BY ' . $order_sql . ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page ) );
        // phpcs:enable

        _prime_post_caches( array_map( 'absint', $ids ) );
        $last  = RAR_WSO_Log::last_times( $ids );
        $items = array();
        foreach ( $ids as $id ) {
            $payload = $this->product_payload( wc_get_product( $id ), $last[ (int) $id ] ?? null );
            if ( $payload ) {
                $items[] = $payload;
            }
        }

        $data = array(
            'items'  => $items,
            'total'  => $total,
            'page'   => $page,
            'pages'  => max( 1, (int) ceil( $total / $per_page ) ),
            'counts' => $this->stock_counts( $search, $category ),
        );

        if ( '1' === $this->post( 'with_cats' ) ) {
            $terms              = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 200 ) );
            $data['categories'] = array();
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $data['categories'][] = array( 'id' => $term->term_id, 'name' => wp_specialchars_decode( $term->name, ENT_QUOTES ), 'count' => (int) $term->count );
                }
            }
            $data['moves_today'] = RAR_WSO_Log::moves_since( RAR_WSO_Reports::gmt( RAR_WSO_Reports::today_start() ) );
        }

        wp_send_json_success( $data );
    }

    /**
     * Sets a product's stock to an absolute quantity.
     *
     * - The product's stock is locked while it is read and written, so two phones (or a
     *   phone and a website sale) cannot overwrite each other's change.
     * - $expected is the quantity the staff member was looking at. If the real stock moved
     *   meanwhile (a website order, another staff update), the save is refused with HTTP 409
     *   and the current figure, instead of silently wiping out the other change.
     * - A variation whose stock is kept on the parent product updates the parent's shared
     *   stock; it is never converted into a separately stocked variation.
     *
     * @param int         $id       Product or variation ID.
     * @param string      $raw      New quantity.
     * @param string      $reason   Reason for the log.
     * @param string|null $expected Quantity shown on the phone ('' = not tracked, null = don't check).
     * @return array|WP_Error
     */
    private function apply_stock( $id, $raw, $reason, $expected = null ) {
        if ( ! $id || $raw === '' || ! is_numeric( $raw ) ) {
            return new WP_Error( 'invalid', __( 'A valid product and stock quantity are required.', 'rar-woo-stock-order' ), array( 'status' => 422 ) );
        }

        $product = wc_get_product( $id );
        if ( ! $product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
            return new WP_Error( 'missing', __( 'Product not found.', 'rar-woo-stock-order' ), array( 'status' => 404 ) );
        }

        $qty = (float) $raw;
        if ( $qty < 0 || $qty > 9999999 ) {
            return new WP_Error( 'invalid', __( 'Stock must be between 0 and 9,999,999.', 'rar-woo-stock-order' ), array( 'status' => 422 ) );
        }

        if ( 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
            return new WP_Error( 'stock_off', __( 'Stock management is turned off for the whole shop (WooCommerce → Settings → Products → Inventory → Enable stock management). Turn it on to save quantities.', 'rar-woo-stock-order' ), array( 'status' => 422 ) );
        }

        $shared = $product->is_type( 'variation' ) && 'parent' === $product->get_manage_stock();
        $owner  = $shared ? wc_get_product( $product->get_parent_id() ) : $product;
        if ( ! $owner ) {
            return new WP_Error( 'missing', __( 'Product not found.', 'rar-woo-stock-order' ), array( 'status' => 404 ) );
        }

        $locks = RAR_WSO_Lock::acquire_products( array( $owner->get_id() ), self::LOCK_WAIT );
        if ( false === $locks ) {
            return new WP_Error( 'busy', __( 'Another sale or stock update is changing this product right now. Please try again in a moment.', 'rar-woo-stock-order' ), array( 'status' => 409, 'busy' => true ) );
        }

        try {
            // Re-read under the lock: the product object may be older than the database.
            $tracked = (bool) $owner->get_manage_stock( 'edit' );
            $old     = $tracked ? self::fresh_stock( $owner->get_id() ) : null;

            if ( null !== $expected ) {
                $saw_untracked = '' === (string) $expected;
                $changed       = $saw_untracked ? null !== $old : ( null === $old || abs( $old - (float) $expected ) > 0.0001 );
                if ( $changed ) {
                    $fresh = $this->product_payload( wc_get_product( $id ) );
                    if ( $fresh && null !== $old ) {
                        $fresh['stock_qty']    = $old;
                        $fresh['manage_stock'] = true;
                        $fresh['level']        = $old <= 0 ? 'out' : ( $old <= RAR_WSO_Plugin::low_threshold() ? 'low' : 'ok' );
                    }
                    return new WP_Error(
                        'conflict',
                        sprintf(
                            /* translators: 1: product name, 2: quantity the phone showed, 3: current quantity */
                            __( '%1$s changed from %2$s to %3$s while you were editing (a sale or another update). Check the new figure and save again.', 'rar-woo-stock-order' ),
                            wp_strip_all_tags( $product->get_name() ),
                            $saw_untracked ? __( 'not tracked', 'rar-woo-stock-order' ) : wc_format_localized_decimal( (float) $expected ),
                            null === $old ? __( 'not tracked', 'rar-woo-stock-order' ) : wc_format_localized_decimal( $old )
                        ),
                        array( 'status' => 409, 'conflict' => true, 'product' => $fresh )
                    );
                }
            }

            if ( ! $tracked ) {
                $owner->set_manage_stock( true );
                $owner->save();
            }
            // Atomic database write + WooCommerce stock status sync, low-stock emails and hooks.
            wc_update_product_stock( $owner, $qty, 'set' );
        } finally {
            self::release_locks( $locks );
        }

        $reason = '' !== $reason ? $reason : __( 'Manual update', 'rar-woo-stock-order' );
        if ( $shared ) {
            $reason = mb_substr( $reason . ' · ' . __( 'shared stock of all variations', 'rar-woo-stock-order' ), 0, 120 );
        }

        update_post_meta(
            $owner->get_id(),
            '_rar_wso_last_stock_update',
            wp_json_encode(
                array(
                    'user_id' => get_current_user_id(),
                    'time'    => current_time( 'mysql' ),
                    'from'    => is_null( $old ) ? null : (float) $old,
                    'to'      => $qty,
                    'reason'  => $reason,
                )
            )
        );

        RAR_WSO_Log::add( $owner->get_id(), $old, $qty, $reason, 'staff' );

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info(
                sprintf( 'Stock update product #%d: %s -> %s by user #%d (%s)', $owner->get_id(), is_null( $old ) ? 'unmanaged' : $old, $qty, get_current_user_id(), $reason ),
                array( 'source' => 'rar-wso' )
            );
        }

        do_action( 'rar_wso_stock_updated', wc_get_product( $owner->get_id() ), $old, $qty, get_current_user_id() );

        return $this->product_payload( wc_get_product( $id ), time() );
    }

    /** '' / numeric string when the app sent the quantity it was showing, null otherwise. */
    private static function expected_value( $value ) {
        if ( null === $value ) {
            return null;
        }
        $value = trim( (string) $value );
        return ( '' === $value || is_numeric( $value ) ) ? $value : null;
    }

    private static function send_stock_error( WP_Error $error ) {
        $data = (array) $error->get_error_data();
        wp_send_json_error(
            array_filter(
                array(
                    'message'  => $error->get_error_message(),
                    'conflict' => ! empty( $data['conflict'] ),
                    'busy'     => ! empty( $data['busy'] ),
                    'product'  => $data['product'] ?? null,
                ),
                static function ( $v ) {
                    return null !== $v && false !== $v;
                }
            ),
            $data['status'] ?? 422
        );
    }

    public function stock_update() {
        $this->guard( 'rar_wso_manage_stock' );

        $id       = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw      = isset( $_POST['qty'] ) ? wc_format_decimal( wp_unslash( $_POST['qty'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $expected = isset( $_POST['expected'] ) ? self::expected_value( sanitize_text_field( wp_unslash( $_POST['expected'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $result   = $this->apply_stock( $id, $raw, $this->post( 'reason' ), $expected );

        if ( is_wp_error( $result ) ) {
            self::send_stock_error( $result );
        }

        wp_send_json_success(
            array(
                'message' => __( 'Stock updated.', 'rar-woo-stock-order' ),
                'product' => $result,
            )
        );
    }

    public function stock_bulk() {
        $this->guard( 'rar_wso_manage_stock' );

        $items  = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $reason = $this->post( 'reason' );

        if ( ! is_array( $items ) || ! $items || count( $items ) > 100 ) {
            wp_send_json_error( array( 'message' => __( 'Send between 1 and 100 stock changes at a time.', 'rar-woo-stock-order' ) ), 422 );
        }

        $updated = array();
        $errors  = array();
        foreach ( $items as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id       = absint( $row['id'] ?? 0 );
            $expected = array_key_exists( 'expected', $row ) ? self::expected_value( is_scalar( $row['expected'] ) ? (string) $row['expected'] : null ) : null;
            $result   = $this->apply_stock( $id, wc_format_decimal( is_scalar( $row['qty'] ?? '' ) ? (string) ( $row['qty'] ?? '' ) : '' ), $reason, $expected );
            if ( is_wp_error( $result ) ) {
                $data     = (array) $result->get_error_data();
                $errors[] = array(
                    'id'       => $id,
                    'message'  => $result->get_error_message(),
                    'conflict' => ! empty( $data['conflict'] ),
                    'product'  => $data['product'] ?? null,
                );
            } else {
                $updated[] = $result;
            }
        }

        wp_send_json_success(
            array(
                'message' => sprintf( _n( '%d product updated.', '%d products updated.', count( $updated ), 'rar-woo-stock-order' ), count( $updated ) ),
                'updated' => $updated,
                'errors'  => $errors,
            )
        );
    }

    public function stock_log() {
        $this->guard( 'rar_wso_manage_stock' );
        wp_send_json_success(
            RAR_WSO_Log::query(
                array(
                    'product_id' => absint( $this->post( 'product_id', '0' ) ),
                    'search'     => $this->post( 'search' ),
                    'page'       => absint( $this->post( 'page', '1' ) ),
                    'per_page'   => 40,
                )
            )
        );
    }

    public function orders() {
        $this->guard();
        if ( ! RAR_WSO_Plugin::can_view_orders() ) {
            $this->deny();
        }

        $manager  = RAR_WSO_Plugin::is_manager();
        $scope    = $this->post( 'scope', 'today' );
        $filter   = $this->post( 'status', 'all' );
        $search   = $this->post( 'search' );
        $page     = max( 1, absint( $this->post( 'page', '1' ) ) );
        $per_page = min( 50, max( 5, absint( $this->post( 'per_page', '30' ) ) ) );
        $oldest   = 'old' === $this->post( 'sort', 'new' );

        if ( ! in_array( $scope, array( 'today', '7d', 'month', 'all', 'stale' ), true ) || ( ! $manager && in_array( $scope, array( 'all', 'stale' ), true ) ) ) {
            $scope = 'today';
        }

        $all  = self::all_statuses();
        $live = self::live_statuses();

        // The base set the chips count over: a date scope plus an optional live/void/sale group.
        $group = 'all';
        if ( in_array( $filter, array( 'live', 'void', 'sale' ), true ) ) {
            $group  = $filter;
            $filter = 'all';
        }
        $base_statuses = array_keys( $all );
        if ( 'live' === $group ) {
            $base_statuses = $live;
        } elseif ( 'void' === $group ) {
            $base_statuses = array_values( array_filter( $base_statuses, array( 'RAR_WSO_Reports', 'is_return' ) ) );
        } elseif ( 'sale' === $group ) {
            $base_statuses = array_values( array_filter( $base_statuses, array( 'RAR_WSO_Reports', 'is_sale' ) ) );
        }
        if ( 'stale' === $scope ) {
            $base_statuses = $live;
        }

        $from = null;
        $to   = new DateTimeImmutable( 'now', RAR_WSO_Reports::tz() );
        if ( in_array( $scope, array( 'today', '7d', 'month' ), true ) ) {
            $p    = RAR_WSO_Reports::period( $scope );
            $from = $p['from'];
        }
        if ( 'stale' === $scope ) {
            $to = $to->modify( '-24 hours' );
        }

        // Chip counts.
        $counts = array();
        if ( null === $from ) {
            $overview = RAR_WSO_Reports::status_overview( $live );
            foreach ( $base_statuses as $s ) {
                $counts[ $s ] = (int) ( $overview['by_status'][ $s ][ 'stale' === $scope ? 'stale' : 'count' ] ?? 0 );
            }
        } else {
            $rows = RAR_WSO_Reports::rows( $from, $to );
            foreach ( $base_statuses as $s ) {
                $counts[ $s ] = 0;
            }
            foreach ( $rows as $r ) {
                if ( isset( $counts[ $r['status'] ] ) ) {
                    $counts[ $r['status'] ]++;
                }
            }
        }

        $statuses = 'all' !== $filter && in_array( $filter, $base_statuses, true ) ? array( $filter ) : $base_statuses;
        $items    = array();
        $total    = 0;

        if ( $statuses ) {
            if ( '' !== $search ) {
                $ids = array_map( 'absint', (array) wc_order_search( $search ) );
                if ( ctype_digit( ltrim( $search, '#' ) ) ) {
                    $ids[] = absint( ltrim( $search, '#' ) );
                }
                // Order search does not match phone numbers in every storage mode; look them up exactly.
                $phone = self::normalize_bd_phone( $search );
                if ( '' !== $phone ) {
                    foreach ( array( $phone, '+88' . $phone, '88' . $phone ) as $variant ) {
                        $ids = array_merge( $ids, array_map( 'absint', (array) wc_get_orders( array( 'type' => 'shop_order', 'billing_phone' => $variant, 'limit' => 300, 'return' => 'ids' ) ) ) );
                    }
                }
                $ids   = array_slice( array_unique( array_filter( $ids ) ), 0, 300 );
                $found = array();
                foreach ( $ids as $id ) {
                    $order = wc_get_order( $id );
                    if ( ! $order || 'shop_order' !== $order->get_type() || ! in_array( $order->get_status(), $statuses, true ) ) {
                        continue;
                    }
                    $created = $order->get_date_created();
                    $ts      = $created ? $created->getTimestamp() : 0;
                    if ( ( $from && $ts < $from->getTimestamp() ) || $ts > $to->getTimestamp() ) {
                        continue;
                    }
                    $found[] = $order;
                }
                usort( $found, static function ( $a, $b ) use ( $oldest ) {
                    $x = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
                    $y = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
                    return $oldest ? $x <=> $y : $y <=> $x;
                } );
                $total = count( $found );
                $items = array_map( array( $this, 'order_light' ), array_slice( $found, ( $page - 1 ) * $per_page, $per_page ) );
            } else {
                $args = array(
                    'type'     => 'shop_order',
                    'status'   => array_map( static function ( $s ) { return 'wc-' . $s; }, $statuses ),
                    'limit'    => $per_page,
                    'paged'    => $page,
                    'paginate' => true,
                    'orderby'  => 'date',
                    'order'    => $oldest ? 'ASC' : 'DESC',
                );
                if ( $from ) {
                    $args['date_created'] = $from->getTimestamp() . '...' . $to->getTimestamp();
                } elseif ( 'stale' === $scope ) {
                    $args['date_created'] = '<' . $to->getTimestamp();
                }
                $result = wc_get_orders( $args );
                $total  = (int) $result->total;
                $items  = array_map( array( $this, 'order_light' ), $result->orders );
            }
        }

        wp_send_json_success(
            array(
                'items'  => $items,
                'total'  => $total,
                'page'   => $page,
                'pages'  => max( 1, (int) ceil( $total / $per_page ) ),
                'counts' => $counts,
            )
        );
    }

    private function get_shop_order( $id ) {
        $order = wc_get_order( $id );
        if ( ! $order || 'shop_order' !== $order->get_type() ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'rar-woo-stock-order' ) ), 404 );
        }
        return $order;
    }

    public function order_detail() {
        $this->guard();
        if ( ! RAR_WSO_Plugin::can_view_orders() ) {
            $this->deny();
        }
        $order = $this->get_shop_order( absint( $this->post( 'id', '0' ) ) );
        if ( ! self::staff_may_open( $order ) ) {
            wp_send_json_error( array( 'message' => __( 'Staff accounts can open orders from this month and the last 7 days, plus orders they created. Ask a Shop Manager for older orders.', 'rar-woo-stock-order' ) ), 403 );
        }
        wp_send_json_success( array( 'order' => $this->order_full( $order ) ) );
    }

    /**
     * Staff (view-only) see the same orders their lists show: this month / last 7 days,
     * plus any order they created themselves. Shop Managers see everything.
     */
    public static function staff_may_open( $order ) {
        if ( RAR_WSO_Plugin::is_manager() ) {
            return true;
        }
        if ( (int) $order->get_meta( '_rar_wso_created_by' ) === get_current_user_id() ) {
            return true;
        }
        $created = $order->get_date_created();
        $from    = min( RAR_WSO_Reports::period( 'month' )['from']->getTimestamp(), RAR_WSO_Reports::period( '7d' )['from']->getTimestamp() );
        return $created && $created->getTimestamp() >= $from;
    }

    /**
     * Stock each order line needs, summed per stock owner (a variation with parent-level
     * stock counts against the parent). Only lines that track stock and don't allow backorders.
     *
     * @param array<int, array{product:WC_Product, qty:float|int}> $lines
     * @return array<int, array{qty:float, name:string}>
     */
    private static function stock_need( $lines ) {
        $need = array();
        foreach ( $lines as $line ) {
            $product = $line['product'];
            if ( ! $product->managing_stock() || $product->backorders_allowed() ) {
                continue;
            }
            $owner_id = (int) $product->get_stock_managed_by_id();
            if ( ! isset( $need[ $owner_id ] ) ) {
                $owner             = $owner_id === $product->get_id() ? $product : wc_get_product( $owner_id );
                $need[ $owner_id ] = array( 'qty' => 0.0, 'name' => wp_strip_all_tags( $owner ? $owner->get_name() : $product->get_name() ) );
            }
            $need[ $owner_id ]['qty'] += (float) $line['qty'];
        }
        return $need;
    }

    /** Returns the first stock shortage (fresh from the database) or null. */
    private static function stock_shortage( $need ) {
        foreach ( $need as $owner_id => $row ) {
            $have = self::fresh_stock( $owner_id );
            if ( $row['qty'] > $have + 0.0001 ) {
                return sprintf(
                    /* translators: 1: quantity in stock, 2: product name, 3: quantity ordered */
                    __( 'Only %1$s unit(s) of %2$s are in stock, but %3$s were added to this order.', 'rar-woo-stock-order' ),
                    wc_format_localized_decimal( max( 0, $have ) ),
                    $row['name'],
                    wc_format_localized_decimal( $row['qty'] )
                );
            }
        }
        return null;
    }

    public function order_status() {
        $this->guard();
        if ( ! RAR_WSO_Plugin::is_manager() ) {
            $this->deny();
        }

        $order  = $this->get_shop_order( absint( $this->post( 'id', '0' ) ) );
        $status = sanitize_key( $this->post( 'status' ) );
        $status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

        if ( ! isset( self::all_statuses()[ $status ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown order status.', 'rar-woo-stock-order' ) ), 422 );
        }

        if ( ! isset( self::changeable_statuses()[ $status ] ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Refunds must be made from the WooCommerce order screen (Refund button), so the money, stock and accounts stay correct. Use Cancelled or Returned here instead.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        $from = $order->get_status();
        if ( $from !== $status ) {
            // Re-opening a cancelled/failed order takes the stock again: check it is still there.
            $locks = array();
            if ( in_array( $status, array( 'processing', 'completed', 'on-hold' ), true ) && ! $order->get_data_store()->get_stock_reduced( $order->get_id() ) && 'yes' === get_option( 'woocommerce_manage_stock' ) ) {
                $lines = array();
                foreach ( $order->get_items() as $item ) {
                    $product = $item->get_product();
                    if ( $product && ! $item->get_meta( '_reduced_stock', true ) ) {
                        $lines[] = array( 'product' => $product, 'qty' => (float) $item->get_quantity() );
                    }
                }
                $need  = self::stock_need( $lines );
                $locks = RAR_WSO_Lock::acquire_products( array_keys( $need ), self::LOCK_WAIT );
                if ( false === $locks ) {
                    $this->busy();
                }
                $short = self::stock_shortage( $need );
                if ( $short ) {
                    self::release_locks( $locks );
                    wp_send_json_error( array( 'message' => $short . ' ' . __( 'Add stock first, then change the status.', 'rar-woo-stock-order' ) ), 422 );
                }
            }
            try {
                $user = wp_get_current_user();
                $order->update_status(
                    $status,
                    sprintf( 'Status changed in RAR Woo Stock & Order by %s (#%d).', $user->display_name, $user->ID ),
                    true
                );
            } finally {
                self::release_locks( $locks );
            }
            do_action( 'rar_wso_order_status_changed', $order, $from, $status, get_current_user_id() );
        }

        $order = wc_get_order( $order->get_id() );
        wp_send_json_success(
            array(
                'message' => sprintf( __( 'Order #%1$s is now %2$s.', 'rar-woo-stock-order' ), $order->get_order_number(), wc_get_order_status_name( $order->get_status() ) ),
                'order'   => $this->order_light( $order ),
                'from'    => $from,
            )
        );
    }

    /** Returning-customer lookup by phone for the Create Order form. */
    public function customer() {
        $this->guard( 'rar_wso_create_orders' );

        $phone = self::normalize_bd_phone( $this->post( 'phone' ) );
        if ( '' === $phone ) {
            wp_send_json_success( array( 'found' => false ) );
        }

        $orders = array();
        foreach ( array( $phone, '+88' . $phone, '88' . $phone ) as $variant ) {
            foreach ( wc_get_orders( array( 'type' => 'shop_order', 'billing_phone' => $variant, 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) ) as $order ) {
                $orders[ $order->get_id() ] = $order;
            }
        }
        if ( ! $orders ) {
            wp_send_json_success( array( 'found' => false ) );
        }

        uasort( $orders, static function ( $a, $b ) {
            return ( $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0 ) <=> ( $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0 );
        } );
        $last    = reset( $orders );
        $light   = $this->order_light( $last );
        $address = trim( $last->get_shipping_address_1() ? $last->get_shipping_address_1() . ' ' . $last->get_shipping_address_2() : $last->get_billing_address_1() . ' ' . $last->get_billing_address_2() );

        wp_send_json_success(
            array(
                'found'    => true,
                'count'    => count( $orders ),
                'last'     => $light['time'],
                'name'     => $light['name'],
                'email'    => $last->get_billing_email(),
                'address'  => $address,
                'city'     => $light['city'],
                'district' => $light['district'],
            )
        );
    }

    /** Bangladesh mobile number: 11 digits, 013–019. Accepts +88 / 88 prefix and Bangla digits. */
    public static function normalize_bd_phone( $raw ) {
        $digits = strtr( (string) $raw, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
        $digits = preg_replace( '/\D+/', '', $digits );
        if ( 13 === strlen( $digits ) && 0 === strpos( $digits, '880' ) ) {
            $digits = substr( $digits, 2 );
        }
        return preg_match( '/^01[3-9]\d{8}$/', $digits ) ? $digits : '';
    }

    /** Every payment option the shop knows: built-in ones, extra ones from the settings, then the filter. */
    public static function payment_options() {
        $options = array(
            'cod'   => __( 'Cash on delivery', 'woocommerce' ),
            'bkash' => 'bKash',
            'nagad' => 'Nagad',
            'cash'  => __( 'Cash (paid at shop)', 'rar-woo-stock-order' ),
        );
        $settings = RAR_WSO_Plugin::settings();
        foreach ( (array) preg_split( '/\r\n|\r|\n/', (string) $settings['payment_extra'] ) as $label ) {
            $label = trim( sanitize_text_field( $label ) );
            $slug  = sanitize_key( 'x-' . sanitize_title( $label ) );
            // Non-English labels (e.g. Bangla) become long %-encoded slugs: use a stable hash instead.
            $key   = ( strlen( $slug ) > 40 || ! preg_match( '/^[\x20-\x7e]+$/', $label ) ) ? 'x-' . substr( md5( $label ), 0, 12 ) : $slug;
            if ( '' !== $label && 'x-' !== $key && ! isset( $options[ $key ] ) ) {
                $options[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 40 ) : substr( $label, 0, 40 );
            }
        }
        return (array) apply_filters( 'rar_wso_payment_options', $options );
    }

    /** The four built-in options can be switched off in the settings. */
    public static function builtin_payment_keys() {
        return array( 'cod', 'bkash', 'nagad', 'cash' );
    }

    /**
     * Options offered in the app: ticked built-in ones plus every extra method (extra
     * methods typed in the settings or added by the filter are removed by deleting them).
     * Falls back to all options if nothing would be left.
     */
    public static function enabled_payment_options() {
        $all      = self::payment_options();
        $settings = RAR_WSO_Plugin::settings();
        $on       = array_filter( array_map( 'sanitize_key', explode( ',', (string) $settings['payment_methods'] ) ) );
        $out      = array();
        foreach ( $all as $key => $label ) {
            if ( ! in_array( $key, self::builtin_payment_keys(), true ) || in_array( $key, $on, true ) ) {
                $out[ $key ] = $label;
            }
        }
        return $out ? $out : $all;
    }

    /** Payment option selected first on a new order. */
    public static function default_payment() {
        $enabled  = self::enabled_payment_options();
        $settings = RAR_WSO_Plugin::settings();
        $key      = sanitize_key( (string) $settings['payment_default'] );
        return isset( $enabled[ $key ] ) ? $key : (string) key( $enabled );
    }

    public function create_order() {
        $this->guard( 'rar_wso_create_orders' );

        $payload = isset( $_POST['payload'] ) ? json_decode( wp_unslash( $_POST['payload'] ), true ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! is_array( $payload ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order data.', 'rar-woo-stock-order' ) ), 422 );
        }

        $name       = sanitize_text_field( $payload['name'] ?? '' );
        $phone_raw  = sanitize_text_field( $payload['phone'] ?? '' );
        $email      = sanitize_email( $payload['email'] ?? '' );
        $address    = trim( preg_replace( '/\s*\n\s*/', ', ', sanitize_textarea_field( $payload['address'] ?? '' ) ) );
        $city       = sanitize_text_field( $payload['city'] ?? '' );
        $district   = sanitize_text_field( $payload['district'] ?? '' );
        $note       = sanitize_textarea_field( $payload['note'] ?? '' );
        $shipping   = max( 0, (float) wc_format_decimal( $payload['shipping'] ?? 0 ) );
        $disc_type  = 'percent' === ( $payload['discount_type'] ?? '' ) ? 'percent' : 'amount';
        $disc_value = max( 0, (float) wc_format_decimal( $payload['discount'] ?? 0 ) );
        $request_id = substr( sanitize_key( $payload['request_id'] ?? '' ), 0, 64 );
        $items      = isset( $payload['items'] ) && is_array( $payload['items'] ) ? array_values( $payload['items'] ) : array();

        if ( $name === '' || $phone_raw === '' || $address === '' || $city === '' || $district === '' || empty( $items ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Name, phone, address, town/city, district and at least one item are required.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        if ( count( $items ) > self::MAX_LINES ) {
            /* translators: %d maximum number of lines */
            wp_send_json_error( array( 'message' => sprintf( __( 'One order can have at most %d lines. Split it into two orders.', 'rar-woo-stock-order' ), self::MAX_LINES ) ), 422 );
        }

        $phone = self::normalize_bd_phone( $phone_raw );
        if ( '' === $phone ) {
            wp_send_json_error(
                array( 'message' => __( 'Enter a valid Bangladesh mobile number: 11 digits starting with 013–019.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        if ( $email && ! is_email( $email ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Please enter a valid email address.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        $district_code = $this->district_to_state_code( $district );
        if ( $district_code === '' ) {
            wp_send_json_error(
                array( 'message' => __( 'Please select a valid Bangladesh district.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        /*
         * 1) Same request ID = same order. A MySQL named lock (not add_option(), which is
         *    INSERT … ON DUPLICATE KEY UPDATE and can let two requests through) makes sure
         *    only one of two simultaneous retries gets past this point.
         */
        $dedupe_key = '';
        $req_lock   = '';
        if ( $request_id !== '' ) {
            $dedupe_key = 'rar_wso_req_' . md5( get_current_user_id() . '|' . $request_id );

            $existing_order = $this->find_request_order( $dedupe_key, $request_id );
            if ( $existing_order ) {
                $this->send_order_success( $existing_order, __( 'This order was already created. Showing the existing order instead of creating a duplicate.', 'rar-woo-stock-order' ), true );
            }

            $req_lock = 'req|' . get_current_user_id() . '|' . $request_id;
            if ( ! RAR_WSO_Lock::acquire( $req_lock, 0 ) ) {
                wp_send_json_error(
                    array( 'message' => __( 'This order is already being saved. Please wait a moment.', 'rar-woo-stock-order' ), 'busy' => true ),
                    409
                );
            }
            // A request that finished between our first check and taking the lock.
            $existing_order = $this->find_request_order( $dedupe_key, $request_id );
            if ( $existing_order ) {
                RAR_WSO_Lock::release( $req_lock );
                $this->send_order_success( $existing_order, __( 'This order was already created. Showing the existing order instead of creating a duplicate.', 'rar-woo-stock-order' ), true );
            }
        }

        $settings     = RAR_WSO_Plugin::settings();
        $can_override = 'yes' === $settings['allow_price_override'] && RAR_WSO_Plugin::can( 'rar_wso_adjust_price' );
        $shipping     = max(
            0,
            (float) apply_filters( 'rar_wso_shipping_total', $shipping, $payload, get_current_user_id() )
        );

        $payments = self::enabled_payment_options();
        $chosen   = sanitize_key( $payload['payment'] ?? '' );
        if ( ! isset( $payments[ $chosen ] ) ) {
            $chosen = self::default_payment();
        }

        /*
         * 2) Validate every line, the price and the discount limit BEFORE anything is saved,
         *    so a refused order never exists — not even for a moment — and other plugins
         *    (notifications, courier, pixels) never see a half-built or deleted order.
         */
        try {
            $lines = $this->prepare_lines( $items, $can_override );
        } catch ( RAR_WSO_Input_Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 422 );
        }

        $items_subtotal = 0.0;
        $list_subtotal  = 0.0;
        $price_changed  = false;
        foreach ( $lines as $line ) {
            $items_subtotal += $line['price'] * $line['qty'];
            $list_subtotal  += $line['base'] * $line['qty'];
            $price_changed   = $price_changed || $line['price'] < $line['base'] - 0.0001;
        }
        $decimals = wc_get_price_decimals();
        $discount = 'percent' === $disc_type
            ? round( $items_subtotal * min( 100, $disc_value ) / 100, $decimals )
            : min( $disc_value, $items_subtotal );

        // Lower item prices count toward the same limit as the discount, so the limit
        // cannot be bypassed by editing the rate instead of the discount box.
        $max_pct   = RAR_WSO_Plugin::max_discount_percent();
        $reduction = max( 0, $list_subtotal - $items_subtotal ) + $discount;
        if ( $reduction > 0.0001 && $list_subtotal > 0 && ( $reduction / $list_subtotal * 100 ) > $max_pct + 0.001 ) {
            $limit_amount = trim( html_entity_decode( wp_strip_all_tags( wc_price( $list_subtotal * $max_pct / 100 ) ), ENT_QUOTES, 'UTF-8' ), " \t\n\r\0\x0B\xC2\xA0" );
            if ( 0 >= $max_pct ) {
                $message = $price_changed
                    ? __( 'Your account cannot sell below the list price or give discounts. Ask a Shop Manager.', 'rar-woo-stock-order' )
                    : __( 'Your account cannot give discounts. Ask a Shop Manager.', 'rar-woo-stock-order' );
            } elseif ( $price_changed ) {
                $message = sprintf(
                    /* translators: 1: percent off list price, 2: max percent, 3: max amount */
                    __( 'Lower item rates and the discount together give %1$s%% off the list price. Your limit is %2$s%% (%3$s on this order). Ask a Shop Manager.', 'rar-woo-stock-order' ),
                    wc_format_localized_decimal( round( $reduction / $list_subtotal * 100, 1 ) ),
                    wc_format_localized_decimal( $max_pct ),
                    $limit_amount
                );
            } else {
                $message = sprintf(
                    /* translators: 1: max percent, 2: max amount */
                    __( 'Discount is above your limit of %1$s%% (%2$s for this order). Ask a Shop Manager for a bigger discount.', 'rar-woo-stock-order' ),
                    wc_format_localized_decimal( $max_pct ),
                    $limit_amount
                );
            }
            if ( $req_lock ) {
                RAR_WSO_Lock::release( $req_lock );
            }
            wp_send_json_error( array( 'message' => $message ), 422 );
        }

        /*
         * 3) Stock: lock every product this order takes stock from (in a fixed order, so two
         *    orders can't deadlock), then re-check the quantity straight from the database.
         *    Two phones selling the last unit at the same moment can no longer both succeed.
         */
        $need  = self::stock_need( $lines );
        $locks = RAR_WSO_Lock::acquire_products( array_keys( $need ), self::LOCK_WAIT );
        if ( false === $locks ) {
            if ( $req_lock ) {
                RAR_WSO_Lock::release( $req_lock );
            }
            $this->busy();
        }
        $short = self::stock_shortage( $need );
        if ( $short ) {
            self::release_locks( $locks );
            if ( $req_lock ) {
                RAR_WSO_Lock::release( $req_lock );
            }
            wp_send_json_error( array( 'message' => $short ), 422 );
        }

        $order = null;
        try {
            // 4) Build the complete order in memory; the first save happens with every
            //    item, address and total in place (so woocommerce_new_order sees a full order).
            $order = new WC_Order();
            $order->set_status( 'pending' );
            $order->set_created_via( 'rar-wso-staff' );
            $order->set_currency( get_woocommerce_currency() );
            $order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
            if ( class_exists( 'WC_Geolocation' ) ) {
                $order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
            }
            $order->set_customer_user_agent( function_exists( 'wc_get_user_agent' ) ? wc_get_user_agent() : '' );

            $parts   = preg_split( '/\s+/', trim( $name ), 2 );
            $billing = array(
                'first_name' => $parts[0] ?? $name,
                'last_name'  => $parts[1] ?? '',
                'phone'      => $phone,
                'email'      => $email,
                'address_1'  => $address,
                'city'       => $city,
                'state'      => $district_code,
                'country'    => 'BD',
            );
            $order->set_address( $billing, 'billing' );
            $order->set_address( $billing, 'shipping' );

            $incl_tax = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() && wc_prices_include_tax();
            foreach ( $lines as $line ) {
                // Rates are entered like catalogue prices; when those include tax, store the
                // line net of tax so calculate_totals() adds it back only once.
                $line_total = $incl_tax
                    ? (float) wc_get_price_excluding_tax( $line['product'], array( 'qty' => $line['qty'], 'price' => $line['price'] ) )
                    : $line['price'] * $line['qty'];
                $item = new WC_Order_Item_Product();
                $item->set_props(
                    array(
                        'product'  => $line['product'],
                        'quantity' => $line['qty'],
                        'subtotal' => $line_total,
                        'total'    => $line_total,
                    )
                );
                $item->set_backorder_meta();
                if ( abs( $line['price'] - $line['base'] ) > 0.0001 ) {
                    $item->add_meta_data( '_rar_wso_price_override', wc_format_decimal( $line['price'] ), true );
                    $item->add_meta_data( '_rar_wso_list_price', wc_format_decimal( $line['base'] ), true );
                }
                $order->add_item( $item );
            }

            if ( $discount > 0 ) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name(
                    'percent' === $disc_type
                        /* translators: %s percent */
                        ? sprintf( __( 'Discount (%s%%)', 'rar-woo-stock-order' ), wc_format_localized_decimal( min( 100, $disc_value ) ) )
                        : __( 'Discount', 'rar-woo-stock-order' )
                );
                $fee->set_amount( -$discount );
                $fee->set_total( -$discount );
                $fee->set_tax_status( 'none' );
                $order->add_item( $fee );
                $order->update_meta_data( '_rar_wso_discount', wc_format_decimal( $discount ) );
            }

            if ( $shipping > 0 ) {
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title( __( 'Staff order delivery', 'rar-woo-stock-order' ) );
                $shipping_item->set_method_id( 'rar_wso_manual_shipping' );
                $shipping_item->set_total( $shipping );
                $order->add_item( $shipping_item );
            }

            $payment_method = sanitize_key( apply_filters( 'rar_wso_payment_method', $chosen, $payload, $order ) );
            $payment_title  = $payments[ $payment_method ] ?? $payments[ $chosen ];
            if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
                $gateways = WC()->payment_gateways()->payment_gateways();
                if ( isset( $gateways[ $payment_method ] ) ) {
                    $payment_title = $gateways[ $payment_method ]->get_title();
                }
            }
            $payment_title = sanitize_text_field( apply_filters( 'rar_wso_payment_method_title', $payment_title, $payment_method, $payload, $order ) );

            $order->set_payment_method( $payment_method );
            $order->set_payment_method_title( $payment_title );
            $order->update_meta_data( '_rar_wso_created_by', get_current_user_id() );
            $order->update_meta_data( '_rar_wso_channel', 'staff-pwa' );
            if ( $request_id !== '' ) {
                $order->update_meta_data( '_rar_wso_request_id', $request_id );
            }
            if ( $note ) {
                $order->set_customer_note( $note );
            }

            // First save, with totals. Taxes (if the store uses them) are added right after.
            $order->calculate_totals( false );
            if ( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() ) {
                $order->calculate_totals( true );
            }

            // 5) Take the stock while the products are still locked, then let other orders in.
            //    The order is flagged as "stock reduced" only if WooCommerce really reduced it
            //    (a plugin may veto it through woocommerce_can_reduce_order_stock).
            if ( $need ) {
                wc_reduce_stock_levels( $order );
                foreach ( $order->get_items() as $item ) {
                    if ( $item->get_meta( '_reduced_stock', true ) ) {
                        $order->get_data_store()->set_stock_reduced( $order->get_id(), true );
                        break;
                    }
                }
            }
            self::release_locks( $locks );
            $locks = array();

            $user = wp_get_current_user();
            $order->add_order_note(
                sprintf( 'Created via RAR Woo Stock & Order by %s (#%d).', $user->display_name, $user->ID ),
                false,
                true
            );

            // 6) Final status. Emails and other plugins' status hooks run here, after the
            //    stock locks are released, so a slow mail server never holds up other orders.
            $target = self::order_status_setting( $settings['default_order_status'] );
            if ( 'pending' !== $target ) {
                $order->update_status( $target, __( 'Staff order created from RAR Woo Stock & Order.', 'rar-woo-stock-order' ), true );
            }
            // Products that were not stock-locked (backorders allowed) are reduced like WooCommerce does.
            wc_maybe_reduce_stock_levels( $order->get_id() );
        } catch ( Throwable $e ) {
            self::release_locks( $locks );
            if ( $order instanceof WC_Order && $order->get_id() ) {
                // Give back exactly what was taken (items carry _reduced_stock), then remove the order.
                $saved = wc_get_order( $order->get_id() );
                if ( $saved ) {
                    wc_increase_stock_levels( $saved );
                    $saved->delete( true );
                }
            }
            if ( $req_lock ) {
                RAR_WSO_Lock::release( $req_lock );
            }

            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error( 'Staff order creation failed: ' . $e->getMessage(), array( 'source' => 'rar-wso' ) );
            }

            wp_send_json_error( array( 'message' => $e->getMessage() ), $e instanceof RAR_WSO_Input_Exception ? 422 : 500 );
        }

        // The order is final from here on (emails sent, stock taken): nothing below may undo it.
        if ( $dedupe_key !== '' ) {
            set_transient( $dedupe_key, $order->get_id(), 15 * MINUTE_IN_SECONDS );
        }
        if ( $req_lock ) {
            RAR_WSO_Lock::release( $req_lock );
        }
        try {
            do_action( 'rar_wso_order_created', $order, $payload, get_current_user_id() );
        } catch ( Throwable $e ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error( sprintf( 'rar_wso_order_created hook failed for order #%d: %s', $order->get_id(), $e->getMessage() ), array( 'source' => 'rar-wso' ) );
            }
        }

        $this->send_order_success( wc_get_order( $order->get_id() ), __( 'Order created successfully.', 'rar-woo-stock-order' ), false );
    }

    /**
     * Checks and prices every requested line without saving anything.
     *
     * @return array<int, array{product:WC_Product, qty:int, price:float, base:float}>
     * @throws RAR_WSO_Input_Exception When a line can't be ordered.
     */
    private function prepare_lines( $items, $can_override ) {
        $lines = array();
        foreach ( $items as $raw ) {
            if ( ! is_array( $raw ) ) {
                throw new RAR_WSO_Input_Exception( __( 'Invalid order line.', 'rar-woo-stock-order' ) );
            }
            $product_id = absint( $raw['id'] ?? 0 );
            $qty        = max( 1, absint( $raw['qty'] ?? 1 ) );
            if ( $qty > self::MAX_QTY ) {
                /* translators: %d max quantity */
                throw new RAR_WSO_Input_Exception( sprintf( __( 'Quantity can be at most %d per line.', 'rar-woo-stock-order' ), self::MAX_QTY ) );
            }

            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
                /* translators: %d product ID */
                throw new RAR_WSO_Input_Exception( sprintf( __( 'Product #%d is not available for ordering.', 'rar-woo-stock-order' ), $product_id ) );
            }
            if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) {
                /* translators: %s product name */
                throw new RAR_WSO_Input_Exception( sprintf( __( '%s is out of stock.', 'rar-woo-stock-order' ), wp_strip_all_tags( $product->get_name() ) ) );
            }

            $base  = (float) $product->get_price();
            $price = $base;
            if ( $can_override && isset( $raw['price'] ) && is_numeric( $raw['price'] ) ) {
                $price = min( 99999999, max( 0, (float) wc_format_decimal( $raw['price'] ) ) );
            }

            $lines[] = array( 'product' => $product, 'qty' => $qty, 'price' => $price, 'base' => $base );
        }
        return $lines;
    }

    /** Status a new staff order moves to. Refunded / cancelled / failed / draft are never allowed. */
    public static function order_status_setting( $status ) {
        $status = sanitize_key( (string) $status );
        $status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
        if ( ! isset( wc_get_order_statuses()[ 'wc-' . $status ] ) || in_array( $status, array( 'refunded', 'cancelled', 'failed', 'checkout-draft' ), true ) ) {
            return 'processing';
        }
        return $status;
    }

    /** Finds an order already created for this staff request ID (transient first, then order meta). */
    private function find_request_order( $dedupe_key, $request_id ) {
        $id = absint( get_transient( $dedupe_key ) );
        if ( $id ) {
            $order = wc_get_order( $id );
            if ( $order ) {
                return $order;
            }
        }
        // Look the request ID up in the order storage that is actually active. (Legacy
        // post storage ignores wc_get_orders() meta_query, so query posts directly there.)
        $ids = array();
        if ( RAR_WSO_Reports::hpos() ) {
            $ids = wc_get_orders(
                array(
                    'limit'      => 5,
                    'type'       => 'shop_order',
                    'status'     => array_keys( wc_get_order_statuses() ),
                    'return'     => 'ids',
                    'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                        array( 'key' => '_rar_wso_request_id', 'value' => $request_id ),
                    ),
                )
            );
        } else {
            $ids = get_posts(
                array(
                    'post_type'        => 'shop_order',
                    'post_status'      => array_keys( wc_get_order_statuses() ),
                    'posts_per_page'   => 5,
                    'fields'           => 'ids',
                    'no_found_rows'    => true,
                    'suppress_filters' => true,
                    'meta_key'         => '_rar_wso_request_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_value'       => $request_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                )
            );
        }
        // Never trust the query alone: only return an order whose own meta matches.
        foreach ( (array) $ids as $id ) {
            $order = wc_get_order( $id );
            if (
                $order &&
                $request_id === (string) $order->get_meta( '_rar_wso_request_id' ) &&
                (int) $order->get_meta( '_rar_wso_created_by' ) === get_current_user_id()
            ) {
                return $order;
            }
        }
        return null;
    }

    private function send_order_success( $order, $message, $duplicate ) {
        wp_send_json_success(
            array(
                'message'   => $message,
                'order_id'  => $order->get_id(),
                'order_num' => $order->get_order_number(),
                'status'    => wc_get_order_status_name( $order->get_status() ),
                'total'     => wp_strip_all_tags( html_entity_decode( $order->get_formatted_order_total(), ENT_QUOTES, 'UTF-8' ) ),
                'admin_url' => current_user_can( 'manage_woocommerce' ) ? $order->get_edit_order_url() : '',
                'duplicate' => (bool) $duplicate,
                'order'     => $this->order_full( $order ),
            )
        );
    }

    private function district_to_state_code( $district ) {
        $states = WC()->countries->get_states( 'BD' );
        foreach ( (array) $states as $code => $label ) {
            if (
                0 === strcasecmp( trim( wp_strip_all_tags( $label ) ), trim( $district ) ) ||
                0 === strcasecmp( $code, trim( $district ) )
            ) {
                return $code;
            }
        }
        return '';
    }
}
