<?php
/**
 * Minimal .xlsx writer (no external library). Uses ZipArchive; when it is missing the caller
 * falls back to CSV. Values are written as inline strings / numbers, with a few cell styles
 * (header, title, money, integer) and column widths, so the file opens cleanly in Excel,
 * Google Sheets and LibreOffice. Text that could be read as a formula is neutralised.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WSO_Xlsx {
    /** Cell style ids (see styles.xml below). */
    const S_NORMAL = 0;
    const S_HEAD   = 1;
    const S_TITLE  = 2;
    const S_MONEY  = 3;
    const S_INT    = 4;
    const S_BOLD   = 5;
    const S_MUTED  = 6;

    public static function available() {
        return class_exists( 'ZipArchive' );
    }

    /** Excel sheet names: max 31 chars, none of : \ / ? * [ ] */
    public static function sheet_name( $name, $used = array() ) {
        $name = preg_replace( '/[:\\\\\/\?\*\[\]]/u', ' ', (string) $name );
        $name = trim( $name );
        $name = '' === $name ? 'Sheet' : $name;
        $name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 31 ) : substr( $name, 0, 31 );
        $base = $name;
        $i    = 2;
        while ( in_array( strtolower( $name ), array_map( 'strtolower', $used ), true ) ) {
            $suffix = ' ' . $i++;
            $name   = ( function_exists( 'mb_substr' ) ? mb_substr( $base, 0, 31 - strlen( $suffix ) ) : substr( $base, 0, 31 - strlen( $suffix ) ) ) . $suffix;
        }
        return $name;
    }

    private static function col( $n ) {
        $s = '';
        for ( $n = (int) $n; $n >= 0; $n = intdiv( $n, 26 ) - 1 ) {
            $s = chr( 65 + ( $n % 26 ) ) . $s;
        }
        return $s;
    }

    private static function esc( $v ) {
        $v = (string) $v;
        // Strip characters XML 1.0 cannot carry.
        $v = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $v );
        return htmlspecialchars( (string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    /** Text starting with = + - @ is shown as text (never run as a formula in the viewer's spreadsheet). */
    private static function safe_text( $v ) {
        $v = (string) $v;
        if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            return "'" . $v;
        }
        return $v;
    }

    /**
     * One worksheet.
     *
     * @param array $rows   List of rows; a row is a list of cells. A cell is a scalar, or
     *                      array( value, style ) with style one of the S_* constants.
     * @param array $widths Column widths in characters.
     */
    private static function sheet_xml( $rows, $widths ) {
        $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $x .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $x .= '<sheetViews><sheetView workbookViewId="0" showGridLines="1"/></sheetViews>';
        if ( $widths ) {
            $x .= '<cols>';
            foreach ( array_values( $widths ) as $i => $w ) {
                $x .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . (float) $w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ( array_values( $rows ) as $r => $cells ) {
            $x .= '<row r="' . ( $r + 1 ) . '">';
            foreach ( array_values( (array) $cells ) as $c => $cell ) {
                $style = self::S_NORMAL;
                $val   = $cell;
                if ( is_array( $cell ) ) {
                    $val   = $cell[0] ?? '';
                    $style = (int) ( $cell[1] ?? 0 );
                }
                if ( null === $val || '' === $val ) {
                    continue;
                }
                $ref = self::col( $c ) . ( $r + 1 );
                if ( is_int( $val ) || is_float( $val ) ) {
                    $x .= '<c r="' . $ref . '" s="' . $style . '"><v>' . ( is_float( $val ) ? rtrim( rtrim( sprintf( '%.4F', $val ), '0' ), '.' ) : $val ) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc( self::safe_text( $val ) ) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData></worksheet>';
        return $x;
    }

    private static function styles_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF66718C"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF15234A"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="7">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * Builds the workbook and returns its bytes, or false if it could not be created.
     *
     * @param array $sheets List of array( 'name' => string, 'rows' => array, 'widths' => array ).
     * @return string|false
     */
    public static function build( $sheets ) {
        if ( ! self::available() || ! $sheets ) {
            return false;
        }
        $tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'rar-wso-xlsx' ) : tempnam( sys_get_temp_dir(), 'rarx' );
        if ( ! $tmp ) {
            return false;
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE ) ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return false;
        }

        $names    = array();
        $ct       = '';
        $wbSheets = '';
        $wbRels   = '';
        foreach ( array_values( $sheets ) as $i => $sheet ) {
            $n         = $i + 1;
            $name      = self::sheet_name( $sheet['name'] ?? 'Sheet', $names );
            $names[]   = $name;
            $ct       .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::esc( $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels   .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->addFromString( 'xl/worksheets/sheet' . $n . '.xml', self::sheet_xml( $sheet['rows'] ?? array(), $sheet['widths'] ?? array() ) );
        }
        $styleId = count( $sheets ) + 1;
        $wbRels .= '<Relationship Id="rId' . $styleId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ct . '</Types>' );
        $zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
        $zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>' );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>' );
        $zip->addFromString( 'xl/styles.xml', self::styles_xml() );

        $ok = $zip->close();
        if ( ! $ok ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return false;
        }
        $bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        return $bytes;
    }
}
