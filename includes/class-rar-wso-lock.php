<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Short database locks for stock and order writes.
 *
 * Uses MySQL / MariaDB named locks (GET_LOCK). They belong to this request's database
 * connection, so they are released automatically even if PHP crashes or times out, and
 * two requests can never both hold the same lock. If the database does not support
 * named locks (e.g. an SQLite drop-in), an atomic INSERT IGNORE row in wp_options with
 * a time-to-live is used instead.
 */
class RAR_WSO_Lock {
    /** @var array<string,string> lock key => backend ('db' | 'row') */
    private static $held = array();

    /** @var bool|null */
    private static $supported = null;

    /** @var bool|null */
    private static $multi = null;

    private static function name( $key ) {
        global $wpdb;
        // Named locks are server-wide on MySQL; prefix with this site's DB + table prefix. Max 64 chars.
        return 'rarwso_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 8 ) . '_' . md5( (string) $key );
    }

    public static function supported() {
        global $wpdb;
        if ( null === self::$supported ) {
            $suppress          = $wpdb->suppress_errors( true );
            $probe             = $wpdb->get_var( "SELECT IS_FREE_LOCK('rarwso_probe')" );
            self::$supported   = null !== $probe && '' === (string) $wpdb->last_error;
            $wpdb->suppress_errors( $suppress );
        }
        return self::$supported;
    }

    /** MySQL 5.7+ / MariaDB 10.0+ can hold several named locks in one connection. */
    public static function multi_supported() {
        global $wpdb;
        if ( null === self::$multi ) {
            $info        = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
            $version     = method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '0';
            self::$multi = false !== stripos( $info, 'mariadb' ) || version_compare( $version, '5.7', '>=' );
        }
        return self::$multi;
    }

    /**
     * @param string $key  Lock key.
     * @param int    $wait Seconds to wait for another request to finish (0 = don't wait).
     */
    public static function acquire( $key, $wait = 0 ) {
        global $wpdb;
        if ( isset( self::$held[ $key ] ) ) {
            return true;
        }
        if ( self::supported() ) {
            $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::name( $key ), max( 0, (int) $wait ) ) );
            if ( '1' === (string) $got ) {
                self::$held[ $key ] = 'db';
                self::shutdown_guard();
                return true;
            }
            return false;
        }
        return self::row_acquire( $key, $wait );
    }

    public static function release( $key ) {
        global $wpdb;
        if ( ! isset( self::$held[ $key ] ) ) {
            return;
        }
        if ( 'db' === self::$held[ $key ] ) {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::name( $key ) ) );
        } else {
            $wpdb->delete( $wpdb->options, array( 'option_name' => 'rar_wso_lk_' . md5( (string) $key ) ) );
        }
        unset( self::$held[ $key ] );
    }

    public static function release_all() {
        foreach ( array_keys( self::$held ) as $key ) {
            self::release( $key );
        }
    }

    /**
     * Locks the stock of several products in a fixed order (no deadlocks).
     *
     * @param int[] $ids  Stock-owner product IDs.
     * @param int   $wait Seconds to wait per product.
     * @return string[]|false Lock keys held, or false if one could not be taken.
     */
    public static function acquire_products( $ids, $wait = 10 ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        sort( $ids );
        if ( ! $ids ) {
            return array();
        }
        // Old MySQL (< 5.7) can hold only one named lock per connection: use one store-wide stock lock.
        $keys = ( self::supported() && ! self::multi_supported() ) ? array( 'stock' ) : array_map( static function ( $id ) {
            return 'stock|' . $id;
        }, $ids );
        $held = array();
        foreach ( $keys as $key ) {
            if ( ! self::acquire( $key, $wait ) ) {
                foreach ( $held as $h ) {
                    self::release( $h );
                }
                return false;
            }
            $held[] = $key;
        }
        return $held;
    }

    private static function row_acquire( $key, $wait, $ttl = 60 ) {
        global $wpdb;
        $opt      = 'rar_wso_lk_' . md5( (string) $key );
        $deadline = microtime( true ) + max( 0, (int) $wait );
        do {
            $now = time();
            // INSERT IGNORE affects exactly one row only for the request that created it.
            $n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $opt, (string) $now ) );
            if ( 1 !== (int) $n ) {
                // Take over a lock left by a crashed request (atomic compare-and-set).
                $n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %s", (string) $now, $opt, (string) ( $now - $ttl ) ) );
            }
            if ( 1 === (int) $n ) {
                self::$held[ $key ] = 'row';
                wp_cache_delete( $opt, 'options' );
                self::shutdown_guard();
                return true;
            }
            if ( microtime( true ) >= $deadline ) {
                return false;
            }
            usleep( 150000 );
        } while ( true );
    }

    private static function shutdown_guard() {
        static $registered = false;
        if ( ! $registered ) {
            $registered = true;
            register_shutdown_function( array( __CLASS__, 'release_all' ) );
        }
    }
}
