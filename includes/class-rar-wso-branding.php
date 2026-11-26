<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * App icon: the image chosen in Settings → App identity is turned into the PNG sizes phones
 * need (192, 512, maskable 512, Apple touch 180) and stored in uploads/rar-wso-icons/.
 * Without a chosen image the bundled icons are used.
 */
final class RAR_WSO_Branding {
    const OPTION = 'rar_wso_icons';

    private static $sizes = array(
        '192'      => array( 192, false ),
        '512'      => array( 512, false ),
        'maskable' => array( 512, true ),
        'apple'    => array( 180, true ),
    );

    private static $bundled = array(
        '192'      => 'icon-192.png',
        '512'      => 'icon-512.png',
        'maskable' => 'maskable-512.png',
        'apple'    => 'apple-touch-icon.png',
    );

    public static function hooks() {
        add_action( 'update_option_rar_wso_settings', array( __CLASS__, 'maybe_regenerate' ), 20, 2 );
    }

    public static function maybe_regenerate( $old, $new ) {
        $old_id = absint( is_array( $old ) ? ( $old['app_icon_id'] ?? 0 ) : 0 );
        $new_id = absint( is_array( $new ) ? ( $new['app_icon_id'] ?? 0 ) : 0 );
        $old_c  = is_array( $old ) ? (string) ( $old['brand_color'] ?? '' ) : '';
        $new_c  = is_array( $new ) ? (string) ( $new['brand_color'] ?? '' ) : '';
        if ( $old_id !== $new_id || $old_c !== $new_c ) {
            self::regenerate();
        }
    }

    private static function dir() {
        $u = wp_upload_dir( null, false );
        return array( trailingslashit( $u['basedir'] ) . 'rar-wso-icons/', trailingslashit( $u['baseurl'] ) . 'rar-wso-icons/' );
    }

    /** True when a custom icon has been generated for the image currently chosen. */
    public static function has_custom() {
        $s  = RAR_WSO_Plugin::settings();
        $id = absint( $s['app_icon_id'] );
        $o  = get_option( self::OPTION, array() );
        return $id && is_array( $o ) && (int) ( $o['id'] ?? 0 ) === $id && ! empty( $o['files'] );
    }

    /** Icon URL for 192 | 512 | maskable | apple (custom if available, otherwise the bundled file). */
    public static function icon( $key ) {
        $s  = RAR_WSO_Plugin::settings();
        $id = absint( $s['app_icon_id'] );
        if ( $id ) {
            $o = get_option( self::OPTION, array() );
            if ( ! is_array( $o ) || (int) ( $o['id'] ?? 0 ) !== $id || empty( $o['files'] ) ) {
                self::regenerate();
                $o = get_option( self::OPTION, array() );
            }
            if ( is_array( $o ) && ! empty( $o['files'][ $key ] ) ) {
                return $o['files'][ $key ];
            }
        }
        return RAR_WSO_PWA::icon_url( self::$bundled[ $key ] ?? 'icon-192.png' );
    }

    /** Builds all sizes from the chosen image. Returns true on success. */
    public static function regenerate() {
        $s  = RAR_WSO_Plugin::settings();
        $id = absint( $s['app_icon_id'] );
        if ( ! $id ) {
            delete_option( self::OPTION );
            return true;
        }
        if ( ! function_exists( 'imagecreatefromstring' ) || ! wp_attachment_is_image( $id ) ) {
            update_option( self::OPTION, array( 'id' => $id, 'files' => array(), 'error' => 'gd' ), false );
            return false;
        }
        $path = get_attached_file( $id );
        if ( ! $path || ! is_readable( $path ) ) {
            update_option( self::OPTION, array( 'id' => $id, 'files' => array(), 'error' => 'file' ), false );
            return false;
        }
        $data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $src  = $data ? @imagecreatefromstring( $data ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if ( ! $src ) {
            update_option( self::OPTION, array( 'id' => $id, 'files' => array(), 'error' => 'decode' ), false );
            return false;
        }
        list( $dir, $url ) = self::dir();
        if ( ! wp_mkdir_p( $dir ) ) {
            imagedestroy( $src );
            return false;
        }
        // Old generated files are removed first so changed icons never linger.
        foreach ( (array) glob( $dir . 'icon-*.png' ) as $old ) {
            @unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        $hex = ltrim( RAR_WSO_Plugin::brand_color(), '#' );
        $rgb = array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
        $sw  = imagesx( $src );
        $sh  = imagesy( $src );
        $min = max( 1, min( $sw, $sh ) );
        $ver = time();
        $out = array();

        foreach ( self::$sizes as $key => $conf ) {
            list( $size, $padded ) = $conf;
            $canvas = imagecreatetruecolor( $size, $size );
            imagealphablending( $canvas, false );
            imagesavealpha( $canvas, true );
            if ( $padded ) {
                // Maskable / Apple icons are opaque: brand colour behind, artwork in the safe centre.
                $bg = imagecolorallocate( $canvas, $rgb[0], $rgb[1], $rgb[2] );
                imagefilledrectangle( $canvas, 0, 0, $size, $size, $bg );
                imagealphablending( $canvas, true );
                $inner = (int) round( $size * ( 'apple' === $key ? 0.82 : 0.66 ) );
                $off   = (int) round( ( $size - $inner ) / 2 );
                imagecopyresampled( $canvas, $src, $off, $off, (int) ( ( $sw - $min ) / 2 ), (int) ( ( $sh - $min ) / 2 ), $inner, $inner, $min, $min );
            } else {
                $clear = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
                imagefilledrectangle( $canvas, 0, 0, $size, $size, $clear );
                imagealphablending( $canvas, true );
                imagecopyresampled( $canvas, $src, 0, 0, (int) ( ( $sw - $min ) / 2 ), (int) ( ( $sh - $min ) / 2 ), $size, $size, $min, $min );
            }
            $file = 'icon-' . $id . '-' . $key . '-' . $ver . '.png';
            if ( imagepng( $canvas, $dir . $file, 7 ) ) {
                $out[ $key ] = $url . $file;
            }
            imagedestroy( $canvas );
        }
        imagedestroy( $src );

        update_option( self::OPTION, array( 'id' => $id, 'files' => $out, 'ver' => $ver ), false );
        return count( $out ) === count( self::$sizes );
    }
}
