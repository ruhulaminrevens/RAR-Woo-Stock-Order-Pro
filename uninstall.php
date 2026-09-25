<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Deliberately preserve plugin settings and all WooCommerce product/order data.
// Uninstalling this helper must never silently delete operational history.
