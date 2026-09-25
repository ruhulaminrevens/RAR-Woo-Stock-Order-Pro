<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lightweight order aggregates for the dashboard and Shop Manager reports.
 *
 * Reads plain rows with SQL (HPOS or legacy posts storage) instead of loading full
 * WC_Order objects, so month/quarter reports stay fast on shared hosting.
 */
class RAR_WSO_Reports {
    /** Statuses that do not count as a sale. */
    const VOID_STATUSES = array( 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash' );

    /** Not a sale yet: the customer has not confirmed / paid (unfinished checkout). */
    const UNCONFIRMED_STATUSES = array( 'pending' );

    public static function hooks() {
        add_action( 'woocommerce_new_order', array( __CLASS__, 'bust' ) );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'bust' ) );
        add_action( 'woocommerce_update_order', array( __CLASS__, 'bust' ) );
        add_action( 'woocommerce_order_refunded', array( __CLASS__, 'bust' ) );
        add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'bust' ) );
    }

    public static function bust() {
        update_option( 'rar_wso_report_ver', (string) microtime( true ), false );
    }

    /** One fixed transient per report name keeps the options table small even without WP-Cron. */
    private static function cache_get( $name, $ttl ) {
        $v = get_transient( 'rar_wso_c_' . $name );
        if ( is_array( $v ) && ( $v['ver'] ?? '' ) === (string) get_option( 'rar_wso_report_ver', '0' ) && time() - (int) ( $v['at'] ?? 0 ) < $ttl ) {
            return $v['data'];
        }
        return null;
    }

    private static function cache_set( $name, $data, $ttl ) {
        set_transient( 'rar_wso_c_' . $name, array( 'ver' => (string) get_option( 'rar_wso_report_ver', '0' ), 'at' => time(), 'data' => $data ), $ttl );
    }

    public static function hpos() {
        return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    public static function tz() {
        return wp_timezone();
    }

    /** Local midnight today as a DateTimeImmutable in the site timezone. */
    public static function today_start() {
        return new DateTimeImmutable( 'today', self::tz() );
    }

    public static function gmt( DateTimeInterface $dt ) {
        return ( new DateTimeImmutable( '@' . $dt->getTimestamp() ) )->format( 'Y-m-d H:i:s' );
    }

    /**
     * Period ranges used by the dashboard cards, with an equal-length comparison window.
     *
     * @return array{from:DateTimeImmutable,to:DateTimeImmutable,prev_from:DateTimeImmutable,prev_to:DateTimeImmutable,label:string,vs:string}
     */
    public static function period( $key ) {
        $now   = new DateTimeImmutable( 'now', self::tz() );
        $today = self::today_start();

        if ( '7d' === $key ) {
            $from = $today->modify( '-6 days' );
            return array( 'from' => $from, 'to' => $now, 'prev_from' => $from->modify( '-7 days' ), 'prev_to' => $now->modify( '-7 days' ), 'label' => '7 days', 'vs' => 'previous 7 days' );
        }
        if ( 'month' === $key ) {
            $from      = $today->modify( 'first day of this month' );
            $prev_from = $from->modify( '-1 month' );
            $elapsed   = $now->getTimestamp() - $from->getTimestamp();
            $prev_to   = ( new DateTimeImmutable( '@' . ( $prev_from->getTimestamp() + $elapsed ) ) )->setTimezone( self::tz() );
            if ( $prev_to > $from ) {
                $prev_to = $from;
            }
            return array( 'from' => $from, 'to' => $now, 'prev_from' => $prev_from, 'prev_to' => $prev_to, 'label' => 'this month', 'vs' => 'same days last month' );
        }
        return array( 'from' => $today, 'to' => $now, 'prev_from' => $today->modify( '-1 day' ), 'prev_to' => $now->modify( '-1 day' ), 'label' => 'today', 'vs' => 'same time yesterday' );
    }

    /**
     * @return array<int, array{id:int,status:string,total:float,time:int,payment:string,via:string}>
     */
    public static function rows( DateTimeInterface $from, DateTimeInterface $to ) {
        global $wpdb;

        $a = self::gmt( $from );
        $b = self::gmt( $to );

        if ( self::hpos() ) {
            $orders = \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_orders();
            $ops    = $wpdb->prefix . 'wc_order_operational_data';
            $sql    = "SELECT o.id, o.status, o.total_amount AS total, o.date_created_gmt AS created, o.payment_method_title AS payment, op.created_via AS via
                FROM {$orders} o LEFT JOIN {$ops} op ON op.order_id = o.id
                WHERE o.type = 'shop_order' AND o.status <> 'trash' AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
        } else {
            $sql = "SELECT p.ID AS id, p.post_status AS status, mt.meta_value AS total, p.post_date_gmt AS created, mp.meta_value AS payment, mv.meta_value AS via
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} mt ON mt.post_id = p.ID AND mt.meta_key = '_order_total'
                LEFT JOIN {$wpdb->postmeta} mp ON mp.post_id = p.ID AND mp.meta_key = '_payment_method_title'
                LEFT JOIN {$wpdb->postmeta} mv ON mv.post_id = p.ID AND mv.meta_key = '_created_via'
                WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('trash','auto-draft') AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
        }

        $raw     = $wpdb->get_results( $wpdb->prepare( $sql, $a, $b ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $refunds = self::refunds_for( wp_list_pluck( (array) $raw, 'id' ) );
        $out     = array();
        foreach ( (array) $raw as $r ) {
            $status = 0 === strpos( (string) $r['status'], 'wc-' ) ? substr( $r['status'], 3 ) : (string) $r['status'];
            if ( 'checkout-draft' === $status ) {
                continue;
            }
            $gross    = (float) $r['total'];
            $refunded = min( $gross, (float) ( $refunds[ (int) $r['id'] ] ?? 0 ) );
            $out[] = array(
                'id'      => (int) $r['id'],
                'status'  => $status,
                'total'   => $gross - $refunded, // net of partial refunds
                'gross'   => $gross,
                'refunded' => $refunded,
                'time'    => strtotime( $r['created'] . ' UTC' ),
                'payment' => (string) $r['payment'],
                'via'     => (string) $r['via'],
            );
        }
        return $out;
    }

    /**
     * Refunded amount per order ID (WooCommerce refund records, full or partial).
     *
     * @param int[] $ids Order IDs.
     * @return array<int, float>
     */
    public static function refunds_for( $ids ) {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
        if ( ! $ids ) {
            return array();
        }
        $out = array();
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            $in = implode( ',', $chunk );
            if ( self::hpos() ) {
                $orders = \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_orders();
                $sql    = "SELECT parent_order_id AS pid, SUM(ABS(total_amount)) AS amt FROM {$orders} WHERE type = 'shop_order_refund' AND parent_order_id IN ({$in}) GROUP BY parent_order_id";
            } else {
                $sql = "SELECT p.post_parent AS pid, SUM(ABS(m.meta_value)) AS amt FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_refund_amount'
                    WHERE p.post_type = 'shop_order_refund' AND p.post_parent IN ({$in}) GROUP BY p.post_parent";
            }
            foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- IDs are absint()-ed.
                $out[ (int) $r['pid'] ] = (float) $r['amt'];
            }
        }
        return $out;
    }

    /**
     * Counts as a sale: not cancelled / refunded / failed, not a return status,
     * and not an unconfirmed "pending payment" checkout.
     */
    public static function is_sale( $status ) {
        return ! in_array( $status, self::VOID_STATUSES, true )
            && ! in_array( $status, self::UNCONFIRMED_STATUSES, true )
            && false === strpos( (string) $status, 'return' );
    }

    public static function is_return( $status ) {
        return in_array( $status, array( 'cancelled', 'refunded' ), true ) || false !== strpos( $status, 'return' );
    }

    /** @return array<string, float|int> */
    public static function summarize( $rows ) {
        $s = array( 'orders' => 0, 'sales' => 0.0, 'gross' => 0.0, 'refunds' => 0.0, 'sale_orders' => 0, 'completed' => 0, 'cancelled' => 0, 'returned' => 0, 'failed' => 0, 'pending' => 0 );
        foreach ( $rows as $r ) {
            $s['orders']++;
            if ( self::is_sale( $r['status'] ) ) {
                $s['sales']   += $r['total'];
                $s['gross']   += $r['gross'] ?? $r['total'];
                $s['refunds'] += $r['refunded'] ?? 0;
                $s['sale_orders']++;
            } elseif ( in_array( $r['status'], self::UNCONFIRMED_STATUSES, true ) ) {
                $s['pending']++;
            }
            if ( 'completed' === $r['status'] ) {
                $s['completed']++;
            } elseif ( 'cancelled' === $r['status'] ) {
                $s['cancelled']++;
            } elseif ( 'failed' === $r['status'] ) {
                $s['failed']++;
            } elseif ( self::is_return( $r['status'] ) ) {
                $s['returned']++;
            }
        }
        $s['avg'] = $s['sale_orders'] ? $s['sales'] / $s['sale_orders'] : 0.0;
        return $s;
    }

    /** Daily sales/orders buckets in the site timezone. */
    public static function daily( $rows, DateTimeImmutable $from, $days ) {
        $tz      = self::tz();
        $buckets = array();
        for ( $i = 0; $i < $days; $i++ ) {
            $d                              = $from->modify( '+' . $i . ' days' );
            $buckets[ $d->format( 'Y-m-d' ) ] = array( 'date' => $d->format( 'Y-m-d' ), 'sales' => 0.0, 'orders' => 0 );
        }
        foreach ( $rows as $r ) {
            if ( ! self::is_sale( $r['status'] ) ) {
                continue;
            }
            $key = ( new DateTimeImmutable( '@' . $r['time'] ) )->setTimezone( $tz )->format( 'Y-m-d' );
            if ( isset( $buckets[ $key ] ) ) {
                $buckets[ $key ]['sales'] += $r['total'];
                $buckets[ $key ]['orders']++;
            }
        }
        return array_values( $buckets );
    }

    /** Dashboard figures for one period, cached for a minute. */
    public static function period_stats( $key ) {
        $hit = self::cache_get( 'period_' . $key, MINUTE_IN_SECONDS );
        if ( is_array( $hit ) ) {
            return $hit;
        }

        $p    = self::period( $key );
        $cur  = self::summarize( self::rows( $p['from'], $p['to'] ) );
        $prev = self::summarize( self::rows( $p['prev_from'], $p['prev_to'] ) );
        $out  = array(
            'key'   => $key,
            'label' => $p['label'],
            'vs'    => $p['vs'],
            'cur'   => $cur,
            'prev'  => $prev,
        );
        self::cache_set( 'period_' . $key, $out, MINUTE_IN_SECONDS );
        return $out;
    }

    /** Last 7 days of sales for the dashboard mini chart. */
    public static function last7() {
        $from = self::today_start()->modify( '-6 days' );
        $rows = self::rows( $from, new DateTimeImmutable( 'now', self::tz() ) );
        return self::daily( $rows, $from, 7 );
    }

    /**
     * Shop Manager growth report for 7, 30 or 90 days, compared with the previous window.
     */
    public static function report( $days ) {
        $days  = in_array( (int) $days, array( 7, 30, 90 ), true ) ? (int) $days : 30;
        $hit  = self::cache_get( 'report_' . $days, 5 * MINUTE_IN_SECONDS );
        if ( is_array( $hit ) ) {
            return $hit;
        }

        $tz        = self::tz();
        $now       = new DateTimeImmutable( 'now', $tz );
        $from      = self::today_start()->modify( '-' . ( $days - 1 ) . ' days' );
        $prev_from = $from->modify( '-' . $days . ' days' );
        $rows      = self::rows( $from, $now );
        $prev_rows = self::rows( $prev_from, $from->modify( '-1 second' ) );
        $cur       = self::summarize( $rows );
        $prev      = self::summarize( $prev_rows );

        $by_status = array();
        $payments  = array();
        $channels  = array();
        $ids       = array();
        foreach ( $rows as $r ) {
            $by_status[ $r['status'] ] = ( $by_status[ $r['status'] ] ?? 0 ) + 1;
            if ( ! self::is_sale( $r['status'] ) ) {
                continue;
            }
            $ids[] = $r['id'];
            $pay   = '' !== $r['payment'] ? $r['payment'] : __( 'Other', 'rar-woo-stock-order' );
            $payments[ $pay ]['orders'] = ( $payments[ $pay ]['orders'] ?? 0 ) + 1;
            $payments[ $pay ]['sales']  = ( $payments[ $pay ]['sales'] ?? 0 ) + $r['total'];
            $ch = self::channel_label( $r['via'] );
            $channels[ $ch ]['orders'] = ( $channels[ $ch ]['orders'] ?? 0 ) + 1;
            $channels[ $ch ]['sales']  = ( $channels[ $ch ]['sales'] ?? 0 ) + $r['total'];
        }

        $split = static function ( $map ) {
            $out = array();
            foreach ( $map as $name => $v ) {
                $out[] = array( 'name' => (string) $name, 'orders' => (int) $v['orders'], 'sales' => (float) $v['sales'] );
            }
            usort( $out, static function ( $a, $b ) { return $b['sales'] <=> $a['sales']; } );
            return $out;
        };

        $out = array(
            'days'      => $days,
            'from'      => $from->format( 'Y-m-d' ),
            'cur'       => $cur,
            'prev'      => $prev,
            'daily'      => self::daily( $rows, $from, $days ),
            'prev_daily' => self::daily( $prev_rows, $prev_from, $days ),
            'by_status' => $by_status,
            'payments'  => $split( $payments ),
            'channels'  => $split( $channels ),
            'top'       => self::top_products( $ids, 6 ),
            'items'     => self::items_sold( $ids ),
        );
        self::cache_set( 'report_' . $days, $out, 5 * MINUTE_IN_SECONDS );
        return $out;
    }

    /**
     * All-time order counts/totals per status, plus live-queue figures for the Shop Manager cards.
     */
    public static function status_overview( $live ) {
        global $wpdb;

        $hit = self::cache_get( 'overview', 30 );
        if ( is_array( $hit ) ) {
            return $hit;
        }

        if ( self::hpos() ) {
            $orders = \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_orders();
            $sql    = "SELECT status, COUNT(*) AS n, SUM(total_amount) AS t, MIN(date_created_gmt) AS oldest,
                    SUM(CASE WHEN date_created_gmt < %s THEN 1 ELSE 0 END) AS stale
                FROM {$orders} WHERE type = 'shop_order' AND status NOT IN ('trash','auto-draft','wc-checkout-draft') GROUP BY status";
        } else {
            $sql = "SELECT p.post_status AS status, COUNT(*) AS n, SUM(mt.meta_value) AS t, MIN(p.post_date_gmt) AS oldest,
                    SUM(CASE WHEN p.post_date_gmt < %s THEN 1 ELSE 0 END) AS stale
                FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} mt ON mt.post_id = p.ID AND mt.meta_key = '_order_total'
                WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('trash','auto-draft','wc-checkout-draft') GROUP BY p.post_status";
        }

        $cut  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $cut ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $out = array( 'by_status' => array(), 'total' => 0, 'live' => 0, 'live_value' => 0.0, 'stale' => 0, 'oldest_live' => 0, 'processing' => 0, 'processing_value' => 0.0, 'on_hold' => 0, 'pending' => 0 );
        foreach ( (array) $rows as $r ) {
            $slug   = 0 === strpos( $r['status'], 'wc-' ) ? substr( $r['status'], 3 ) : $r['status'];
            $count  = (int) $r['n'];
            $total  = (float) $r['t'];
            $oldest = $r['oldest'] ? strtotime( $r['oldest'] . ' UTC' ) : 0;
            $out['by_status'][ $slug ] = array( 'count' => $count, 'total' => $total, 'oldest' => $oldest, 'stale' => (int) $r['stale'] );
            $out['total'] += $count;
            if ( in_array( $slug, $live, true ) ) {
                $out['live']       += $count;
                $out['live_value'] += $total;
                $out['stale']      += (int) $r['stale'];
                if ( $oldest && ( ! $out['oldest_live'] || $oldest < $out['oldest_live'] ) ) {
                    $out['oldest_live'] = $oldest;
                }
            }
        }
        foreach ( array( 'processing' => 'processing', 'on_hold' => 'on-hold', 'pending' => 'pending' ) as $k => $slug ) {
            $out[ $k ] = (int) ( $out['by_status'][ $slug ]['count'] ?? 0 );
        }
        $out['processing_value'] = (float) ( $out['by_status']['processing']['total'] ?? 0 );

        self::cache_set( 'overview', $out, 30 );
        return $out;
    }

    public static function channel_label( $via ) {
        if ( 'rar-wso-staff' === $via ) {
            return __( 'Staff app', 'rar-woo-stock-order' );
        }
        if ( in_array( $via, array( 'checkout', 'store-api' ), true ) ) {
            return __( 'Website', 'rar-woo-stock-order' );
        }
        if ( 'admin' === $via ) {
            return __( 'WP Admin', 'rar-woo-stock-order' );
        }
        return '' !== (string) $via ? ucwords( str_replace( array( '-', '_' ), ' ', $via ) ) : __( 'Other', 'rar-woo-stock-order' );
    }

    private static function item_rows( $ids ) {
        global $wpdb;
        $ids = array_filter( array_map( 'absint', (array) $ids ) );
        if ( ! $ids ) {
            return array();
        }
        $rows = array();
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            $in = implode( ',', $chunk );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $part = $wpdb->get_results(
                "SELECT oi.order_item_id, oi.order_item_name AS name,
                    MAX(CASE WHEN m.meta_key = '_product_id' THEN m.meta_value END) AS pid,
                    MAX(CASE WHEN m.meta_key = '_variation_id' THEN m.meta_value END) AS vid,
                    MAX(CASE WHEN m.meta_key = '_qty' THEN m.meta_value END) AS qty,
                    MAX(CASE WHEN m.meta_key = '_line_total' THEN m.meta_value END) AS total
                FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
                WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ({$in})
                GROUP BY oi.order_item_id",
                ARRAY_A
            );
            // phpcs:enable
            $rows = array_merge( $rows, (array) $part );
        }
        return $rows;
    }

    private static function items_sold( $ids ) {
        $n = 0.0;
        foreach ( self::item_rows( $ids ) as $r ) {
            $n += (float) $r['qty'];
        }
        return $n;
    }

    private static function top_products( $ids, $limit ) {
        $map = array();
        foreach ( self::item_rows( $ids ) as $r ) {
            $key = (int) $r['vid'] ? (int) $r['vid'] : (int) $r['pid'];
            if ( ! isset( $map[ $key ] ) ) {
                $map[ $key ] = array( 'id' => $key, 'name' => wp_strip_all_tags( $r['name'] ), 'qty' => 0.0, 'sales' => 0.0 );
            }
            $map[ $key ]['qty']   += (float) $r['qty'];
            $map[ $key ]['sales'] += (float) $r['total'];
        }
        $list = array_values( $map );
        usort( $list, static function ( $a, $b ) { return $b['sales'] <=> $a['sales']; } );
        return array_slice( $list, 0, $limit );
    }
}
