<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds the sales + stock report used by the staff app (preview, image, PDF) and by the
 * Excel / CSV export. One data shape feeds all of them, so every format shows the same numbers.
 *
 * Scope:
 *  - Staff see only the orders they created and the stock changes they made.
 *  - Shop Managers (with the Reports permission) can report on everyone or on one person.
 */
class RAR_WSO_Report_Builder {
    const MAX_DAYS = 366;

    /** Parses Y-m-d in the shop's timezone; false when invalid. */
    private static function day( $ymd ) {
        if ( ! is_string( $ymd ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return false;
        }
        return new DateTimeImmutable( $ymd . ' 00:00:00', RAR_WSO_Reports::tz() );
    }

    /**
     * Normalises the requested window. Returns array( from, to, days ) or WP_Error.
     * "to" is the end of the last day (or "now" for today).
     */
    public static function window( $from_ymd, $to_ymd ) {
        $from = self::day( $from_ymd );
        $to   = self::day( $to_ymd );
        if ( ! $from || ! $to ) {
            return new WP_Error( 'dates', __( 'Choose a valid start and end date.', 'rar-woo-stock-order' ) );
        }
        $today = RAR_WSO_Reports::today_start();
        if ( $to > $today ) {
            $to = $today;
        }
        if ( $from > $to ) {
            return new WP_Error( 'dates', __( 'The start date is after the end date.', 'rar-woo-stock-order' ) );
        }
        $days = (int) floor( ( $to->getTimestamp() - $from->getTimestamp() ) / DAY_IN_SECONDS ) + 1;
        if ( $days > self::MAX_DAYS ) {
            /* translators: %d days */
            return new WP_Error( 'range', sprintf( __( 'Choose at most %d days at a time.', 'rar-woo-stock-order' ), self::MAX_DAYS ) );
        }
        $end = $to->setTime( 23, 59, 59 );
        $now = new DateTimeImmutable( 'now', RAR_WSO_Reports::tz() );
        if ( $end > $now ) {
            $end = $now;
        }
        return array( 'from' => $from, 'to' => $end, 'days' => $days );
    }

    private static function user_name( $id, &$cache ) {
        $id = (int) $id;
        if ( ! $id ) {
            return __( 'Website / admin', 'rar-woo-stock-order' );
        }
        if ( ! isset( $cache[ $id ] ) ) {
            $u            = get_userdata( $id );
            $cache[ $id ] = $u ? $u->display_name : '#' . $id;
        }
        return $cache[ $id ];
    }

    /** People that appear in the picker: staff + managers who created orders or changed stock. */
    public static function people() {
        $out = array();
        foreach ( get_users( array( 'role__in' => array( 'rar_wso_staff', 'shop_manager', 'administrator' ), 'orderby' => 'display_name', 'number' => 500, 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
            $out[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name );
        }
        return $out;
    }

    /**
     * @param array $args from (Y-m-d), to (Y-m-d), staff_id (0 = everyone), sections (array of 'sales','stock'), full (bool: no row limits)
     * @return array|WP_Error
     */
    public static function build( $args ) {
        $win = self::window( (string) ( $args['from'] ?? '' ), (string) ( $args['to'] ?? '' ) );
        if ( is_wp_error( $win ) ) {
            return $win;
        }
        $full     = ! empty( $args['full'] );
        $staff_id = absint( $args['staff_id'] ?? 0 );
        $all      = RAR_WSO_Plugin::can_report_all();
        if ( ! $all ) {
            $staff_id = get_current_user_id(); // staff only ever see themselves
        }
        $sections = array_intersect( (array) ( $args['sections'] ?? array( 'sales', 'stock' ) ), array( 'sales', 'stock' ) );
        $sections = $sections ? array_values( $sections ) : array( 'sales', 'stock' );
        $cost_ok  = RAR_WSO_Plugin::can( 'rar_wso_view_cost' );
        $names    = array();

        $report = array(
            'meta' => array(
                'business'  => RAR_WSO_Plugin::business_name(),
                'from'      => $win['from']->format( 'Y-m-d' ),
                'to'        => $win['to']->format( 'Y-m-d' ),
                'days'      => $win['days'],
                'scope'     => $staff_id ? 'staff' : 'all',
                'staff_id'  => $staff_id,
                'staff'     => $staff_id ? self::user_name( $staff_id, $names ) : __( 'All staff & website', 'rar-woo-stock-order' ),
                'generated' => wp_date( 'Y-m-d H:i' ),
                'by'        => wp_get_current_user()->display_name,
                'sections'  => $sections,
                'cost'      => $cost_ok,
            ),
        );

        if ( in_array( 'sales', $sections, true ) ) {
            $report['sales'] = self::sales( $win, $staff_id, $full, $names );
        }
        if ( in_array( 'stock', $sections, true ) ) {
            $report['stock'] = self::stock( $win, $staff_id, $full, $names, $cost_ok );
        }
        return $report;
    }

    private static function sales( $win, $staff_id, $full, &$names ) {
        $creators = array();
        foreach ( RAR_WSO_Reports::creator_rows( $win['from'], $win['to'] ) as $r ) {
            $creators[ $r['id'] ] = $r;
        }
        $rows = array();
        foreach ( RAR_WSO_Reports::rows( $win['from'], $win['to'] ) as $r ) {
            $c            = $creators[ $r['id'] ] ?? null;
            $r['creator'] = $c ? (int) $c['creator'] : 0;
            $r['discount'] = $c ? (float) $c['discount'] : 0.0;
            if ( $staff_id && $r['creator'] !== $staff_id ) {
                continue;
            }
            $rows[] = $r;
        }

        $sum       = RAR_WSO_Reports::summarize( $rows );
        $sale_ids  = array();
        $all_ids   = array();
        $by_pay    = array();
        $by_status = array();
        $by_staff  = array();
        $discount  = 0.0;
        foreach ( $rows as $r ) {
            $all_ids[]  = $r['id'];
            $st         = $r['status'];
            $by_status[ $st ] = ( $by_status[ $st ] ?? 0 ) + 1;
            if ( ! RAR_WSO_Reports::is_sale( $st ) ) {
                continue;
            }
            $sale_ids[] = $r['id'];
            $discount  += $r['discount'];
            $pay        = '' !== trim( $r['payment'] ) ? $r['payment'] : __( 'Not set', 'rar-woo-stock-order' );
            $by_pay[ $pay ]['orders'] = ( $by_pay[ $pay ]['orders'] ?? 0 ) + 1;
            $by_pay[ $pay ]['sales']  = ( $by_pay[ $pay ]['sales'] ?? 0.0 ) + $r['total'];
            $cid = $r['creator'];
            $by_staff[ $cid ]['orders']   = ( $by_staff[ $cid ]['orders'] ?? 0 ) + 1;
            $by_staff[ $cid ]['sales']    = ( $by_staff[ $cid ]['sales'] ?? 0.0 ) + $r['total'];
            $by_staff[ $cid ]['discount'] = ( $by_staff[ $cid ]['discount'] ?? 0.0 ) + $r['discount'];
        }

        // Day by day (site timezone).
        $days  = array();
        $start = $win['from'];
        for ( $i = 0; $i < $win['days']; $i++ ) {
            $days[ $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' ) ] = array( 'orders' => 0, 'sales' => 0.0 );
        }
        foreach ( $rows as $r ) {
            $k = wp_date( 'Y-m-d', $r['time'], RAR_WSO_Reports::tz() );
            if ( ! isset( $days[ $k ] ) ) {
                continue;
            }
            $days[ $k ]['orders']++;
            if ( RAR_WSO_Reports::is_sale( $r['status'] ) ) {
                $days[ $k ]['sales'] += $r['total'];
            }
        }
        $daily = array();
        foreach ( $days as $d => $v ) {
            $daily[] = array( 'date' => $d, 'orders' => $v['orders'], 'sales' => round( $v['sales'], 2 ) );
        }

        $staff_list = array();
        foreach ( $by_staff as $id => $v ) {
            $staff_list[] = array(
                'id'       => (int) $id,
                'name'     => self::user_name( $id, $names ),
                'orders'   => (int) $v['orders'],
                'sales'    => round( $v['sales'], 2 ),
                'avg'      => $v['orders'] ? round( $v['sales'] / $v['orders'], 2 ) : 0,
                'discount' => round( $v['discount'], 2 ),
            );
        }
        usort( $staff_list, static function ( $a, $b ) { return $b['sales'] <=> $a['sales']; } );

        $pay_list = array();
        foreach ( $by_pay as $label => $v ) {
            $pay_list[] = array( 'label' => $label, 'orders' => (int) $v['orders'], 'sales' => round( $v['sales'], 2 ) );
        }
        usort( $pay_list, static function ( $a, $b ) { return $b['sales'] <=> $a['sales']; } );

        $labels      = RAR_WSO_Ajax::all_statuses();
        $status_list = array();
        foreach ( $by_status as $st => $n ) {
            $status_list[] = array( 'status' => $st, 'label' => $labels[ $st ] ?? ucfirst( str_replace( '-', ' ', $st ) ), 'orders' => $n );
        }
        usort( $status_list, static function ( $a, $b ) { return $b['orders'] <=> $a['orders']; } );

        $top = RAR_WSO_Reports::top_products( $sale_ids, $full ? 500 : 15 );
        foreach ( $top as &$t ) {
            $t['sales'] = round( $t['sales'], 2 );
        }
        unset( $t );

        // Order list (for Excel; the app shows counts only).
        $orders = array();
        if ( $full ) {
            usort( $rows, static function ( $a, $b ) { return $a['time'] <=> $b['time']; } );
            foreach ( array_slice( $rows, 0, 5000 ) as $r ) {
                $o        = wc_get_order( $r['id'] );
                $orders[] = array(
                    'number'   => $o ? $o->get_order_number() : (string) $r['id'],
                    'time'     => wp_date( 'Y-m-d H:i', $r['time'], RAR_WSO_Reports::tz() ),
                    'staff'    => self::user_name( $r['creator'], $names ),
                    'customer' => $o ? trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) : '',
                    'phone'    => $o ? $o->get_billing_phone() : '',
                    'status'   => $labels[ $r['status'] ] ?? $r['status'],
                    'payment'  => $r['payment'],
                    'discount' => round( $r['discount'], 2 ),
                    'shipping' => round( $r['shipping'], 2 ),
                    'total'    => round( $r['total'], 2 ),
                    'counted'  => RAR_WSO_Reports::is_sale( $r['status'] ),
                );
            }
        }

        return array(
            'summary'   => array(
                'orders'      => (int) $sum['orders'],
                'sale_orders' => (int) $sum['sale_orders'],
                'sales'       => round( $sum['sales'], 2 ),
                'gross'       => round( $sum['gross'], 2 ),
                'refunds'     => round( $sum['refunds'], 2 ),
                'shipping'    => round( $sum['shipping'], 2 ),
                'discount'    => round( $discount, 2 ),
                'avg'         => round( $sum['avg'], 2 ),
                'completed'   => (int) $sum['completed'],
                'cancelled'   => (int) $sum['cancelled'],
                'returned'    => (int) $sum['returned'],
                'pending'     => (int) $sum['pending'],
                'items'       => (float) RAR_WSO_Reports::items_sold( $sale_ids ),
            ),
            'daily'     => $daily,
            'staff'     => $staff_list,
            'payments'  => $pay_list,
            'statuses'  => $status_list,
            'products'  => $top,
            'orders'    => $orders,
        );
    }

    private static function stock( $win, $staff_id, $full, &$names, $cost_ok ) {
        $ajax = RAR_WSO_Ajax::$instance;
        $snap = array( 'products' => 0, 'units' => 0, 'value' => null, 'low' => 0, 'out' => 0, 'negative' => 0, 'threshold' => RAR_WSO_Plugin::low_threshold() );
        $low  = array();
        $out  = array();
        if ( $ajax ) {
            $d    = $ajax->dashboard_stock();
            $c    = (array) ( $d['counts'] ?? array() );
            $snap = array(
                'products'  => (int) ( $c['all'] ?? 0 ),
                'units'     => (float) ( $c['units'] ?? 0 ),
                'value'     => $cost_ok ? round( (float) ( $c['value'] ?? 0 ), 2 ) : null,
                'low'       => (int) ( $c['low'] ?? 0 ),
                'out'       => (int) ( $c['out'] ?? 0 ),
                'negative'  => (int) ( $c['negative'] ?? 0 ),
                'threshold' => (int) ( $c['threshold'] ?? RAR_WSO_Plugin::low_threshold() ),
            );
            $low = $ajax->stock_names( 'low', $full ? 200 : 12 );
            $out = $ajax->stock_names( 'out', $full ? 200 : 12 );
        }

        $q = array(
            'from'     => RAR_WSO_Reports::gmt( $win['from'] ),
            'to'       => RAR_WSO_Reports::gmt( $win['to'] ),
            'per_page' => 1000,
        );
        if ( $staff_id ) {
            $q['user_id'] = $staff_id;
        }
        $added   = 0.0;
        $removed = 0.0;
        $sold    = 0.0;
        $moves   = 0;
        $by_user = array();
        $rows    = array();
        $total   = 0;
        $pages   = $full ? 5 : 1;
        for ( $page = 1; $page <= $pages; $page++ ) {
            $q['page'] = $page;
            $res       = RAR_WSO_Log::query( $q );
            $total     = (int) $res['total'];
            foreach ( $res['items'] as $it ) {
                if ( null === $it['from'] || null === $it['to'] ) {
                    $diff = null;
                } else {
                    $diff = (float) $it['to'] - (float) $it['from'];
                }
                $moves++;
                if ( 'order' === $it['source'] ) {
                    if ( null !== $diff ) {
                        $sold += -$diff;
                    }
                } elseif ( null !== $diff ) {
                    if ( $diff > 0 ) {
                        $added += $diff;
                    } else {
                        $removed += -$diff;
                    }
                    $who = $it['user'] ? $it['user'] : __( 'Unknown', 'rar-woo-stock-order' );
                    $by_user[ $who ]['moves']   = ( $by_user[ $who ]['moves'] ?? 0 ) + 1;
                    $by_user[ $who ]['added']   = ( $by_user[ $who ]['added'] ?? 0.0 ) + max( 0, $diff );
                    $by_user[ $who ]['removed'] = ( $by_user[ $who ]['removed'] ?? 0.0 ) + max( 0, -$diff );
                }
                $rows[] = array(
                    'time'    => wp_date( 'Y-m-d H:i', $it['time'], RAR_WSO_Reports::tz() ),
                    'product' => $it['product'],
                    'sku'     => $it['sku'],
                    'from'    => $it['from'],
                    'to'      => $it['to'],
                    'diff'    => $diff,
                    'reason'  => $it['reason'],
                    'user'    => $it['user'],
                    'source'  => 'order' === $it['source'] ? 'sale' : 'manual',
                );
            }
            if ( count( $res['items'] ) < 1000 ) {
                break;
            }
        }
        $people = array();
        foreach ( $by_user as $who => $v ) {
            $people[] = array( 'name' => $who, 'moves' => (int) $v['moves'], 'added' => (float) $v['added'], 'removed' => (float) $v['removed'] );
        }
        usort( $people, static function ( $a, $b ) { return $b['moves'] <=> $a['moves']; } );

        return array(
            'snapshot'  => $snap,
            'low'       => $low,
            'out'       => $out,
            'added'     => $added,
            'removed'   => $removed,
            'sold'      => $sold,
            'moves'     => $moves,
            'moves_all' => $total,
            'people'    => $people,
            'rows'      => $full ? $rows : array_slice( $rows, 0, 30 ),
        );
    }

    /* --------------------------------------------------------------------
     * Excel / CSV
     * ------------------------------------------------------------------ */

    private static function num( $v ) {
        return is_numeric( $v ) ? ( (float) $v == (int) $v ? (int) $v : (float) $v ) : $v;
    }

    /** Workbook sheets for RAR_WSO_Xlsx::build() (also used as CSV blocks). */
    public static function sheets( $r ) {
        $m   = $r['meta'];
        $T   = RAR_WSO_Xlsx::S_TITLE;
        $H   = RAR_WSO_Xlsx::S_HEAD;
        $M   = RAR_WSO_Xlsx::S_MONEY;
        $I   = RAR_WSO_Xlsx::S_INT;
        $B   = RAR_WSO_Xlsx::S_BOLD;
        $G   = RAR_WSO_Xlsx::S_MUTED;
        $hd  = static function ( $cols ) use ( $H ) {
            return array_map( static function ( $c ) use ( $H ) { return array( $c, $H ); }, $cols );
        };
        $sheets = array();

        $sum_rows = array(
            array( array( $m['business'] . ' — ' . __( 'Report', 'rar-woo-stock-order' ), $T ) ),
            array( array( $m['from'] . ' → ' . $m['to'] . ' (' . $m['days'] . ' ' . _n( 'day', 'days', $m['days'], 'rar-woo-stock-order' ) . ')', $G ) ),
            array( __( 'For', 'rar-woo-stock-order' ), $m['staff'] ),
            array( __( 'Generated', 'rar-woo-stock-order' ), $m['generated'] . ' · ' . $m['by'] ),
            array(),
        );
        if ( isset( $r['sales'] ) ) {
            $s          = $r['sales']['summary'];
            $sum_rows[] = array( array( __( 'Sales', 'rar-woo-stock-order' ), $B ) );
            foreach ( array(
                array( __( 'Orders (all)', 'rar-woo-stock-order' ), $s['orders'], $I ),
                array( __( 'Counted sales orders', 'rar-woo-stock-order' ), $s['sale_orders'], $I ),
                array( __( 'Net sales', 'rar-woo-stock-order' ), $s['sales'], $M ),
                array( __( 'Gross before refunds', 'rar-woo-stock-order' ), $s['gross'], $M ),
                array( __( 'Refunds', 'rar-woo-stock-order' ), $s['refunds'], $M ),
                array( __( 'Delivery charged', 'rar-woo-stock-order' ), $s['shipping'], $M ),
                array( __( 'Discount given (staff app)', 'rar-woo-stock-order' ), $s['discount'], $M ),
                array( __( 'Average order', 'rar-woo-stock-order' ), $s['avg'], $M ),
                array( __( 'Items sold', 'rar-woo-stock-order' ), $s['items'], $I ),
                array( __( 'Completed', 'rar-woo-stock-order' ), $s['completed'], $I ),
                array( __( 'Cancelled', 'rar-woo-stock-order' ), $s['cancelled'], $I ),
                array( __( 'Returned / refunded', 'rar-woo-stock-order' ), $s['returned'], $I ),
                array( __( 'Pending / on hold', 'rar-woo-stock-order' ), $s['pending'], $I ),
            ) as $l ) {
                $sum_rows[] = array( $l[0], array( self::num( $l[1] ), $l[2] ) );
            }
            $sum_rows[] = array();
        }
        if ( isset( $r['stock'] ) ) {
            $k          = $r['stock'];
            $sum_rows[] = array( array( __( 'Stock', 'rar-woo-stock-order' ), $B ) );
            $lines      = array(
                array( __( 'Products tracked now', 'rar-woo-stock-order' ), $k['snapshot']['products'], $I ),
                array( __( 'Units in hand now', 'rar-woo-stock-order' ), $k['snapshot']['units'], $I ),
            );
            if ( null !== $k['snapshot']['value'] ) {
                $lines[] = array( __( 'Stock value at sale price now', 'rar-woo-stock-order' ), $k['snapshot']['value'], $M );
            }
            $lines[] = array( __( 'Low stock now', 'rar-woo-stock-order' ), $k['snapshot']['low'], $I );
            $lines[] = array( __( 'Out of stock now', 'rar-woo-stock-order' ), $k['snapshot']['out'], $I );
            $lines[] = array( __( 'Units added in period (manual)', 'rar-woo-stock-order' ), $k['added'], $I );
            $lines[] = array( __( 'Units removed in period (manual)', 'rar-woo-stock-order' ), $k['removed'], $I );
            $lines[] = array( __( 'Units sold in period (orders)', 'rar-woo-stock-order' ), $k['sold'], $I );
            foreach ( $lines as $l ) {
                $sum_rows[] = array( $l[0], array( self::num( $l[1] ), $l[2] ) );
            }
        }
        $sheets[] = array( 'name' => __( 'Summary', 'rar-woo-stock-order' ), 'rows' => $sum_rows, 'widths' => array( 40, 34 ) );

        if ( isset( $r['sales'] ) ) {
            $s = $r['sales'];
            $d = array( $hd( array( __( 'Date', 'rar-woo-stock-order' ), __( 'Orders', 'rar-woo-stock-order' ), __( 'Net sales', 'rar-woo-stock-order' ) ) ) );
            foreach ( $s['daily'] as $x ) {
                $d[] = array( $x['date'], array( $x['orders'], $I ), array( self::num( $x['sales'] ), $M ) );
            }
            $sheets[] = array( 'name' => __( 'Sales by day', 'rar-woo-stock-order' ), 'rows' => $d, 'widths' => array( 14, 10, 16 ) );

            $d = array( $hd( array( __( 'Person', 'rar-woo-stock-order' ), __( 'Orders', 'rar-woo-stock-order' ), __( 'Net sales', 'rar-woo-stock-order' ), __( 'Average', 'rar-woo-stock-order' ), __( 'Discount given', 'rar-woo-stock-order' ) ) ) );
            foreach ( $s['staff'] as $x ) {
                $d[] = array( $x['name'], array( $x['orders'], $I ), array( self::num( $x['sales'] ), $M ), array( self::num( $x['avg'] ), $M ), array( self::num( $x['discount'] ), $M ) );
            }
            $sheets[] = array( 'name' => __( 'Sales by person', 'rar-woo-stock-order' ), 'rows' => $d, 'widths' => array( 28, 10, 16, 14, 16 ) );

            $d = array( $hd( array( __( 'Product', 'rar-woo-stock-order' ), __( 'Qty sold', 'rar-woo-stock-order' ), __( 'Sales', 'rar-woo-stock-order' ) ) ) );
            foreach ( $s['products'] as $x ) {
                $d[] = array( $x['name'], array( self::num( $x['qty'] ), $I ), array( self::num( $x['sales'] ), $M ) );
            }
            $sheets[] = array( 'name' => __( 'Products sold', 'rar-woo-stock-order' ), 'rows' => $d, 'widths' => array( 52, 12, 16 ) );

            $d = array( $hd( array( __( 'Payment method', 'rar-woo-stock-order' ), __( 'Orders', 'rar-woo-stock-order' ), __( 'Net sales', 'rar-woo-stock-order' ) ) ) );
            foreach ( $s['payments'] as $x ) {
                $d[] = array( $x['label'], array( $x['orders'], $I ), array( self::num( $x['sales'] ), $M ) );
            }
            $sheets[] = array( 'name' => __( 'Payments', 'rar-woo-stock-order' ), 'rows' => $d, 'widths' => array( 30, 10, 16 ) );

            $d = array( $hd( array( __( 'Order', 'rar-woo-stock-order' ), __( 'Date', 'rar-woo-stock-order' ), __( 'Staff', 'rar-woo-stock-order' ), __( 'Customer', 'rar-woo-stock-order' ), __( 'Phone', 'rar-woo-stock-order' ), __( 'Status', 'rar-woo-stock-order' ), __( 'Payment', 'rar-woo-stock-order' ), __( 'Discount', 'rar-woo-stock-order' ), __( 'Delivery', 'rar-woo-stock-order' ), __( 'Total', 'rar-woo-stock-order' ), __( 'Counted in sales', 'rar-woo-stock-order' ) ) ) );
            foreach ( $s['orders'] as $x ) {
                $d[] = array( '#' . $x['number'], $x['time'], $x['staff'], $x['customer'], $x['phone'], $x['status'], $x['payment'], array( self::num( $x['discount'] ), $M ), array( self::num( $x['shipping'] ), $M ), array( self::num( $x['total'] ), $M ), $x['counted'] ? __( 'Yes', 'rar-woo-stock-order' ) : __( 'No', 'rar-woo-stock-order' ) );
            }
            $sheets[] = array( 'name' => __( 'Orders', 'rar-woo-stock-order' ), 'rows' => $d, 'widths' => array( 12, 17, 20, 24, 16, 16, 20, 12, 12, 14, 12 ) );
        }

        if ( isset( $r['stock'] ) ) {
            $k = $r['stock'];
            $d = array( $hd( array( __( 'Time', 'rar-woo-stock-order' ), __( 'Product', 'rar-woo-stock-order' ), __( 'SKU', 'rar-woo-stock-order' ), __( 'From', 'rar-woo-stock-order' ), __( 'To', 'rar-woo-stock-order' ), __( 'Change', 'rar-woo-stock-order' ), __( 'Type', 'rar-woo-stock-order' ), __( 'Reason', 'rar-woo-stock-order' ), __( 'By', 'rar-woo-stock-order' ) ) ) );
            foreach ( $k['rows'] as $x ) {
                $d[] = array( $x['time'], $x['product'], $x['sku'], null === $x['from'] ? '' : array( self::num( $x['from'] ), $I ), null === $x['to'] ? '' : array( self::num( $x['to'] ), $I ), null === $x['diff'] ? '' : array( self::num( $x['diff'] ), $I ), $x['source'], $x['reason'], $x['user'] );
            }
            $sheets[] = array( 'name' => __( 'Stock movements', 'rar-woo-stock-order' ), 'rows' => $d, 'widths' => array( 17, 46, 16, 9, 9, 9, 9, 28, 20 ) );

            $d = array( $hd( array( __( 'Status', 'rar-woo-stock-order' ), __( 'Product', 'rar-woo-stock-order' ), __( 'Qty', 'rar-woo-stock-order' ) ) ) );
            foreach ( $k['out'] as $x ) {
                $d[] = array( __( 'Out', 'rar-woo-stock-order' ), $x['name'], null === $x['qty'] ? '' : array( self::num( $x['qty'] ), $I ) );
            }
            foreach ( $k['low'] as $x ) {
                $d[] = array( __( 'Low', 'rar-woo-stock-order' ), $x['name'], null === $x['qty'] ? '' : array( self::num( $x['qty'] ), $I ) );
            }
            $sheets[] = array( 'name' => __( 'Low & out of stock', 'rar-woo-stock-order' ), 'rows' => $d, 'widths' => array( 10, 56, 10 ) );
        }
        return $sheets;
    }

    /** All sheets as one CSV (fallback when ZipArchive is missing). UTF-8 BOM, formula-safe. */
    public static function csv( $r ) {
        $fh = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        foreach ( self::sheets( $r ) as $sheet ) {
            fputcsv( $fh, array( '== ' . $sheet['name'] . ' ==' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            foreach ( $sheet['rows'] as $row ) {
                $cells = array();
                foreach ( (array) $row as $cell ) {
                    $v = is_array( $cell ) ? ( $cell[0] ?? '' ) : $cell;
                    if ( is_string( $v ) && '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
                        $v = "'" . $v;
                    }
                    $cells[] = $v;
                }
                fputcsv( $fh, $cells ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }
            fputcsv( $fh, array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        rewind( $fh );
        $out = stream_get_contents( $fh );
        fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return $out;
    }
}
