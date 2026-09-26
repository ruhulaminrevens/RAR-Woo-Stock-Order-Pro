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

/** Fires several AJAX calls at the same moment (curl multi), each as its own user. Returns [[status, json], ...]. */
function rar_parallel( $calls ) {
	$reqs = array();
	foreach ( $calls as $i => $c ) {
		rar_login( $c['user'] );
		$cookie = array();
		foreach ( $GLOBALS['rar_auth'] as $k => $v ) {
			$cookie[] = $k . '=' . $v;
		}
		$reqs[ $i ] = array(
			'url'     => $GLOBALS['rar_base'] . '/wp-admin/admin-ajax.php',
			'type'    => 'POST',
			'data'    => array_merge( array( 'action' => 'rar_wso_' . $c['action'], 'nonce' => wp_create_nonce( 'rar_wso_nonce' ) ), $c['data'] ),
			'headers' => array( 'Cookie' => implode( '; ', $cookie ) ),
		);
	}
	$out = array();
	foreach ( WpOrg\Requests\Requests::request_multiple( $reqs, array( 'timeout' => 60 ) ) as $i => $r ) {
		$out[ $i ] = array( is_object( $r ) && isset( $r->status_code ) ? (int) $r->status_code : 0, is_object( $r ) && isset( $r->body ) ? json_decode( $r->body, true ) : null );
	}
	return $out;
}

function rar_staff_login_post( $login, $pwd, $with_nonce = true ) {
	$page = wp_remote_retrieve_body( wp_remote_get( RAR_WSO_Plugin::staff_url(), array( 'timeout' => 30 ) ) );
	preg_match( '/name="rar_wso_login_nonce" value="([^"]+)"/', $page, $m );
	$body = array( 'rar_wso_login' => 1, 'log' => $login, 'pwd' => $pwd );
	if ( $with_nonce ) {
		$body['rar_wso_login_nonce'] = $m[1] ?? '';
	}
	$res = wp_remote_post( RAR_WSO_Plugin::staff_url(), array( 'body' => $body, 'timeout' => 30, 'redirection' => 0 ) );
	return array( (int) wp_remote_retrieve_response_code( $res ), wp_remote_retrieve_body( $res ) );
}

/** GET/POST a wp-admin URL as the logged-in test user. Returns [status, body, headers]. */
function rar_admin( $path, $post = null ) {
	$args = array( 'timeout' => 60, 'redirection' => 0, 'cookies' => $GLOBALS['rar_auth'], 'sslverify' => false );
	$url  = $GLOBALS['rar_base'] . '/wp-admin/' . $path;
	$res  = null === $post ? wp_remote_get( $url, $args ) : wp_remote_post( $url, array_merge( $args, array( 'body' => $post ) ) );
	if ( is_wp_error( $res ) ) {
		return array( 0, $res->get_error_message(), array() );
	}
	return array( (int) wp_remote_retrieve_response_code( $res ), wp_remote_retrieve_body( $res ), wp_remote_retrieve_headers( $res ) );
}

// Test-only probe: records what other plugins see when WooCommerce announces a new order.
$probe_file = WP_CONTENT_DIR . '/mu-plugins/rar-smoke-probe.php';
wp_mkdir_p( dirname( $probe_file ) );
file_put_contents( $probe_file, "<?php\n// A brand-new CI site: stop WooCommerce's one-time setup-wizard redirect from hijacking the first wp-admin request.\nadd_filter( 'woocommerce_prevent_automatic_wizard_redirect', '__return_true' );\nadd_action( 'rar_wso_order_created', function ( \$order ) { if ( 'RARTEST-THROW' === \$order->get_customer_note() ) { throw new RuntimeException( 'integration hook failed' ); } } );\nadd_filter( 'woocommerce_can_reduce_order_stock', function ( \$ok, \$order ) { return ( 'RARTEST-NOREDUCE' === \$order->get_customer_note() && \$order->has_status( 'pending' ) ) ? false : \$ok; }, 10, 2 );\nif ( isset( \$_GET['rar_smoke_force_role'] ) ) { add_filter( 'pre_option_default_role', function () { return 'rar_wso_staff'; } ); }\nadd_action( 'woocommerce_new_order', function ( \$id, \$order = null ) { \$order = \$order ?: wc_get_order( \$id ); update_option( 'rar_smoke_new_order', array( 'n' => (int) ( get_option( 'rar_smoke_new_order' )['n'] ?? 0 ) + 1, 'items' => count( \$order->get_items() ), 'phone' => \$order->get_billing_phone(), 'total' => (float) \$order->get_total() ), false ); }, 10, 2 );\n" );

echo "RAR Woo Stock & Order " . RAR_WSO_VERSION . " — smoke tests against {$GLOBALS['rar_base']}\n";
echo 'WordPress ' . get_bloginfo( 'version' ) . ' · WooCommerce ' . WC()->version . ' · PHP ' . PHP_VERSION . ' · HPOS ' . ( RAR_WSO_Reports::hpos() ? 'on' : 'off' ) . "\n\n";

$original_settings = get_option( 'rar_wso_settings' );
$staff   = rar_user( 'rartest_staff', 'rar_wso_staff' );
$staff2  = rar_user( 'rartest_staff2', 'rar_wso_staff' );
$manager = rar_user( 'rartest_manager', 'shop_manager' );
$admin   = rar_user( 'rartest_admin', 'administrator' );
$extra_users = array();
$extra_posts = array();
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
	rar_ok( RAR_WSO_Lock::acquire( 'req|' . $staff . '|' . $rid, 0 ), 'test process holds the request lock (another save in flight)' );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => $pl2 ) );
	rar_ok( 409 === $st, 'concurrent request with the same ID is refused while the first holds the lock', "$st" );
	RAR_WSO_Lock::release( 'req|' . $staff . '|' . $rid );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => $pl2 ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	rar_ok( 200 === $st && empty( $r['data']['duplicate'] ), 'after the lock is released the request succeeds once' );
	$free = RAR_WSO_Lock::acquire( 'req|' . $staff . '|' . $rid, 0 );
	RAR_WSO_Lock::release( 'req|' . $staff . '|' . $rid );
	rar_ok( $free, 'lock is released after the request' );
	$same = rar_payload( array( array( 'id' => $p2, 'qty' => 1 ) ) );
	$before_same = rar_stock( $p2 );
	$burst = rar_parallel( array_fill( 0, 5, array( 'user' => $staff, 'action' => 'create_order', 'data' => array( 'payload' => $same ) ) ) );
	$ids = array();
	foreach ( $burst as $b ) {
		if ( 200 === $b[0] ) {
			$ids[] = (int) $b[1]['data']['order_id'];
		}
	}
	$created = array_merge( $created, $ids );
	rar_ok( 1 === count( array_unique( $ids ) ) && rar_stock( $p2 ) === $before_same - 1, '5 simultaneous saves of one order → exactly 1 order, stock −1', wp_json_encode( array_map( function ( $b ) { return $b[0]; }, $burst ) ) );

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

	echo "\nNo overselling between different staff (v1.3.0)\n";
	$last = rar_product( 'LastUnit', 700, 1 );
	$extra_posts[] = $last;
	$r  = rar_parallel( array(
		array( 'user' => $staff, 'action' => 'create_order', 'data' => array( 'payload' => rar_payload( array( array( 'id' => $last, 'qty' => 1 ) ) ) ) ),
		array( 'user' => $staff2, 'action' => 'create_order', 'data' => array( 'payload' => rar_payload( array( array( 'id' => $last, 'qty' => 1 ) ) ) ) ),
	) );
	$won = 0;
	foreach ( $r as $x ) {
		if ( 200 === $x[0] ) {
			$won++;
			$created[] = (int) $x[1]['data']['order_id'];
		}
	}
	rar_ok( 1 === $won && 0 === rar_stock( $last ), 'two staff sell the last unit at once → 1 order, stock 0 (not −1)', wp_json_encode( array_map( function ( $x ) { return $x[0]; }, $r ) ) . ' stock ' . rar_stock( $last ) );
	$ten = rar_product( 'TenUnits', 300, 10 );
	$extra_posts[] = $ten;
	$calls = array();
	for ( $i = 0; $i < 14; $i++ ) {
		$calls[] = array( 'user' => $i % 2 ? $staff : $staff2, 'action' => 'create_order', 'data' => array( 'payload' => rar_payload( array( array( 'id' => $ten, 'qty' => 1 ) ) ) ) );
	}
	$won = 0;
	foreach ( rar_parallel( $calls ) as $x ) {
		if ( 200 === $x[0] ) {
			$won++;
			$created[] = (int) $x[1]['data']['order_id'];
		}
	}
	rar_ok( 10 === $won && 0 === rar_stock( $ten ), '14 simultaneous orders for 10 units → exactly 10 accepted, stock 0', "accepted {$won}, stock " . rar_stock( $ten ) );

	echo "\nPrice override counts toward the discount limit (v1.3.0)\n";
	rar_login( $staff );
	list( $st ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 1, 'price' => 1 ) ) ) ) );
	rar_ok( 422 === $st, 'staff cannot sell a ৳500 item for ৳1', "$st" );
	list( $st ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 2, 'price' => 450 ) ), array( 'discount' => 150 ) ) ) );
	rar_ok( 422 === $st, 'lower rate (৳100 off) + ৳150 discount = 25% of ৳1000 list → refused', "$st" );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 2, 'price' => 450 ) ), array( 'discount' => 100 ) ) ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	rar_ok( 200 === $st, 'lower rate (৳100 off) + ৳100 discount = 20% → allowed', "$st " . wp_json_encode( $r['data']['message'] ?? $r ) );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 1, 'price' => 650 ) ) ) ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	rar_ok( 200 === $st, 'a higher rate is still allowed', "$st" );

	echo "\nRefused orders are never created; new orders are complete when announced (v1.3.0)\n";
	delete_option( 'rar_smoke_new_order' );
	list( $st ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 2 ) ), array( 'discount_type' => 'percent', 'discount' => 90 ) ) ) );
	wp_cache_flush();
	rar_ok( 422 === $st && false === get_option( 'rar_smoke_new_order' ), 'refused order never reaches woocommerce_new_order', "$st " . wp_json_encode( get_option( 'rar_smoke_new_order' ) ) );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 1 ) ) ) ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	wp_cache_flush();
	$seen = get_option( 'rar_smoke_new_order' );
	rar_ok( 1 === (int) ( $seen['n'] ?? 0 ) && 1 === (int) ( $seen['items'] ?? 0 ) && '01711223344' === ( $seen['phone'] ?? '' ) && (float) ( $seen['total'] ?? 0 ) > 0, 'woocommerce_new_order fires once, with items, phone and total', wp_json_encode( $seen ) );
	list( $st ) = rar_call( 'create_order', array( 'payload' => rar_payload( array_fill( 0, 101, array( 'id' => $p2, 'qty' => 1 ) ) ) ) );
	rar_ok( 422 === $st, 'more than 100 lines refused', "$st" );

	echo "\nFinished orders are never rolled back; stock flag is truthful (v1.3.0 review)\n";
	$hk = rar_product( 'HookThrow', 100, 10 );
	$extra_posts[] = $hk;
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $hk, 'qty' => 3 ) ), array( 'note' => 'RARTEST-THROW' ) ) ) );
	$hid = (int) ( $r['data']['order_id'] ?? 0 );
	$created[] = $hid;
	rar_ok( 200 === $st && $hid && 'processing' === rar_order_status( $hid ) && 7 === rar_stock( $hk ), 'a failing integration hook after the order is saved keeps the order and its stock change', "$st stock " . rar_stock( $hk ) );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $hk, 'qty' => 2 ) ), array( 'note' => 'RARTEST-NOREDUCE' ) ) ) );
	$nid = (int) ( $r['data']['order_id'] ?? 0 );
	$created[] = $nid;
	wp_cache_flush();
	$no = wc_get_order( $nid );
	rar_ok( 200 === $st && $no && 5 === rar_stock( $hk ), 'a plugin that only allows stock reduction once the order is Processing still gets it reduced (7 → 5)', "$st stock " . rar_stock( $hk ) );

	echo "\nStore-wide stock management off (v1.3.0 review)\n";
	update_option( 'woocommerce_manage_stock', 'no' );
	list( $st, $r ) = rar_call( 'stock_update', array( 'product_id' => $hk, 'qty' => 50, 'reason' => 'x' ) );
	update_option( 'woocommerce_manage_stock', 'yes' );
	rar_ok( 422 === $st && false !== strpos( (string) ( $r['data']['message'] ?? '' ), 'turned off' ), 'stock save explains that stock management is off instead of pretending to save', "$st" );

	echo "\nPrices entered including tax (v1.3.0 review)\n";
	$tax_was = array( get_option( 'woocommerce_calc_taxes' ), get_option( 'woocommerce_prices_include_tax' ) );
	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_prices_include_tax', 'yes' );
	$rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'BD', 'tax_rate' => '15.0000', 'tax_rate_name' => 'RARTEST VAT', 'tax_rate_priority' => 1, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
	$tx = rar_product( 'TaxIncl', 115, 10 );
	$extra_posts[] = $tx;
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $tx, 'qty' => 2 ) ), array( 'shipping' => 0 ) ) ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	WC_Tax::_delete_tax_rate( $rate );
	update_option( 'woocommerce_calc_taxes', $tax_was[0] );
	update_option( 'woocommerce_prices_include_tax', $tax_was[1] );
	$to = wc_get_order( (int) ( $r['data']['order_id'] ?? 0 ) );
	rar_ok( 200 === $st && $to && abs( (float) $to->get_total() - 230 ) < 0.02 && abs( (float) $to->get_total_tax() - 30 ) < 0.02, '2 × ৳115 (15% VAT included) totals ৳230 with ৳30 tax, not ৳264.50', $to ? $to->get_total() . ' tax ' . $to->get_total_tax() : "$st" );

	echo "\nStock updates can't overwrite a newer change (v1.3.0)\n";
	$q = rar_product( 'Conflict', 200, 10 );
	$extra_posts[] = $q;
	wc_update_product_stock( wc_get_product( $q ), 1, 'decrease' ); // a website sale while the phone showed 10
	list( $st, $r ) = rar_call( 'stock_update', array( 'product_id' => $q, 'qty' => 15, 'expected' => 10, 'reason' => 'Restock' ) );
	rar_ok( 409 === $st && ! empty( $r['data']['conflict'] ) && 9.0 === (float) ( $r['data']['product']['stock_qty'] ?? -1 ), 'stale +5 refused with the current figure (9)', "$st " . wp_json_encode( $r['data'] ?? $r ) );
	rar_ok( 9 === rar_stock( $q ), 'stock untouched by the refused save' );
	list( $st ) = rar_call( 'stock_update', array( 'product_id' => $q, 'qty' => 14, 'expected' => 9, 'reason' => 'Restock' ) );
	rar_ok( 200 === $st && 14 === rar_stock( $q ), 'save with the fresh figure works (9 → 14)', "$st" );
	list( $st, $r ) = rar_call( 'stock_bulk', array( 'items' => wp_json_encode( array( array( 'id' => $q, 'qty' => 20, 'expected' => 10 ), array( 'id' => $p1, 'qty' => 6, 'expected' => 5 ) ) ), 'reason' => 'Count' ) );
	rar_ok( 200 === $st && 1 === count( $r['data']['updated'] ?? array() ) && ! empty( $r['data']['errors'][0]['conflict'] ) && 14 === rar_stock( $q ) && 6 === rar_stock( $p1 ), 'bulk save: stale row refused, fresh row saved', wp_json_encode( $r['data'] ?? $r ) );
	list( $st ) = rar_call( 'stock_update', array( 'product_id' => $p1, 'qty' => 5, 'reason' => 'Put back' ) );
	rar_ok( 200 === $st && 5 === rar_stock( $p1 ), 'older app without "expected" still works' );

	echo "\nVariations with shared (parent) stock (v1.3.0)\n";
	$parent = new WC_Product_Variable();
	$parent->set_name( 'RARTEST Shared Tee' );
	$parent->set_status( 'publish' );
	$parent->set_manage_stock( true );
	$parent->set_stock_quantity( 40 );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( 'S', 'M' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$parent->set_attributes( array( $attr ) );
	$parent->save();
	$extra_posts[] = $parent->get_id();
	$vids = array();
	foreach ( array( 'S', 'M' ) as $size ) {
		$var = new WC_Product_Variation();
		$var->set_parent_id( $parent->get_id() );
		$var->set_attributes( array( 'size' => $size ) );
		$var->set_regular_price( '500' );
		$var->set_status( 'publish' );
		$var->save();
		$vids[]        = $var->get_id();
		$extra_posts[] = $var->get_id();
	}
	list( $st, $r ) = rar_call( 'stock_update', array( 'product_id' => $vids[0], 'qty' => 45, 'expected' => 40, 'reason' => 'Restock' ) );
	wp_cache_flush();
	rar_ok( 200 === $st && 'parent' === wc_get_product( $vids[0] )->get_manage_stock() && 45 === (int) wc_get_product( $parent->get_id() )->get_stock_quantity(), 'updating size S sets the shared parent stock (45), S stays parent-managed', "$st" );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $vids[0], 'qty' => 30 ), array( 'id' => $vids[1], 'qty' => 20 ) ) ) ) );
	rar_ok( 422 === $st, 'S 30 + M 20 = 50 of 45 shared units refused', "$st" );

	echo "\nStaff can only open recent orders (v1.3.0)\n";
	$old = wc_create_order( array( 'status' => 'completed' ) );
	$old->set_billing_first_name( 'RARTEST Old' );
	$old->set_date_created( time() - 200 * DAY_IN_SECONDS );
	$old->save();
	$created[] = $old->get_id();
	list( $st ) = rar_call( 'order_detail', array( 'id' => $old->get_id() ) );
	rar_ok( 403 === $st, 'staff cannot open a 200-day-old order', "$st" );
	list( $st ) = rar_call( 'order_detail', array( 'id' => $oid ) );
	rar_ok( 200 === $st, 'staff can open a recent order', "$st" );
	rar_login( $manager );
	list( $st ) = rar_call( 'order_detail', array( 'id' => $old->get_id() ) );
	rar_ok( 200 === $st, 'shop manager can open the old order', "$st" );

	echo "\nRe-opening a cancelled order checks stock (v1.3.0)\n";
	$two = rar_product( 'ReopenTwo', 100, 2 );
	$extra_posts[] = $two;
	rar_login( $staff );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $two, 'qty' => 2 ) ) ) ) );
	$reopen = (int) ( $r['data']['order_id'] ?? 0 );
	$created[] = $reopen;
	rar_login( $manager );
	rar_call( 'order_status', array( 'id' => $reopen, 'status' => 'cancelled' ) );
	rar_ok( 2 === rar_stock( $two ), 'cancel restores 2 units' );
	rar_login( $staff );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $two, 'qty' => 1 ) ) ) ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	rar_login( $manager );
	list( $st ) = rar_call( 'order_status', array( 'id' => $reopen, 'status' => 'processing' ) );
	rar_ok( 422 === $st && 1 === rar_stock( $two ) && 'cancelled' === rar_order_status( $reopen ), 're-open needing 2 with 1 left is refused; stock stays 1', "$st" );

	echo "\nStaff login page protection (v1.3.0)\n";
	$page = wp_remote_get( RAR_WSO_Plugin::staff_url(), array( 'timeout' => 30 ) );
	$h    = wp_remote_retrieve_headers( $page );
	rar_ok( 'SAMEORIGIN' === ( $h['x-frame-options'] ?? '' ) && false !== strpos( (string) ( $h['content-security-policy'] ?? '' ), "frame-ancestors 'self'" ), 'app pages cannot be framed by other sites (X-Frame-Options + CSP)' );
	rar_ok( false !== strpos( wp_remote_retrieve_body( $page ), 'rar_wso_login_nonce' ), 'login form carries a CSRF token' );
	list( $st, $body ) = rar_staff_login_post( 'rartest_staff', 'x', false );
	rar_ok( false !== strpos( $body, 'open too long' ), 'login without the token is refused', "$st" );
	for ( $i = 0; $i < RAR_WSO_Security::user_limit(); $i++ ) {
		rar_staff_login_post( 0 === $i % 2 ? 'rartest_staff' : 'rartest_staff@example.test', 'wrong-' . $i ); // username and email share one counter
	}
	list( $st, $body ) = rar_staff_login_post( 'rartest_staff', 'still-wrong' );
	rar_ok( 429 === $st && false !== stripos( $body, 'Too many wrong passwords' ), 'after ' . RAR_WSO_Security::user_limit() . ' wrong passwords the form is locked (429)', "$st" );
	RAR_WSO_Security::clear_failures( 'rartest_staff' );
	delete_transient( 'rar_wso_lf_ip_' . md5( RAR_WSO_Security::client_ip() ) );
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%rar\\_wso\\_lf\\_%'" );
	wp_cache_flush();
	wp_set_password( 'Correct-Horse-9', $staff );
	list( $st ) = rar_staff_login_post( 'rartest_staff', 'Correct-Horse-9' );
	rar_ok( 302 === $st, 'correct password signs in after the lock is cleared', "$st" );

	echo "\nNo staff accounts from public registration (v1.3.0)\n";
	$was = get_option( 'default_role' );
	update_option( 'default_role', 'rar_wso_staff' );
	wp_cache_flush();
	rar_ok( 'rar_wso_staff' !== get_option( 'default_role' ) && 'rar_wso_staff' !== RAR_WSO_Security::stored_default_role(), 'staff role cannot become the default sign-up role', get_option( 'default_role' ) );
	$wpdb->update( $wpdb->options, array( 'option_value' => 'shop_manager' ), array( 'option_name' => 'default_role' ) ); // written straight to the DB
	wp_cache_flush();
	rar_ok( 'shop_manager' !== get_option( 'default_role' ), 'a Shop Manager default role written to the DB is overridden on read', get_option( 'default_role' ) );
	$wpdb->update( $wpdb->options, array( 'option_value' => $was ), array( 'option_name' => 'default_role' ) );
	wp_cache_flush();
	// Public sign-up over HTTP while another plugin forces the staff role (pre_option filter in the probe).
	$reg_was = get_option( 'users_can_register' );
	update_option( 'users_can_register', 1 );
	$res = wp_remote_post( site_url( 'wp-login.php?action=register&rar_smoke_force_role=1' ), array( 'timeout' => 30, 'redirection' => 0, 'body' => array( 'user_login' => 'rartest_selfreg', 'user_email' => 'selfreg@example.test' ) ) );
	update_option( 'users_can_register', $reg_was );
	wp_cache_flush();
	$self = get_user_by( 'login', 'rartest_selfreg' );
	if ( $self ) {
		$extra_users[] = $self->ID;
	}
	rar_ok( $self && ! array_intersect( array( 'rar_wso_staff', 'shop_manager', 'administrator' ), (array) $self->roles ), 'public sign-up that would get the staff role is downgraded to Customer', $self ? implode( ',', (array) $self->roles ) : 'no user (HTTP ' . wp_remote_retrieve_response_code( $res ) . ')' );

	echo "\nStaff accounts added by an Administrator (v1.3.0)\n";
	delete_transient( '_wc_activation_redirect' ); // fresh site: WooCommerce would send the first admin request to its setup wizard
	rar_login( $admin );
	$res = wp_remote_post( $GLOBALS['rar_base'] . '/wp-admin/admin-post.php', array( 'timeout' => 30, 'redirection' => 0, 'cookies' => $GLOBALS['rar_auth'], 'body' => array( 'action' => 'rar_wso_add_staff', '_wpnonce' => wp_create_nonce( 'rar_wso_add_staff' ), 'user_login' => 'rartest_newstaff', 'user_email' => 'newstaff@example.test', 'display_name' => 'RARTEST New Staff' ) ) );
	wp_cache_flush();
	$new = get_user_by( 'login', 'rartest_newstaff' );
	if ( $new ) {
		$extra_users[] = $new->ID;
	}
	$flash = get_transient( 'rar_wso_flash_' . $admin );
	rar_ok( 302 === (int) wp_remote_retrieve_response_code( $res ) && $new && array( 'rar_wso_staff' ) === array_values( $new->roles ), 'admin creates a staff account (role Staff only)', wp_remote_retrieve_response_code( $res ) . ' ' . wp_json_encode( $flash ) . ' roles=' . ( $new ? implode( ',', $new->roles ) : 'none' ) );
	rar_login( $manager );
	$res = wp_remote_post( $GLOBALS['rar_base'] . '/wp-admin/admin-post.php', array( 'timeout' => 30, 'redirection' => 0, 'cookies' => $GLOBALS['rar_auth'], 'body' => array( 'action' => 'rar_wso_add_staff', '_wpnonce' => wp_create_nonce( 'rar_wso_add_staff' ), 'user_login' => 'rartest_sneaky', 'user_email' => 'sneaky@example.test' ) ) );
	wp_cache_flush();
	rar_ok( 403 === (int) wp_remote_retrieve_response_code( $res ) && ! get_user_by( 'login', 'rartest_sneaky' ), 'shop manager cannot add staff unless an admin allows it', (string) wp_remote_retrieve_response_code( $res ) );

	echo "\nPaused staff (v1.3.0)\n";
	update_user_meta( $staff2, 'rar_wso_suspended', '1' );
	rar_login( $staff2 );
	list( $st ) = rar_call( 'stats', array( 'period' => 'today' ) );
	rar_ok( 403 === $st, 'paused staff is refused by the app backend', "$st" );
	wp_set_password( 'Paused-Pass-7', $staff2 );
	$signon = wp_authenticate( 'rartest_staff2', 'Paused-Pass-7' );
	rar_ok( is_wp_error( $signon ) && 'rar_wso_paused' === $signon->get_error_code(), 'paused staff cannot sign in anywhere' );
	delete_user_meta( $staff2, 'rar_wso_suspended' );
	rar_login( $manager );

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

	echo "\nAdmin Control Center (v1.4.0)\n";
	delete_transient( '_wc_activation_redirect' );
	rar_login( $admin );
	$bad = array();
	foreach ( array( 'overview' => 'rarx-kpis', 'staff' => 'rarx-people', 'activity' => 'rarx-filters', 'settings' => 'rarx-settings-form', 'security' => 'rarx-checklist', 'tools' => 'rarx-export-grid', 'help' => 'rarx-faq' ) as $tab => $marker ) {
		list( $st, $body ) = rar_admin( 'admin.php?page=rar-wso&tab=' . $tab );
		if ( 200 !== $st || false === strpos( $body, $marker ) || preg_match( '/(Fatal error|Warning:|Notice:|Deprecated:)/', $body ) ) {
			$bad[] = "{$tab}:{$st}";
		}
	}
	rar_ok( ! $bad, 'all 7 Control Center tabs render for an admin without PHP errors', implode( ' ', $bad ) );
	list( $st, $r ) = array( 0, null );
	$res = wp_remote_post( $GLOBALS['rar_base'] . '/wp-admin/admin-ajax.php', array( 'timeout' => 60, 'cookies' => $GLOBALS['rar_auth'], 'body' => array( 'action' => 'rar_wso_admin_live', 'nonce' => wp_create_nonce( 'rar_wso_admin' ) ) ) );
	$live = json_decode( wp_remote_retrieve_body( $res ), true );
	rar_ok( ! empty( $live['success'] ) && false !== strpos( (string) $live['data']['html'], 'rarx-kpi' ), 'overview live refresh returns fresh KPI cards' );
	rar_login( $manager );
	list( $st ) = rar_admin( 'admin.php?page=rar-wso&tab=overview' );
	rar_ok( 200 === $st, 'shop manager can open the Control Center', "$st" );
	rar_login( $staff );
	list( $st, $body ) = rar_admin( 'admin.php?page=rar-wso' );
	rar_ok( 200 !== $st || false === strpos( $body, 'rarx-kpis' ), 'staff accounts cannot open the Control Center', "$st" );

	echo "\nPer-staff permissions (v1.4.0)\n";
	rar_login( $admin );
	list( $st ) = rar_admin( 'admin-post.php', array( 'action' => 'rar_wso_staff_update', 'user_id' => $staff2, '_wpnonce' => wp_create_nonce( 'rar_wso_staff_update_' . $staff2 ), 'caps' => array( 'rar_wso_create_orders' ), 'view_orders' => 'no', 'max_discount' => '5', 'branch' => 'RARTEST Branch' ) );
	wp_cache_flush();
	rar_ok( 302 === $st && ! user_can( $staff2, 'rar_wso_manage_stock' ) && user_can( $staff2, 'rar_wso_create_orders' ) && 'RARTEST Branch' === get_user_meta( $staff2, 'rar_wso_branch', true ), 'admin blocks stock updates for one staff member and sets a branch', "$st" );
	rar_login( $staff2 );
	list( $st ) = rar_call( 'stock_update', array( 'product_id' => $p2, 'qty' => 40, 'reason' => 'x' ) );
	rar_ok( 403 === $st, 'blocked staff member cannot update stock', "$st" );
	list( $st ) = rar_call( 'orders', array( 'scope' => 'today' ) );
	rar_ok( 403 === $st, 'order lists hidden for that person only', "$st" );
	list( $st ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 2 ) ), array( 'discount' => 100 ) ) ) );
	rar_ok( 422 === $st, 'personal 5% limit refuses a 10% discount (shop limit is 20%)', "$st" );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 2 ) ), array( 'discount' => 50 ) ) ) );
	$created[] = (int) ( $r['data']['order_id'] ?? 0 );
	rar_ok( 200 === $st, 'personal 5% limit allows 5%', "$st" );
	rar_login( $staff );
	list( $st ) = rar_call( 'orders', array( 'scope' => 'today' ) );
	rar_ok( 200 === $st, 'other staff keep the shop defaults', "$st" );
	$audit = RAR_WSO_Audit::query( array( 'action' => 'staff_updated', 'per_page' => 1 ) );
	rar_ok( $audit['items'] && (int) $audit['items'][0]['object'] === $staff2, 'permission change is written to the audit log' );
	rar_login( $admin );
	rar_admin( 'admin-post.php', array( 'action' => 'rar_wso_staff_update', 'user_id' => $staff2, '_wpnonce' => wp_create_nonce( 'rar_wso_staff_update_' . $staff2 ), 'caps' => array_keys( RAR_WSO_Admin::staff_caps() ) ) );
	wp_cache_flush();
	rar_ok( user_can( $staff2, 'rar_wso_manage_stock' ) && '' === get_user_meta( $staff2, 'rar_wso_max_discount', true ), 'saving with defaults restores the Staff role permissions' );

	echo "\nSettings form (v1.4.0)\n";
	$before = RAR_WSO_Plugin::settings();
	list( $st ) = rar_admin( 'options.php', array( 'option_page' => 'rar_wso_group', 'action' => 'update', '_wpnonce' => wp_create_nonce( 'rar_wso_group-options' ), '_wp_http_referer' => '/wp-admin/admin.php?page=rar-wso&tab=settings', 'rar_wso_settings' => array_merge( $before, array( '_form' => '1', 'enabled' => '1', 'brand_color' => '#0A7F6A', 'staff_slug' => 'wp-admin', 'payment_methods' => array( 'cod', 'bkash' ), 'payment_extra' => "Rocket\n=cmd()", 'payment_default' => 'bkash', 'free_shipping_over' => '3000', 'login_user_limit' => '2', 'log_retention_days' => '7', 'allow_price_override' => '1', 'staff_view_orders' => '1', 'digest_enabled' => null, 'admin_bar_link' => '1', 'dashboard_widget' => '1', 'order_column' => '1' ) ) ) );
	wp_cache_flush();
	$s = RAR_WSO_Plugin::settings();
	rar_ok( 302 === $st && '#0a7f6a' === $s['brand_color'] && 'staff' === $s['staff_slug'] && '3' === $s['login_user_limit'] && '30' === $s['log_retention_days'] && 'no' === $s['digest_enabled'] && 'yes' === $s['enabled'], 'settings are validated (colour, reserved slug kept, limits clamped, unticked box off)', "$st " . wp_json_encode( array_intersect_key( $s, array_flip( array( 'brand_color', 'staff_slug', 'login_user_limit', 'log_retention_days', 'digest_enabled', 'enabled' ) ) ) ) );
	$pay = RAR_WSO_Ajax::enabled_payment_options();
	rar_ok( isset( $pay['cod'], $pay['bkash'], $pay['x-rocket'] ) && ! isset( $pay['nagad'] ) && 'bkash' === RAR_WSO_Ajax::default_payment(), 'payment methods: ticked built-ins + extra "Rocket"', wp_json_encode( $pay ) );
	$audit = RAR_WSO_Audit::query( array( 'action' => 'settings_saved', 'per_page' => 1 ) );
	rar_ok( $audit['items'] && false !== strpos( $audit['items'][0]['message'], 'brand_color' ), 'settings change is audited with the changed keys' );
	rar_login( $staff );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 1 ) ), array( 'payment' => 'nagad' ) ) ) );
	$no = wc_get_order( (int) ( $r['data']['order_id'] ?? 0 ) );
	$created[] = $no ? $no->get_id() : 0;
	rar_ok( 200 === $st && $no && 'bkash' === $no->get_payment_method(), 'a switched-off payment method falls back to the default', $no ? $no->get_payment_method() : "$st" );
	list( $st, $r ) = rar_call( 'create_order', array( 'payload' => rar_payload( array( array( 'id' => $p2, 'qty' => 1 ) ), array( 'payment' => 'x-rocket' ) ) ) );
	$no = wc_get_order( (int) ( $r['data']['order_id'] ?? 0 ) );
	$created[] = $no ? $no->get_id() : 0;
	rar_ok( 200 === $st && $no && 'Rocket' === $no->get_payment_method_title(), 'extra payment method is stored with its name', $no ? $no->get_payment_method_title() : "$st" );
	$app = wp_remote_retrieve_body( wp_remote_get( RAR_WSO_Plugin::staff_url(), array( 'timeout' => 30, 'cookies' => $GLOBALS['rar_auth'] ) ) );
	rar_ok( false !== strpos( $app, '--top:#0a7f6a' ) && false !== strpos( $app, '"freeShipOver":3000' ) && false !== strpos( $app, '"payDefault":"bkash"' ), 'staff app picks up brand colour, free delivery and default payment' );
	update_option( 'rar_wso_settings', $before );

	echo "\nExports & tools (v1.4.0)\n";
	rar_login( $admin );
	$x = function ( $type, $extra = array() ) {
		return rar_admin( 'admin-post.php', array_merge( array( 'action' => 'rar_wso_export', 'type' => $type, '_wpnonce' => wp_create_nonce( 'rar_wso_export' ) ), $extra ) );
	};
	list( $st, $body, $h ) = $x( 'stock' );
	rar_ok( 200 === $st && 0 === strpos( $body, "\xEF\xBB\xBF" . '"Product ID"' ) && false !== strpos( $body, 'RARTEST Alpha' ) && false !== strpos( (string) ( $h['content-type'] ?? '' ), 'text/csv' ), 'stock valuation CSV (UTF-8 BOM, every product)', "$st " . substr( $body, 0, 60 ) );
	list( $st, $body ) = $x( 'orders', array( 'from' => wp_date( 'Y-m-d' ) ) );
	rar_ok( 200 === $st && false !== strpos( $body, '#' . wc_get_order( $oid )->get_order_number() ) && false !== strpos( $body, 'RARTEST Customer' ), 'staff orders CSV lists today\'s staff orders', "$st" );
	list( $st, $body ) = $x( 'movements' );
	rar_ok( 200 === $st && false !== strpos( $body, 'Order #' ), 'stock movements CSV', "$st" );
	list( $st, $body ) = $x( 'audit' );
	rar_ok( 200 === $st && false !== strpos( $body, 'Staff permissions changed' ), 'audit log CSV', "$st" );
	list( $st, $body ) = $x( 'settings' );
	$json = json_decode( $body, true );
	rar_ok( 200 === $st && 'rar-woo-stock-order' === ( $json['plugin'] ?? '' ) && isset( $json['settings']['brand_color'] ), 'settings backup is valid JSON', "$st" );
	rar_login( $manager );
	list( $st ) = $x( 'settings' );
	rar_ok( 403 === $st, 'shop manager cannot download the settings backup', "$st" );
	list( $st ) = rar_admin( 'admin-post.php', array( 'action' => 'rar_wso_tool', 'do' => 'reset_settings', '_wpnonce' => wp_create_nonce( 'rar_wso_tool_reset_settings' ) ) );
	rar_ok( 403 === $st, 'shop manager cannot reset settings', "$st" );
	list( $st ) = rar_admin( 'admin-post.php', array( 'action' => 'rar_wso_tool', 'do' => 'clear_cache', '_wpnonce' => wp_create_nonce( 'rar_wso_tool_clear_cache' ) ) );
	rar_ok( 302 === $st, 'shop manager can clear dashboard caches', "$st" );
	list( $st ) = rar_admin( 'admin-post.php', array( 'action' => 'rar_wso_tool', 'do' => 'clear_cache', '_wpnonce' => 'bad' ) );
	rar_ok( 403 === $st, 'tools refuse a bad nonce', "$st" );
	rar_login( $admin );
	add_filter( 'pre_wp_mail', '__return_true' );
	rar_ok( RAR_WSO_Digest::send( true ) && false !== strpos( RAR_WSO_Digest::html( RAR_WSO_Digest::data(), 'RARTEST Biz', 'today' ), 'RARTEST Biz' ), 'daily summary email builds and sends' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$checks = RAR_WSO_Health::checks();
	$score  = RAR_WSO_Health::score( $checks );
	rar_ok( count( $checks ) >= 15 && $score >= 0 && $score <= 100, "health checks run ({$score}/100)" );
	rar_ok( wp_next_scheduled( 'rar_wso_housekeeping' ) || ( do_action( 'admin_init' ) || wp_next_scheduled( 'rar_wso_housekeeping' ) ), 'daily housekeeping is scheduled' );

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
	foreach ( array_reverse( array_merge( array( $p1, $p2 ), $extra_posts ) ) as $pid ) {
		wp_delete_post( $pid, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array_merge( array( $staff, $staff2, $manager, $admin ), $extra_users ) as $uid ) {
		wp_delete_user( $uid );
	}
	update_option( 'rar_wso_settings', $original_settings );
	delete_option( 'rar_smoke_new_order' );
	@unlink( $probe_file );
}

echo "\n{$GLOBALS['rar_pass']} passed, {$GLOBALS['rar_fail']} failed\n";
exit( $GLOBALS['rar_fail'] ? 1 : 0 );
