<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Screens of the Control Center (WooCommerce → Stock & Order).
 * Pure rendering: reads data, prints escaped HTML. All changes go through RAR_WSO_Admin handlers.
 */
class RAR_WSO_Admin_Views {

    /* ====================================================================
     * Small helpers
     * ================================================================== */

    public static function icon( $name, $size = 18 ) {
        static $paths = array(
            'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'activity'  => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'sliders'   => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
            'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'wrench'    => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            'help'      => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="m4.93 4.93 4.24 4.24M14.83 14.83l4.24 4.24M14.83 9.17l4.24-4.24M4.93 19.07l4.24-4.24"/>',
            'phone'     => '<rect x="5" y="2" width="14" height="20" rx="2.5"/><path d="M12 18h.01"/>',
            'qr'        => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/>',
            'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'external'  => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>',
            'plus'      => '<path d="M12 5v14M5 12h14"/>',
            'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
            'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
            'refresh'   => '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8M21 3v5h-5"/>',
            'check'     => '<path d="M20 6 9 17l-5-5"/>',
            'alert'     => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01"/>',
            'x'         => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
            'info'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
            'box'       => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05M12 22.08V12"/>',
            'cart'      => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
            'money'     => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
            'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'zap'       => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
            'lock'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'mail'      => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
            'printer'   => '<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
            'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
            'pause'     => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
            'play'      => '<path d="m6 3 14 9-14 9V3z"/>',
            'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'key'       => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
            'trash'     => '<path d="M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
            'up'        => '<path d="m23 6-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
            'down'      => '<path d="m23 18-9.5-9.5-5 5L1 6"/><path d="M17 18h6v-6"/>',
            'palette'   => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.5-.67 1.5-1.5 0-.39-.15-.74-.39-1.01-.24-.27-.38-.62-.38-1.02 0-.83.67-1.5 1.5-1.5H16c3.31 0 6-2.69 6-6 0-4.96-4.49-8.96-10-8.96z"/>',
            'truck'     => '<path d="M1 3h15v13H1zM16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
            'card'      => '<rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/>',
            'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
            'bell'      => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>',
            'server'    => '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
            'award'     => '<circle cx="12" cy="8" r="7"/><path d="M8.21 13.89 7 23l5-3 5 3-1.21-9.12"/>',
            'edit'      => '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'store'     => '<path d="M3 9l1.5-5h15L21 9M3 9h18v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9zM9 21v-6h6v6"/>',
            'chat'      => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
            'chevron'   => '<path d="m9 18 6-6-6-6"/>',
            'layers'    => '<path d="m12 2 10 5-10 5L2 7l10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>',
            'tag'       => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82zM7 7h.01"/>',
            'globe'     => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        );
        $p = $paths[ $name ] ?? $paths['info'];
        return '<svg class="rarx-i" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
    }

    /** Prints an icon (markup is built from a fixed internal list). */
    public static function i( $name, $size = 18 ) {
        echo self::icon( $name, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function symbol() {
        return trim( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ), " \t\n\r\0\x0B\xC2\xA0" );
    }

    /** 1,94,701 grouping for Bangladeshi Taka; the site's own grouping for other currencies. */
    public static function number( $n, $decimals = 0 ) {
        $n = (float) $n;
        if ( 'BDT' !== get_woocommerce_currency() ) {
            return number_format_i18n( $n, $decimals );
        }
        $neg  = $n < 0;
        $abs  = abs( $n );
        $int  = (string) floor( $abs + ( $decimals ? 0 : 0.5 ) );
        $frac = $decimals ? substr( number_format( $abs - floor( $abs ), $decimals, '.', '' ), 1 ) : '';
        if ( strlen( $int ) > 3 ) {
            $int = preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', substr( $int, 0, -3 ) ) . ',' . substr( $int, -3 );
        }
        return ( $neg ? '-' : '' ) . $int . $frac;
    }

    public static function money( $v ) {
        return self::symbol() . self::number( $v );
    }

    /** ৳19.5 L / ৳1.25 Cr for large figures on cards. */
    public static function money_short( $v ) {
        $v   = (float) $v;
        $abs = abs( $v );
        if ( 'BDT' === get_woocommerce_currency() && $abs >= 1e5 ) {
            return $abs >= 1e7 ? self::symbol() . number_format_i18n( $v / 1e7, 2 ) . ' Cr' : self::symbol() . number_format_i18n( $v / 1e5, $abs >= 1e6 ? 1 : 2 ) . ' L';
        }
        if ( 'BDT' !== get_woocommerce_currency() && $abs >= 1e6 ) {
            return self::symbol() . number_format_i18n( $v / 1e6, 2 ) . 'M';
        }
        return self::money( $v );
    }

    public static function qty( $v ) {
        return wc_stock_amount( $v ) == $v ? number_format_i18n( $v ) : number_format_i18n( $v, 2 ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
    }

    public static function ago( $ts ) {
        if ( ! $ts ) {
            return __( 'never', 'rar-woo-stock-order' );
        }
        $diff = time() - (int) $ts;
        if ( $diff < 60 ) {
            return __( 'just now', 'rar-woo-stock-order' );
        }
        /* translators: %s human time difference */
        return sprintf( __( '%s ago', 'rar-woo-stock-order' ), human_time_diff( (int) $ts, time() ) );
    }

    public static function when( $ts ) {
        return $ts ? wp_date( 'j M, g:i a', (int) $ts ) : '—';
    }

    private static function delta( $cur, $prev, $suffix = '' ) {
        if ( $prev <= 0 && $cur <= 0 ) {
            return '<span class="rarx-delta flat">—</span>' . ( $suffix ? ' ' . esc_html( $suffix ) : '' );
        }
        if ( $prev <= 0 ) {
            return '<span class="rarx-delta up">' . esc_html__( 'new', 'rar-woo-stock-order' ) . '</span>' . ( $suffix ? ' ' . esc_html( $suffix ) : '' );
        }
        $p   = ( $cur - $prev ) / $prev * 100;
        $cls = abs( $p ) < 0.5 ? 'flat' : ( $p > 0 ? 'up' : 'down' );
        return '<span class="rarx-delta ' . $cls . '">' . ( 'up' === $cls ? '▲' : ( 'down' === $cls ? '▼' : '•' ) ) . ' ' . esc_html( number_format_i18n( abs( $p ), abs( $p ) < 10 ? 1 : 0 ) ) . '%</span>' . ( $suffix ? ' ' . esc_html( $suffix ) : '' );
    }

    public static function initials( $name ) {
        $clean = trim( (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', '', (string) $name ) );
        $parts = preg_split( '/\s+/', '' !== $clean ? $clean : trim( (string) $name ) );
        $a     = isset( $parts[0] ) ? $parts[0] : '';
        $b     = count( $parts ) > 1 ? end( $parts ) : '';
        $f     = static function ( $s ) {
            return function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $s, 0, 1 ) ) : strtoupper( substr( $s, 0, 1 ) );
        };
        return $f( $a ) . ( $b ? $f( $b ) : '' );
    }

    /** Stable pastel hue per person for avatars. */
    private static function hue( $seed ) {
        return (int) ( hexdec( substr( md5( (string) $seed ), 0, 4 ) ) % 360 );
    }

    private static function avatar( $user ) {
        return '<span class="rarx-avatar" style="--h:' . (int) self::hue( $user->ID ) . '" aria-hidden="true">' . esc_html( self::initials( $user->display_name ) ) . '</span>';
    }

    private static function pill( $text, $tone ) {
        return '<span class="rarx-pill ' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span>';
    }

    /** A POST button to admin-post.php (tools / emergency actions). */
    private static function tool_button( $do, $label, $icon, $class = '', $confirm = '', $back = 'tools', $extra = '' ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rarx-inline-form"<?php echo $confirm ? ' data-rarx-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
            <input type="hidden" name="action" value="rar_wso_tool">
            <input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
            <input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>">
            <?php wp_nonce_field( 'rar_wso_tool_' . $do ); ?>
            <?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the caller ?>
            <button type="submit" class="rarx-btn <?php echo esc_attr( $class ); ?>"><?php self::i( $icon, 16 ); ?><span><?php echo esc_html( $label ); ?></span></button>
        </form>
        <?php
    }

    private static function export_button( $type, $label, $filters = array(), $class = '' ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rarx-inline-form">
            <input type="hidden" name="action" value="rar_wso_export">
            <input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
            <?php wp_nonce_field( 'rar_wso_export' ); ?>
            <?php foreach ( $filters as $k => $v ) : ?>
                <input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
            <?php endforeach; ?>
            <button type="submit" class="rarx-btn <?php echo esc_attr( $class ); ?>"><?php self::i( 'download', 16 ); ?><span><?php echo esc_html( $label ); ?></span></button>
        </form>
        <?php
    }

    private static function empty_state( $icon, $title, $text ) {
        ?>
        <div class="rarx-empty"><span class="rarx-empty-ico"><?php self::i( $icon, 22 ); ?></span><strong><?php echo esc_html( $title ); ?></strong><p><?php echo esc_html( $text ); ?></p></div>
        <?php
    }

        private static function stock_data() {
        $empty = array( 'counts' => array( 'all' => 0, 'ok' => 0, 'low' => 0, 'out' => 0, 'untracked' => 0, 'negative' => 0, 'live' => 0, 'units' => 0, 'value' => 0, 'threshold' => RAR_WSO_Plugin::low_threshold() ), 'low' => array(), 'out' => array() );
        return RAR_WSO_Ajax::$instance ? RAR_WSO_Ajax::$instance->dashboard_stock() : $empty;
    }

    private static function flash() {
        $key   = 'rar_wso_flash_' . get_current_user_id();
        $flash = get_transient( $key );
        if ( ! is_array( $flash ) ) {
            return;
        }
        delete_transient( $key );
        $error = 'error' === $flash['type'];
        ?>
        <div class="rarx-flash <?php echo $error ? 'is-error' : 'is-ok'; ?>" role="<?php echo $error ? 'alert' : 'status'; ?>">
            <span class="rarx-flash-ico"><?php self::i( $error ? 'alert' : 'check', 20 ); ?></span>
            <div class="rarx-flash-body">
                <p><?php echo esc_html( $flash['text'] ); ?></p>
                <?php if ( ! empty( $flash['link'] ) ) : ?>
                    <div class="rarx-linkbox">
                        <input type="text" readonly value="<?php echo esc_attr( $flash['link'] ); ?>" id="rarx-flash-link" aria-label="<?php esc_attr_e( 'One-time set-password link', 'rar-woo-stock-order' ); ?>">
                        <button type="button" class="rarx-btn sm" data-rarx-copy="#rarx-flash-link"><?php self::i( 'copy', 15 ); ?><span><?php esc_html_e( 'Copy', 'rar-woo-stock-order' ); ?></span></button>
                        <a class="rarx-btn sm wa" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( sprintf( /* translators: 1: link, 2: staff app */ __( "Set your staff app password (link works for 24 hours):\n%1\$s\n\nThen open the app: %2\$s", 'rar-woo-stock-order' ), $flash['link'], RAR_WSO_Plugin::staff_url() ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php self::i( 'chat', 15 ); ?><span>WhatsApp</span></a>
                    </div>
                <?php endif; ?>
            </div>
            <button type="button" class="rarx-flash-x" data-rarx-dismiss aria-label="<?php esc_attr_e( 'Dismiss', 'rar-woo-stock-order' ); ?>">×</button>
        </div>
        <?php
    }

    /* ====================================================================
     * Page shell
     * ================================================================== */

    public static function page( $tab ) {
        $s       = RAR_WSO_Plugin::settings();
        $on      = 'yes' === $s['enabled'];
        $logo    = RAR_WSO_Plugin::logo_url( 'thumbnail' );
        $staffs  = count( get_users( array( 'role' => 'rar_wso_staff', 'fields' => 'ID', 'number' => 1000 ) ) );
        $brand   = RAR_WSO_Plugin::brand_color();
        $counts  = array(
            'staff'    => $staffs,
            'security' => 0,
        );
        ?>
        <div class="wrap rarx" id="rarx" style="--rarx-brand:<?php echo esc_attr( $brand ); ?>">
            <header class="rarx-hero">
                <div class="rarx-hero-main">
                    <span class="rarx-hero-logo"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php else : ?><?php self::i( 'store', 26 ); ?><?php endif; ?></span>
                    <div class="rarx-hero-text">
                        <div class="rarx-eyebrow"><?php esc_html_e( 'RAR Woo Stock & Order', 'rar-woo-stock-order' ); ?> <span class="rarx-ver">v<?php echo esc_html( RAR_WSO_VERSION ); ?></span></div>
                        <h1><?php echo esc_html( RAR_WSO_Plugin::business_name() ); ?> <span><?php esc_html_e( 'Control Center', 'rar-woo-stock-order' ); ?></span></h1>
                        <p class="rarx-hero-status">
                            <span class="rarx-live-dot <?php echo $on ? 'on' : 'off'; ?>" aria-hidden="true"></span>
                            <?php echo $on ? esc_html__( 'Staff app is live', 'rar-woo-stock-order' ) : esc_html__( 'Staff app is switched off', 'rar-woo-stock-order' ); ?>
                            <span class="rarx-sep" aria-hidden="true">·</span>
                            <?php /* translators: %d staff count */ echo esc_html( sprintf( _n( '%d staff account', '%d staff accounts', $staffs, 'rar-woo-stock-order' ), $staffs ) ); ?>
                            <span class="rarx-sep" aria-hidden="true">·</span>
                            <?php echo esc_html( wp_date( 'l, j F Y' ) ); ?>
                        </p>
                    </div>
                </div>
                <div class="rarx-hero-actions">
                    <a class="rarx-btn light" href="<?php echo esc_url( RAR_WSO_Plugin::staff_url() ); ?>" target="_blank" rel="noopener"><?php self::i( 'phone', 16 ); ?><span><?php esc_html_e( 'Open staff app', 'rar-woo-stock-order' ); ?></span></a>
                    <button type="button" class="rarx-btn glass" data-rarx-copy-text="<?php echo esc_attr( RAR_WSO_Plugin::staff_url() ); ?>"><?php self::i( 'copy', 16 ); ?><span><?php esc_html_e( 'Copy link', 'rar-woo-stock-order' ); ?></span></button>
                    <button type="button" class="rarx-btn glass" data-rarx-qr><?php self::i( 'qr', 16 ); ?><span><?php esc_html_e( 'QR code', 'rar-woo-stock-order' ); ?></span></button>
                </div>
            </header>
            <hr class="wp-header-end">

            <nav class="rarx-tabs" aria-label="<?php esc_attr_e( 'Control Center sections', 'rar-woo-stock-order' ); ?>">
                <?php foreach ( RAR_WSO_Admin::tabs() as $key => $t ) : ?>
                    <a href="<?php echo esc_url( RAR_WSO_Admin::url( $key ) ); ?>" class="rarx-tab<?php echo $tab === $key ? ' is-active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>>
                        <?php self::i( $t[1], 17 ); ?><span><?php echo esc_html( $t[0] ); ?></span>
                        <?php if ( 'staff' === $key && $counts['staff'] ) : ?><em class="rarx-count"><?php echo esc_html( number_format_i18n( $counts['staff'] ) ); ?></em><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php self::flash(); ?>

            <div class="rarx-body rarx-tab-<?php echo esc_attr( $tab ); ?>">
                <?php
                switch ( $tab ) {
                    case 'staff':
                        self::tab_staff();
                        break;
                    case 'activity':
                        self::tab_activity();
                        break;
                    case 'settings':
                        self::tab_settings();
                        break;
                    case 'security':
                        self::tab_security();
                        break;
                    case 'tools':
                        self::tab_tools();
                        break;
                    case 'help':
                        self::tab_help();
                        break;
                    default:
                        self::tab_overview();
                }
                ?>
            </div>
            <p class="rarx-foot"><?php /* translators: %s version */ echo esc_html( sprintf( __( 'RAR Woo Stock & Order %s · figures in the site timezone (%s), net of refunds.', 'rar-woo-stock-order' ), RAR_WSO_VERSION, wp_timezone_string() ) ); ?></p>
            <?php self::qr_dialog(); ?>
        </div>
        <?php
    }

    private static function qr_dialog() {
        $s    = RAR_WSO_Plugin::settings();
        $logo = RAR_WSO_Plugin::logo_url( 'medium' );
        ?>
        <dialog class="rarx-dialog" id="rarx-qr-dialog" aria-labelledby="rarx-qr-title">
            <div class="rarx-print-card" id="rarx-print-card">
                <div class="rarx-pc-head">
                    <?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="" class="rarx-pc-logo"><?php endif; ?>
                    <div>
                        <strong id="rarx-qr-title"><?php echo esc_html( $s['dashboard_title'] ); ?></strong>
                        <span><?php echo esc_html( RAR_WSO_Plugin::business_name() ); ?> · <?php esc_html_e( 'Staff only', 'rar-woo-stock-order' ); ?></span>
                    </div>
                </div>
                <div class="rarx-qr" id="rarx-qr-box" role="img" aria-label="<?php esc_attr_e( 'QR code for the staff app link', 'rar-woo-stock-order' ); ?>"></div>
                <p class="rarx-pc-url"><?php echo esc_html( RAR_WSO_Plugin::staff_url() ); ?></p>
                <ol class="rarx-pc-steps">
                    <li><?php esc_html_e( 'Scan with the phone camera and open the link in Chrome (iPhone: Safari).', 'rar-woo-stock-order' ); ?> <span lang="bn">ফোনের ক্যামেরা দিয়ে স্ক্যান করুন।</span></li>
                    <li><?php esc_html_e( 'Sign in with the username and password the manager gave you.', 'rar-woo-stock-order' ); ?> <span lang="bn">নিজের username ও password দিন।</span></li>
                    <li><?php esc_html_e( 'Menu ⋮ → Install app / Add to Home screen (iPhone: Share → Add to Home Screen).', 'rar-woo-stock-order' ); ?> <span lang="bn">Home screen-এ app হিসেবে যোগ করুন।</span></li>
                </ol>
            </div>
            <div class="rarx-dialog-actions">
                <button type="button" class="rarx-btn primary" data-rarx-print><?php self::i( 'printer', 16 ); ?><span><?php esc_html_e( 'Print poster', 'rar-woo-stock-order' ); ?></span></button>
                <button type="button" class="rarx-btn" data-rarx-qr-png><?php self::i( 'download', 16 ); ?><span><?php esc_html_e( 'Download PNG', 'rar-woo-stock-order' ); ?></span></button>
                <button type="button" class="rarx-btn ghost" data-rarx-close><?php esc_html_e( 'Close', 'rar-woo-stock-order' ); ?></button>
            </div>
        </dialog>
        <?php
    }

    /* ====================================================================
     * Overview
     * ================================================================== */

    /** Alerts + KPI cards (also returned by the live-refresh endpoint). */
    public static function overview_live() {
        $s     = RAR_WSO_Plugin::settings();
        $today = RAR_WSO_Reports::period_stats( 'today' );
        $month = RAR_WSO_Reports::period_stats( 'month' );
        $ov    = RAR_WSO_Reports::admin_overview();
        $queue = RAR_WSO_Reports::status_overview( RAR_WSO_Ajax::live_statuses() );
        $stock = self::stock_data();
        $c     = $stock['counts'];
        $t     = $today['cur'];
        $tp    = $today['prev'];
        $m     = $month['cur'];
        $mp    = $month['prev'];
        $tot   = $ov['tot'];
        $share = $tot['today']['all'] > 0 ? $tot['today']['staff'] / $tot['today']['all'] * 100 : 0;
        $orders_url = admin_url( 'admin.php?page=wc-orders' );
        if ( ! RAR_WSO_Reports::hpos() ) {
            $orders_url = admin_url( 'edit.php?post_type=shop_order' );
        }

        $alerts = array();
        if ( 'yes' !== $s['enabled'] ) {
            $alerts[] = array( 'warn', __( 'The staff app is switched off — staff cannot sign in.', 'rar-woo-stock-order' ), RAR_WSO_Admin::url( 'settings' ) . '#rarx-sec-general', __( 'Settings', 'rar-woo-stock-order' ) );
        }
        if ( $c['negative'] > 0 ) {
            /* translators: %d count */
            $alerts[] = array( 'bad', sprintf( _n( '%d product shows stock below zero — recount it and correct the figure.', '%d products show stock below zero — recount them and correct the figures.', $c['negative'], 'rar-woo-stock-order' ), $c['negative'] ), RAR_WSO_Plugin::staff_url() . '#stock', __( 'Open Stock Manager', 'rar-woo-stock-order' ) );
        }
        if ( $queue['stale'] > 0 ) {
            /* translators: %d count */
            $alerts[] = array( 'warn', sprintf( _n( '%d order has been waiting more than 24 hours.', '%d orders have been waiting more than 24 hours.', $queue['stale'], 'rar-woo-stock-order' ), $queue['stale'] ), add_query_arg( 'status', 'wc-processing', $orders_url ), __( 'View orders', 'rar-woo-stock-order' ) );
        }
        foreach ( RAR_WSO_Health::checks( wp_doing_ajax() ) as $chk ) {
            if ( 'fail' === $chk['status'] ) {
                $alerts[] = array( 'bad', $chk['label'] . ': ' . $chk['detail'], $chk['url'] ? $chk['url'] : RAR_WSO_Admin::url( 'security' ), __( 'Fix', 'rar-woo-stock-order' ) );
            }
        }

        if ( $alerts ) :
            ?>
            <div class="rarx-alerts">
                <?php foreach ( array_slice( $alerts, 0, 5 ) as $a ) : ?>
                    <div class="rarx-alert <?php echo esc_attr( $a[0] ); ?>"><?php self::i( 'bad' === $a[0] ? 'alert' : 'bell', 18 ); ?><span><?php echo esc_html( $a[1] ); ?></span><a href="<?php echo esc_url( $a[2] ); ?>"<?php echo 0 === strpos( $a[2], RAR_WSO_Plugin::staff_url() ) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $a[3] ); ?> →</a></div>
                <?php endforeach; ?>
            </div>
            <?php
        endif;

        $kpis = array(
            array( 'money', 'blue', __( 'Sales today', 'rar-woo-stock-order' ), self::money( $t['sales'] ), self::delta( $t['sales'], $tp['sales'], __( 'vs same time yesterday', 'rar-woo-stock-order' ) ), $orders_url ),
            array( 'cart', 'violet', __( 'Orders today', 'rar-woo-stock-order' ), number_format_i18n( $t['sale_orders'] ), self::delta( $t['sale_orders'], $tp['sale_orders'] ) . ' ' . esc_html( sprintf( /* translators: %s money */ __( 'avg %s', 'rar-woo-stock-order' ), self::money( $t['avg'] ) ) ), $orders_url ),
            array( 'phone', 'teal', __( 'Staff app today', 'rar-woo-stock-order' ), self::money( $tot['today']['staff'] ), esc_html( sprintf( /* translators: 1: orders, 2: percent */ __( '%1$s orders · %2$s%% of sales', 'rar-woo-stock-order' ), number_format_i18n( $tot['today']['staff_n'] ), number_format_i18n( $share, 0 ) ) ), RAR_WSO_Admin::url( 'activity', array( 'view' => 'orders' ) ) ),
            array( 'up', 'green', __( 'Month to date', 'rar-woo-stock-order' ), self::money_short( $m['sales'] ), self::delta( $m['sales'], $mp['sales'], __( 'vs same days last month', 'rar-woo-stock-order' ) ), $orders_url ),
            array( 'clock', $queue['stale'] ? 'amber' : 'slate', __( 'Orders waiting', 'rar-woo-stock-order' ), number_format_i18n( $queue['live'] ), $queue['stale'] ? '<span class="rarx-delta down">' . esc_html( sprintf( /* translators: %d count */ __( '%d over 24h', 'rar-woo-stock-order' ), $queue['stale'] ) ) . '</span> ' . esc_html( self::money_short( $queue['live_value'] ) ) : esc_html( sprintf( /* translators: 1: processing, 2: on hold */ __( '%1$d processing · %2$d on hold', 'rar-woo-stock-order' ), $queue['processing'], $queue['on_hold'] ) ), add_query_arg( 'status', 'wc-processing', $orders_url ) ),
            array( 'box', 'indigo', __( 'Stock value', 'rar-woo-stock-order' ), self::money_short( $c['value'] ), esc_html( sprintf( /* translators: 1: units, 2: products */ __( '%1$s units · %2$s items (at selling price)', 'rar-woo-stock-order' ), self::number( $c['units'] ), number_format_i18n( $c['all'] ) ) ), RAR_WSO_Admin::url( 'tools' ) . '#rarx-exports' ),
            array( 'alert', $c['low'] ? 'amber' : 'slate', __( 'Low stock', 'rar-woo-stock-order' ), number_format_i18n( $c['low'] ), esc_html( sprintf( /* translators: %d threshold */ __( '1 to %d units left', 'rar-woo-stock-order' ), $c['threshold'] ) ), RAR_WSO_Plugin::staff_url() . '#stock' ),
            array( 'x', $c['out'] ? 'red' : 'slate', __( 'Out of stock', 'rar-woo-stock-order' ), number_format_i18n( $c['out'] ), $c['negative'] ? '<span class="rarx-delta down">' . esc_html( sprintf( /* translators: %d count */ __( '%d below zero', 'rar-woo-stock-order' ), $c['negative'] ) ) . '</span>' : esc_html__( 'none below zero', 'rar-woo-stock-order' ), admin_url( 'edit.php?post_type=product&stock_status=outofstock' ) ),
        );
        ?>
        <div class="rarx-kpis">
            <?php foreach ( $kpis as $k ) : ?>
                <a class="rarx-kpi tone-<?php echo esc_attr( $k[1] ); ?>" href="<?php echo esc_url( $k[5] ); ?>"<?php echo 0 === strpos( $k[5], RAR_WSO_Plugin::staff_url() ) ? ' target="_blank" rel="noopener"' : ''; ?>>
                    <span class="rarx-kpi-ico"><?php self::i( $k[0], 18 ); ?></span>
                    <span class="rarx-kpi-label"><?php echo esc_html( $k[2] ); ?></span>
                    <strong class="rarx-kpi-value"><?php echo esc_html( $k[3] ); ?></strong>
                    <span class="rarx-kpi-sub"><?php echo $k[4]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function chart( $days ) {
        $w     = 720;
        $h     = 220;
        $pad_l = 8;
        $pad_b = 26;
        $pad_t = 14;
        $n     = count( $days );
        $max   = 0.0;
        foreach ( $days as $d ) {
            $max = max( $max, $d['staff'] + $d['other'] );
        }
        $max   = $max > 0 ? $max * 1.12 : 1;
        $slot  = ( $w - $pad_l ) / max( 1, $n );
        $bw    = min( 34, $slot * 0.62 );
        $ch    = $h - $pad_b - $pad_t;
        ob_start();
        ?>
        <svg class="rarx-chart" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" role="img" aria-label="<?php esc_attr_e( 'Sales per day for the last 14 days, staff app and website', 'rar-woo-stock-order' ); ?>" preserveAspectRatio="none">
            <defs>
                <linearGradient id="rarx-g1" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--rarx-brand)" stop-opacity="1"/><stop offset="1" stop-color="var(--rarx-brand)" stop-opacity=".78"/></linearGradient>
            </defs>
            <?php for ( $g = 1; $g <= 3; $g++ ) : $gy = $pad_t + $ch - $ch * $g / 3; ?>
                <line x1="0" x2="<?php echo (int) $w; ?>" y1="<?php echo esc_attr( round( $gy, 1 ) ); ?>" y2="<?php echo esc_attr( round( $gy, 1 ) ); ?>" class="rarx-gridline"/>
            <?php endfor; ?>
            <?php
            foreach ( $days as $i => $d ) :
                $x     = $pad_l + $i * $slot + ( $slot - $bw ) / 2;
                $hs    = $ch * $d['staff'] / $max;
                $ho    = $ch * $d['other'] / $max;
                $base  = $pad_t + $ch;
                $date  = new DateTimeImmutable( $d['date'], wp_timezone() );
                $label = wp_date( 'j M', $date->getTimestamp() );
                $tip   = sprintf( '%s — %s: %s (%d) · %s: %s (%d)', wp_date( 'D j M', $date->getTimestamp() ), __( 'Staff app', 'rar-woo-stock-order' ), self::money( $d['staff'] ), $d['staff_n'], __( 'Website & other', 'rar-woo-stock-order' ), self::money( $d['other'] ), $d['other_n'] );
                $is_today = $i === $n - 1;
                ?>
                <g class="rarx-bar<?php echo $is_today ? ' is-today' : ''; ?>"><title><?php echo esc_html( $tip ); ?></title>
                    <rect x="<?php echo esc_attr( round( $x - 4, 1 ) ); ?>" y="<?php echo (int) $pad_t; ?>" width="<?php echo esc_attr( round( $bw + 8, 1 ) ); ?>" height="<?php echo (int) $ch; ?>" class="rarx-hit"/>
                    <?php if ( $ho > 0 ) : ?><rect x="<?php echo esc_attr( round( $x, 1 ) ); ?>" y="<?php echo esc_attr( round( $base - $hs - $ho, 1 ) ); ?>" width="<?php echo esc_attr( round( $bw, 1 ) ); ?>" height="<?php echo esc_attr( round( max( 1.5, $ho ), 1 ) ); ?>" rx="4" class="rarx-b-other"/><?php endif; ?>
                    <?php if ( $hs > 0 ) : ?><rect x="<?php echo esc_attr( round( $x, 1 ) ); ?>" y="<?php echo esc_attr( round( $base - $hs, 1 ) ); ?>" width="<?php echo esc_attr( round( $bw, 1 ) ); ?>" height="<?php echo esc_attr( round( max( 1.5, $hs ), 1 ) ); ?>" rx="4" fill="url(#rarx-g1)"/><?php endif; ?>
                    <?php if ( $hs <= 0 && $ho <= 0 ) : ?><rect x="<?php echo esc_attr( round( $x, 1 ) ); ?>" y="<?php echo esc_attr( $base - 2 ); ?>" width="<?php echo esc_attr( round( $bw, 1 ) ); ?>" height="2" rx="1" class="rarx-b-zero"/><?php endif; ?>
                    <?php if ( 0 === $i % 2 || $is_today ) : ?><text x="<?php echo esc_attr( round( $x + $bw / 2, 1 ) ); ?>" y="<?php echo (int) ( $h - 8 ); ?>" text-anchor="middle" class="rarx-axis"><?php echo esc_html( $is_today ? __( 'Today', 'rar-woo-stock-order' ) : $label ); ?></text><?php endif; ?>
                </g>
            <?php endforeach; ?>
        </svg>
        <?php
        return (string) ob_get_clean();
    }

    private static function tab_overview() {
        $ov     = RAR_WSO_Reports::admin_overview();
        $stock  = self::stock_data();
        $checks = RAR_WSO_Health::checks();
        $score  = RAR_WSO_Health::score( $checks );
        $issues = RAR_WSO_Health::problems( $checks );
        $sum14  = array( 'staff' => 0.0, 'other' => 0.0, 'staff_n' => 0, 'other_n' => 0 );
        foreach ( $ov['days'] as $d ) {
            foreach ( $sum14 as $k => $v ) {
                $sum14[ $k ] += $d[ $k ];
            }
        }
        $all14 = $sum14['staff'] + $sum14['other'];
        ?>
        <section class="rarx-live" data-rarx-live aria-live="polite">
            <?php self::overview_live(); ?>
        </section>
        <div class="rarx-live-bar">
            <span class="rarx-live-state" data-rarx-live-state><?php self::i( 'refresh', 14 ); ?> <?php /* translators: %s time */ echo esc_html( sprintf( __( 'Updated %s', 'rar-woo-stock-order' ), wp_date( 'g:i:s a' ) ) ); ?></span>
            <label class="rarx-switch sm"><input type="checkbox" data-rarx-live-toggle checked><span class="rarx-switch-ui" aria-hidden="true"></span><span><?php esc_html_e( 'Auto-refresh every minute', 'rar-woo-stock-order' ); ?></span></label>
            <button type="button" class="rarx-link" data-rarx-live-now><?php esc_html_e( 'Refresh now', 'rar-woo-stock-order' ); ?></button>
        </div>

        <div class="rarx-grid g-3">
            <section class="rarx-card span-2">
                <div class="rarx-card-h">
                    <div><h2><?php esc_html_e( 'Sales — last 14 days', 'rar-woo-stock-order' ); ?></h2>
                    <p class="rarx-muted"><?php /* translators: 1: total, 2: percent */ echo esc_html( sprintf( __( '%1$s in total · staff app %2$s%%', 'rar-woo-stock-order' ), self::money( $all14 ), number_format_i18n( $all14 > 0 ? $sum14['staff'] / $all14 * 100 : 0, 0 ) ) ); ?></p></div>
                    <div class="rarx-legend"><span><i class="lg-staff"></i><?php esc_html_e( 'Staff app', 'rar-woo-stock-order' ); ?></span><span><i class="lg-other"></i><?php esc_html_e( 'Website & other', 'rar-woo-stock-order' ); ?></span></div>
                </div>
                <div class="rarx-chart-wrap"><?php echo self::chart( $ov['days'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?></div>
                <div class="rarx-mini-stats">
                    <div><span><?php esc_html_e( 'Staff app orders', 'rar-woo-stock-order' ); ?></span><b><?php echo esc_html( number_format_i18n( $sum14['staff_n'] ) ); ?></b></div>
                    <div><span><?php esc_html_e( 'Website & other orders', 'rar-woo-stock-order' ); ?></span><b><?php echo esc_html( number_format_i18n( $sum14['other_n'] ) ); ?></b></div>
                    <div><span><?php esc_html_e( 'Average per day', 'rar-woo-stock-order' ); ?></span><b><?php echo esc_html( self::money( $all14 / 14 ) ); ?></b></div>
                </div>
            </section>

            <section class="rarx-card">
                <div class="rarx-card-h"><h2><?php esc_html_e( 'Health', 'rar-woo-stock-order' ); ?></h2><a class="rarx-link" href="<?php echo esc_url( RAR_WSO_Admin::url( 'security' ) ); ?>"><?php esc_html_e( 'Details', 'rar-woo-stock-order' ); ?> →</a></div>
                <div class="rarx-health-top">
                    <?php self::ring( $score ); ?>
                    <div>
                        <strong><?php echo $score >= 90 ? esc_html__( 'In great shape', 'rar-woo-stock-order' ) : ( $score >= 70 ? esc_html__( 'A few things to fix', 'rar-woo-stock-order' ) : esc_html__( 'Needs attention', 'rar-woo-stock-order' ) ); ?></strong>
                        <p class="rarx-muted"><?php /* translators: %d count */ echo esc_html( sprintf( _n( '%d item to review', '%d items to review', count( $issues ), 'rar-woo-stock-order' ), count( $issues ) ) ); ?></p>
                    </div>
                </div>
                <?php if ( $issues ) : ?>
                    <ul class="rarx-issues">
                        <?php foreach ( array_slice( $issues, 0, 4 ) as $iss ) : ?>
                            <li class="<?php echo esc_attr( $iss['status'] ); ?>"><?php self::i( 'fail' === $iss['status'] ? 'x' : 'alert', 15 ); ?><span><?php echo esc_html( $iss['label'] ); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p class="rarx-ok-line"><?php self::i( 'check', 16 ); ?> <?php esc_html_e( 'Every check passed.', 'rar-woo-stock-order' ); ?></p>
                <?php endif; ?>
            </section>
        </div>

        <div class="rarx-grid g-3">
            <section class="rarx-card span-2">
                <div class="rarx-card-h"><div><h2><?php esc_html_e( 'Team this month', 'rar-woo-stock-order' ); ?></h2><p class="rarx-muted"><?php esc_html_e( 'Orders created in the staff app (net of refunds; cancelled / failed not counted as sales)', 'rar-woo-stock-order' ); ?></p></div><a class="rarx-link" href="<?php echo esc_url( RAR_WSO_Admin::url( 'staff' ) ); ?>"><?php esc_html_e( 'All staff', 'rar-woo-stock-order' ); ?> →</a></div>
                <?php self::team_table( $ov['team'], 8 ); ?>
            </section>
            <section class="rarx-card">
                <div class="rarx-card-h"><h2><?php esc_html_e( 'Stock watch', 'rar-woo-stock-order' ); ?></h2><a class="rarx-link" href="<?php echo esc_url( RAR_WSO_Plugin::staff_url() . '#stock' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Stock Manager', 'rar-woo-stock-order' ); ?> ↗</a></div>
                <?php self::stock_watch( $stock ); ?>
            </section>
        </div>

        <div class="rarx-grid g-3">
            <section class="rarx-card span-2">
                <div class="rarx-card-h"><h2><?php esc_html_e( 'Recent activity', 'rar-woo-stock-order' ); ?></h2><a class="rarx-link" href="<?php echo esc_url( RAR_WSO_Admin::url( 'activity' ) ); ?>"><?php esc_html_e( 'Full log', 'rar-woo-stock-order' ); ?> →</a></div>
                <?php self::timeline(); ?>
            </section>
            <section class="rarx-card">
                <div class="rarx-card-h"><h2><?php esc_html_e( 'Quick actions', 'rar-woo-stock-order' ); ?></h2></div>
                <div class="rarx-quick">
                    <?php if ( RAR_WSO_Plugin::can_manage_staff() ) : ?>
                        <a class="rarx-q" href="<?php echo esc_url( RAR_WSO_Admin::url( 'staff' ) . '#rarx-add' ); ?>"><span class="qi tone-teal"><?php self::i( 'plus', 18 ); ?></span><span><b><?php esc_html_e( 'Add staff', 'rar-woo-stock-order' ); ?></b><small><?php esc_html_e( 'Create a login in 30 seconds', 'rar-woo-stock-order' ); ?></small></span></a>
                    <?php endif; ?>
                    <button type="button" class="rarx-q" data-rarx-qr><span class="qi tone-violet"><?php self::i( 'printer', 18 ); ?></span><span><b><?php esc_html_e( 'Print QR poster', 'rar-woo-stock-order' ); ?></b><small><?php esc_html_e( 'Staff scan it to install the app', 'rar-woo-stock-order' ); ?></small></span></button>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rarx-q-form">
                        <input type="hidden" name="action" value="rar_wso_export"><input type="hidden" name="type" value="stock"><?php wp_nonce_field( 'rar_wso_export' ); ?>
                        <button type="submit" class="rarx-q"><span class="qi tone-indigo"><?php self::i( 'download', 18 ); ?></span><span><b><?php esc_html_e( 'Stock valuation CSV', 'rar-woo-stock-order' ); ?></b><small><?php esc_html_e( 'Excel-ready, every product', 'rar-woo-stock-order' ); ?></small></span></button>
                    </form>
                    <a class="rarx-q" href="<?php echo esc_url( RAR_WSO_Admin::url( 'settings' ) ); ?>"><span class="qi tone-amber"><?php self::i( 'sliders', 18 ); ?></span><span><b><?php esc_html_e( 'Settings', 'rar-woo-stock-order' ); ?></b><small><?php esc_html_e( 'Discounts, shipping, branding', 'rar-woo-stock-order' ); ?></small></span></a>
                </div>
            </section>
        </div>
        <?php
    }

    private static function ring( $score ) {
        $r    = 30;
        $circ = 2 * M_PI * $r;
        $tone = $score >= 90 ? 'good' : ( $score >= 70 ? 'warn' : 'bad' );
        ?>
        <svg class="rarx-ring <?php echo esc_attr( $tone ); ?>" width="76" height="76" viewBox="0 0 76 76" role="img" aria-label="<?php /* translators: %d score */ echo esc_attr( sprintf( __( 'Health score %d out of 100', 'rar-woo-stock-order' ), $score ) ); ?>">
            <circle cx="38" cy="38" r="<?php echo (int) $r; ?>" class="rarx-ring-bg"/>
            <circle cx="38" cy="38" r="<?php echo (int) $r; ?>" class="rarx-ring-fg" stroke-dasharray="<?php echo esc_attr( round( $circ * $score / 100, 2 ) . ' ' . round( $circ, 2 ) ); ?>" transform="rotate(-90 38 38)"/>
            <text x="38" y="43" text-anchor="middle"><?php echo (int) $score; ?></text>
        </svg>
        <?php
    }

    private static function team_table( $team, $limit ) {
        if ( ! $team ) {
            self::empty_state( 'users', __( 'No staff-app orders this month yet', 'rar-woo-stock-order' ), __( 'When staff create orders in the app, their sales and discounts appear here.', 'rar-woo-stock-order' ) );
            return;
        }
        $rank = 0;
        ?>
        <div class="rarx-table-wrap">
        <table class="rarx-table rarx-team">
            <thead><tr><th scope="col">#</th><th scope="col"><?php esc_html_e( 'Staff', 'rar-woo-stock-order' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Today', 'rar-woo-stock-order' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Orders', 'rar-woo-stock-order' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Sales', 'rar-woo-stock-order' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Avg order', 'rar-woo-stock-order' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Discount', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Last order', 'rar-woo-stock-order' ); ?></th></tr></thead>
            <tbody>
            <?php
            foreach ( array_slice( $team, 0, $limit, true ) as $uid => $row ) :
                $rank++;
                $u      = get_userdata( $uid );
                $branch = (string) get_user_meta( $uid, 'rar_wso_branch', true );
                $avg    = $row['orders'] ? $row['sales'] / $row['orders'] : 0;
                $dpct   = ( $row['sales'] + $row['discount'] ) > 0 ? $row['discount'] / ( $row['sales'] + $row['discount'] ) * 100 : 0;
                ?>
                <tr>
                    <td data-label="#"><span class="rarx-rank r<?php echo (int) min( 4, $rank ); ?>"><?php echo (int) $rank; ?></span></td>
                    <td data-label="<?php esc_attr_e( 'Staff', 'rar-woo-stock-order' ); ?>" class="rarx-who"><?php if ( $u ) { echo self::avatar( $u ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><b><?php echo esc_html( $u ? $u->display_name : '#' . $uid ); ?></b><?php if ( '' !== $branch ) : ?><small><?php echo esc_html( $branch ); ?></small><?php endif; ?></span></td>
                    <td data-label="<?php esc_attr_e( 'Today', 'rar-woo-stock-order' ); ?>" class="num"><?php echo esc_html( $row['today'] ? $row['today'] . ' · ' . self::money_short( $row['today_sales'] ) : '—' ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Orders', 'rar-woo-stock-order' ); ?>" class="num"><?php echo esc_html( number_format_i18n( $row['orders'] ) ); ?><?php if ( $row['void'] ) : ?> <small class="rarx-muted" title="<?php esc_attr_e( 'Cancelled, failed or returned', 'rar-woo-stock-order' ); ?>">(+<?php echo (int) $row['void']; ?> ✕)</small><?php endif; ?></td>
                    <td data-label="<?php esc_attr_e( 'Sales', 'rar-woo-stock-order' ); ?>" class="num"><b><?php echo esc_html( self::money( $row['sales'] ) ); ?></b></td>
                    <td data-label="<?php esc_attr_e( 'Avg order', 'rar-woo-stock-order' ); ?>" class="num"><?php echo esc_html( self::money( $avg ) ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Discount', 'rar-woo-stock-order' ); ?>" class="num"><?php echo esc_html( $row['discount'] > 0 ? self::money( $row['discount'] ) . ' · ' . number_format_i18n( $dpct, 1 ) . '%' : '—' ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Last order', 'rar-woo-stock-order' ); ?>"><?php echo esc_html( self::ago( $row['last'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    private static function stock_watch( $stock ) {
        $c = $stock['counts'];
        $total = max( 1, $c['all'] );
        ?>
        <div class="rarx-stockbar" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: ok, 2: low, 3: out, 4: untracked */ __( '%1$d in stock, %2$d low, %3$d out, %4$d not tracked', 'rar-woo-stock-order' ), $c['ok'], $c['low'], $c['out'], $c['untracked'] ) ); ?>">
            <span class="ok" style="width:<?php echo esc_attr( round( $c['ok'] / $total * 100, 2 ) ); ?>%"></span><span class="low" style="width:<?php echo esc_attr( round( $c['low'] / $total * 100, 2 ) ); ?>%"></span><span class="out" style="width:<?php echo esc_attr( round( $c['out'] / $total * 100, 2 ) ); ?>%"></span><span class="un" style="width:<?php echo esc_attr( round( $c['untracked'] / $total * 100, 2 ) ); ?>%"></span>
        </div>
        <ul class="rarx-stocklegend">
            <li><i class="ok"></i><?php esc_html_e( 'In stock', 'rar-woo-stock-order' ); ?><b><?php echo esc_html( number_format_i18n( $c['ok'] ) ); ?></b></li>
            <li><i class="low"></i><?php esc_html_e( 'Low', 'rar-woo-stock-order' ); ?><b><?php echo esc_html( number_format_i18n( $c['low'] ) ); ?></b></li>
            <li><i class="out"></i><?php esc_html_e( 'Out', 'rar-woo-stock-order' ); ?><b><?php echo esc_html( number_format_i18n( $c['out'] ) ); ?></b></li>
            <li><i class="un"></i><?php esc_html_e( 'Not tracked', 'rar-woo-stock-order' ); ?><b><?php echo esc_html( number_format_i18n( $c['untracked'] ) ); ?></b></li>
        </ul>
        <?php
        $list = array_merge(
            array_map( static function ( $x ) { $x['k'] = 'out'; return $x; }, (array) $stock['out'] ),
            array_map( static function ( $x ) { $x['k'] = 'low'; return $x; }, (array) $stock['low'] )
        );
        if ( ! $list ) {
            echo '<p class="rarx-ok-line">' . self::icon( 'check', 16 ) . ' ' . esc_html__( 'Nothing is low or out of stock.', 'rar-woo-stock-order' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }
        ?>
        <ul class="rarx-watch">
            <?php foreach ( array_slice( $list, 0, 8 ) as $x ) : ?>
                <li><span class="rarx-dot <?php echo esc_attr( null !== $x['qty'] && $x['qty'] < 0 ? 'neg' : $x['k'] ); ?>"></span><span class="nm"><?php echo esc_html( $x['name'] ); ?></span><b class="<?php echo esc_attr( $x['k'] ); ?>"><?php echo esc_html( null === $x['qty'] ? '—' : self::qty( $x['qty'] ) ); ?></b></li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    private static function timeline() {
        $moves = RAR_WSO_Log::query( array( 'per_page' => 8 ) );
        $audit = RAR_WSO_Audit::query( array( 'per_page' => 6 ) );
        $items = array();
        foreach ( $moves['items'] as $x ) {
            $d       = ( null === $x['from'] || null === $x['to'] ) ? null : $x['to'] - $x['from'];
            $items[] = array(
                'time' => $x['time'],
                'ico'  => 'order' === $x['source'] ? 'cart' : 'box',
                'tone' => null === $d ? 'info' : ( $d >= 0 ? 'good' : 'warn' ),
                'text' => sprintf( '%s: %s → %s', $x['product'], null === $x['from'] ? '—' : self::qty( $x['from'] ), null === $x['to'] ? '—' : self::qty( $x['to'] ) ),
                'meta' => $x['reason'] . ( $x['user'] ? ' · ' . $x['user'] : '' ),
                'chip' => null === $d ? '' : ( $d > 0 ? '+' : '' ) . self::qty( $d ),
            );
        }
        foreach ( $audit['items'] as $x ) {
            $items[] = array(
                'time' => $x['time'],
                'ico'  => 'login' === $x['action'] ? 'logout' : ( 'bad' === $x['tone'] ? 'alert' : 'shield' ),
                'tone' => $x['tone'],
                'text' => $x['label'] . ( $x['message'] ? ': ' . $x['message'] : '' ),
                'meta' => $x['user'],
                'chip' => '',
            );
        }
        usort( $items, static function ( $a, $b ) { return $b['time'] <=> $a['time']; } );
        if ( ! $items ) {
            self::empty_state( 'activity', __( 'No activity yet', 'rar-woo-stock-order' ), __( 'Stock changes, sign-ins and admin actions will show here.', 'rar-woo-stock-order' ) );
            return;
        }
        ?>
        <ol class="rarx-timeline">
            <?php foreach ( array_slice( $items, 0, 10 ) as $it ) : ?>
                <li class="<?php echo esc_attr( $it['tone'] ); ?>">
                    <span class="tl-ico"><?php self::i( $it['ico'], 15 ); ?></span>
                    <div class="tl-main"><span class="tl-text"><?php echo esc_html( $it['text'] ); ?></span><span class="tl-meta"><?php echo esc_html( self::ago( $it['time'] ) ); ?><?php echo '' !== $it['meta'] ? ' · ' . esc_html( $it['meta'] ) : ''; ?></span></div>
                    <?php if ( '' !== $it['chip'] ) : ?><b class="tl-chip <?php echo esc_attr( $it['tone'] ); ?>"><?php echo esc_html( $it['chip'] ); ?></b><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }

    /* ====================================================================
     * Staff
     * ================================================================== */

    private static function tab_staff() {
        $can     = RAR_WSO_Plugin::can_manage_staff();
        $s       = RAR_WSO_Plugin::settings();
        $users   = get_users( array( 'role__in' => array( 'rar_wso_staff', 'shop_manager' ), 'orderby' => 'display_name', 'number' => 500 ) );
        $team    = RAR_WSO_Reports::admin_overview()['team'];
        $today0  = RAR_WSO_Reports::today_start()->getTimestamp();
        $sum     = array( 'staff' => 0, 'managers' => 0, 'online' => 0, 'today' => 0, 'paused' => 0, 'never' => 0 );
        $rows    = array();
        foreach ( $users as $u ) {
            $is_staff = array_values( (array) $u->roles ) === array( 'rar_wso_staff' );
            $paused   = RAR_WSO_Security::is_paused( $u->ID );
            $seen     = (int) get_user_meta( $u->ID, 'rar_wso_last_seen', true );
            $sum[ $is_staff ? 'staff' : 'managers' ]++;
            if ( $paused ) {
                $sum['paused']++;
            }
            if ( $seen && time() - $seen <= 10 * MINUTE_IN_SECONDS ) {
                $sum['online']++;
            }
            if ( $seen >= $today0 ) {
                $sum['today']++;
            }
            if ( ! $seen ) {
                $sum['never']++;
            }
            $rows[] = compact( 'u', 'is_staff', 'paused', 'seen' );
        }
        $chips = array(
            array( 'users', __( 'Staff accounts', 'rar-woo-stock-order' ), $sum['staff'], 'all' ),
            array( 'zap', __( 'Online now', 'rar-woo-stock-order' ), $sum['online'], 'online' ),
            array( 'clock', __( 'Active today', 'rar-woo-stock-order' ), $sum['today'], 'today' ),
            array( 'pause', __( 'Paused', 'rar-woo-stock-order' ), $sum['paused'], 'paused' ),
            array( 'award', __( 'Shop Managers', 'rar-woo-stock-order' ), $sum['managers'], 'manager' ),
        );
        ?>
        <div class="rarx-stat-chips" role="group" aria-label="<?php esc_attr_e( 'Filter staff', 'rar-woo-stock-order' ); ?>">
            <?php foreach ( $chips as $i => $c ) : ?>
                <button type="button" class="rarx-stat<?php echo 0 === $i ? ' is-on' : ''; ?>" data-rarx-filter="<?php echo esc_attr( $c[3] ); ?>" aria-pressed="<?php echo 0 === $i ? 'true' : 'false'; ?>"><?php self::i( $c[0], 16 ); ?><span><?php echo esc_html( $c[1] ); ?></span><b><?php echo esc_html( number_format_i18n( $c[2] ) ); ?></b></button>
            <?php endforeach; ?>
        </div>

        <section class="rarx-card">
            <div class="rarx-card-h wrap">
                <div><h2><?php esc_html_e( 'People', 'rar-woo-stock-order' ); ?></h2><p class="rarx-muted"><?php esc_html_e( 'Staff can only use the app (stock + orders). Pause, sign out or send a new password link at any time.', 'rar-woo-stock-order' ); ?></p></div>
                <div class="rarx-toolbar">
                    <label class="rarx-search"><?php self::i( 'search', 16 ); ?><input type="search" data-rarx-staff-q placeholder="<?php esc_attr_e( 'Search name, username, email, branch', 'rar-woo-stock-order' ); ?>" aria-label="<?php esc_attr_e( 'Search staff', 'rar-woo-stock-order' ); ?>"></label>
                    <?php if ( $can ) : ?><a class="rarx-btn primary" href="#rarx-add" data-rarx-focus="#rarx-add-name"><?php self::i( 'plus', 16 ); ?><span><?php esc_html_e( 'Add staff', 'rar-woo-stock-order' ); ?></span></a><?php endif; ?>
                </div>
            </div>

            <?php if ( ! $rows ) : ?>
                <?php self::empty_state( 'users', __( 'No staff yet', 'rar-woo-stock-order' ), __( 'Add the first staff account below. They get an email to set their own password.', 'rar-woo-stock-order' ) ); ?>
            <?php else : ?>
            <div class="rarx-people" data-rarx-people>
                <div class="rarx-people-head" aria-hidden="true"><span><?php esc_html_e( 'Person', 'rar-woo-stock-order' ); ?></span><span><?php esc_html_e( 'Role & status', 'rar-woo-stock-order' ); ?></span><span><?php esc_html_e( 'Last active', 'rar-woo-stock-order' ); ?></span><span><?php esc_html_e( 'This month', 'rar-woo-stock-order' ); ?></span><span><?php esc_html_e( 'Access', 'rar-woo-stock-order' ); ?></span><span></span></div>
                <?php
                foreach ( $rows as $r ) :
                    $u        = $r['u'];
                    $branch   = (string) get_user_meta( $u->ID, 'rar_wso_branch', true );
                    $online   = $r['seen'] && time() - $r['seen'] <= 10 * MINUTE_IN_SECONDS;
                    $stats    = $team[ $u->ID ] ?? null;
                    $own_disc = (string) get_user_meta( $u->ID, 'rar_wso_max_discount', true );
                    $own_view = (string) get_user_meta( $u->ID, 'rar_wso_view_orders', true );
                    $blocked  = array();
                    foreach ( RAR_WSO_Admin::staff_caps() as $cap => $label ) {
                        if ( ! user_can( $u, $cap ) ) {
                            $blocked[] = $label;
                        }
                    }
                    $filters = array( 'all' );
                    $filters[] = $r['is_staff'] ? 'staff' : 'manager';
                    if ( $r['paused'] ) {
                        $filters[] = 'paused';
                    }
                    if ( $online ) {
                        $filters[] = 'online';
                    }
                    if ( $r['seen'] >= $today0 ) {
                        $filters[] = 'today';
                    }
                    $search = strtolower( implode( ' ', array( $u->display_name, $u->user_login, $u->user_email, $branch ) ) );
                    $manage = $can && RAR_WSO_Admin::is_plain_staff( $u ) && get_current_user_id() !== (int) $u->ID;
                    ?>
                    <article class="rarx-person<?php echo $r['paused'] ? ' is-paused' : ''; ?>" id="rarx-staff-<?php echo (int) $u->ID; ?>" data-search="<?php echo esc_attr( $search ); ?>" data-filters="<?php echo esc_attr( implode( ' ', $filters ) ); ?>">
                        <div class="rarx-person-row">
                            <div class="rarx-who"><?php echo self::avatar( $u ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php if ( $online ) : ?><i class="rarx-online" title="<?php esc_attr_e( 'Online now', 'rar-woo-stock-order' ); ?>"></i><?php endif; ?>
                                <span><b><?php echo esc_html( $u->display_name ); ?></b><small><?php echo esc_html( $u->user_login ); ?> · <?php echo esc_html( $u->user_email ); ?></small><?php if ( '' !== $branch ) : ?><small class="rarx-branch"><?php self::i( 'tag', 12 ); ?> <?php echo esc_html( $branch ); ?></small><?php endif; ?></span>
                            </div>
                            <div class="rarx-cell" data-label="<?php esc_attr_e( 'Role & status', 'rar-woo-stock-order' ); ?>">
                                <?php echo $r['is_staff'] ? self::pill( __( 'Staff', 'rar-woo-stock-order' ), 'info' ) : self::pill( __( 'Shop Manager', 'rar-woo-stock-order' ), 'violet' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php echo $r['paused'] ? self::pill( __( 'Paused', 'rar-woo-stock-order' ), 'bad' ) : self::pill( __( 'Active', 'rar-woo-stock-order' ), 'good' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                            <div class="rarx-cell" data-label="<?php esc_attr_e( 'Last active', 'rar-woo-stock-order' ); ?>"><?php echo $online ? '<span class="rarx-on-txt">' . esc_html__( 'Online now', 'rar-woo-stock-order' ) . '</span>' : esc_html( $r['seen'] ? self::ago( $r['seen'] ) : __( 'Never signed in', 'rar-woo-stock-order' ) ); ?></div>
                            <div class="rarx-cell" data-label="<?php esc_attr_e( 'This month', 'rar-woo-stock-order' ); ?>"><?php echo $stats ? '<b>' . esc_html( self::money( $stats['sales'] ) ) . '</b><small>' . esc_html( sprintf( /* translators: %d orders */ _n( '%d order', '%d orders', $stats['orders'], 'rar-woo-stock-order' ), $stats['orders'] ) ) . '</small>' : '<span class="rarx-muted">—</span>'; ?></div>
                            <div class="rarx-cell rarx-access" data-label="<?php esc_attr_e( 'Access', 'rar-woo-stock-order' ); ?>">
                                <?php if ( ! $r['is_staff'] ) : ?>
                                    <small><?php esc_html_e( 'Full: order control, reports, all stock', 'rar-woo-stock-order' ); ?></small>
                                <?php else : ?>
                                    <small><?php /* translators: %s percent */ echo esc_html( sprintf( __( 'Discount up to %s%%', 'rar-woo-stock-order' ), '' !== $own_disc ? $own_disc : $s['staff_max_discount'] ) ); ?><?php echo '' !== $own_disc ? ' <em>' . esc_html__( '(personal)', 'rar-woo-stock-order' ) . '</em>' : ''; ?></small>
                                    <?php if ( $blocked ) : ?><small class="rarx-blocked"><?php /* translators: %s list */ echo esc_html( sprintf( __( 'Blocked: %s', 'rar-woo-stock-order' ), implode( ', ', $blocked ) ) ); ?></small><?php endif; ?>
                                    <?php if ( '' !== $own_view ) : ?><small><?php echo 'yes' === $own_view ? esc_html__( 'Order lists: allowed', 'rar-woo-stock-order' ) : esc_html__( 'Order lists: hidden', 'rar-woo-stock-order' ); ?></small><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="rarx-cell rarx-actions">
                                <?php if ( $manage ) : ?>
                                    <button type="button" class="rarx-btn sm" data-rarx-toggle="#rarx-manage-<?php echo (int) $u->ID; ?>" aria-expanded="false" aria-controls="rarx-manage-<?php echo (int) $u->ID; ?>"><?php self::i( 'edit', 15 ); ?><span><?php esc_html_e( 'Manage', 'rar-woo-stock-order' ); ?></span></button>
                                <?php elseif ( ! $r['is_staff'] && current_user_can( 'edit_user', $u->ID ) ) : ?>
                                    <a class="rarx-btn sm ghost" href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php esc_html_e( 'Edit in Users', 'rar-woo-stock-order' ); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ( $manage ) : ?>
                            <div class="rarx-manage" id="rarx-manage-<?php echo (int) $u->ID; ?>" hidden>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rarx-manage-form">
                                    <input type="hidden" name="action" value="rar_wso_staff_update">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
                                    <?php wp_nonce_field( 'rar_wso_staff_update_' . $u->ID ); ?>
                                    <div class="rarx-fields-2">
                                        <label class="rarx-f"><span><?php esc_html_e( 'Display name', 'rar-woo-stock-order' ); ?></span><input type="text" name="display_name" value="<?php echo esc_attr( $u->display_name ); ?>" maxlength="60"></label>
                                        <label class="rarx-f"><span><?php esc_html_e( 'Branch / territory', 'rar-woo-stock-order' ); ?></span><input type="text" name="branch" value="<?php echo esc_attr( $branch ); ?>" maxlength="60" placeholder="<?php esc_attr_e( 'e.g. Dhanmondi outlet', 'rar-woo-stock-order' ); ?>"></label>
                                        <label class="rarx-f"><span><?php esc_html_e( 'Personal discount limit (%)', 'rar-woo-stock-order' ); ?></span><input type="number" name="max_discount" min="0" max="100" step="0.5" value="<?php echo esc_attr( $own_disc ); ?>" placeholder="<?php /* translators: %s percent */ echo esc_attr( sprintf( __( 'Shop default (%s%%)', 'rar-woo-stock-order' ), $s['staff_max_discount'] ) ); ?>"></label>
                                        <label class="rarx-f"><span><?php esc_html_e( 'Today / 7-day / month order lists', 'rar-woo-stock-order' ); ?></span>
                                            <select name="view_orders">
                                                <option value=""><?php /* translators: %s yes/no */ echo esc_html( sprintf( __( 'Shop default (%s)', 'rar-woo-stock-order' ), 'yes' === $s['staff_view_orders'] ? __( 'allowed', 'rar-woo-stock-order' ) : __( 'hidden', 'rar-woo-stock-order' ) ) ); ?></option>
                                                <option value="yes" <?php selected( $own_view, 'yes' ); ?>><?php esc_html_e( 'Allow for this person', 'rar-woo-stock-order' ); ?></option>
                                                <option value="no" <?php selected( $own_view, 'no' ); ?>><?php esc_html_e( 'Hide for this person', 'rar-woo-stock-order' ); ?></option>
                                            </select>
                                        </label>
                                    </div>
                                    <fieldset class="rarx-perms"><legend><?php esc_html_e( 'Can use in the app', 'rar-woo-stock-order' ); ?></legend>
                                        <?php foreach ( RAR_WSO_Admin::staff_caps() as $cap => $label ) : ?>
                                            <label class="rarx-switch"><input type="checkbox" name="caps[]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( user_can( $u, $cap ) ); ?>><span class="rarx-switch-ui" aria-hidden="true"></span><span><?php echo esc_html( $label ); ?></span></label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                    <div class="rarx-manage-foot"><button type="submit" class="rarx-btn primary"><?php self::i( 'check', 16 ); ?><span><?php esc_html_e( 'Save changes', 'rar-woo-stock-order' ); ?></span></button><span class="rarx-muted"><?php esc_html_e( 'Applies the next time the app loads.', 'rar-woo-stock-order' ); ?></span></div>
                                </form>
                                <div class="rarx-manage-actions">
                                    <?php
                                    $act = static function ( $do, $label, $icon, $cls, $confirm ) use ( $u ) {
                                        ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $confirm ? ' data-rarx-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
                                            <input type="hidden" name="action" value="rar_wso_staff_action"><input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>"><input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
                                            <?php wp_nonce_field( 'rar_wso_staff_' . $u->ID ); ?>
                                            <button type="submit" class="rarx-btn sm <?php echo esc_attr( $cls ); ?>"><?php self::i( $icon, 15 ); ?><span><?php echo esc_html( $label ); ?></span></button>
                                        </form>
                                        <?php
                                    };
                                    if ( $r['paused'] ) {
                                        $act( 'resume', __( 'Resume', 'rar-woo-stock-order' ), 'play', 'good', '' );
                                    } else {
                                        $act( 'pause', __( 'Pause', 'rar-woo-stock-order' ), 'pause', 'warn', __( 'Pause this account and sign it out everywhere?', 'rar-woo-stock-order' ) );
                                    }
                                    $act( 'signout', __( 'Sign out everywhere', 'rar-woo-stock-order' ), 'logout', '', '' );
                                    $act( 'link', __( 'New password link', 'rar-woo-stock-order' ), 'key', '', '' );
                                    ?>
                                    <a class="rarx-btn sm ghost" href="<?php echo esc_url( RAR_WSO_Admin::url( 'activity', array( 'view' => 'orders', 'user_f' => $u->ID ) ) ); ?>"><?php self::i( 'cart', 15 ); ?><span><?php esc_html_e( 'Orders', 'rar-woo-stock-order' ); ?></span></a>
                                    <a class="rarx-btn sm ghost" href="<?php echo esc_url( RAR_WSO_Admin::url( 'activity', array( 'view' => 'stock', 'user_f' => $u->ID ) ) ); ?>"><?php self::i( 'box', 15 ); ?><span><?php esc_html_e( 'Stock changes', 'rar-woo-stock-order' ); ?></span></a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <p class="rarx-empty-filter" data-rarx-nomatch hidden><?php esc_html_e( 'No staff match this search.', 'rar-woo-stock-order' ); ?></p>
            </div>
            <?php endif; ?>
        </section>

        <section class="rarx-card" id="rarx-add">
            <div class="rarx-card-h"><div><h2><?php esc_html_e( 'Add a staff account', 'rar-woo-stock-order' ); ?></h2><p class="rarx-muted"><?php esc_html_e( 'Creates a Woo Stock & Order Staff login (stock updates + order creation only, no wp-admin). The person sets their own password from a one-time link — you never see or send a password.', 'rar-woo-stock-order' ); ?></p></div></div>
            <?php if ( $can ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rarx-add-form">
                    <input type="hidden" name="action" value="rar_wso_add_staff">
                    <input type="hidden" name="send_email_shown" value="1">
                    <?php wp_nonce_field( 'rar_wso_add_staff' ); ?>
                    <div class="rarx-fields-3">
                        <label class="rarx-f"><span><?php esc_html_e( 'Full name', 'rar-woo-stock-order' ); ?> *</span><input type="text" id="rarx-add-name" name="display_name" required autocomplete="off" maxlength="60"></label>
                        <label class="rarx-f"><span><?php esc_html_e( 'Username', 'rar-woo-stock-order' ); ?> *</span><input type="text" name="user_login" required minlength="3" maxlength="50" autocomplete="off" autocapitalize="none" spellcheck="false" pattern="[A-Za-z0-9_.@\-]+" title="<?php esc_attr_e( 'Letters, numbers, dot, dash, underscore', 'rar-woo-stock-order' ); ?>"></label>
                        <label class="rarx-f"><span><?php esc_html_e( 'Email', 'rar-woo-stock-order' ); ?> *</span><input type="email" name="user_email" required autocomplete="off"></label>
                        <label class="rarx-f"><span><?php esc_html_e( 'Branch / territory', 'rar-woo-stock-order' ); ?></span><input type="text" name="branch" maxlength="60" placeholder="<?php esc_attr_e( 'Optional', 'rar-woo-stock-order' ); ?>"></label>
                        <label class="rarx-f"><span><?php esc_html_e( 'Personal discount limit (%)', 'rar-woo-stock-order' ); ?></span><input type="number" name="max_discount" min="0" max="100" step="0.5" placeholder="<?php /* translators: %s percent */ echo esc_attr( sprintf( __( 'Shop default (%s%%)', 'rar-woo-stock-order' ), $s['staff_max_discount'] ) ); ?>"></label>
                        <label class="rarx-switch rarx-f-switch"><input type="checkbox" name="send_email" value="1" checked><span class="rarx-switch-ui" aria-hidden="true"></span><span><?php esc_html_e( 'Email the set-password link', 'rar-woo-stock-order' ); ?></span></label>
                    </div>
                    <div class="rarx-form-foot"><button type="submit" class="rarx-btn primary"><?php self::i( 'plus', 16 ); ?><span><?php esc_html_e( 'Create staff account', 'rar-woo-stock-order' ); ?></span></button><span class="rarx-muted"><?php esc_html_e( 'You also get the link on screen to send by WhatsApp.', 'rar-woo-stock-order' ); ?></span></div>
                </form>
            <?php else : ?>
                <div class="rarx-note"><?php self::i( 'lock', 16 ); ?><span><?php esc_html_e( 'Only an Administrator can add or pause staff accounts. An Administrator can allow Shop Managers to do it in Settings → Orders & pricing.', 'rar-woo-stock-order' ); ?></span></div>
            <?php endif; ?>
        </section>
        <?php
    }

    /* ====================================================================
     * Activity
     * ================================================================== */

    private static function tab_activity() {
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'stock'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = in_array( $view, array( 'stock', 'orders', 'audit' ), true ) ? $view : 'stock';
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $f    = RAR_WSO_Admin::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw  = array();
        foreach ( array( 's', 'from', 'to', 'user_f', 'source_f', 'status_f', 'action_f' ) as $k ) {
            $raw[ $k ] = isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        $views = array(
            'stock'  => array( __( 'Stock movements', 'rar-woo-stock-order' ), 'box' ),
            'orders' => array( __( 'Staff orders', 'rar-woo-stock-order' ), 'cart' ),
            'audit'  => array( __( 'Admin & security log', 'rar-woo-stock-order' ), 'shield' ),
        );
        $people = get_users( array( 'role__in' => array( 'rar_wso_staff', 'shop_manager', 'administrator' ), 'orderby' => 'display_name', 'number' => 500, 'fields' => array( 'ID', 'display_name' ) ) );
        ?>
        <div class="rarx-subtabs" role="tablist">
            <?php foreach ( $views as $k => $v ) : ?>
                <a role="tab" aria-selected="<?php echo $view === $k ? 'true' : 'false'; ?>" class="<?php echo $view === $k ? 'is-active' : ''; ?>" href="<?php echo esc_url( RAR_WSO_Admin::url( 'activity', array( 'view' => $k ) ) ); ?>"><?php self::i( $v[1], 16 ); ?><span><?php echo esc_html( $v[0] ); ?></span></a>
            <?php endforeach; ?>
        </div>

        <section class="rarx-card">
            <form method="get" class="rarx-filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr( RAR_WSO_Admin::PAGE ); ?>"><input type="hidden" name="tab" value="activity"><input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
                <label class="rarx-search grow"><?php self::i( 'search', 16 ); ?><input type="search" name="s" value="<?php echo esc_attr( $raw['s'] ); ?>" placeholder="<?php echo 'stock' === $view ? esc_attr__( 'Product, SKU or reason', 'rar-woo-stock-order' ) : ( 'audit' === $view ? esc_attr__( 'Details or IP address', 'rar-woo-stock-order' ) : esc_attr__( 'Order number', 'rar-woo-stock-order' ) ); ?>" aria-label="<?php esc_attr_e( 'Search', 'rar-woo-stock-order' ); ?>"<?php echo 'orders' === $view ? ' disabled' : ''; ?>></label>
                <label class="rarx-f inline"><span><?php esc_html_e( 'From', 'rar-woo-stock-order' ); ?></span><input type="date" name="from" value="<?php echo esc_attr( $raw['from'] ); ?>"></label>
                <label class="rarx-f inline"><span><?php esc_html_e( 'To', 'rar-woo-stock-order' ); ?></span><input type="date" name="to" value="<?php echo esc_attr( $raw['to'] ); ?>"></label>
                <label class="rarx-f inline"><span><?php esc_html_e( 'Person', 'rar-woo-stock-order' ); ?></span><select name="user_f"><option value=""><?php esc_html_e( 'Everyone', 'rar-woo-stock-order' ); ?></option><?php foreach ( $people as $p ) : ?><option value="<?php echo (int) $p->ID; ?>" <?php selected( (int) $raw['user_f'], (int) $p->ID ); ?>><?php echo esc_html( $p->display_name ); ?></option><?php endforeach; ?></select></label>
                <?php if ( 'stock' === $view ) : ?>
                    <label class="rarx-f inline"><span><?php esc_html_e( 'Source', 'rar-woo-stock-order' ); ?></span><select name="source_f"><option value=""><?php esc_html_e( 'All', 'rar-woo-stock-order' ); ?></option><option value="staff" <?php selected( $raw['source_f'], 'staff' ); ?>><?php esc_html_e( 'Staff app updates', 'rar-woo-stock-order' ); ?></option><option value="order" <?php selected( $raw['source_f'], 'order' ); ?>><?php esc_html_e( 'Orders (sold / restocked)', 'rar-woo-stock-order' ); ?></option></select></label>
                <?php elseif ( 'orders' === $view ) : ?>
                    <label class="rarx-f inline"><span><?php esc_html_e( 'Status', 'rar-woo-stock-order' ); ?></span><select name="status_f"><option value=""><?php esc_html_e( 'All', 'rar-woo-stock-order' ); ?></option><?php foreach ( RAR_WSO_Ajax::all_statuses() as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $raw['status_f'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
                <?php else : ?>
                    <label class="rarx-f inline"><span><?php esc_html_e( 'Event', 'rar-woo-stock-order' ); ?></span><select name="action_f"><option value=""><?php esc_html_e( 'All', 'rar-woo-stock-order' ); ?></option><option value="security" <?php selected( $raw['action_f'], 'security' ); ?>><?php esc_html_e( 'Security events only', 'rar-woo-stock-order' ); ?></option><?php foreach ( RAR_WSO_Audit::actions() as $k => $a ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $raw['action_f'], $k ); ?>><?php echo esc_html( $a[0] ); ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>
                <div class="rarx-filter-btns">
                    <button type="submit" class="rarx-btn primary"><?php self::i( 'search', 15 ); ?><span><?php esc_html_e( 'Apply', 'rar-woo-stock-order' ); ?></span></button>
                    <a class="rarx-btn ghost" href="<?php echo esc_url( RAR_WSO_Admin::url( 'activity', array( 'view' => $view ) ) ); ?>"><?php esc_html_e( 'Reset', 'rar-woo-stock-order' ); ?></a>
                </div>
            </form>
            <div class="rarx-filters-export">
                <?php self::export_button( 'stock' === $view ? 'movements' : $view, __( 'Export CSV (these filters)', 'rar-woo-stock-order' ), array_filter( $raw ) ); ?>
            </div>
            <?php
            if ( 'orders' === $view ) {
                self::orders_table( $f, $page );
            } elseif ( 'audit' === $view ) {
                self::audit_table( $f, $page );
            } else {
                self::moves_table( $f, $page );
            }
            ?>
        </section>
        <?php
    }

    private static function pager( $page, $total, $per ) {
        $pages = max( 1, (int) ceil( $total / $per ) );
        if ( $pages <= 1 ) {
            /* translators: %s count */
            echo '<p class="rarx-pager-info">' . esc_html( sprintf( _n( '%s entry', '%s entries', $total, 'rar-woo-stock-order' ), number_format_i18n( $total ) ) ) . '</p>';
            return;
        }
        $url = static function ( $p ) {
            return esc_url( add_query_arg( 'paged', $p ) );
        };
        ?>
        <nav class="rarx-pager" aria-label="<?php esc_attr_e( 'Pages', 'rar-woo-stock-order' ); ?>">
            <span class="rarx-pager-info"><?php /* translators: 1: page, 2: pages, 3: total */ echo esc_html( sprintf( __( 'Page %1$s of %2$s · %3$s entries', 'rar-woo-stock-order' ), number_format_i18n( $page ), number_format_i18n( $pages ), number_format_i18n( $total ) ) ); ?></span>
            <span class="rarx-pager-btns">
                <?php if ( $page > 1 ) : ?><a class="rarx-btn sm" href="<?php echo $url( 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">« <?php esc_html_e( 'First', 'rar-woo-stock-order' ); ?></a><a class="rarx-btn sm" href="<?php echo $url( $page - 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">‹ <?php esc_html_e( 'Previous', 'rar-woo-stock-order' ); ?></a><?php endif; ?>
                <?php if ( $page < $pages ) : ?><a class="rarx-btn sm" href="<?php echo $url( $page + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Next', 'rar-woo-stock-order' ); ?> ›</a><?php endif; ?>
            </span>
        </nav>
        <?php
    }

    private static function moves_table( $f, $page ) {
        $per = 25;
        $res = RAR_WSO_Log::query( array_merge( $f, array( 'page' => $page, 'per_page' => $per ) ) );
        if ( ! $res['items'] ) {
            self::empty_state( 'box', __( 'No stock changes found', 'rar-woo-stock-order' ), __( 'Try a wider date range or clear the filters.', 'rar-woo-stock-order' ) );
            return;
        }
        ?>
        <div class="rarx-table-wrap"><table class="rarx-table rarx-cards-sm">
            <thead><tr><th scope="col"><?php esc_html_e( 'When', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Product', 'rar-woo-stock-order' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Stock', 'rar-woo-stock-order' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Change', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Reason', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'By', 'rar-woo-stock-order' ); ?></th></tr></thead>
            <tbody>
            <?php
            foreach ( $res['items'] as $x ) :
                $d = ( null === $x['from'] || null === $x['to'] ) ? null : $x['to'] - $x['from'];
                ?>
                <tr>
                    <td data-label="<?php esc_attr_e( 'When', 'rar-woo-stock-order' ); ?>"><?php echo esc_html( self::when( $x['time'] ) ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Product', 'rar-woo-stock-order' ); ?>"><b><?php echo esc_html( $x['product'] ); ?></b><?php if ( $x['sku'] ) : ?><small class="rarx-sku"><?php echo esc_html( $x['sku'] ); ?></small><?php endif; ?></td>
                    <td data-label="<?php esc_attr_e( 'Stock', 'rar-woo-stock-order' ); ?>" class="num"><?php echo esc_html( ( null === $x['from'] ? '—' : self::qty( $x['from'] ) ) . ' → ' . ( null === $x['to'] ? '—' : self::qty( $x['to'] ) ) ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Change', 'rar-woo-stock-order' ); ?>" class="num"><?php echo null === $d ? '—' : '<b class="rarx-chg ' . ( $d >= 0 ? 'up' : 'down' ) . '">' . esc_html( ( $d > 0 ? '+' : '' ) . self::qty( $d ) ) . '</b>'; ?></td>
                    <td data-label="<?php esc_attr_e( 'Reason', 'rar-woo-stock-order' ); ?>"><?php echo esc_html( $x['reason'] ); ?><?php if ( $x['order_id'] ) : $o = wc_get_order( $x['order_id'] ); ?> <?php if ( $o ) : ?><a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>">#<?php echo esc_html( $o->get_order_number() ); ?></a><?php endif; ?><?php endif; ?></td>
                    <td data-label="<?php esc_attr_e( 'By', 'rar-woo-stock-order' ); ?>"><?php echo 'order' === $x['source'] ? self::pill( __( 'Order', 'rar-woo-stock-order' ), 'info' ) : esc_html( $x['user'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
        self::pager( $page, $res['total'], $per );
    }

    public static function status_tone( $status ) {
        $map = array( 'completed' => 'good', 'processing' => 'info', 'on-hold' => 'violet', 'pending' => 'warn', 'cancelled' => 'muted', 'refunded' => 'bad', 'failed' => 'bad' );
        return $map[ $status ] ?? ( false !== strpos( $status, 'return' ) ? 'bad' : 'teal' );
    }

    private static function orders_table( $f, $page ) {
        $per = 25;
        if ( '' !== $f['search'] ) {
            $f['search'] = '';
        }
        $res = RAR_WSO_Reports::staff_orders( array_merge( $f, array( 'page' => $page, 'per_page' => $per ) ) );
        if ( ! $res['ids'] ) {
            self::empty_state( 'cart', __( 'No staff orders found', 'rar-woo-stock-order' ), __( 'Orders created in the staff app appear here with the staff member who took them.', 'rar-woo-stock-order' ) );
            return;
        }
        $names = array();
        ?>
        <div class="rarx-table-wrap"><table class="rarx-table rarx-cards-sm">
            <thead><tr><th scope="col"><?php esc_html_e( 'Order', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'When', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Customer', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Staff', 'rar-woo-stock-order' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Items', 'rar-woo-stock-order' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Total', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'rar-woo-stock-order' ); ?></th></tr></thead>
            <tbody>
            <?php
            foreach ( $res['ids'] as $id ) :
                $o = wc_get_order( $id );
                if ( ! $o ) {
                    continue;
                }
                $uid = (int) $o->get_meta( '_rar_wso_created_by' );
                if ( ! isset( $names[ $uid ] ) ) {
                    $u             = get_userdata( $uid );
                    $names[ $uid ] = $u ? $u->display_name : '#' . $uid;
                }
                $created = $o->get_date_created();
                $disc    = (float) $o->get_meta( '_rar_wso_discount' );
                ?>
                <tr>
                    <td data-label="<?php esc_attr_e( 'Order', 'rar-woo-stock-order' ); ?>"><a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>"><b>#<?php echo esc_html( $o->get_order_number() ); ?></b></a></td>
                    <td data-label="<?php esc_attr_e( 'When', 'rar-woo-stock-order' ); ?>"><?php echo esc_html( $created ? self::when( $created->getTimestamp() ) : '—' ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Customer', 'rar-woo-stock-order' ); ?>"><?php echo esc_html( trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) ); ?><small class="rarx-sku"><?php echo esc_html( $o->get_billing_phone() ); ?></small></td>
                    <td data-label="<?php esc_attr_e( 'Staff', 'rar-woo-stock-order' ); ?>"><?php echo esc_html( $names[ $uid ] ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Items', 'rar-woo-stock-order' ); ?>" class="num"><?php echo esc_html( number_format_i18n( $o->get_item_count() ) ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Total', 'rar-woo-stock-order' ); ?>" class="num"><b><?php echo esc_html( self::money( $o->get_total() - $o->get_total_refunded() ) ); ?></b><?php if ( $disc > 0 ) : ?><small class="rarx-sku"><?php /* translators: %s money */ echo esc_html( sprintf( __( 'discount %s', 'rar-woo-stock-order' ), self::money( $disc ) ) ); ?></small><?php endif; ?></td>
                    <td data-label="<?php esc_attr_e( 'Status', 'rar-woo-stock-order' ); ?>"><?php echo self::pill( wc_get_order_status_name( $o->get_status() ), self::status_tone( $o->get_status() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
        self::pager( $page, $res['total'], $per );
    }

    private static function audit_table( $f, $page ) {
        $per = 25;
        $res = RAR_WSO_Audit::query( array_merge( $f, array( 'page' => $page, 'per_page' => $per ) ) );
        if ( ! $res['items'] ) {
            self::empty_state( 'shield', __( 'Nothing logged yet', 'rar-woo-stock-order' ), __( 'Staff sign-ins, login locks, staff changes, settings changes and exports are recorded here.', 'rar-woo-stock-order' ) );
            return;
        }
        self::audit_rows( $res['items'] );
        self::pager( $page, $res['total'], $per );
    }

    private static function audit_rows( $items ) {
        ?>
        <div class="rarx-table-wrap"><table class="rarx-table rarx-cards-sm">
            <thead><tr><th scope="col"><?php esc_html_e( 'When', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Event', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'By', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'IP', 'rar-woo-stock-order' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $items as $x ) : ?>
                <tr>
                    <td data-label="<?php esc_attr_e( 'When', 'rar-woo-stock-order' ); ?>"><?php echo esc_html( self::when( $x['time'] ) ); ?></td>
                    <td data-label="<?php esc_attr_e( 'Event', 'rar-woo-stock-order' ); ?>"><?php echo self::pill( $x['label'], $x['tone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    <td data-label="<?php esc_attr_e( 'Details', 'rar-woo-stock-order' ); ?>"><?php echo esc_html( $x['message'] ); ?></td>
                    <td data-label="<?php esc_attr_e( 'By', 'rar-woo-stock-order' ); ?>"><?php echo esc_html( $x['user'] ); ?></td>
                    <td data-label="<?php esc_attr_e( 'IP', 'rar-woo-stock-order' ); ?>"><code><?php echo esc_html( $x['ip'] ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    }

    /* ====================================================================
     * Settings
     * ================================================================== */

    private static function field( $label, $help, $control, $for = '' ) {
        ?>
        <div class="rarx-field">
            <div class="rarx-field-l"><?php if ( $for ) : ?><label for="<?php echo esc_attr( $for ); ?>"><?php echo esc_html( $label ); ?></label><?php else : ?><span class="rarx-field-t"><?php echo esc_html( $label ); ?></span><?php endif; ?><?php if ( $help ) : ?><p><?php echo esc_html( $help ); ?></p><?php endif; ?></div>
            <div class="rarx-field-c"><?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- controls are built with esc_* below ?></div>
        </div>
        <?php
    }

    private static function sw( $key, $label, $s, $disabled = false ) {
        return '<label class="rarx-switch"><input type="checkbox" name="rar_wso_settings[' . esc_attr( $key ) . ']" value="1"' . checked( $s[ $key ], 'yes', false ) . disabled( $disabled, true, false ) . '><span class="rarx-switch-ui" aria-hidden="true"></span><span>' . esc_html( $label ) . '</span></label>';
    }

    private static function inp( $key, $s, $type = 'text', $attrs = '', $suffix = '', $prefix = '' ) {
        $id = 'rarx-f-' . $key;
        $in = '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="rar_wso_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '" ' . $attrs . '>';
        if ( $prefix || $suffix ) {
            return '<span class="rarx-affix">' . ( $prefix ? '<em>' . esc_html( $prefix ) . '</em>' : '' ) . $in . ( $suffix ? '<em>' . esc_html( $suffix ) . '</em>' : '' ) . '</span>';
        }
        return $in;
    }

    private static function tab_settings() {
        $s        = RAR_WSO_Plugin::settings();
        $can_save = current_user_can( 'manage_options' );
        $sections = array(
            'general'  => array( __( 'General', 'rar-woo-stock-order' ), 'store' ),
            'orders'   => array( __( 'Orders & pricing', 'rar-woo-stock-order' ), 'cart' ),
            'payments' => array( __( 'Payments', 'rar-woo-stock-order' ), 'card' ),
            'shipping' => array( __( 'Shipping', 'rar-woo-stock-order' ), 'truck' ),
            'stock'    => array( __( 'Stock', 'rar-woo-stock-order' ), 'box' ),
            'brand'    => array( __( 'Branding & slip', 'rar-woo-stock-order' ), 'palette' ),
            'security' => array( __( 'Access & security', 'rar-woo-stock-order' ), 'lock' ),
            'reports'  => array( __( 'Reports & integrations', 'rar-woo-stock-order' ), 'bell' ),
        );
        $cur      = self::symbol();
        $logo     = RAR_WSO_Plugin::logo_url( 'thumbnail' );
        $updated  = isset( $_GET['settings-updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        settings_errors( 'rar_wso_settings' );
        if ( $updated ) {
            echo '<div class="rarx-flash is-ok" role="status"><span class="rarx-flash-ico">' . self::icon( 'check', 20 ) . '</span><div class="rarx-flash-body"><p>' . esc_html__( 'Settings saved. The staff app uses them on its next load.', 'rar-woo-stock-order' ) . '</p></div><button type="button" class="rarx-flash-x" data-rarx-dismiss aria-label="' . esc_attr__( 'Dismiss', 'rar-woo-stock-order' ) . '">×</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        $statuses = array();
        foreach ( wc_get_order_statuses() as $key => $label ) {
            $slug = substr( $key, 3 );
            if ( ! in_array( $slug, array( 'refunded', 'cancelled', 'failed', 'checkout-draft' ), true ) ) {
                $statuses[ $slug ] = $label;
            }
        }
        $all_pay = RAR_WSO_Ajax::payment_options();
        $on_pay  = array_filter( explode( ',', (string) $s['payment_methods'] ) );
        $presets = array( '#15234a', '#0f3d3e', '#1f2937', '#5b21b6', '#9f1239', '#0b5ed7', '#0a7f6a', '#b45309' );
        ?>
        <div class="rarx-settings">
            <nav class="rarx-snav" aria-label="<?php esc_attr_e( 'Settings sections', 'rar-woo-stock-order' ); ?>">
                <?php foreach ( $sections as $k => $v ) : ?>
                    <a href="#rarx-sec-<?php echo esc_attr( $k ); ?>" data-rarx-spy="rarx-sec-<?php echo esc_attr( $k ); ?>"><?php self::i( $v[1], 16 ); ?><span><?php echo esc_html( $v[0] ); ?></span></a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="rarx-settings-form" data-rarx-dirty-form>
                <?php settings_fields( 'rar_wso_group' ); ?>
                <input type="hidden" name="rar_wso_settings[_form]" value="1">
                <?php if ( ! $can_save ) : ?>
                    <div class="rarx-note"><?php self::i( 'lock', 16 ); ?><span><?php esc_html_e( 'Only an Administrator can change these settings. You can see them for reference.', 'rar-woo-stock-order' ); ?></span></div>
                <?php endif; ?>
                <fieldset <?php disabled( ! $can_save ); ?> class="rarx-fieldset">

                <section class="rarx-card rarx-sec" id="rarx-sec-general">
                    <h2><?php self::i( 'store', 18 ); ?> <?php esc_html_e( 'General', 'rar-woo-stock-order' ); ?></h2>
                    <?php
                    self::field( __( 'Staff app', 'rar-woo-stock-order' ), __( 'When off, staff cannot sign in to the app and nothing can be saved from it.', 'rar-woo-stock-order' ), self::sw( 'enabled', __( 'Staff app is on', 'rar-woo-stock-order' ), $s ) );
                    self::field( __( 'App name', 'rar-woo-stock-order' ), __( 'Shown on the phone home screen, login page and app header.', 'rar-woo-stock-order' ), self::inp( 'dashboard_title', $s, 'text', 'maxlength="40" data-rarx-preview="title"' ), 'rarx-f-dashboard_title' );
                    self::field( __( 'Staff link', 'rar-woo-stock-order' ), __( 'Changing it breaks installed apps and bookmarks — staff must open the new link and install again.', 'rar-woo-stock-order' ), self::inp( 'staff_slug', $s, 'text', 'maxlength="40" pattern="[a-z0-9\-]+" spellcheck="false"', '/', untrailingslashit( preg_replace( '#^https?://#', '', home_url( '/' ) ) ) . '/' ), 'rarx-f-staff_slug' );
                    self::field( __( 'Business name', 'rar-woo-stock-order' ), __( 'Printed on order slips and WhatsApp summaries. Empty = the site title.', 'rar-woo-stock-order' ), self::inp( 'business_name', $s, 'text', 'maxlength="80" placeholder="' . esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '"' ), 'rarx-f-business_name' );
                    ?>
                </section>

                <section class="rarx-card rarx-sec" id="rarx-sec-orders">
                    <h2><?php self::i( 'cart', 18 ); ?> <?php esc_html_e( 'Orders & pricing', 'rar-woo-stock-order' ); ?></h2>
                    <?php
                    $opts = '';
                    foreach ( $statuses as $slug => $label ) {
                        $opts .= '<option value="' . esc_attr( $slug ) . '"' . selected( $s['default_order_status'], $slug, false ) . '>' . esc_html( $label ) . '</option>';
                    }
                    self::field( __( 'Status of new staff orders', 'rar-woo-stock-order' ), __( 'Processing takes the stock and sends the usual WooCommerce emails.', 'rar-woo-stock-order' ), '<select id="rarx-f-status" name="rar_wso_settings[default_order_status]">' . $opts . '</select>', 'rarx-f-status' );
                    self::field( __( 'Change item rates', 'rar-woo-stock-order' ), __( 'A lower rate counts toward the discount limit, together with the discount.', 'rar-woo-stock-order' ), self::sw( 'allow_price_override', __( 'Staff may change the rate of an item while billing', 'rar-woo-stock-order' ), $s ) );
                    self::field( __( 'Staff discount limit', 'rar-woo-stock-order' ), __( 'Largest total reduction (discount + lower rates) on one order, as a percent of the list-price subtotal. 0 = none. A personal limit can be set per person in the Staff tab. Shop Managers are not limited.', 'rar-woo-stock-order' ), self::inp( 'staff_max_discount', $s, 'number', 'min="0" max="100" step="0.5" class="small"', '%' ), 'rarx-f-staff_max_discount' );
                    self::field( __( 'Order lists for staff', 'rar-woo-stock-order' ), __( 'Today / 7-day / month lists, view only. Status changes, All Orders and reports stay with Shop Managers.', 'rar-woo-stock-order' ), self::sw( 'staff_view_orders', __( 'Staff can open order lists', 'rar-woo-stock-order' ), $s ) );
                    self::field( __( 'Staff accounts', 'rar-woo-stock-order' ), current_user_can( 'promote_users' ) ? __( 'Administrators can always add and pause staff.', 'rar-woo-stock-order' ) : __( 'Only an Administrator can change this.', 'rar-woo-stock-order' ), self::sw( 'managers_add_staff', __( 'Shop Managers may add, pause and sign out staff', 'rar-woo-stock-order' ), $s, ! current_user_can( 'promote_users' ) ) );
                    ?>
                </section>

                <section class="rarx-card rarx-sec" id="rarx-sec-payments">
                    <h2><?php self::i( 'card', 18 ); ?> <?php esc_html_e( 'Payments', 'rar-woo-stock-order' ); ?></h2>
                    <?php
                    $boxes = '<div class="rarx-checks">';
                    foreach ( RAR_WSO_Ajax::builtin_payment_keys() as $key ) {
                        $boxes .= '<label class="rarx-check"><input type="checkbox" name="rar_wso_settings[payment_methods][]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $on_pay, true ), true, false ) . '><span>' . esc_html( $all_pay[ $key ] ?? $key ) . '</span></label>';
                    }
                    $boxes .= '</div>';
                    self::field( __( 'Payment methods in the app', 'rar-woo-stock-order' ), __( 'Untick the ones your shop does not take. If none is ticked and no extra method is set, all are shown.', 'rar-woo-stock-order' ), $boxes );
                    self::field( __( 'Extra payment methods', 'rar-woo-stock-order' ), __( 'One per line, e.g. Rocket, Upay, Bank transfer, Card (POS).', 'rar-woo-stock-order' ), '<textarea id="rarx-f-payment_extra" name="rar_wso_settings[payment_extra]" rows="3" placeholder="Rocket&#10;Bank transfer">' . esc_textarea( $s['payment_extra'] ) . '</textarea>', 'rarx-f-payment_extra' );
                    $popts = '';
                    foreach ( $all_pay as $key => $label ) {
                        $popts .= '<option value="' . esc_attr( $key ) . '"' . selected( RAR_WSO_Ajax::default_payment(), $key, false ) . '>' . esc_html( $label ) . '</option>';
                    }
                    self::field( __( 'Selected first', 'rar-woo-stock-order' ), __( 'Payment method already chosen on a new order.', 'rar-woo-stock-order' ), '<select id="rarx-f-payment_default" name="rar_wso_settings[payment_default]">' . $popts . '</select>', 'rarx-f-payment_default' );
                    ?>
                </section>

                <section class="rarx-card rarx-sec" id="rarx-sec-shipping">
                    <h2><?php self::i( 'truck', 18 ); ?> <?php esc_html_e( 'Shipping', 'rar-woo-stock-order' ); ?></h2>
                    <?php
                    self::field( __( 'Inside Dhaka', 'rar-woo-stock-order' ), __( 'Filled in automatically when the district is Dhaka. Staff can still change it on the order.', 'rar-woo-stock-order' ), self::inp( 'shipping_dhaka', $s, 'number', 'min="0" step="0.01" class="small"', '', $cur ), 'rarx-f-shipping_dhaka' );
                    self::field( __( 'Outside Dhaka', 'rar-woo-stock-order' ), __( 'Used for every other district.', 'rar-woo-stock-order' ), self::inp( 'shipping_outside', $s, 'number', 'min="0" step="0.01" class="small"', '', $cur ), 'rarx-f-shipping_outside' );
                    self::field( __( 'Before a district is chosen', 'rar-woo-stock-order' ), __( 'Also used when the two fields above are empty.', 'rar-woo-stock-order' ), self::inp( 'default_shipping', $s, 'number', 'min="0" step="0.01" class="small"', '', $cur ), 'rarx-f-default_shipping' );
                    self::field( __( 'Free delivery from', 'rar-woo-stock-order' ), __( 'Items subtotal at which the automatic shipping becomes 0. 0 = never. Staff can still type a charge.', 'rar-woo-stock-order' ), self::inp( 'free_shipping_over', $s, 'number', 'min="0" step="1" class="small"', '', $cur ), 'rarx-f-free_shipping_over' );
                    ?>
                </section>

                <section class="rarx-card rarx-sec" id="rarx-sec-stock">
                    <h2><?php self::i( 'box', 18 ); ?> <?php esc_html_e( 'Stock', 'rar-woo-stock-order' ); ?></h2>
                    <?php
                    self::field( __( 'Low stock level', 'rar-woo-stock-order' ), __( 'From 1 up to this number shows orange (low); above it green; 0 or less red.', 'rar-woo-stock-order' ), self::inp( 'low_stock_threshold', $s, 'number', 'min="1" step="1" class="small"', __( 'units', 'rar-woo-stock-order' ) ), 'rarx-f-low_stock_threshold' );
                    self::field( __( 'Reasons for stock changes', 'rar-woo-stock-order' ), __( 'The list Shop Managers pick from in the Stock Manager, one per line (up to 20). Every change is saved in the stock history with its reason.', 'rar-woo-stock-order' ), '<textarea id="rarx-f-stock_reasons" name="rar_wso_settings[stock_reasons]" rows="6">' . esc_textarea( $s['stock_reasons'] ) . '</textarea>', 'rarx-f-stock_reasons' );
                    ?>
                </section>

                <section class="rarx-card rarx-sec" id="rarx-sec-brand">
                    <h2><?php self::i( 'palette', 18 ); ?> <?php esc_html_e( 'Branding & slip', 'rar-woo-stock-order' ); ?></h2>
                    <div class="rarx-brand-grid">
                        <div>
                            <?php
                            $sw = '<div class="rarx-color"><input type="color" id="rarx-f-brand_color" name="rar_wso_settings[brand_color]" value="' . esc_attr( RAR_WSO_Plugin::brand_color() ) . '" data-rarx-preview="color"><code data-rarx-color-code>' . esc_html( RAR_WSO_Plugin::brand_color() ) . '</code><div class="rarx-swatches">';
                            foreach ( $presets as $p ) {
                                $sw .= '<button type="button" class="rarx-swatch" style="background:' . esc_attr( $p ) . '" data-rarx-swatch="' . esc_attr( $p ) . '" aria-label="' . esc_attr( $p ) . '"></button>';
                            }
                            $sw .= '</div></div>';
                            self::field( __( 'Brand colour', 'rar-woo-stock-order' ), __( 'App header, phone status bar, order slip and the daily email.', 'rar-woo-stock-order' ), $sw, 'rarx-f-brand_color' );
                            $lg  = '<div class="rarx-logo-pick"><input type="hidden" name="rar_wso_settings[logo_id]" value="' . esc_attr( $s['logo_id'] ) . '" data-rarx-logo-id>';
                            $lg .= '<span class="rarx-logo-prev" data-rarx-logo-prev>' . ( $logo ? '<img src="' . esc_url( $logo ) . '" alt="">' : self::icon( 'store', 22 ) ) . '</span>';
                            $lg .= current_user_can( 'upload_files' ) ? '<button type="button" class="rarx-btn sm" data-rarx-logo-pick>' . esc_html__( 'Choose logo', 'rar-woo-stock-order' ) . '</button> <button type="button" class="rarx-btn sm ghost" data-rarx-logo-clear' . ( $logo ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'rar-woo-stock-order' ) . '</button>' : '<span class="rarx-muted">' . esc_html__( 'Needs permission to upload files.', 'rar-woo-stock-order' ) . '</span>';
                            $lg .= '</div>';
                            self::field( __( 'Logo', 'rar-woo-stock-order' ), __( 'Square image works best (at least 192 × 192). Shown in the app header, login page, slip and QR poster.', 'rar-woo-stock-order' ), $lg );
                            self::field( __( 'Phone on slip', 'rar-woo-stock-order' ), '', self::inp( 'slip_phone', $s, 'text', 'maxlength="40" placeholder="01XXXXXXXXX"' ), 'rarx-f-slip_phone' );
                            self::field( __( 'Address on slip', 'rar-woo-stock-order' ), '', self::inp( 'slip_address', $s, 'text', 'maxlength="140"' ), 'rarx-f-slip_address' );
                            self::field( __( 'Slip footer line', 'rar-woo-stock-order' ), '', self::inp( 'slip_footer', $s, 'text', 'maxlength="120"' ), 'rarx-f-slip_footer' );
                            ?>
                        </div>
                        <div class="rarx-preview" aria-hidden="true">
                            <div class="rarx-phone">
                                <div class="rarx-phone-top" data-rarx-preview-top style="background:<?php echo esc_attr( RAR_WSO_Plugin::brand_color() ); ?>">
                                    <span class="rarx-phone-mark" data-rarx-preview-mark><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php else : ?><?php echo esc_html( self::initials( RAR_WSO_Plugin::business_name() ) ); ?><?php endif; ?></span>
                                    <span><b data-rarx-preview-title><?php echo esc_html( $s['dashboard_title'] ); ?></b><small><?php echo esc_html( wp_get_current_user()->display_name ); ?> · Staff</small></span>
                                </div>
                                <div class="rarx-phone-body"><i></i><i></i><i class="w"></i><i></i></div>
                            </div>
                            <p class="rarx-muted"><?php esc_html_e( 'Live preview', 'rar-woo-stock-order' ); ?></p>
                        </div>
                    </div>
                </section>

                <section class="rarx-card rarx-sec" id="rarx-sec-security">
                    <h2><?php self::i( 'lock', 18 ); ?> <?php esc_html_e( 'Access & security', 'rar-woo-stock-order' ); ?></h2>
                    <?php
                    self::field( __( 'Wrong passwords per username', 'rar-woo-stock-order' ), __( 'After this many wrong passwords for one account, the staff login form locks for that account.', 'rar-woo-stock-order' ), self::inp( 'login_user_limit', $s, 'number', 'min="3" max="50" step="1" class="small"' ), 'rarx-f-login_user_limit' );
                    self::field( __( 'Wrong passwords per network', 'rar-woo-stock-order' ), __( 'Covers one attacker trying many usernames. Keep it higher if many staff share the shop Wi-Fi.', 'rar-woo-stock-order' ), self::inp( 'login_ip_limit', $s, 'number', 'min="5" max="200" step="1" class="small"' ), 'rarx-f-login_ip_limit' );
                    self::field( __( 'Lock time', 'rar-woo-stock-order' ), '', self::inp( 'lockout_minutes', $s, 'number', 'min="5" max="1440" step="1" class="small"', __( 'minutes', 'rar-woo-stock-order' ) ), 'rarx-f-lockout_minutes' );
                    self::field( __( '"Keep me signed in" for staff', 'rar-woo-stock-order' ), __( 'How long a staff phone stays signed in. Shorter is safer if phones are shared; Shop Managers and Administrators keep the WordPress default.', 'rar-woo-stock-order' ), self::inp( 'session_days', $s, 'number', 'min="1" max="90" step="1" class="small"', __( 'days', 'rar-woo-stock-order' ) ), 'rarx-f-session_days' );
                    ?>
                </section>

                <section class="rarx-card rarx-sec" id="rarx-sec-reports">
                    <h2><?php self::i( 'bell', 18 ); ?> <?php esc_html_e( 'Reports & integrations', 'rar-woo-stock-order' ); ?></h2>
                    <?php
                    $next = RAR_WSO_Digest::next_run();
                    self::field( __( 'Daily summary email', 'rar-woo-stock-order' ), $next ? sprintf( /* translators: %s time */ __( 'Next email: %s. Sales, staff orders, stock alerts and waiting orders in one email.', 'rar-woo-stock-order' ), wp_date( 'D j M, g:i a', $next ) ) : __( 'Sales, staff orders, stock alerts and waiting orders in one email every evening.', 'rar-woo-stock-order' ), self::sw( 'digest_enabled', __( 'Send the daily summary', 'rar-woo-stock-order' ), $s ) );
                    self::field( __( 'Send to', 'rar-woo-stock-order' ), __( 'One or more emails, separated by commas. Empty = the site admin email.', 'rar-woo-stock-order' ), self::inp( 'digest_email', $s, 'text', 'placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"' ), 'rarx-f-digest_email' );
                    $hours = '';
                    for ( $h = 0; $h < 24; $h++ ) {
                        $hours .= '<option value="' . $h . '"' . selected( (int) $s['digest_hour'], $h, false ) . '>' . esc_html( gmdate( 'g:00 a', $h * HOUR_IN_SECONDS ) ) . '</option>';
                    }
                    self::field( __( 'Send at', 'rar-woo-stock-order' ), __( 'Site time. WP-Cron sends it on the first visit after this time; a hosting cron job makes it exact.', 'rar-woo-stock-order' ), '<select id="rarx-f-digest_hour" name="rar_wso_settings[digest_hour]">' . $hours . '</select>', 'rarx-f-digest_hour' );
                    $keep = '';
                    foreach ( array( 0 => __( 'Keep everything', 'rar-woo-stock-order' ), 90 => __( '90 days', 'rar-woo-stock-order' ), 180 => __( '6 months', 'rar-woo-stock-order' ), 365 => __( '1 year', 'rar-woo-stock-order' ), 730 => __( '2 years', 'rar-woo-stock-order' ), 1825 => __( '5 years', 'rar-woo-stock-order' ) ) as $d => $l ) {
                        $keep .= '<option value="' . (int) $d . '"' . selected( (int) $s['log_retention_days'], $d, false ) . '>' . esc_html( $l ) . '</option>';
                    }
                    if ( ! in_array( (int) $s['log_retention_days'], array( 0, 90, 180, 365, 730, 1825 ), true ) ) {
                        $keep .= '<option value="' . (int) $s['log_retention_days'] . '" selected>' . esc_html( sprintf( /* translators: %d days */ __( '%d days', 'rar-woo-stock-order' ), (int) $s['log_retention_days'] ) ) . '</option>';
                    }
                    self::field( __( 'Keep stock & audit history', 'rar-woo-stock-order' ), __( 'Older entries are deleted once a day. Orders and products are never touched.', 'rar-woo-stock-order' ), '<select id="rarx-f-log_retention_days" name="rar_wso_settings[log_retention_days]">' . $keep . '</select>', 'rarx-f-log_retention_days' );
                    self::field( __( 'Shortcuts in WordPress', 'rar-woo-stock-order' ), '', '<div class="rarx-stack">' . self::sw( 'admin_bar_link', __( '"Staff App" menu in the top admin bar', 'rar-woo-stock-order' ), $s ) . self::sw( 'dashboard_widget', __( '"Stock & Order — today" box on the WordPress Dashboard', 'rar-woo-stock-order' ), $s ) . self::sw( 'order_column', __( '"Staff" column on the WooCommerce orders list', 'rar-woo-stock-order' ), $s ) . '</div>' );
                    ?>
                </section>
                </fieldset>

                <?php if ( $can_save ) : ?>
                <div class="rarx-savebar" data-rarx-savebar>
                    <span class="rarx-savebar-msg" data-rarx-dirty-msg><?php self::i( 'info', 16 ); ?> <?php esc_html_e( 'Changes are saved for the whole shop.', 'rar-woo-stock-order' ); ?></span>
                    <button type="submit" class="rarx-btn primary lg"><?php self::i( 'check', 17 ); ?><span><?php esc_html_e( 'Save changes', 'rar-woo-stock-order' ); ?></span></button>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    /* ====================================================================
     * Security & Health
     * ================================================================== */

    private static function tab_security() {
        $checks = RAR_WSO_Health::checks();
        $score  = RAR_WSO_Health::score( $checks );
        $reg    = RAR_WSO_Security::registration_status();
        $s      = RAR_WSO_Plugin::settings();
        $groups = array(
            'app'      => array( __( 'Staff app', 'rar-woo-stock-order' ), 'phone' ),
            'security' => array( __( 'Security', 'rar-woo-stock-order' ), 'shield' ),
            'server'   => array( __( 'Server', 'rar-woo-stock-order' ), 'server' ),
        );
        $cnt = array( 'ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );
        foreach ( $checks as $c ) {
            $cnt[ $c['status'] ]++;
        }
        $blocked = get_transient( 'rar_wso_role_blocked' );
        ?>
        <div class="rarx-grid g-3">
            <section class="rarx-card rarx-score-card">
                <?php self::ring( $score ); ?>
                <div>
                    <h2><?php esc_html_e( 'Health score', 'rar-woo-stock-order' ); ?></h2>
                    <p class="rarx-counts"><span class="good"><?php self::i( 'check', 14 ); ?> <?php /* translators: %d count */ echo esc_html( sprintf( __( '%d passed', 'rar-woo-stock-order' ), $cnt['ok'] ) ); ?></span><span class="warn"><?php self::i( 'alert', 14 ); ?> <?php /* translators: %d count */ echo esc_html( sprintf( __( '%d to review', 'rar-woo-stock-order' ), $cnt['warn'] ) ); ?></span><span class="bad"><?php self::i( 'x', 14 ); ?> <?php /* translators: %d count */ echo esc_html( sprintf( __( '%d to fix', 'rar-woo-stock-order' ), $cnt['fail'] ) ); ?></span></p>
                    <p class="rarx-muted"><?php esc_html_e( 'Read-only checks. Nothing is changed until you press a button.', 'rar-woo-stock-order' ); ?></p>
                </div>
            </section>
            <section class="rarx-card span-2">
                <div class="rarx-card-h"><h2><?php esc_html_e( 'Staff login protection', 'rar-woo-stock-order' ); ?></h2><a class="rarx-link" href="<?php echo esc_url( RAR_WSO_Admin::url( 'settings' ) . '#rarx-sec-security' ); ?>"><?php esc_html_e( 'Change limits', 'rar-woo-stock-order' ); ?> →</a></div>
                <div class="rarx-mini-stats four">
                    <div><span><?php esc_html_e( 'Wrong passwords today', 'rar-woo-stock-order' ); ?></span><b><?php echo esc_html( number_format_i18n( $reg['today'] ) ); ?></b></div>
                    <div><span><?php esc_html_e( 'Since install', 'rar-woo-stock-order' ); ?></span><b><?php echo esc_html( number_format_i18n( $reg['failures'] ) ); ?></b></div>
                    <div><span><?php esc_html_e( 'Lock after', 'rar-woo-stock-order' ); ?></span><b><?php /* translators: 1: per user, 2: per network */ echo esc_html( sprintf( __( '%1$d / %2$d tries', 'rar-woo-stock-order' ), RAR_WSO_Security::user_limit(), RAR_WSO_Security::ip_limit() ) ); ?></b></div>
                    <div><span><?php esc_html_e( 'Lock time', 'rar-woo-stock-order' ); ?></span><b><?php /* translators: %d minutes */ echo esc_html( sprintf( __( '%d min', 'rar-woo-stock-order' ), (int) round( RAR_WSO_Security::window() / 60 ) ) ); ?></b></div>
                </div>
                <?php if ( $blocked ) : ?>
                    <div class="rarx-alert warn"><?php self::i( 'shield', 18 ); ?><span><?php /* translators: %s role */ echo esc_html( sprintf( __( 'Blocked: something tried to make "%s" the default role for new sign-ups. It was kept as Customer.', 'rar-woo-stock-order' ), $blocked ) ); ?></span></div>
                <?php endif; ?>
                <div class="rarx-btn-row">
                    <?php if ( RAR_WSO_Plugin::can_manage_staff() ) : ?>
                        <?php self::tool_button( 'clear_lockouts', __( 'Clear all login locks', 'rar-woo-stock-order' ), 'key', '', __( 'Unlock every staff login that is locked after wrong passwords?', 'rar-woo-stock-order' ), 'security' ); ?>
                    <?php endif; ?>
                    <a class="rarx-btn ghost" href="<?php echo esc_url( RAR_WSO_Admin::url( 'activity', array( 'view' => 'audit', 'action_f' => 'security' ) ) ); ?>"><?php self::i( 'activity', 16 ); ?><span><?php esc_html_e( 'Security log', 'rar-woo-stock-order' ); ?></span></a>
                </div>
            </section>
        </div>

        <div class="rarx-grid g-3">
            <?php foreach ( $groups as $g => $meta ) : ?>
                <section class="rarx-card">
                    <div class="rarx-card-h"><h2><?php self::i( $meta[1], 18 ); ?> <?php echo esc_html( $meta[0] ); ?></h2></div>
                    <ul class="rarx-checklist">
                        <?php foreach ( $checks as $c ) : if ( $c['group'] !== $g ) { continue; } ?>
                            <li class="<?php echo esc_attr( $c['status'] ); ?>">
                                <span class="ck-ico"><?php self::i( 'ok' === $c['status'] ? 'check' : ( 'fail' === $c['status'] ? 'x' : ( 'warn' === $c['status'] ? 'alert' : 'info' ) ), 15 ); ?></span>
                                <div><b><?php echo esc_html( $c['label'] ); ?></b><p><?php echo esc_html( $c['detail'] ); ?></p>
                                <?php if ( 'flush_rewrite' === $c['action'] && current_user_can( 'manage_woocommerce' ) ) : ?>
                                    <?php self::tool_button( 'flush_rewrite', __( 'Repair now', 'rar-woo-stock-order' ), 'wrench', 'sm', '', 'security' ); ?>
                                <?php elseif ( $c['url'] && 'ok' !== $c['status'] ) : ?>
                                    <a class="rarx-link" href="<?php echo esc_url( $c['url'] ); ?>"><?php esc_html_e( 'Open', 'rar-woo-stock-order' ); ?> →</a>
                                <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </div>

        <?php if ( RAR_WSO_Plugin::can_manage_staff() || current_user_can( 'manage_options' ) ) : ?>
        <section class="rarx-card rarx-danger">
            <div class="rarx-card-h"><div><h2><?php self::i( 'alert', 18 ); ?> <?php esc_html_e( 'Emergency', 'rar-woo-stock-order' ); ?></h2><p class="rarx-muted"><?php esc_html_e( 'For a lost or stolen phone, a staff member leaving suddenly, or a suspected break-in. Each action is written to the audit log.', 'rar-woo-stock-order' ); ?></p></div></div>
            <div class="rarx-danger-grid">
                <?php if ( RAR_WSO_Plugin::can_manage_staff() ) : ?>
                    <div><b><?php esc_html_e( 'Sign out all staff', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Every staff phone must sign in again. Accounts stay active.', 'rar-woo-stock-order' ); ?></p><?php self::tool_button( 'signout_all', __( 'Sign out all staff', 'rar-woo-stock-order' ), 'logout', 'warn', __( 'Sign out every staff account on every phone?', 'rar-woo-stock-order' ), 'security' ); ?></div>
                    <div><b><?php esc_html_e( 'Pause all staff', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Signs everyone out and blocks sign-in until you resume people one by one.', 'rar-woo-stock-order' ); ?></p><?php self::tool_button( 'pause_all', __( 'Pause all staff', 'rar-woo-stock-order' ), 'pause', 'bad', __( 'Pause EVERY staff account now? Nobody on staff can sign in until you resume them.', 'rar-woo-stock-order' ), 'security' ); ?></div>
                <?php endif; ?>
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <?php if ( 'yes' === $s['enabled'] ) : ?>
                        <div><b><?php esc_html_e( 'Switch the staff app off', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Stops all app sign-ins and saves (including Shop Managers) until switched on.', 'rar-woo-stock-order' ); ?></p><?php self::tool_button( 'app_off', __( 'Switch app off', 'rar-woo-stock-order' ), 'lock', 'bad', __( 'Switch the staff app off for everyone?', 'rar-woo-stock-order' ), 'security' ); ?></div>
                    <?php else : ?>
                        <div><b><?php esc_html_e( 'Switch the staff app on', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'The app is off now.', 'rar-woo-stock-order' ); ?></p><?php self::tool_button( 'app_on', __( 'Switch app on', 'rar-woo-stock-order' ), 'play', 'good', '', 'security' ); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="rarx-card">
            <div class="rarx-card-h"><h2><?php esc_html_e( 'Recent security events', 'rar-woo-stock-order' ); ?></h2><a class="rarx-link" href="<?php echo esc_url( RAR_WSO_Admin::url( 'activity', array( 'view' => 'audit', 'action_f' => 'security' ) ) ); ?>"><?php esc_html_e( 'All', 'rar-woo-stock-order' ); ?> →</a></div>
            <?php
            $ev = RAR_WSO_Audit::query( array( 'action' => 'security', 'per_page' => 8 ) );
            if ( $ev['items'] ) {
                self::audit_rows( $ev['items'] );
            } else {
                self::empty_state( 'shield', __( 'No security events yet', 'rar-woo-stock-order' ), __( 'Sign-ins, login locks, pauses and emergency actions will be listed here.', 'rar-woo-stock-order' ) );
            }
            ?>
        </section>
        <?php
    }

    /* ====================================================================
     * Tools
     * ================================================================== */

    private static function tab_tools() {
        $month0 = RAR_WSO_Reports::today_start()->modify( 'first day of this month' )->format( 'Y-m-d' );
        $d30    = RAR_WSO_Reports::today_start()->modify( '-29 days' )->format( 'Y-m-d' );
        $d90    = RAR_WSO_Reports::today_start()->modify( '-89 days' )->format( 'Y-m-d' );
        $stats  = RAR_WSO_Log::stats();
        $cogs   = RAR_WSO_Export::cogs_enabled();
        ?>
        <section class="rarx-card" id="rarx-exports">
            <div class="rarx-card-h"><div><h2><?php self::i( 'download', 18 ); ?> <?php esc_html_e( 'Exports', 'rar-woo-stock-order' ); ?></h2><p class="rarx-muted"><?php esc_html_e( 'CSV files open directly in Excel (UTF-8, Bangla text kept). For a custom range, use the filters on the Activity tab and press Export there.', 'rar-woo-stock-order' ); ?></p></div></div>
            <div class="rarx-export-grid">
                <div class="rarx-export"><span class="qi tone-indigo"><?php self::i( 'box', 20 ); ?></span><b><?php esc_html_e( 'Stock valuation', 'rar-woo-stock-order' ); ?></b><p><?php echo $cogs ? esc_html__( 'Every product and variation: SKU, category, quantity, prices, value at selling price and at cost (WooCommerce Cost of Goods), last change.', 'rar-woo-stock-order' ) : esc_html__( 'Every product and variation: SKU, category, quantity, prices, value at selling price, last change. Turn on WooCommerce “Cost of Goods Sold” to add cost value.', 'rar-woo-stock-order' ); ?></p><?php self::export_button( 'stock', __( 'Download CSV', 'rar-woo-stock-order' ) ); ?></div>
                <div class="rarx-export"><span class="qi tone-teal"><?php self::i( 'activity', 20 ); ?></span><b><?php esc_html_e( 'Stock movements — 30 days', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Every stock change with before / after, reason, order number and who did it.', 'rar-woo-stock-order' ); ?></p><?php self::export_button( 'movements', __( 'Download CSV', 'rar-woo-stock-order' ), array( 'from' => $d30 ) ); ?></div>
                <div class="rarx-export"><span class="qi tone-violet"><?php self::i( 'cart', 20 ); ?></span><b><?php esc_html_e( 'Staff orders — this month', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Order, staff, branch, customer, district, subtotal, discount, shipping, refunds, net total, payment.', 'rar-woo-stock-order' ); ?></p><?php self::export_button( 'orders', __( 'Download CSV', 'rar-woo-stock-order' ), array( 'from' => $month0 ) ); ?></div>
                <div class="rarx-export"><span class="qi tone-slate"><?php self::i( 'shield', 20 ); ?></span><b><?php esc_html_e( 'Audit log — 90 days', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Sign-ins, login locks, staff changes, settings changes and exports.', 'rar-woo-stock-order' ); ?></p><?php self::export_button( 'audit', __( 'Download CSV', 'rar-woo-stock-order' ), array( 'from' => $d90 ) ); ?></div>
            </div>
        </section>

        <div class="rarx-grid g-2">
            <section class="rarx-card">
                <div class="rarx-card-h"><h2><?php self::i( 'wrench', 18 ); ?> <?php esc_html_e( 'Maintenance', 'rar-woo-stock-order' ); ?></h2></div>
                <ul class="rarx-tools">
                    <li><div><b><?php esc_html_e( 'Recalculate dashboard figures', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Clears the short-lived caches behind the app dashboard and this screen. Safe at any time.', 'rar-woo-stock-order' ); ?></p></div><?php self::tool_button( 'clear_cache', __( 'Clear caches', 'rar-woo-stock-order' ), 'refresh' ); ?></li>
                    <li><div><b><?php esc_html_e( 'Repair the staff link', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Use when /staff/ shows "Page not found" (for example after a migration or a permalink change).', 'rar-woo-stock-order' ); ?></p></div><?php self::tool_button( 'flush_rewrite', __( 'Repair link', 'rar-woo-stock-order' ), 'globe' ); ?></li>
                    <li><div><b><?php esc_html_e( 'Send a test summary email', 'rar-woo-stock-order' ); ?></b><p><?php /* translators: %s emails */ echo esc_html( sprintf( __( 'Sends today\'s summary now to %s — also checks that the site can send email.', 'rar-woo-stock-order' ), implode( ', ', RAR_WSO_Digest::recipients() ) ) ); ?></p></div><?php self::tool_button( 'test_digest', __( 'Send test', 'rar-woo-stock-order' ), 'mail' ); ?></li>
                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                        <li><div><b><?php esc_html_e( 'Delete old history', 'rar-woo-stock-order' ); ?></b><p><?php /* translators: 1: rows, 2: date */ echo esc_html( sprintf( __( 'Stock history has %1$s entries since %2$s. Orders and products are never deleted.', 'rar-woo-stock-order' ), number_format_i18n( $stats['rows'] ), $stats['oldest'] ? wp_date( get_option( 'date_format' ), $stats['oldest'] ) : '—' ) ); ?></p></div>
                            <?php self::tool_button( 'prune', __( 'Delete', 'rar-woo-stock-order' ), 'trash', 'warn', __( 'Delete stock and audit history older than the chosen number of days? This cannot be undone — export it first if you need it.', 'rar-woo-stock-order' ), 'tools', '<label class="rarx-affix sm"><em>' . esc_html__( 'Older than', 'rar-woo-stock-order' ) . '</em><input type="number" name="days" min="30" value="365" step="1" required><em>' . esc_html__( 'days', 'rar-woo-stock-order' ) . '</em></label>' ); ?>
                        </li>
                    <?php endif; ?>
                </ul>
            </section>

            <section class="rarx-card">
                <div class="rarx-card-h"><h2><?php self::i( 'layers', 18 ); ?> <?php esc_html_e( 'Settings backup', 'rar-woo-stock-order' ); ?></h2></div>
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <ul class="rarx-tools">
                        <li><div><b><?php esc_html_e( 'Export settings', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'A small .json file — keep it before changes, or copy settings to a staging site.', 'rar-woo-stock-order' ); ?></p></div><?php self::export_button( 'settings', __( 'Export', 'rar-woo-stock-order' ) ); ?></li>
                        <li><div><b><?php esc_html_e( 'Import settings', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Restores a file exported above. Staff accounts are not included.', 'rar-woo-stock-order' ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="rarx-import" data-rarx-confirm="<?php esc_attr_e( 'Replace the current settings with the ones in this file?', 'rar-woo-stock-order' ); ?>">
                                <input type="hidden" name="action" value="rar_wso_tool"><input type="hidden" name="do" value="import_settings"><input type="hidden" name="back" value="tools">
                                <?php wp_nonce_field( 'rar_wso_tool_import_settings' ); ?>
                                <input type="file" name="settings_file" accept=".json,application/json" required>
                                <button type="submit" class="rarx-btn sm"><?php self::i( 'upload', 15 ); ?><span><?php esc_html_e( 'Import', 'rar-woo-stock-order' ); ?></span></button>
                            </form></div></li>
                        <li><div><b><?php esc_html_e( 'Reset to defaults', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Keeps the staff link and the on/off switch. Staff accounts and history are not touched.', 'rar-woo-stock-order' ); ?></p></div><?php self::tool_button( 'reset_settings', __( 'Reset', 'rar-woo-stock-order' ), 'refresh', 'warn', __( 'Reset every setting (except the staff link and on/off switch) to the defaults?', 'rar-woo-stock-order' ) ); ?></li>
                    </ul>
                <?php else : ?>
                    <div class="rarx-note"><?php self::i( 'lock', 16 ); ?><span><?php esc_html_e( 'Only an Administrator can back up or restore settings.', 'rar-woo-stock-order' ); ?></span></div>
                <?php endif; ?>
            </section>
        </div>

        <section class="rarx-card">
            <div class="rarx-card-h"><div><h2><?php self::i( 'server', 18 ); ?> <?php esc_html_e( 'System report', 'rar-woo-stock-order' ); ?></h2><p class="rarx-muted"><?php esc_html_e( 'Paste this when asking for support. It contains no passwords or keys.', 'rar-woo-stock-order' ); ?></p></div><button type="button" class="rarx-btn sm" data-rarx-copy="#rarx-sysreport"><?php self::i( 'copy', 15 ); ?><span><?php esc_html_e( 'Copy', 'rar-woo-stock-order' ); ?></span></button></div>
            <textarea id="rarx-sysreport" class="rarx-code" rows="10" readonly><?php echo esc_textarea( RAR_WSO_Export::system_report() ); ?></textarea>
        </section>
        <?php
    }

    /* ====================================================================
     * Help
     * ================================================================== */

    private static function tab_help() {
        ?>
        <div class="rarx-grid g-2">
            <section class="rarx-card">
                <div class="rarx-card-h"><h2><?php self::i( 'zap', 18 ); ?> <?php esc_html_e( 'Get started in 5 steps', 'rar-woo-stock-order' ); ?></h2></div>
                <ol class="rarx-steps">
                    <li><b><?php esc_html_e( 'Check Security & Health', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Fix anything red first — HTTPS, permalinks and WooCommerce stock management must be on.', 'rar-woo-stock-order' ); ?></p></li>
                    <li><b><?php esc_html_e( 'Set your rules', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Settings: discount limit, shipping charges, payment methods, brand colour and logo.', 'rar-woo-stock-order' ); ?></p></li>
                    <li><b><?php esc_html_e( 'Add staff', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Staff tab → Add staff. Each person gets a one-time link to set their own password.', 'rar-woo-stock-order' ); ?></p></li>
                    <li><b><?php esc_html_e( 'Install on phones', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Print the QR poster (top of this page). Staff scan it, sign in, and add the app to the home screen.', 'rar-woo-stock-order' ); ?></p></li>
                    <li><b><?php esc_html_e( 'Watch the numbers', 'rar-woo-stock-order' ); ?></b><p><?php esc_html_e( 'Overview updates every minute; turn on the daily summary email in Settings → Reports.', 'rar-woo-stock-order' ); ?></p></li>
                </ol>
            </section>
            <section class="rarx-card">
                <div class="rarx-card-h"><h2><?php self::i( 'users', 18 ); ?> <?php esc_html_e( 'Who can do what', 'rar-woo-stock-order' ); ?></h2></div>
                <div class="rarx-table-wrap"><table class="rarx-table rarx-matrix">
                    <thead><tr><th scope="col"></th><th scope="col"><?php esc_html_e( 'Staff', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Shop Manager', 'rar-woo-stock-order' ); ?></th><th scope="col"><?php esc_html_e( 'Admin', 'rar-woo-stock-order' ); ?></th></tr></thead>
                    <tbody>
                        <?php
                        $rows = array(
                            array( __( 'Update stock, create orders', 'rar-woo-stock-order' ), '✓*', '✓', '✓' ),
                            array( __( 'Discounts / lower rates', 'rar-woo-stock-order' ), __( 'up to limit', 'rar-woo-stock-order' ), '✓', '✓' ),
                            array( __( 'Today / 7-day / month order lists', 'rar-woo-stock-order' ), __( 'if allowed', 'rar-woo-stock-order' ), '✓', '✓' ),
                            array( __( 'Order status changes, all orders, reports', 'rar-woo-stock-order' ), '—', '✓', '✓' ),
                            array( __( 'This Control Center', 'rar-woo-stock-order' ), '—', '✓', '✓' ),
                            array( __( 'Add / pause staff', 'rar-woo-stock-order' ), '—', __( 'if allowed', 'rar-woo-stock-order' ), '✓' ),
                            array( __( 'Change settings', 'rar-woo-stock-order' ), '—', '—', '✓' ),
                        );
                        foreach ( $rows as $r ) {
                            echo '<tr><th scope="row">' . esc_html( $r[0] ) . '</th><td>' . esc_html( $r[1] ) . '</td><td>' . esc_html( $r[2] ) . '</td><td>' . esc_html( $r[3] ) . '</td></tr>';
                        }
                        ?>
                    </tbody>
                </table></div>
                <p class="rarx-muted">* <?php esc_html_e( 'Each item can be blocked for one person in Staff → Manage.', 'rar-woo-stock-order' ); ?></p>
            </section>
        </div>
        <section class="rarx-card">
            <div class="rarx-card-h"><h2><?php self::i( 'help', 18 ); ?> <?php esc_html_e( 'Common questions', 'rar-woo-stock-order' ); ?></h2></div>
            <div class="rarx-faq">
                <?php
                $faq = array(
                    array( __( 'A staff member forgot the password', 'rar-woo-stock-order' ), __( 'Staff → Manage → New password link. Send the link (email or WhatsApp); it works once, for 24 hours.', 'rar-woo-stock-order' ) ),
                    array( __( 'A phone was lost or someone left the shop', 'rar-woo-stock-order' ), __( 'Staff → Manage → Pause. The account is signed out on every device immediately and cannot sign in again until resumed.', 'rar-woo-stock-order' ) ),
                    array( __( 'The staff link shows "Page not found"', 'rar-woo-stock-order' ), __( 'Tools → Repair the staff link. Also check that Settings → Permalinks is not set to "Plain".', 'rar-woo-stock-order' ) ),
                    array( __( 'Stock shows below zero', 'rar-woo-stock-order' ), __( 'Usually a sale was entered before stock was added, or a count was never done. Recount the item and set the real figure in the Stock Manager; the history shows who changed what.', 'rar-woo-stock-order' ) ),
                    array( __( 'Can two phones sell the last unit at the same time?', 'rar-woo-stock-order' ), __( 'No. Each product is locked while an order takes its stock, and the quantity is re-checked from the database — the second phone is told the stock is gone.', 'rar-woo-stock-order' ) ),
                    array( __( 'The daily email does not arrive', 'rar-woo-stock-order' ), __( 'Tools → Send test. If it fails, the server cannot send mail: install an SMTP plugin with your domain email. If it arrives late, add a hosting cron job for wp-cron.php.', 'rar-woo-stock-order' ) ),
                    array( __( 'Why is a sale not counted?', 'rar-woo-stock-order' ), __( 'Pending payment, cancelled, failed and refunded orders are not sales. Partial refunds are subtracted. Figures use the site timezone.', 'rar-woo-stock-order' ) ),
                );
                foreach ( $faq as $q ) :
                    ?>
                    <details><summary><?php echo esc_html( $q[0] ); ?></summary><p><?php echo esc_html( $q[1] ); ?></p></details>
                <?php endforeach; ?>
            </div>
            <p class="rarx-muted"><?php esc_html_e( 'Source code, updates and release notes:', 'rar-woo-stock-order' ); ?> <a href="https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order-Pro" target="_blank" rel="noopener">github.com/ruhulaminrevens/RAR-Woo-Stock-Order-Pro</a></p>
        </section>
        <?php
    }
}
