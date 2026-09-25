<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAR_WSO_Ajax {
    public function __construct() {
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

        return array(
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
    private function product_base_sql() {
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

    private function level_sql( $level ) {
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

    /** Level counts, units in hand and stock value for the given filters. */
    private function stock_counts( $search = '', $category = 0 ) {
        global $wpdb;
        $base = $this->product_base_sql() . $this->filter_sql( $search, $category );
        $sql  = 'SELECT COUNT(*) AS total_count,
            SUM(CASE WHEN ' . $this->level_sql( 'ok' ) . ' THEN 1 ELSE 0 END) AS ok,
            SUM(CASE WHEN ' . $this->level_sql( 'low' ) . ' THEN 1 ELSE 0 END) AS low,
            SUM(CASE WHEN ' . $this->level_sql( 'out' ) . ' THEN 1 ELSE 0 END) AS out_count,
            SUM(CASE WHEN ' . $this->level_sql( 'untracked' ) . ' THEN 1 ELSE 0 END) AS untracked,
            SUM(CASE WHEN l.stock_quantity > 0 THEN l.stock_quantity ELSE 0 END) AS units,
            SUM(CASE WHEN l.stock_quantity > 0 THEN l.stock_quantity * l.max_price ELSE 0 END) AS stock_value ' . $base;
        $row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return array(
            'all'       => (int) ( $row['total_count'] ?? 0 ),
            'ok'        => (int) ( $row['ok'] ?? 0 ),
            'low'       => (int) ( $row['low'] ?? 0 ),
            'out'       => (int) ( $row['out_count'] ?? 0 ),
            'untracked' => (int) ( $row['untracked'] ?? 0 ),
            'live'      => (int) ( $row['total_count'] ?? 0 ) - (int) ( $row['out_count'] ?? 0 ),
            'units'     => (float) ( $row['units'] ?? 0 ),
            'value'     => (float) ( $row['stock_value'] ?? 0 ),
            'threshold' => RAR_WSO_Plugin::low_threshold(),
        );
    }

    private function stock_names( $level, $limit = 5 ) {
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
        $stock = $this->stock_counts();

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
            'low_names' => $this->stock_names( 'low' ),
            'out_names' => $this->stock_names( 'out' ),
            'now'       => time(),
        );

        if ( RAR_WSO_Plugin::can_view_orders() ) {
            $recent = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC', 'status' => array_map( static function ( $s ) { return 'wc-' . $s; }, array_keys( self::all_statuses() ) ) ) );
            $data['recent'] = array_map( array( $this, 'order_light' ), $recent );
            $live_today = 0;
            $from       = RAR_WSO_Reports::today_start();
            foreach ( RAR_WSO_Reports::rows( $from, new DateTimeImmutable( 'now', RAR_WSO_Reports::tz() ) ) as $r ) {
                if ( in_array( $r['status'], self::live_statuses(), true ) ) {
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
     * @return array|WP_Error
     */
    private function apply_stock( $id, $raw, $reason ) {
        if ( ! $id || $raw === '' || ! is_numeric( $raw ) ) {
            return new WP_Error( 'invalid', __( 'A valid product and stock quantity are required.', 'rar-woo-stock-order' ), array( 'status' => 422 ) );
        }

        $product = wc_get_product( $id );
        if ( ! $product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
            return new WP_Error( 'missing', __( 'Product not found.', 'rar-woo-stock-order' ), array( 'status' => 404 ) );
        }

        $qty = max( 0, (float) $raw );
        $old = $product->get_manage_stock() ? $product->get_stock_quantity() : null;

        $product->set_manage_stock( true );
        $product->set_stock_quantity( $qty );
        $product->set_stock_status( $qty > 0 ? 'instock' : ( $product->backorders_allowed() ? 'onbackorder' : 'outofstock' ) );
        $product->save();

        $reason = '' !== $reason ? $reason : __( 'Manual update', 'rar-woo-stock-order' );

        update_post_meta(
            $id,
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

        RAR_WSO_Log::add( $id, $old, $qty, $reason, 'staff' );

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info(
                sprintf( 'Stock update product #%d: %s -> %s by user #%d (%s)', $id, is_null( $old ) ? 'unmanaged' : $old, $qty, get_current_user_id(), $reason ),
                array( 'source' => 'rar-wso' )
            );
        }

        do_action( 'rar_wso_stock_updated', $product, $old, $qty, get_current_user_id() );

        return $this->product_payload( wc_get_product( $id ), time() );
    }

    public function stock_update() {
        $this->guard( 'rar_wso_manage_stock' );

        $id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw    = isset( $_POST['qty'] ) ? wc_format_decimal( wp_unslash( $_POST['qty'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $result = $this->apply_stock( $id, $raw, $this->post( 'reason' ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), $result->get_error_data()['status'] ?? 422 );
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
            $id     = absint( $row['id'] ?? 0 );
            $result = $this->apply_stock( $id, wc_format_decimal( (string) ( $row['qty'] ?? '' ) ), $reason );
            if ( is_wp_error( $result ) ) {
                $errors[] = array( 'id' => $id, 'message' => $result->get_error_message() );
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
        wp_send_json_success( array( 'order' => $this->order_full( $order ) ) );
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

        $from = $order->get_status();
        if ( $from !== $status ) {
            $user = wp_get_current_user();
            $order->update_status(
                $status,
                sprintf( 'Status changed in RAR Woo Stock & Order by %s (#%d).', $user->display_name, $user->ID ),
                true
            );
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

    public static function payment_options() {
        return (array) apply_filters(
            'rar_wso_payment_options',
            array(
                'cod'   => __( 'Cash on delivery', 'woocommerce' ),
                'bkash' => 'bKash',
                'nagad' => 'Nagad',
                'cash'  => __( 'Cash (paid at shop)', 'rar-woo-stock-order' ),
            )
        );
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
        $request_id = sanitize_key( $payload['request_id'] ?? '' );
        $items      = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();

        if ( $name === '' || $phone_raw === '' || $address === '' || $city === '' || $district === '' || empty( $items ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Name, phone, address, town/city, district and at least one item are required.', 'rar-woo-stock-order' ) ),
                422
            );
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

        $dedupe_key = '';
        if ( $request_id !== '' ) {
            $dedupe_key = 'rar_wso_req_' . md5( get_current_user_id() . '|' . $request_id );
            $existing   = absint( get_transient( $dedupe_key ) );
            if ( $existing ) {
                $existing_order = wc_get_order( $existing );
                if ( $existing_order ) {
                    $this->send_order_success(
                        $existing_order,
                        __( 'This order was already created. Showing the existing order instead of creating a duplicate.', 'rar-woo-stock-order' ),
                        true
                    );
                }
            }
        }

        $settings     = RAR_WSO_Plugin::settings();
        $can_override = 'yes' === $settings['allow_price_override'] && RAR_WSO_Plugin::can( 'rar_wso_adjust_price' );
        $shipping     = max(
            0,
            (float) apply_filters( 'rar_wso_shipping_total', $shipping, $payload, get_current_user_id() )
        );

        $payments = self::payment_options();
        $chosen   = sanitize_key( $payload['payment'] ?? 'cod' );
        if ( ! isset( $payments[ $chosen ] ) ) {
            $chosen = 'cod';
        }

        try {
            $order = wc_create_order( array( 'status' => 'pending' ) );
            if ( is_wp_error( $order ) ) {
                throw new Exception( $order->get_error_message() );
            }

            $parts = preg_split( '/\s+/', trim( $name ), 2 );
            $first = $parts[0] ?? $name;
            $last  = $parts[1] ?? '';

            $billing = array(
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => $phone,
                'email'      => $email,
                'address_1'  => $address,
                'city'       => $city,
                'state'      => $district_code,
                'country'    => 'BD',
            );

            $order->set_address( $billing, 'billing' );
            $order->set_address( $billing, 'shipping' );

            $items_subtotal = 0.0;
            foreach ( $items as $raw ) {
                $product_id = absint( $raw['id'] ?? 0 );
                $qty        = max( 1, absint( $raw['qty'] ?? 1 ) );
                $product    = wc_get_product( $product_id );

                if ( ! $product || ! $product->is_purchasable() ) {
                    throw new Exception(
                        sprintf(
                            /* translators: %d product ID */
                            __( 'Product #%d is not available for ordering.', 'rar-woo-stock-order' ),
                            $product_id
                        )
                    );
                }

                if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) {
                    throw new Exception(
                        sprintf(
                            /* translators: %s product name */
                            __( '%s is out of stock.', 'rar-woo-stock-order' ),
                            wp_strip_all_tags( $product->get_name() )
                        )
                    );
                }

                $stock_qty = $product->get_stock_quantity();
                if (
                    $product->get_manage_stock() &&
                    null !== $stock_qty &&
                    $qty > (float) $stock_qty &&
                    ! $product->backorders_allowed()
                ) {
                    throw new Exception(
                        sprintf(
                            /* translators: 1: quantity, 2: product name */
                            __( 'Only %1$s unit(s) of %2$s are currently in stock.', 'rar-woo-stock-order' ),
                            wc_format_localized_decimal( $stock_qty ),
                            wp_strip_all_tags( $product->get_name() )
                        )
                    );
                }

                $base  = (float) $product->get_price();
                $price = $base;

                if ( $can_override && isset( $raw['price'] ) && is_numeric( $raw['price'] ) ) {
                    $price = max( 0, (float) wc_format_decimal( $raw['price'] ) );
                }

                $item_id = $order->add_product(
                    $product,
                    $qty,
                    array(
                        'subtotal' => $price * $qty,
                        'total'    => $price * $qty,
                    )
                );

                if ( ! $item_id ) {
                    throw new Exception( __( 'Could not add one of the selected products to the order.', 'rar-woo-stock-order' ) );
                }

                $items_subtotal += $price * $qty;

                if ( $can_override && abs( $price - $base ) > 0.0001 ) {
                    wc_add_order_item_meta( $item_id, '_rar_wso_price_override', wc_format_decimal( $price ), true );
                }
            }

            $discount = 'percent' === $disc_type
                ? round( $items_subtotal * min( 100, $disc_value ) / 100, wc_get_price_decimals() )
                : min( $disc_value, $items_subtotal );

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

            $payment_title = sanitize_text_field(
                apply_filters( 'rar_wso_payment_method_title', $payment_title, $payment_method, $payload, $order )
            );

            $order->set_payment_method( $payment_method );
            $order->set_payment_method_title( $payment_title );
            $order->set_created_via( 'rar-wso-staff' );
            $order->update_meta_data( '_rar_wso_created_by', get_current_user_id() );
            $order->update_meta_data( '_rar_wso_channel', 'staff-pwa' );

            if ( $request_id !== '' ) {
                $order->update_meta_data( '_rar_wso_request_id', $request_id );
            }

            if ( $note ) {
                $order->set_customer_note( $note );
            }

            $order->calculate_totals( true );
            $order->save();

            $user = wp_get_current_user();
            $order->add_order_note(
                sprintf(
                    'Created via RAR Woo Stock & Order by %s (#%d).',
                    $user->display_name,
                    $user->ID
                ),
                false,
                true
            );

            $target = sanitize_key( $settings['default_order_status'] );
            if ( ! isset( wc_get_order_statuses()[ 'wc-' . $target ] ) ) {
                $target = 'processing';
            }

            $order->update_status(
                $target,
                __( 'Staff order created from RAR Woo Stock & Order.', 'rar-woo-stock-order' ),
                true
            );

            wc_maybe_reduce_stock_levels( $order->get_id() );

            if ( $dedupe_key !== '' ) {
                set_transient( $dedupe_key, $order->get_id(), 15 * MINUTE_IN_SECONDS );
            }

            do_action( 'rar_wso_order_created', $order, $payload, get_current_user_id() );

            $this->send_order_success(
                wc_get_order( $order->get_id() ),
                __( 'Order created successfully.', 'rar-woo-stock-order' ),
                false
            );
        } catch ( Throwable $e ) {
            if ( isset( $order ) && $order instanceof WC_Order && $order->get_id() ) {
                $order->delete( true );
            }

            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    'Staff order creation failed: ' . $e->getMessage(),
                    array( 'source' => 'rar-wso' )
                );
            }

            wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
        }
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
