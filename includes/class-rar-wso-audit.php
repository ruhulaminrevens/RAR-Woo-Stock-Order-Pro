<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin & security audit trail: who created / paused staff, changed settings, exported
 * data, signed in to the staff app, or tripped the login limit.
 *
 * Stored in its own small table ({prefix}rar_wso_audit, created with the stock log),
 * so it never bloats wp_options and survives plugin updates.
 */
class RAR_WSO_Audit {
    /** Human labels and a tone for the admin list. */
    public static function actions() {
        return array(
            'staff_created'   => array( __( 'Staff account created', 'rar-woo-stock-order' ), 'good' ),
            'staff_updated'   => array( __( 'Staff permissions changed', 'rar-woo-stock-order' ), 'info' ),
            'staff_paused'    => array( __( 'Staff paused', 'rar-woo-stock-order' ), 'warn' ),
            'staff_resumed'   => array( __( 'Staff resumed', 'rar-woo-stock-order' ), 'good' ),
            'staff_signout'   => array( __( 'Signed out everywhere', 'rar-woo-stock-order' ), 'info' ),
            'staff_link'      => array( __( 'Password link issued', 'rar-woo-stock-order' ), 'info' ),
            'login'           => array( __( 'Signed in', 'rar-woo-stock-order' ), 'good' ),
            'login_locked'    => array( __( 'Login locked (too many wrong passwords)', 'rar-woo-stock-order' ), 'bad' ),
            'settings_saved'  => array( __( 'Settings saved', 'rar-woo-stock-order' ), 'info' ),
            'settings_import' => array( __( 'Settings imported', 'rar-woo-stock-order' ), 'warn' ),
            'settings_reset'  => array( __( 'Settings reset to defaults', 'rar-woo-stock-order' ), 'warn' ),
            'export'          => array( __( 'Data exported', 'rar-woo-stock-order' ), 'info' ),
            'tool'            => array( __( 'Maintenance tool used', 'rar-woo-stock-order' ), 'info' ),
            'emergency'       => array( __( 'Emergency action', 'rar-woo-stock-order' ), 'bad' ),
            'digest'          => array( __( 'Daily summary email', 'rar-woo-stock-order' ), 'info' ),
        );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rar_wso_audit';
    }

    public static function schema( $collate ) {
        $table = self::table();
        return "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                action varchar(40) NOT NULL DEFAULT '',
                object_id bigint(20) unsigned NOT NULL DEFAULT 0,
                message varchar(255) NOT NULL DEFAULT '',
                ip varchar(45) NOT NULL DEFAULT '',
                created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
                PRIMARY KEY  (id),
                KEY action (action),
                KEY created_gmt (created_gmt)
            ) {$collate};";
    }

    /**
     * Adds one entry. Never throws and never prints a database error (an audit write must
     * not break a login or a settings save, e.g. right before the upgrade created the table).
     */
    public static function add( $action, $message = '', $object_id = 0, $user_id = null ) {
        global $wpdb;
        $shown = $wpdb->hide_errors();
        $wpdb->insert(
            self::table(),
            array(
                'user_id'     => null === $user_id ? get_current_user_id() : absint( $user_id ),
                'action'      => substr( sanitize_key( $action ), 0, 40 ),
                'object_id'   => absint( $object_id ),
                'message'     => function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( (string) $message ), 0, 255 ) : substr( sanitize_text_field( (string) $message ), 0, 255 ),
                'ip'          => substr( (string) RAR_WSO_Security::client_ip(), 0, 45 ),
                'created_gmt' => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%s' )
        );
        if ( $shown ) {
            $wpdb->show_errors();
        }
    }

    /**
     * @param array $args page, per_page, action ('' | exact | 'security'), user_id, search, from, to (GMT 'Y-m-d H:i:s').
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function query( $args = array() ) {
        global $wpdb;
        $table    = self::table();
        $page     = max( 1, absint( $args['page'] ?? 1 ) );
        $per_page = min( 200, max( 1, absint( $args['per_page'] ?? 30 ) ) );
        $where    = array( '1=1' );
        $params   = array();

        $action = sanitize_key( $args['action'] ?? '' );
        if ( 'security' === $action ) {
            $where[] = "a.action IN ('login','login_locked','staff_paused','staff_resumed','staff_signout','staff_link','emergency','settings_import','settings_reset')";
        } elseif ( '' !== $action ) {
            $where[]  = 'a.action = %s';
            $params[] = $action;
        }
        if ( ! empty( $args['user_id'] ) ) {
            $where[]  = 'a.user_id = %d';
            $params[] = absint( $args['user_id'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
            $where[]  = '(a.message LIKE %s OR a.ip LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if ( ! empty( $args['from'] ) ) {
            $where[]  = 'a.created_gmt >= %s';
            $params[] = (string) $args['from'];
        }
        if ( ! empty( $args['to'] ) ) {
            $where[]  = 'a.created_gmt <= %s';
            $params[] = (string) $args['to'];
        }

        $where_sql = implode( ' AND ', $where );
        $shown     = $wpdb->hide_errors();
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count_sql = "SELECT COUNT(*) FROM {$table} a WHERE {$where_sql}";
        $total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
        $rows      = $wpdb->get_results( $wpdb->prepare( "SELECT a.* FROM {$table} a WHERE {$where_sql} ORDER BY a.id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
        // phpcs:enable
        if ( $shown ) {
            $wpdb->show_errors();
        }

        $labels = self::actions();
        $names  = array();
        $items  = array();
        foreach ( (array) $rows as $row ) {
            $uid = (int) $row['user_id'];
            if ( $uid && ! isset( $names[ $uid ] ) ) {
                $u             = get_userdata( $uid );
                $names[ $uid ] = $u ? $u->display_name : '#' . $uid;
            }
            $items[] = array(
                'id'      => (int) $row['id'],
                'action'  => $row['action'],
                'label'   => $labels[ $row['action'] ][0] ?? ucwords( str_replace( '_', ' ', $row['action'] ) ),
                'tone'    => $labels[ $row['action'] ][1] ?? 'info',
                'message' => $row['message'],
                'user'    => $uid ? $names[ $uid ] : __( 'System / visitor', 'rar-woo-stock-order' ),
                'user_id' => $uid,
                'object'  => (int) $row['object_id'],
                'ip'      => $row['ip'],
                'time'    => strtotime( $row['created_gmt'] . ' UTC' ),
            );
        }
        return array( 'items' => $items, 'total' => $total );
    }

    /** Deletes entries older than $days days. Returns the number removed. */
    public static function prune( $days ) {
        global $wpdb;
        $days = absint( $days );
        if ( ! $days ) {
            return 0;
        }
        $cut   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $shown = $wpdb->hide_errors();
        $n     = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_gmt < %s', $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $shown ) {
            $wpdb->show_errors();
        }
        return $n;
    }

    public static function count_since( $action, $gmt ) {
        global $wpdb;
        $shown = $wpdb->hide_errors();
        $n     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE action = %s AND created_gmt >= %s', $action, $gmt ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $shown ) {
            $wpdb->show_errors();
        }
        return $n;
    }
}
