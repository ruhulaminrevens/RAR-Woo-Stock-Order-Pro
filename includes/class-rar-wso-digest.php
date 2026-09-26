<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Daily business summary email + daily housekeeping (history retention).
 *
 * Runs on WP-Cron. On shared hosting WP-Cron only runs when someone visits the site;
 * a real cron job that opens wp-cron.php makes the email arrive on time.
 */
class RAR_WSO_Digest {
    const HOOK       = 'rar_wso_daily_digest';
    const HOUSE_HOOK = 'rar_wso_housekeeping';

    public static function hooks() {
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
        add_action( self::HOUSE_HOOK, array( __CLASS__, 'housekeeping' ) );
        add_action( 'update_option_rar_wso_settings', array( __CLASS__, 'settings_changed' ), 10, 2 );
        // A new site timezone moves "9 pm".
        add_action( 'update_option_timezone_string', array( __CLASS__, 'reschedule' ), 10, 0 );
        add_action( 'update_option_gmt_offset', array( __CLASS__, 'reschedule' ), 10, 0 );
        // Self-healing: (re)schedule if an event went missing (e.g. after a migration).
        add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) );
    }

    public static function settings_changed( $old, $new ) {
        $keys = array( 'digest_enabled', 'digest_hour' );
        foreach ( $keys as $k ) {
            if ( ( $old[ $k ] ?? null ) !== ( $new[ $k ] ?? null ) ) {
                self::reschedule( $new );
                return;
            }
        }
    }

    public static function ensure_scheduled() {
        if ( ! wp_next_scheduled( self::HOUSE_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOUSE_HOOK );
        }
        $s = RAR_WSO_Plugin::settings();
        if ( 'yes' === $s['digest_enabled'] && ! wp_next_scheduled( self::HOOK ) ) {
            self::reschedule( $s );
        }
    }

    /** Next run at digest_hour:00 in the site timezone. */
    public static function reschedule( $settings = null ) {
        $s = is_array( $settings ) ? array_merge( RAR_WSO_Plugin::settings(), $settings ) : RAR_WSO_Plugin::settings();
        wp_clear_scheduled_hook( self::HOOK );
        if ( ! wp_next_scheduled( self::HOUSE_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOUSE_HOOK );
        }
        if ( 'yes' !== $s['digest_enabled'] ) {
            return;
        }
        $hour = min( 23, max( 0, absint( $s['digest_hour'] ) ) );
        $next = new DateTimeImmutable( 'today ' . sprintf( '%02d:00', $hour ), wp_timezone() );
        if ( $next->getTimestamp() <= time() + 60 ) {
            $next = $next->modify( '+1 day' );
        }
        wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
    }

    public static function next_run() {
        $ts = wp_next_scheduled( self::HOOK );
        return $ts ? (int) $ts : 0;
    }

    public static function recipients() {
        $s    = RAR_WSO_Plugin::settings();
        $list = array();
        foreach ( preg_split( '/[\s,;]+/', (string) $s['digest_email'] ) as $e ) {
            $e = sanitize_email( $e );
            if ( is_email( $e ) ) {
                $list[] = $e;
            }
        }
        if ( ! $list ) {
            $list[] = get_option( 'admin_email' );
        }
        return array_slice( array_unique( $list ), 0, 10 );
    }

    public static function run() {
        $s = RAR_WSO_Plugin::settings();
        if ( 'yes' !== $s['digest_enabled'] ) {
            return;
        }
        self::send( false );
    }

    /**
     * The day the email is about. An evening email (12:00 or later) covers today; a morning
     * email covers yesterday in full. WP-Cron only runs on a visit, so an evening email that
     * fires after midnight still reports the day it was meant for.
     */
    public static function report_day() {
        $s     = RAR_WSO_Plugin::settings();
        $hour  = min( 23, max( 0, absint( $s['digest_hour'] ) ) );
        $today = RAR_WSO_Reports::today_start();
        $now   = new DateTimeImmutable( 'now', wp_timezone() );
        if ( $hour < 12 ) {
            return $today->modify( '-1 day' );
        }
        return (int) $now->format( 'G' ) < $hour ? $today->modify( '-1 day' ) : $today;
    }

    public static function housekeeping() {
        $s    = RAR_WSO_Plugin::settings();
        $days = absint( $s['log_retention_days'] );
        if ( $days >= 30 ) {
            RAR_WSO_Log::prune( $days );
            RAR_WSO_Audit::prune( max( $days, 90 ) );
        }
    }

    /**
     * Figures for one calendar day (site timezone): that day (up to now if it is today) against
     * the same span the day before, month to that day, staff totals; queue and stock are current.
     */
    public static function data( $day = null ) {
        $tz    = wp_timezone();
        $day   = $day instanceof DateTimeImmutable ? $day : self::report_day();
        $now   = new DateTimeImmutable( 'now', $tz );
        $end   = min( $now, $day->modify( '+1 day' )->modify( '-1 second' ) );
        $span  = $end->getTimestamp() - $day->getTimestamp();
        $pday  = $day->modify( '-1 day' );
        $pend  = ( new DateTimeImmutable( '@' . ( $pday->getTimestamp() + $span ) ) )->setTimezone( $tz );
        $month = $day->modify( 'first day of this month' );

        $cur  = RAR_WSO_Reports::summarize( RAR_WSO_Reports::rows( $day, $end ) );
        $prev = RAR_WSO_Reports::summarize( RAR_WSO_Reports::rows( $pday, $pend ) );
        $mtd  = RAR_WSO_Reports::summarize( RAR_WSO_Reports::rows( $month, $end ) );

        $tot  = array( 'today' => array( 'staff' => 0.0, 'staff_n' => 0 ) );
        $team = array();
        foreach ( RAR_WSO_Reports::creator_rows( $month, $end ) as $r ) {
            if ( ! $r['creator'] || ! RAR_WSO_Reports::is_sale( $r['status'] ) ) {
                continue;
            }
            $uid = $r['creator'];
            if ( ! isset( $team[ $uid ] ) ) {
                $team[ $uid ] = array( 'orders' => 0, 'sales' => 0.0, 'today' => 0, 'today_sales' => 0.0 );
            }
            $team[ $uid ]['orders']++;
            $team[ $uid ]['sales'] += $r['total'];
            if ( $r['time'] >= $day->getTimestamp() ) {
                $team[ $uid ]['today']++;
                $team[ $uid ]['today_sales'] += $r['total'];
                $tot['today']['staff']       += $r['total'];
                $tot['today']['staff_n']++;
            }
        }
        uasort( $team, static function ( $a, $b ) { return $b['sales'] <=> $a['sales']; } );

        $queue = RAR_WSO_Reports::status_overview( RAR_WSO_Ajax::live_statuses() );
        $stock = RAR_WSO_Ajax::$instance ? RAR_WSO_Ajax::$instance->dashboard_stock() : array( 'counts' => array(), 'low' => array(), 'out' => array() );
        return array(
            'day'      => $day,
            'today'    => array( 'cur' => $cur, 'prev' => $prev ),
            'month'    => array( 'cur' => $mtd ),
            'overview' => array( 'tot' => $tot, 'team' => $team ),
            'queue'    => $queue,
            'stock'    => $stock,
        );
    }

    public static function send( $test ) {
        $d        = self::data();
        $to       = self::recipients();
        $business = RAR_WSO_Plugin::business_name();
        $date     = wp_date( get_option( 'date_format' ), $d['day']->getTimestamp() );
        /* translators: 1: business, 2: date */
        $subject  = sprintf( __( '[%1$s] Daily summary — %2$s', 'rar-woo-stock-order' ), $business, $date );
        if ( $test ) {
            $subject = '[TEST] ' . $subject;
        }
        $html = self::html( $d, $business, $date );
        // One email per person, so recipients don't see each other's addresses.
        $sent = false;
        foreach ( $to as $address ) {
            $sent = wp_mail( $address, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) ) || $sent;
        }
        RAR_WSO_Audit::add( 'digest', ( $sent ? 'Sent' : 'FAILED' ) . ( $test ? ' (test)' : '' ) . ' to ' . implode( ', ', $to ), 0, $test ? null : 0 );
        return (bool) $sent;
    }

    private static function money( $v ) {
        return html_entity_decode( wp_strip_all_tags( wc_price( $v, array( 'decimals' => 0 ) ) ), ENT_QUOTES, 'UTF-8' );
    }

    private static function pct( $cur, $prev ) {
        if ( $prev <= 0 ) {
            return $cur > 0 ? '▲ new' : '—';
        }
        $p = ( $cur - $prev ) / $prev * 100;
        return ( $p >= 0 ? '▲ ' : '▼ ' ) . number_format_i18n( abs( $p ), 0 ) . '%';
    }

    public static function html( $d, $business, $date ) {
        $brand = RAR_WSO_Plugin::brand_color();
        $t     = $d['today']['cur'];
        $tp    = $d['today']['prev'];
        $m     = $d['month']['cur'];
        $tot   = $d['overview']['tot'];
        $c     = $d['stock']['counts'] + array( 'low' => 0, 'out' => 0, 'negative' => 0, 'value' => 0, 'units' => 0 );
        $q     = $d['queue'];
        $cell  = 'padding:10px 12px;border-bottom:1px solid #e6e9f0;font:14px/1.4 Arial,sans-serif;color:#1b2340';
        $kpi   = static function ( $label, $value, $sub ) {
            return '<td style="padding:8px;width:25%;vertical-align:top"><div style="background:#f5f7fb;border-radius:10px;padding:12px"><div style="font:12px Arial,sans-serif;color:#66718c">' . esc_html( $label ) . '</div><div style="font:bold 20px Arial,sans-serif;color:#131b31;margin-top:4px">' . esc_html( $value ) . '</div><div style="font:12px Arial,sans-serif;color:#66718c;margin-top:2px">' . esc_html( $sub ) . '</div></div></td>';
        };

        ob_start();
        ?>
<div style="background:#eef1f7;padding:24px 12px">
<table role="presentation" cellspacing="0" cellpadding="0" style="max-width:640px;width:100%;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden">
<tr><td style="background:<?php echo esc_attr( $brand ); ?>;padding:22px 24px;color:#fff;font:bold 20px Arial,sans-serif"><?php echo esc_html( $business ); ?><div style="font:13px Arial,sans-serif;opacity:.8;margin-top:4px"><?php echo esc_html( sprintf( /* translators: %s date */ __( 'Daily summary · %s', 'rar-woo-stock-order' ), $date ) ); ?></div></td></tr>
<tr><td style="padding:12px 16px"><table role="presentation" width="100%"><tr>
<?php
        echo $kpi( __( 'Sales', 'rar-woo-stock-order' ), self::money( $t['sales'] ), self::pct( $t['sales'], $tp['sales'] ) . ' ' . __( 'vs day before', 'rar-woo-stock-order' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $kpi( __( 'Orders', 'rar-woo-stock-order' ), number_format_i18n( $t['sale_orders'] ), sprintf( /* translators: %s avg */ __( 'avg %s', 'rar-woo-stock-order' ), self::money( $t['avg'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $kpi( __( 'Staff app', 'rar-woo-stock-order' ), self::money( $tot['today']['staff'] ), sprintf( /* translators: %d orders */ _n( '%d order', '%d orders', $tot['today']['staff_n'], 'rar-woo-stock-order' ), $tot['today']['staff_n'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $kpi( __( 'Month to date', 'rar-woo-stock-order' ), self::money( $m['sales'] ), sprintf( /* translators: %d orders */ _n( '%d order', '%d orders', $m['sale_orders'], 'rar-woo-stock-order' ), $m['sale_orders'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
</tr></table></td></tr>
<tr><td style="padding:4px 24px 0;font:bold 15px Arial,sans-serif;color:#131b31"><?php esc_html_e( 'Needs attention', 'rar-woo-stock-order' ); ?></td></tr>
<tr><td style="padding:8px 24px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">
<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php esc_html_e( 'Orders waiting (Processing / On hold / Pending)', 'rar-woo-stock-order' ); ?></td><td style="<?php echo esc_attr( $cell ); ?>;text-align:right;font-weight:bold"><?php echo esc_html( number_format_i18n( $q['live'] ) ); ?><?php echo $q['stale'] ? ' <span style="color:#c0392b">(' . esc_html( sprintf( /* translators: %d count */ __( '%d over 24h', 'rar-woo-stock-order' ), $q['stale'] ) ) . ')</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php esc_html_e( 'Out of stock', 'rar-woo-stock-order' ); ?></td><td style="<?php echo esc_attr( $cell ); ?>;text-align:right;font-weight:bold;color:#c0392b"><?php echo esc_html( number_format_i18n( $c['out'] ) ); ?></td></tr>
<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( sprintf( /* translators: %d threshold */ __( 'Low stock (1–%d)', 'rar-woo-stock-order' ), RAR_WSO_Plugin::low_threshold() ) ); ?></td><td style="<?php echo esc_attr( $cell ); ?>;text-align:right;font-weight:bold;color:#b86e00"><?php echo esc_html( number_format_i18n( $c['low'] ) ); ?></td></tr>
<?php if ( $c['negative'] ) : ?>
<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php esc_html_e( 'Below zero (check counts)', 'rar-woo-stock-order' ); ?></td><td style="<?php echo esc_attr( $cell ); ?>;text-align:right;font-weight:bold;color:#c0392b"><?php echo esc_html( number_format_i18n( $c['negative'] ) ); ?></td></tr>
<?php endif; ?>
<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php esc_html_e( 'Wrong passwords on staff login today (so far)', 'rar-woo-stock-order' ); ?></td><td style="<?php echo esc_attr( $cell ); ?>;text-align:right;font-weight:bold"><?php echo esc_html( number_format_i18n( RAR_WSO_Security::failures_today() ) ); ?></td></tr>
</table></td></tr>
<?php
        $names = array_merge( (array) ( $d['stock']['out'] ?? array() ), (array) ( $d['stock']['low'] ?? array() ) );
        if ( $names ) :
            ?>
<tr><td style="padding:8px 24px;font:13px/1.6 Arial,sans-serif;color:#3a4562"><b><?php esc_html_e( 'Restock soon:', 'rar-woo-stock-order' ); ?></b> <?php echo esc_html( implode( ', ', array_map( static function ( $n ) { return $n['name'] . ' (' . ( null === $n['qty'] ? '—' : wc_stock_amount( $n['qty'] ) ) . ')'; }, array_slice( $names, 0, 10 ) ) ) ); ?></td></tr>
            <?php
        endif;
        $team = array_slice( $d['overview']['team'], 0, 8, true );
        if ( $team ) :
            ?>
<tr><td style="padding:12px 24px 0;font:bold 15px Arial,sans-serif;color:#131b31"><?php esc_html_e( 'Staff this month', 'rar-woo-stock-order' ); ?></td></tr>
<tr><td style="padding:8px 24px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">
<tr><th align="left" style="<?php echo esc_attr( $cell ); ?>;color:#66718c;font-size:12px"><?php esc_html_e( 'Staff', 'rar-woo-stock-order' ); ?></th><th align="right" style="<?php echo esc_attr( $cell ); ?>;color:#66718c;font-size:12px"><?php esc_html_e( 'Day', 'rar-woo-stock-order' ); ?></th><th align="right" style="<?php echo esc_attr( $cell ); ?>;color:#66718c;font-size:12px"><?php esc_html_e( 'Month', 'rar-woo-stock-order' ); ?></th></tr>
            <?php
            foreach ( $team as $uid => $row ) :
                $u = get_userdata( $uid );
                ?>
<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $u ? $u->display_name : '#' . $uid ); ?></td><td style="<?php echo esc_attr( $cell ); ?>;text-align:right"><?php echo esc_html( $row['today'] . ' · ' . self::money( $row['today_sales'] ) ); ?></td><td style="<?php echo esc_attr( $cell ); ?>;text-align:right;font-weight:bold"><?php echo esc_html( $row['orders'] . ' · ' . self::money( $row['sales'] ) ); ?></td></tr>
            <?php endforeach; ?>
</table></td></tr>
        <?php endif; ?>
<tr><td style="padding:18px 24px 24px;font:13px Arial,sans-serif;color:#66718c">
<a href="<?php echo esc_url( admin_url( 'admin.php?page=rar-wso' ) ); ?>" style="display:inline-block;background:<?php echo esc_attr( $brand ); ?>;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:bold"><?php esc_html_e( 'Open Control Center', 'rar-woo-stock-order' ); ?></a>
&nbsp; <a href="<?php echo esc_url( RAR_WSO_Plugin::staff_url() ); ?>" style="color:<?php echo esc_attr( $brand ); ?>"><?php esc_html_e( 'Staff app', 'rar-woo-stock-order' ); ?></a>
<p style="margin:16px 0 0;font-size:12px"><?php esc_html_e( 'Figures are net of refunds; pending payments, cancelled and failed orders are not counted as sales. Turn this email off in WooCommerce → Stock & Order → Settings → Reports.', 'rar-woo-stock-order' ); ?></p>
</td></tr>
</table>
</div>
        <?php
        return (string) ob_get_clean();
    }
}
