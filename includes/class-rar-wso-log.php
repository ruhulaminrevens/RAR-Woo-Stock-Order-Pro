<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stock movement history: manual staff updates plus WooCommerce order reductions/restocks.
 */
class RAR_WSO_Log {
    const DB_VERSION = '1';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rar_wso_stock_log';
    }

    public static function install() {
        global $wpdb;

        if ( get_option( 'rar_wso_log_db' ) === self::DB_VERSION && self::table_exists() ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                product_id bigint(20) unsigned NOT NULL DEFAULT 0,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                order_id bigint(20) unsigned NOT NULL DEFAULT 0,
                qty_from decimal(19,4) NULL,
                qty_to decimal(19,4) NULL,
                reason varchar(120) NOT NULL DEFAULT '',
                source varchar(20) NOT NULL DEFAULT 'staff',
                created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
                PRIMARY KEY  (id),
                KEY product_id (product_id),
                KEY created_gmt (created_gmt)
            ) {$collate};"
        );

        update_option( 'rar_wso_log_db', self::DB_VERSION, false );
    }

    private static function table_exists() {
        global $wpdb;
        $table = self::table();
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    public static function hooks() {
        add_action( 'woocommerce_reduce_order_item_stock', array( __CLASS__, 'on_reduce' ), 10, 3 );
        add_action( 'woocommerce_restore_order_item_stock', array( __CLASS__, 'on_restore' ), 10, 4 );
    }

    public static function add( $product_id, $from, $to, $reason, $source = 'staff', $order_id = 0, $user_id = null ) {
        global $wpdb;

        $wpdb->insert(
            self::table(),
            array(
                'product_id'  => absint( $product_id ),
                'user_id'     => null === $user_id ? get_current_user_id() : absint( $user_id ),
                'order_id'    => absint( $order_id ),
                'qty_from'    => is_null( $from ) ? null : (float) $from,
                'qty_to'      => is_null( $to ) ? null : (float) $to,
                'reason'      => mb_substr( sanitize_text_field( (string) $reason ), 0, 120 ),
                'source'      => sanitize_key( $source ),
                'created_gmt' => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%d', '%f', '%f', '%s', '%s', '%s' )
        );
    }

    public static function on_reduce( $item, $change, $order ) {
        if ( empty( $change['product'] ) || ! $order ) {
            return;
        }
        self::add(
            $change['product']->get_id(),
            isset( $change['from'] ) ? $change['from'] : null,
            isset( $change['to'] ) ? $change['to'] : null,
            sprintf( 'Order #%s sold', $order->get_order_number() ),
            'order',
            $order->get_id()
        );
    }

    public static function on_restore( $item, $new_stock, $old_stock, $order ) {
        if ( ! $item || ! is_callable( array( $item, 'get_product' ) ) || ! $order ) {
            return;
        }
        $product = $item->get_product();
        if ( ! $product ) {
            return;
        }
        self::add(
            $product->get_id(),
            $old_stock,
            $new_stock,
            sprintf( 'Order #%s restocked (%s)', $order->get_order_number(), wc_get_order_status_name( $order->get_status() ) ),
            'order',
            $order->get_id()
        );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function query( $args ) {
        global $wpdb;

        $table    = self::table();
        $page     = max( 1, absint( $args['page'] ?? 1 ) );
        $per_page = min( 100, max( 1, absint( $args['per_page'] ?? 40 ) ) );
        $where    = array( '1=1' );
        $params   = array();

        if ( ! empty( $args['product_id'] ) ) {
            $where[]  = 'l.product_id = %d';
            $params[] = absint( $args['product_id'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(p.post_title LIKE %s OR l.reason LIKE %s OR lk.sku LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $joins     = "LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id LEFT JOIN {$wpdb->wc_product_meta_lookup} lk ON lk.product_id = l.product_id";
        $count_sql = "SELECT COUNT(*) FROM {$table} l {$joins} WHERE {$where_sql}";
        $list_sql  = "SELECT l.* FROM {$table} l {$joins} WHERE {$where_sql} ORDER BY l.id DESC LIMIT %d OFFSET %d";

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
        $rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
        // phpcs:enable

        $users = array();
        $items = array();
        foreach ( (array) $rows as $row ) {
            $product = wc_get_product( (int) $row['product_id'] );
            $uid     = (int) $row['user_id'];
            if ( $uid && ! isset( $users[ $uid ] ) ) {
                $user          = get_userdata( $uid );
                $users[ $uid ] = $user ? $user->display_name : '#' . $uid;
            }
            $items[] = array(
                'id'       => (int) $row['id'],
                'product'  => $product ? wp_strip_all_tags( $product->get_name() ) : sprintf( 'Product #%d', $row['product_id'] ),
                'sku'      => $product ? $product->get_sku() : '',
                'from'     => is_null( $row['qty_from'] ) ? null : (float) $row['qty_from'],
                'to'       => is_null( $row['qty_to'] ) ? null : (float) $row['qty_to'],
                'reason'   => $row['reason'],
                'source'   => $row['source'],
                'order_id' => (int) $row['order_id'],
                'user'     => $uid ? $users[ $uid ] : ( 'order' === $row['source'] ? 'WooCommerce' : '' ),
                'time'     => strtotime( $row['created_gmt'] . ' UTC' ),
            );
        }

        return array( 'items' => $items, 'total' => $total );
    }

    /** Latest movement time (unix) per product, for "updated x ago". */
    public static function last_times( $ids ) {
        global $wpdb;
        $ids = array_filter( array_map( 'absint', (array) $ids ) );
        if ( ! $ids ) {
            return array();
        }
        $table = self::table();
        $in    = implode( ',', $ids );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT product_id, MAX(created_gmt) AS t FROM {$table} WHERE product_id IN ({$in}) GROUP BY product_id", ARRAY_A );
        $out  = array();
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row['product_id'] ] = strtotime( $row['t'] . ' UTC' );
        }
        return $out;
    }

    public static function moves_since( $gmt ) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_gmt >= %s", $gmt ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
