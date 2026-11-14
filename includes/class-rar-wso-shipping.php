<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Delivery charge and courier for staff orders.
 *
 * With RAR Woo Smart Courier active (Settings → Shipping → "Courier & delivery charge" = Smart Courier),
 * the staff app shows the same couriers, prices, delivery estimates and badges the customer sees at
 * checkout. The chosen courier is written to the order exactly like a checkout choice, marked
 * "Set by staff", so Smart Courier never replaces it with an automatic pick.
 *
 * Without Smart Courier the plugin's own Inside / Outside Dhaka charges are used, as before.
 * Free delivery follows the plugin's "Free delivery from" amount and, optionally, the
 * WooCommerce "Free shipping" method of the matching shipping zone.
 */
class RAR_WSO_Shipping {

    /** Shipping-line method ID of a courier chosen in the staff app. */
    const METHOD_ID = 'rar_wso_courier';

    /** Shipping-line method ID of a plain delivery charge (no courier). */
    const MANUAL_METHOD_ID = 'rar_wso_manual_shipping';

    /** Smart Courier is installed, switched on, and selected in the settings. */
    public static function smart_available() {
        $s = RAR_WSO_Plugin::settings();
        if ( 'basic' === ( $s['courier_mode'] ?? 'smart' ) ) {
            return false;
        }
        return class_exists( 'RWSC_Engine' ) && class_exists( 'RWSC_Settings' ) && method_exists( 'RWSC_Engine', 'quotes' )
            && RWSC_Settings::on( 'enabled' );
    }

    /** Smart Courier installed at all (for the settings screen). */
    public static function smart_installed() {
        return class_exists( 'RWSC_Engine' ) && class_exists( 'RWSC_Settings' );
    }

    /** True when a Bangladesh state code / district name is Dhaka district. */
    public static function is_dhaka( $state ) {
        $state = strtoupper( trim( (string) $state ) );
        if ( '' === $state ) {
            return false;
        }
        if ( 'BD-13' === $state || 'DHAKA' === $state ) {
            return true;
        }
        $states = function_exists( 'WC' ) && WC()->countries ? (array) WC()->countries->get_states( 'BD' ) : array();
        return isset( $states[ $state ] ) && 0 === strcasecmp( trim( wp_strip_all_tags( $states[ $state ] ) ), 'Dhaka' );
    }

    /** The plugin's own district charge (no free-delivery rule applied). */
    public static function basic_rate( $state ) {
        $s = RAR_WSO_Plugin::settings();
        if ( '' === trim( (string) $state ) ) {
            return (float) $s['default_shipping'];
        }
        $v = self::is_dhaka( $state ) ? $s['shipping_dhaka'] : $s['shipping_outside'];
        return '' === (string) $v ? (float) $s['default_shipping'] : (float) $v;
    }

    /** Zone label for the basic rate. */
    public static function basic_label( $state ) {
        if ( '' === trim( (string) $state ) ) {
            return '';
        }
        return self::is_dhaka( $state ) ? __( 'Inside Dhaka', 'rar-woo-stock-order' ) : __( 'Outside Dhaka', 'rar-woo-stock-order' );
    }

    /**
     * Does this order get free delivery?
     *
     * @param array $destination country, state, city, address_1.
     * @param float $subtotal    Items subtotal (after line rates, before the order discount).
     * @param float $after_disc  Items subtotal after the order discount.
     * @return array{free:bool, reason:string}
     */
    public static function free_rule( array $destination, $subtotal, $after_disc ) {
        $s     = RAR_WSO_Plugin::settings();
        $over  = (float) $s['free_shipping_over'];
        $money = static function ( $v ) {
            return trim( html_entity_decode( wp_strip_all_tags( wc_price( $v, array( 'decimals' => 0 ) ) ), ENT_QUOTES, 'UTF-8' ), " \t\n\r\0\x0B\xC2\xA0" );
        };
        if ( $over > 0 && $subtotal + 0.0001 >= $over ) {
            /* translators: %s amount */
            return array( 'free' => true, 'all' => true, 'reason' => sprintf( __( 'Free delivery — items %s+', 'rar-woo-stock-order' ), $money( $over ) ) );
        }
        if ( 'yes' !== ( $s['follow_wc_free'] ?? 'yes' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
            return array( 'free' => false, 'reason' => '' );
        }
        $zone = WC_Shipping_Zones::get_zone_matching_package(
            array(
                'destination' => array(
                    'country'   => $destination['country'] ?? 'BD',
                    'state'     => $destination['state'] ?? '',
                    'postcode'  => $destination['postcode'] ?? '',
                    'city'      => $destination['city'] ?? '',
                    'address'   => $destination['address_1'] ?? '',
                    'address_1' => $destination['address_1'] ?? '',
                    'address_2' => '',
                ),
            )
        );
        if ( ! $zone ) {
            return array( 'free' => false, 'reason' => '' );
        }
        foreach ( $zone->get_shipping_methods( true ) as $method ) {
            if ( 'free_shipping' !== $method->id ) {
                continue;
            }
            $requires = (string) $method->get_option( 'requires', '' );
            $min      = (float) $method->get_option( 'min_amount', 0 );
            $total    = 'yes' === $method->get_option( 'ignore_discounts', 'no' ) ? $subtotal : $after_disc;
            $title    = trim( (string) $method->get_option( 'title', '' ) );
            $title    = '' !== $title ? $title : __( 'Free shipping', 'rar-woo-stock-order' );
            if ( '' === $requires ) {
                /* translators: %s method name */
                return array( 'free' => true, 'reason' => sprintf( __( '%s (WooCommerce zone rule)', 'rar-woo-stock-order' ), $title ) );
            }
            // Staff orders carry no coupons, so "coupon" and "both" never qualify here.
            if ( in_array( $requires, array( 'min_amount', 'either' ), true ) && $min > 0 && $total + 0.0001 >= $min ) {
                /* translators: 1: method name, 2: minimum amount */
                return array( 'free' => true, 'reason' => sprintf( __( '%1$s — order %2$s+', 'rar-woo-stock-order' ), $title, $money( $min ) ) );
            }
        }
        return array( 'free' => false, 'reason' => '' );
    }

    /**
     * Delivery options for an address and a set of items.
     *
     * @param array $args state (BD-xx), city, address, lines (array of [WC_Product, qty]), subtotal, after_discount.
     * @return array
     */
    public static function quote( array $args ) {
        $args = wp_parse_args(
            $args,
            array( 'state' => '', 'city' => '', 'address' => '', 'lines' => array(), 'subtotal' => 0, 'after_discount' => null )
        );
        $sub   = max( 0, (float) $args['subtotal'] );
        $after = null === $args['after_discount'] ? $sub : max( 0, (float) $args['after_discount'] );
        $dest  = array(
            'country'   => 'BD',
            'state'     => (string) $args['state'],
            'city'      => (string) $args['city'],
            'address_1' => (string) $args['address'],
            'address_2' => '',
            'postcode'  => '',
        );
        $free  = '' !== $dest['state'] ? self::free_rule( $dest, $sub, $after ) : array( 'free' => false, 'reason' => '' );
        $basic = self::basic_rate( $dest['state'] );
        $out   = array(
            'engine'      => 'basic',
            'zone'        => '',
            'zone_label'  => self::basic_label( $dest['state'] ),
            'weight'      => 0.0,
            'free'        => $free['free'],
            'free_reason' => $free['reason'],
            'basic'       => $free['free'] ? 0.0 : $basic,
            'basic_normal'=> $basic,
            'quotes'      => array(),
            'recommended' => '',
            'notice'      => '',
        );
        if ( ! self::smart_available() || '' === $dest['state'] ) {
            return $out;
        }

        $lines = array();
        foreach ( (array) $args['lines'] as $l ) {
            if ( isset( $l[0] ) && $l[0] instanceof WC_Product ) {
                $lines[] = array( $l[0], max( 1, (float) ( $l[1] ?? 1 ) ) );
            }
        }
        try {
            $q = RWSC_Engine::quotes(
                array(
                    'destination' => $dest,
                    'lines'       => $lines,
                    'free'        => $free['free'],
                )
            );
        } catch ( Throwable $e ) {
            $out['notice'] = __( 'Smart Courier could not price this address; the standard charge is used.', 'rar-woo-stock-order' );
            return $out;
        }
        $out['weight'] = (float) ( $q['weight'] ?? 0 );
        if ( '' === (string) ( $q['zone'] ?? '' ) || empty( $q['quotes'] ) ) {
            $out['notice'] = __( 'No Smart Courier courier delivers to this address; the standard charge is used.', 'rar-woo-stock-order' );
            return $out;
        }
        $out['engine']     = 'smart';
        $out['zone']       = (string) $q['zone'];
        $out['zone_label'] = (string) $q['zone_label'];
        $out['district']   = (string) ( $q['district'] ?? '' );
        foreach ( $q['quotes'] as $row ) {
            if ( empty( $row['available'] ) ) {
                continue;
            }
            $badges = array();
            foreach ( RWSC_Engine::badges( $row ) as $b ) {
                $txt = RWSC_Engine::badge_text( $b );
                if ( '' !== $txt ) {
                    $badges[] = array( 'key' => $b, 'text' => $txt );
                }
            }
            $out['quotes'][] = array(
                'key'          => (string) $row['key'],
                'name'         => (string) $row['name'],
                'color'        => (string) $row['color'],
                'cost'         => round( (float) $row['cost'], wc_get_price_decimals() ),
                'normal'       => round( (float) $row['normal_cost'], wc_get_price_decimals() ),
                'free'         => ! empty( $row['free_applied'] ),
                'eta'          => (string) $row['eta'],
                'edd'          => ! empty( $row['edd'] ) ? $row['edd']['from'] . '|' . $row['edd']['to'] : '',
                'edd_label'    => ! empty( $row['edd']['label'] ) ? (string) $row['edd']['label'] : '',
                'recommended'  => ! empty( $row['recommended'] ),
                'badges'       => $badges,
            );
            if ( ! empty( $free['all'] ) ) {
                // The staff app's own "Free delivery from" amount makes every courier free.
                $last           = count( $out['quotes'] ) - 1;
                $out['quotes'][ $last ]['cost'] = 0.0;
                $out['quotes'][ $last ]['free'] = true;
            }
            if ( ! empty( $row['recommended'] ) && '' === $out['recommended'] ) {
                $out['recommended'] = (string) $row['key'];
            }
        }
        if ( ! $out['quotes'] ) {
            $out['engine'] = 'basic';
            $out['notice'] = __( 'No Smart Courier courier delivers to this address; the standard charge is used.', 'rar-woo-stock-order' );
        } elseif ( '' === $out['recommended'] ) {
            $out['recommended'] = $out['quotes'][0]['key'];
        }
        return $out;
    }

    /** One courier row from a quote, or null. */
    public static function pick( array $quote, $key ) {
        foreach ( (array) $quote['quotes'] as $row ) {
            if ( $row['key'] === $key ) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Adds the shipping line and, for a courier, the Smart Courier shipment data (order not saved here).
     *
     * @param WC_Order   $order    Order being built.
     * @param array      $quote    Result of quote().
     * @param array|null $pick     Courier row, or null for a plain charge.
     * @param float      $shipping Charge on the order.
     * @param bool       $manual   Staff typed the charge.
     */
    public static function apply( WC_Order $order, array $quote, $pick, $shipping, $manual ) {
        $order->update_meta_data( '_rar_wso_ship_mode', $manual ? 'manual' : 'auto' );
        if ( ! $pick ) {
            if ( $shipping > 0 ) {
                $item = new WC_Order_Item_Shipping();
                $item->set_method_title( '' !== $quote['zone_label'] ? sprintf( /* translators: %s zone */ __( 'Delivery — %s', 'rar-woo-stock-order' ), $quote['zone_label'] ) : __( 'Staff order delivery', 'rar-woo-stock-order' ) );
                $item->set_method_id( self::MANUAL_METHOD_ID );
                $item->set_total( $shipping );
                $order->add_item( $item );
            }
            return;
        }

        $free_applied = ! $manual && ! empty( $pick['free'] );
        $meta         = array(
            '_rwsc_courier'          => sanitize_key( $pick['key'] ),
            '_rwsc_selected_courier' => sanitize_text_field( $pick['name'] ),
            '_rwsc_eta'              => sanitize_text_field( $pick['eta'] ),
            '_rwsc_edd'              => sanitize_text_field( $pick['edd'] ),
            '_rwsc_zone'             => sanitize_key( $quote['zone'] ),
            '_rwsc_district'         => sanitize_text_field( (string) ( $quote['district'] ?? '' ) ),
            '_rwsc_weight'           => (string) $quote['weight'],
            '_rwsc_normal_cost'      => (string) $pick['normal'],
            '_rwsc_free_applied'     => $free_applied ? 'yes' : 'no',
            '_rwsc_recommended'      => ! empty( $pick['recommended'] ) ? 'yes' : 'no',
        );

        // Shipping line, shaped like a Smart Courier checkout rate.
        $item = new WC_Order_Item_Shipping();
        $item->set_method_title( $pick['name'] );
        $item->set_method_id( self::METHOD_ID );
        $item->set_total( $shipping );
        foreach ( $meta as $k => $v ) {
            $item->add_meta_data( $k, $v, true );
        }
        $order->add_item( $item );

        // Shipment record on the order (same keys Smart Courier writes), so its Orders list, Shipments
        // board, booking buttons and auto-send see a courier the staff member chose.
        foreach ( $meta as $k => $v ) {
            $order->update_meta_data( $k, $v );
        }
        $order->update_meta_data( '_rwsc_source', 'manual' );
        if ( '' === (string) $order->get_meta( '_rwsc_status', true ) ) {
            $order->update_meta_data( '_rwsc_status', 'pending' );
            $order->update_meta_data( '_rwsc_status_at', time() );
        }
        $user = wp_get_current_user();
        $log  = $order->get_meta( '_rwsc_log', true );
        $log  = is_array( $log ) ? $log : array();
        $log[] = array(
            't' => time(),
            's' => 'pending',
            'u' => $user && $user->exists() ? $user->display_name : 'Staff app',
            /* translators: %s courier name */
            'm' => sprintf( __( 'Courier %s chosen in the staff app', 'rar-woo-stock-order' ), $pick['name'] ),
        );
        $order->update_meta_data( '_rwsc_log', $log );
        $order->update_meta_data( '_rar_wso_courier', sanitize_key( $pick['key'] ) );

        /**
         * Fires after the staff-chosen courier is written to a new order (before it is saved).
         *
         * @param WC_Order $order Order.
         * @param array    $pick  Courier row.
         * @param array    $quote Full quote.
         */
        do_action( 'rar_wso_courier_applied', $order, $pick, $quote );
    }

    /**
     * Courier summary of an existing order for the staff app (order detail, slip, list).
     *
     * @return array|null
     */
    public static function order_courier( WC_Order $order ) {
        if ( function_exists( 'rwsc_get_shipment' ) ) {
            try {
                $s = rwsc_get_shipment( $order );
            } catch ( Throwable $e ) {
                $s = null;
            }
            if ( is_array( $s ) && '' !== (string) $s['courier_name'] ) {
                $src = array(
                    'checkout'    => __( 'Chosen at checkout', 'rar-woo-stock-order' ),
                    'manual'      => __( 'Set by staff', 'rar-woo-stock-order' ),
                    'auto'        => __( 'Suggested by Smart Courier', 'rar-woo-stock-order' ),
                    'courier_api' => __( 'Booked through the courier API', 'rar-woo-stock-order' ),
                );
                return array(
                    'name'         => (string) $s['courier_name'],
                    'color'        => (string) $s['color'],
                    'status'       => (string) $s['status'],
                    'status_label' => (string) $s['status_label'],
                    'tracking'     => (string) $s['tracking'],
                    'tracking_url' => (string) $s['tracking_url'],
                    'eta'          => (string) $s['eta'],
                    'edd'          => (string) $s['edd_label'],
                    'source'       => $src[ $s['source'] ] ?? '',
                );
            }
        }
        foreach ( $order->get_shipping_methods() as $item ) {
            $title = trim( wp_strip_all_tags( $item->get_method_title() ) );
            if ( self::METHOD_ID === $item->get_method_id() && '' !== $title ) {
                return array( 'name' => $title, 'color' => '', 'status' => '', 'status_label' => '', 'tracking' => '', 'tracking_url' => '', 'eta' => (string) $item->get_meta( '_rwsc_eta', true ), 'edd' => '', 'source' => __( 'Set by staff', 'rar-woo-stock-order' ) );
            }
        }
        return null;
    }
}
