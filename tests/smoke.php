<?php
/**
 * RAR Woo Stock & Order — runtime smoke tests.
 *
 * Run inside a WordPress + WooCommerce site with the plugin active and the site served
 * over HTTP (php -S, Apache, nginx...):
 *   wp eval-file tests/smoke.php
 *   RAR_TEST_URL=http://127.0.0.1:8080 wp eval-file tests/smoke.php
 *
 * Creates its own test products, users and orders (prefixed "RARTEST") and removes them at the end.
 * Exit code 0 = all passed, 1 = at least one failure.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// phpcs:disable

/*
 * Every call goes over real HTTP to admin-ajax.php (like the phone app does), with a real
 * login cookie and nonce, so status codes, wp_die() and output buffering behave exactly as
 * in production. Set RAR_TEST_URL if the site is reachable at a different address than home_url().
 */
$GLOBALS['rar_fail'] = 0;
$GLOBALS['rar_pass'] = 0;
$GLOBALS['rar_base'] = rtrim( getenv( 'RAR_TEST_URL' ) ?: home_url(), '/' );
$GLOBALS['rar_auth'] = array();

function rar_ok( $cond, $label, $extra = '' ) {
	if ( $cond ) {
		$GLOBALS['rar_pass']++;
		echo "  PASS  {$label}\n";
	} else {
		$GLOBALS['rar_fail']++;
		echo "  FAIL  {$label}" . ( $extra ? "  -> {$extra}" : '' ) . "\n";
	}
}

/** Log in as $user_id for the following calls (0 = logged out). */
function rar_login( $user_id ) {
	wp_set_current_user( $user_id );
	$GLOBALS['rar_auth'] = array();
	unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	if ( ! $user_id ) {
		return;
	}
	$exp     = time() + HOUR_IN_SECONDS;
	$token   = WP_Session_Tokens::get_instance( $user_id )->create( $exp );
	$logged  = wp_generate_auth_cookie( $user_id, $exp, 'logged_in', $token );
	$auth    = wp_generate_auth_cookie( $user_id, $exp, is_ssl() ? 'secure_auth' : 'auth', $token );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged; // wp_create_nonce() reads the session token from here.
	$GLOBALS['rar_auth'] = array( LOGGED_IN_COOKIE => $logged, ( is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE ) => $auth );
}

/** Calls an AJAX action over HTTP and returns [http_status, decoded_json|raw]. */
function rar_call( $action, $data = array(), $nonce = null ) {
	$body = array_merge( array( 'action' => 'rar_wso_' . $action, 'nonce' => null === $nonce ? wp_create_nonce( 'rar_wso_nonce' ) : $nonce ), $data );
	$res  = wp_remote_post( $GLOBALS['rar_base'] . '/wp-admin/admin-ajax.php', array( 'body' => $body, 'cookies' => $GLOBALS['rar_auth'], 'timeout' => 60, 'sslverify' => false ) );
	if ( is_wp_error( $res ) ) {
		return array( 0, $res->get_error_message() );
	}
	$raw  = wp_remote_retrieve_body( $res );
	$json = json_decode( $raw, true );
	return array( (int) wp_remote_retrieve_response_code( $res ), null === $json ? $raw : $json );
}

/** Reads fresh product stock straight from the database (the HTTP requests changed it). */
function rar_stock( $id ) {
	wp_cache_flush();
	return (int) wc_get_product( $id )->get_stock_quantity();
}

function rar_order_status( $id ) {
	wp_cache_flush();
	$o = wc_get_order( $id );
	return $o ? $o->get_status() : 'missing';
}

function rar_settings( $changes ) {
	wp_cache_flush();
	$s = RAR_WSO_Plugin::settings();
	update_option( 'rar_wso_settings', array_merge( $s, $changes ) );
}

function rar_product( $name, $price, $stock ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'RARTEST ' . $name );
	$p->set_regular_price( (string) $price );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $stock );
	$p->set_status( 'publish' );
	$p->save();
	return $p->get_id();
}

function rar_user( $login, $role ) {
	$id = username_exists( $login );
	if ( ! $id ) {
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'user_email' => $login . '@example.test' ) );
	}
	return (int) $id;
}

function rar_payload( $items, $extra = array() ) {
	return wp_json_encode( array_merge( array(
		'request_id'    => 'rartest' . wp_rand( 100000, 999999 ) . wp_rand( 100000, 999999 ),
		'name'          => 'RARTEST Customer',
		'phone'         => '01711223344',
		'email'         => '',
		'address'       => 'House 1, Road 2, Dhanmondi',
		'city'          => 'Dhanmondi',
		'district'      => 'Dhaka',
		'note'          => '',
		'shipping'      => 60,
		'discount_type' => 'amount',
		'discount'      => 0,
		'payment'       => 'cod',
		'items'         => $items,
	), $extra ) );
}

echo "RAR Woo Stock & Order " . RAR_WSO_VERSION . " — smoke tests against {$GLOBALS['rar_base']}\n";
echo 'WordPress ' . get_bloginfo( 'version' ) . ' · WooCommerce ' . WC()->version . ' · PHP ' . PHP_VERSION . ' · HPOS ' . ( RAR_WSO_Reports::hpos() ? 'on' : 'off' ) . "\n\n";

$original_settings = get_option( 'rar_wso_settings' );
$staff   = rar_user( 'rartest_staff', 'rar_wso_staff' );
$manager = rar_user( 'rartest_manager', 'shop_manager' );
RAR_WSO_Plugin::register_roles_and_caps();
$p1 = rar_product( 'Alpha', 1000, 5 );
$p2 = rar_product( 'Beta', 500, 50 );
$created = array();

try {
	rar_settings( array( 'enabled' => 'yes', 'staff_max_discount' => '20', 'allow_price_override' => 'yes' ) );

	echo "Session / access\n";
	rar_login( $staff );
	list( $st, $r ) = rar_call( 'stats', array( 'period' => 'today' ) );
	rar_ok( 200 === $st && ! empty( $r['success'] ), 'staff can load dashboard stats', "$st " . wp_json_encode( $r ) );
	list( $st, $r ) = rar_call( 'stats', array( 'period' => 'today' ), 'stale-nonce' );
	rar_ok( 403 === $st && ! empty( $r['data']['nonce'] ), 'stale nonce is reported as JSON (app can auto-renew)', "$st" );
	list( $st, $r ) = rar_call( 'refresh', array(), '' );
	rar_ok( 200 === $st && ! empty( $r['data']['nonce'] ), 'refresh endpoint returns a fresh nonce' );
	rar_login( 0 );
	list( $st, $r ) = rar_call( 'refresh', array(), '' );
	rar_ok( 401 === $st, 'refresh when logged out asks to sign in', "$st" );
	rar_login( $staff );

	echo "\nApp disabled blocks the backend too (review point 2)\n";
	rar_settings( array( 'enabled' => 'no' ) );
	list( $st ) = rar_call( 'stats', array( 'period' => 'today' ) );
	rar_ok( 403 === $st, 'stats refused while app is disabled', "$st" );
	list( $st ) = rar_call( 'stock_update', array( 'id' => $p2, 'qty' => 49 ) );
	rar_ok( 403 === $st, 'stock update refused while app is disabled', "$st" );
	list( $st ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 1 ) ) ) ) );
	rar_ok( 403 === $st, 'order creation refused while app is disabled', "$st" );
	rar_settings( array( 'enabled' => 'yes' ) );

	echo "\nOrder creation\n";
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => $pl = rar_payload( array( array( 'id' => $p2, 'qty' => 2 ) ) ) ) );
	$oid = (int) ( $r['data']['order_id'] ?? 0 );
	$created[] = $oid;
	rar_ok( 200 === $st && $oid > 0, 'staff creates an order', "$st " . wp_json_encode( $r ) );
	rar_ok( 48 === rar_stock( $p2 ), 'stock reduced 50 → 48' );

	echo "\nDuplicate protection (review point 1)\n";
	delete_transient( 'rar_wso_req_' . md5( $staff . '|' . json_decode( $pl, true )['request_id'] ) );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => $pl ) );
	rar_ok( 200 === $st && ! empty( $r['data']['duplicate'] ) && (int) $r['data']['order_id'] === $oid, 'same request ID returns the existing order even after the transient is gone', wp_json_encode( $r['data'] ?? $r ) );
	rar_ok( 48 === rar_stock( $p2 ), 'no second stock reduction' );
	$pl2 = rar_payload( array( array( 'id' => $p2, 'qty' => 1 ) ) );
	$rid = json_decode( $pl2, true )['request_id'];
	add_option( 'rar_wso_lock_' . md5( $staff . '|' . $rid ), time(), '', 'no' ); // another request is in flight
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => $pl2 ) );
	rar_ok( 409 === $st, 'concurrent request with the same ID is refused while the first holds the lock', "$st" );
	delete_option( 'rar_wso_lock_' . md5( $staff . '|' . $rid ) );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => $pl2 ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	rar_ok( 200 === $st && empty( $r['data']['duplicate'] ), 'after the lock is released the request succeeds once' );
	wp_cache_flush();
	rar_ok( false === get_option( 'rar_wso_lock_' . md5( $staff . '|' . $rid ) ), 'lock is cleaned up after the request' );

	echo "\nCombined stock check across lines (review point 1)\n";
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p1, 'qty' => 3 ), array( 'id' => $p1, 'qty' => 3 ) ) ) ) );
	rar_ok( 422 === $st, '3 + 3 of a product with 5 in stock is refused', "$st " . wp_json_encode( $r ) );
	rar_ok( 5 === rar_stock( $p1 ), 'stock untouched after refusal' );

	echo "\nDiscount limit (review point 3)\n";
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 2 ) ), array( 'discount_type' => 'percent', 'discount' => 100 ) ) ) );
	rar_ok( 422 === $st, 'staff 100% discount refused (limit 20%)', "$st " . wp_json_encode( $r ) );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 2 ) ), array( 'discount' => 250 ) ) ) );
	rar_ok( 422 === $st, 'staff ৳250 on ৳1000 (25%) refused', "$st" );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 2 ) ), array( 'discount' => 200 ) ) ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	rar_ok( 200 === $st, 'staff ৳200 on ৳1000 (20%) allowed', "$st " . wp_json_encode( $r ) );
	rar_settings( array( 'staff_max_discount' => '0' ) );
	list( $st ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 1 ) ), array( 'discount' => 1 ) ) ) );
	rar_ok( 422 === $st, 'limit 0 → staff cannot give any discount', "$st" );
	rar_settings( array( 'staff_max_discount' => '20' ) );
	rar_login( $manager );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 2 ) ), array( 'discount_type' => 'percent', 'discount' => 50 ) ) ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	rar_ok( 200 === $st, 'shop manager 50% discount allowed', "$st " . wp_json_encode( $r ) );

	echo "\nRefunded status blocked in the app (review point 5)\n";
	list( $st, $r ) = rar_call( 'order_status', array( 'id' => $oid, 'status' => 'refunded' ) );
	rar_ok( 422 === $st && 'refunded' !== rar_order_status( $oid ), 'manager cannot set Refunded from the app', "$st" );
	list( $st ) = rar_call( 'order_status', array( 'id' => $oid, 'status' => 'completed' ) );
	rar_ok( 200 === $st && 'completed' === rar_order_status( $oid ), 'normal status change still works' );

	echo "\nReports (review point 4)\n";
	wp_cache_flush();
	$pending = wc_create_order( array( 'status' => 'pending' ) );
	$pending->add_product( wc_get_product( $p2 ), 4 );
	$pending->calculate_totals();
	$pending->save();
	$created[] = $pending->get_id();
	$rows = RAR_WSO_Reports::rows( new DateTimeImmutable( '-1 hour', RAR_WSO_Reports::tz() ), new DateTimeImmutable( '+1 hour', RAR_WSO_Reports::tz() ) );
	$before = RAR_WSO_Reports::summarize( $rows );
	rar_ok( ! RAR_WSO_Reports::is_sale( 'pending' ) && ! RAR_WSO_Reports::is_sale( 'returned' ) && RAR_WSO_Reports::is_sale( 'processing' ), 'pending and returned are not sales; processing is' );
	rar_ok( $before['pending'] >= 1, 'pending order counted separately, not in sales' );
	$refund = wc_create_refund( array( 'order_id' => $oid, 'amount' => 300, 'reason' => 'RARTEST partial refund', 'restock_items' => false ) );
	rar_ok( ! is_wp_error( $refund ), 'partial refund recorded in WooCommerce', is_wp_error( $refund ) ? $refund->get_error_message() : '' );
	$rows  = RAR_WSO_Reports::rows( new DateTimeImmutable( '-1 hour', RAR_WSO_Reports::tz() ), new DateTimeImmutable( '+1 hour', RAR_WSO_Reports::tz() ) );
	$after = RAR_WSO_Reports::summarize( $rows );
	rar_ok( abs( ( $before['sales'] - $after['sales'] ) - 300 ) < 0.01, 'net sales drop by exactly the ৳300 refund', 'before ' . $before['sales'] . ' after ' . $after['sales'] );
	rar_ok( abs( $after['refunds'] - $before['refunds'] - 300 ) < 0.01, 'refunds total shows ৳300' );

	echo "\nPWA endpoints\n";
	rar_ok( false !== strpos( RAR_WSO_PWA::manifest_url(), 'rar_wso_manifest=1' ) && false !== strpos( RAR_WSO_PWA::sw_url(), 'rar_wso_sw=1' ), 'cache-safe manifest / service worker URLs' );
	foreach ( array( 'icon-192.png', 'icon-512.png', 'maskable-512.png', 'apple-touch-icon.png' ) as $icon ) {
		rar_ok( file_exists( RAR_WSO_PATH . 'assets/icons/' . $icon ), "icon {$icon} present" );
	}
} catch ( Throwable $e ) {
	rar_ok( false, 'unexpected exception', get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	foreach ( array_filter( $created ) as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$o->delete( true );
		}
	}
	foreach ( array( $p1, $p2 ) as $pid ) {
		wp_delete_post( $pid, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $staff );
	wp_delete_user( $manager );
	update_option( 'rar_wso_settings', $original_settings );
}

echo "\n{$GLOBALS['rar_pass']} passed, {$GLOBALS['rar_fail']} failed\n";
exit( $GLOBALS['rar_fail'] ? 1 : 0 );
