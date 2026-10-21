<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * High-speed Custom Database Index & Feed Storage for Shaghafi Sync & Repair
 */
class Shaghafi_DB {

    /**
     * Return custom table name
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'shaghafi_feed_items';
    }

    /**
     * Create or upgrade the feed index table
     */
    public static function create_tables() {
        global $wpdb;
        $table_name      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id varchar(64) NOT NULL,
            variant_id varchar(64) NOT NULL,
            artikelnummer varchar(120) NOT NULL,
            base_sku varchar(120) NOT NULL,
            sku_suffix_norm varchar(64) DEFAULT '',
            variantenauspraegung varchar(255) DEFAULT '',
            attr_norm varchar(255) DEFAULT '',
            bestand int(11) NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'active',
            last_updated varchar(64) DEFAULT '',
            live_price varchar(32) DEFAULT NULL,
            live_price_exact tinyint(1) NOT NULL DEFAULT 0,
            live_stock int(11) DEFAULT NULL,
            live_image_url text DEFAULT NULL,
            live_gallery_json longtext DEFAULT NULL,
            live_title text DEFAULT NULL,
            live_attr_name varchar(128) DEFAULT NULL,
            live_product_url text DEFAULT NULL,
            live_checked_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY variant_id (variant_id),
            KEY product_id (product_id),
            KEY artikelnummer (artikelnummer),
            KEY base_sku (base_sku),
            KEY attr_norm (attr_norm(64))
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'shaghafi_db_version', '2.0.0' );
    }

    /**
     * Ensure table exists and has initial bundled feed if empty
     */
    public static function ensure_initialized() {
        global $wpdb;
        $table = self::table_name();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            self::create_tables();
        }

        // v3.0.0 schema migration: add live_price_exact flag to installs created by older versions.
        // 1 = live_price comes from an exact per-variant SKU match, 0 = generic/fallback product price.
        // Wrapped defensively so a schema/permission issue can never white-screen the whole site.
        try {
            $col_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $table . '` LIKE %s', 'live_price_exact' ) );
            if ( empty( $col_exists ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN live_price_exact tinyint(1) NOT NULL DEFAULT 0 AFTER live_price" );
            }
        } catch ( Throwable $e ) {
            if ( function_exists( 'error_log' ) ) {
                error_log( '[Shaghafi DB migration] ' . $e->getMessage() );
            }
        }

        // One-time migration in v2.3.0: wipe any legacy wholesale B2B prices scraped from shaghafi.de
        // so only retail prices from akzentshop.de / meinakzent.de / raptor-watches.com are ever used.
        if ( ! get_option( 'shaghafi_price_source_migrated_v23' ) ) {
            $wpdb->query( "UPDATE {$table} SET live_price = NULL" );
            update_option( 'shaghafi_price_source_migrated_v23', 1 );
        }
        // Self-healing cleanup in v2.4.0: remove any phantom rows in wp_shaghafi_feed_items where
        // variant_id == product_id on a variable product that has real child variants (variant_id != product_id).
        if ( ! get_option( 'shaghafi_phantom_cleanup_v24' ) ) {
            $wpdb->query(
                "DELETE t1 FROM {$table} t1
                 INNER JOIN {$table} t2 ON t1.product_id = t2.product_id
                 WHERE t1.variant_id = t1.product_id AND t2.variant_id != t2.product_id"
            );
            delete_transient( 'shaghafi_catalog_scan_cache_v21' );
            delete_transient( 'shaghafi_obsolete_scan_cache_v21' );
            update_option( 'shaghafi_phantom_cleanup_v24', 1 );
        }
        // One-time non-destructive heal in v2.6.0: synchronize WCBoost Variation Swatches images
        // and natural ascending attribute order (e.g. 4, 5, 7, 8, 9) on all variable products.
        if ( ! get_option( 'shaghafi_swatches_order_healed_v26' ) && class_exists( 'Shaghafi_Repairer' ) ) {
            $parent_ids = $wpdb->get_col(
                "SELECT DISTINCT p.post_parent
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = 'product_variation'
                   AND p.post_status IN ('publish', 'private')
                   AND p.post_parent > 0"
            );
            if ( ! empty( $parent_ids ) ) {
                foreach ( $parent_ids as $pid ) {
                    Shaghafi_Repairer::sync_clean_parent_attributes( (int) $pid );
                }
            }
            delete_transient( 'shaghafi_catalog_scan_cache_v21' );
            update_option( 'shaghafi_swatches_order_healed_v26', 1 );
        }
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( 0 === $count ) {
            $upload_dir  = wp_upload_dir();
            $custom_feed = trailingslashit( $upload_dir['basedir'] ) . 'shaghafi-sync/likadeo.csv';
            $bundled     = SHAGHAFI_SYNC_PATH . 'data/likadeo.csv';
            if ( file_exists( $custom_feed ) ) {
                self::import_csv_file( $custom_feed, 'local_upload' );
            } elseif ( file_exists( $bundled ) ) {
                self::import_csv_file( $bundled, 'bundled_reference' );
            }
        }

        // One-time non-destructive heal in v2.7.0:
        // 1) Re-sync wp_shaghafi_feed_items with the latest CSV (purging discontinued variants like RA10243-005)
        // 2) Clean size variation thumbnails (so size products use parent main image without false warnings)
        // 3) Permanently delete disabled (private/draft) variations that are no longer in the Shaghafi feed
        if ( ! get_option( 'shaghafi_healed_v27' ) ) {
            $upload_dir  = wp_upload_dir();
            $custom_feed = trailingslashit( $upload_dir['basedir'] ) . 'shaghafi-sync/likadeo.csv';
            $bundled     = SHAGHAFI_SYNC_PATH . 'data/likadeo.csv';
            if ( file_exists( $custom_feed ) && file_exists( $bundled ) ) {
                $use_file   = ( filemtime( $custom_feed ) >= filemtime( $bundled ) ) ? $custom_feed : $bundled;
                $use_source = ( $use_file === $custom_feed ) ? 'remote_sync' : 'bundled_reference';
                self::import_csv_file( $use_file, $use_source );
            } elseif ( file_exists( $bundled ) ) {
                self::import_csv_file( $bundled, 'bundled_reference' );
            } elseif ( file_exists( $custom_feed ) ) {
                self::import_csv_file( $custom_feed, 'remote_sync' );
            }

            if ( class_exists( 'Shaghafi_Repairer' ) ) {
                $parent_ids = $wpdb->get_col(
                    "SELECT DISTINCT p.post_parent
                     FROM {$wpdb->posts} p
                     WHERE p.post_type = 'product_variation'
                       AND p.post_parent > 0"
                );
                if ( ! empty( $parent_ids ) ) {
                    foreach ( $parent_ids as $pid ) {
                        Shaghafi_Repairer::sync_clean_parent_attributes( (int) $pid );
                    }
                }
            }
            delete_transient( 'shaghafi_catalog_scan_cache_v21' );
            delete_transient( 'shaghafi_obsolete_scan_cache_v21' );
            update_option( 'shaghafi_healed_v27', 1 );
        }

        // One-time non-destructive heal in v2.9.3:
        // 1) Remove WoodMart 'image' / 'not_dropdown' term meta on pa_* terms storing bare numeric attachment IDs
        //    (which caused WoodMart to render a broken duplicate .wd-swatches-product <img src="45737"> bar above WCBoost Swatches, e.g. RA20362-001)
        // 2) Normalize parent _product_attributes keys/names (e.g. 'Variante:' -> 'variante') and auto-heal linked variations
        //    with empty or case-mismatched attribute_* meta (e.g. post=35928 / 5050833)
        if ( ! get_option( 'shaghafi_healed_v293' ) ) {
            if ( ! empty( $wpdb->termmeta ) ) {
                $wpdb->query(
                    "DELETE tm FROM {$wpdb->termmeta} tm
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
                     WHERE tt.taxonomy LIKE 'pa\_%'
                       AND tm.meta_key IN ('image', 'not_dropdown')"
                );
            }
            if ( class_exists( 'Shaghafi_Repairer' ) ) {
                $parent_ids = $wpdb->get_col(
                    "SELECT DISTINCT p.post_parent
                     FROM {$wpdb->posts} p
                     WHERE p.post_type = 'product_variation'
                       AND p.post_parent > 0"
                );
                if ( ! empty( $parent_ids ) ) {
                    foreach ( $parent_ids as $pid ) {
                        $pid = (int) $pid;
                        Shaghafi_Repairer::sync_clean_parent_attributes( $pid );
                        if ( class_exists( 'WC_Product_Variable' ) ) {
                            WC_Product_Variable::sync( $pid );
                        }
                        if ( function_exists( 'wc_delete_product_transients' ) ) {
                            wc_delete_product_transients( $pid );
                        }
                    }
                }
            }
            delete_transient( 'shaghafi_catalog_scan_cache_v21' );
            delete_transient( 'shaghafi_obsolete_scan_cache_v21' );
            update_option( 'shaghafi_healed_v293', 1 );
        }

        // Continuously auto-detect if a newer likadeo.csv file has been downloaded or placed on disk
        self::detect_and_import_latest_local_csv();
    }

    /**
     * Auto-detect if a newly downloaded likadeo.csv exists on disk and import it automatically.
     * Never let the bundled plugin CSV (whose filemtime updates whenever a plugin .zip is installed)
     * overwrite an already-populated feed table or undo live-scraped variant purges!
     */
    public static function detect_and_import_latest_local_csv() {
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $candidates = array(
            trailingslashit( $upload_dir['basedir'] ) . 'shaghafi-sync/likadeo.csv' => 'remote_sync',
            trailingslashit( $upload_dir['basedir'] ) . 'likadeo.csv'               => 'manual_upload',
            ABSPATH . 'likadeo.csv'                                                 => 'manual_upload',
        );

        $table = self::table_name();
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count <= 0 ) {
            $candidates[ SHAGHAFI_SYNC_PATH . 'data/likadeo.csv' ] = 'bundled_reference';
        }

        $newest_file   = '';
        $newest_source = '';
        $newest_mtime  = 0;

        foreach ( $candidates as $file_path => $source ) {
            if ( file_exists( $file_path ) && is_readable( $file_path ) && filesize( $file_path ) > 1024 ) {
                $mtime = (int) @filemtime( $file_path );
                if ( $mtime > $newest_mtime ) {
                    $newest_mtime  = $mtime;
                    $newest_file   = $file_path;
                    $newest_source = $source;
                }
            }
        }

        if ( '' === $newest_file ) {
            return false;
        }

        $sig      = $newest_file . '|' . $newest_mtime . '|' . (int) @filesize( $newest_file );
        $last_sig = (string) get_option( 'shaghafi_last_local_csv_sig', '' );

        if ( $sig !== $last_sig ) {
            $res = self::import_csv_file( $newest_file, $newest_source );
            if ( ! is_wp_error( $res ) ) {
                update_option( 'shaghafi_last_local_csv_sig', $sig );
                delete_transient( 'shaghafi_catalog_scan_cache_v21' );
                delete_transient( 'shaghafi_obsolete_scan_cache_v21' );
                return $res;
            }
        }

        return false;
    }

    /**
     * Normalize attribute / variantenauspraegung string for resilient matching
     */
    public static function normalize_attr( $val ) {
        if ( null === $val ) {
            return '';
        }
        $val = (string) $val;
        $val = rawurldecode( $val );
        $val = html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $val = mb_strtolower( trim( $val ), 'UTF-8' );
        // Unify decimal comma to dot
        $val = str_replace( ',', '.', $val );
        // Collapse spaces and dashes
        $val = preg_replace( '/[\s\-_]+/u', '', $val );
        // If purely numeric, strip leading zeros (e.g. "006" -> "6")
        if ( preg_match( '/^\d+$/', $val ) ) {
            $val = ltrim( $val, '0' );
            if ( '' === $val ) {
                $val = '0';
            }
        }
        return $val;
    }

    /**
     * Extract numeric or unitless core of attribute (e.g., "45 cm" -> "45", "29,5 mm" -> "29.5")
     */
    public static function normalize_attr_unitless( $val ) {
        $norm = self::normalize_attr( $val );
        $stripped = preg_replace( '/(mm|cm|m|stueck|stück|stk|gr|g)$/u', '', $norm );
        return trim( $stripped );
    }

    /**
     * Extract parent base SKU from artikelnummer
     */
    public static function extract_base_sku( $sku, $product_id = '', $variant_id = '' ) {
        $sku = trim( (string) $sku );
        if ( '' === $sku ) {
            return '';
        }
        // Even for simple products, store prefix before '-' if dashed, or full SKU
        if ( strpos( $sku, '-' ) !== false ) {
            $parts = explode( '-', $sku, 2 );
            return trim( $parts[0] );
        }
        if ( strpos( $sku, '.' ) !== false ) {
            $parts = explode( '.', $sku, 2 );
            return trim( $parts[0] );
        }
        return $sku;
    }

    /**
     * Extract normalized SKU suffix after base_sku (e.g. "3000060-006" -> "6", "7300022-100" -> "100")
     */
    public static function extract_sku_suffix_norm( $sku, $base_sku = '' ) {
        $sku = trim( (string) $sku );
        if ( '' === $base_sku ) {
            $base_sku = self::extract_base_sku( $sku );
        }
        $suffix = '';
        if ( '' !== $base_sku && 0 === strpos( $sku, $base_sku ) ) {
            $suffix = ltrim( substr( $sku, strlen( $base_sku ) ), '-._ ' );
        } elseif ( strpos( $sku, '-' ) !== false ) {
            $parts  = explode( '-', $sku );
            $suffix = end( $parts );
        }
        $suffix = strtolower( trim( $suffix ) );
        if ( preg_match( '/^\d+$/', $suffix ) ) {
            $suffix = ltrim( $suffix, '0' );
            if ( '' === $suffix ) {
                $suffix = '0';
            }
        }
        return $suffix;
    }

    /**
     * High-speed import of likadeo.csv into custom DB table
     */
    public static function import_csv_file( $filepath, $source_label = 'manual' ) {
        global $wpdb;
        if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
            return new WP_Error( 'file_missing', 'فایل CSV مرجع یافت نشد یا قابل خواندن نیست.' );
        }

        $table = self::table_name();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            self::create_tables();
        }

        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'file_open_error', 'خطا در باز کردن فایل CSV.' );
        }

        // Detect delimiter from first line
        $first_line = fgets( $handle );
        if ( false === $first_line ) {
            fclose( $handle );
            return new WP_Error( 'empty_csv', 'فایل CSV خالی است.' );
        }
        // Strip UTF-8 BOM if present
        $first_line = preg_replace( '/^\xEF\xBB\xBF/', '', $first_line );
        $delimiter  = ( substr_count( $first_line, ';' ) >= substr_count( $first_line, ',' ) ) ? ';' : ',';

        $header = str_getcsv( trim( $first_line ), $delimiter );
        $col_map = array(
            'product_id'           => 0,
            'variant_id'           => 1,
            'artikelnummer'        => 2,
            'variantenauspraegung' => 3,
            'bestand'              => 4,
            'status'               => 5,
            'last_updated'         => 6,
        );
        foreach ( $header as $idx => $col_name ) {
            $clean_col = strtolower( trim( $col_name ) );
            if ( isset( $col_map[ $clean_col ] ) ) {
                $col_map[ $clean_col ] = $idx;
            }
        }

        $all_batches  = array();
        $batch        = array();
        $batch_size   = 400;
        $total_rows   = 0;

        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            if ( empty( $row ) || count( $row ) < 3 ) {
                continue;
            }
            $pid = isset( $row[ $col_map['product_id'] ] ) ? strtolower( trim( $row[ $col_map['product_id'] ] ) ) : '';
            $vid = isset( $row[ $col_map['variant_id'] ] ) ? strtolower( trim( $row[ $col_map['variant_id'] ] ) ) : '';
            $sku = isset( $row[ $col_map['artikelnummer'] ] ) ? trim( $row[ $col_map['artikelnummer'] ] ) : '';

            if ( '' === $pid || '' === $vid || '' === $sku ) {
                continue;
            }
            if ( 'product_id' === $pid ) {
                continue;
            }
            // Validate 32-char Shopware hex UUIDs so corrupted lines are never imported
            if ( ! preg_match( '/^[a-f0-9]{32}$/', $pid ) || ! preg_match( '/^[a-f0-9]{32}$/', $vid ) ) {
                continue;
            }

            $attr         = isset( $row[ $col_map['variantenauspraegung'] ] ) ? trim( $row[ $col_map['variantenauspraegung'] ] ) : '';
            $bestand      = isset( $row[ $col_map['bestand'] ] ) ? (int) trim( $row[ $col_map['bestand'] ] ) : 0;
            $status       = isset( $row[ $col_map['status'] ] ) && '' !== trim( $row[ $col_map['status'] ] ) ? trim( $row[ $col_map['status'] ] ) : 'active';
            $last_updated = isset( $row[ $col_map['last_updated'] ] ) ? trim( $row[ $col_map['last_updated'] ] ) : '';

            $base_sku        = self::extract_base_sku( $sku, $pid, $vid );
            $sku_suffix_norm = self::extract_sku_suffix_norm( $sku, $base_sku );
            $attr_norm       = self::normalize_attr( $attr );

            $batch[] = $wpdb->prepare(
                '(%s, %s, %s, %s, %s, %s, %s, %d, %s, %s)',
                $pid,
                $vid,
                $sku,
                $base_sku,
                $sku_suffix_norm,
                $attr,
                $attr_norm,
                $bestand,
                $status,
                $last_updated
            );
            $total_rows++;

            if ( count( $batch ) >= $batch_size ) {
                $all_batches[] = $batch;
                $batch         = array();
            }
        }

        if ( ! empty( $batch ) ) {
            $all_batches[] = $batch;
        }
        fclose( $handle );

        if ( $total_rows < 100 ) {
            return new WP_Error(
                'invalid_csv_content',
                sprintf( 'فایل CSV دانلود/آپلود شده معتبر نیست یا کامل دانلود نشده است (تعداد ردیف‌های معتبر: %d). دیتابیس قبلی حفظ شد.', $total_rows )
            );
        }

        // Authoritative Sync: Mark existing rows before upserting so any variant/product removed from Shaghafi's CSV is purged!
        $wpdb->query( "UPDATE {$table} SET status = '__stale_import__'" );

        foreach ( $all_batches as $b ) {
            self::flush_import_batch( $b );
        }

        $removed_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = '__stale_import__'" );
        if ( $removed_rows > 0 ) {
            $wpdb->query( "DELETE FROM {$table} WHERE status = '__stale_import__'" );
        }

        // Also remove any phantom rows where variant_id == product_id on a multi-variant product
        $wpdb->query(
            "DELETE t1 FROM {$table} t1
             INNER JOIN {$table} t2 ON t1.product_id = t2.product_id
             WHERE t1.variant_id = t1.product_id AND t2.variant_id != t2.product_id"
        );

        // Whenever a fresh remote or uploaded likadeo.csv is imported, purge cached live/retail transients
        // so subsequent live checks fetch fresh real-time prices from akzentshop.de, meinakzent.de, and raptor-watches.com
        if ( in_array( $source_label, array( 'live_remote_feed', 'remote_sync', 'manual_upload' ), true ) ) {
            $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_shaghafi_live_%'
                    OR option_name LIKE '_transient_timeout_shaghafi_live_%'
                    OR option_name LIKE '_transient_shaghafi_retail_%'
                    OR option_name LIKE '_transient_timeout_shaghafi_retail_%'"
            );
        }

        // Meta info about last feed import
        update_option(
            'shaghafi_feed_meta',
            array(
                'total_rows'   => $total_rows,
                'removed_rows' => $removed_rows,
                'imported_at'  => current_time( 'mysql' ),
                'source'       => $source_label,
                'file_path'    => $filepath,
                'file_size'    => file_exists( $filepath ) ? filesize( $filepath ) : 0,
            )
        );

        return array(
            'total_rows'   => $total_rows,
            'removed_rows' => $removed_rows,
            'imported_at'  => current_time( 'mysql' ),
            'source'       => $source_label,
        );
    }

    /**
     * Remove a discontinued variant_id from wp_shaghafi_feed_items when Shaghafi.de live check confirms it no longer exists
     */
    public static function delete_variant_from_feed( $variant_id ) {
        global $wpdb;
        $vid = strtolower( trim( (string) $variant_id ) );
        if ( '' === $vid ) {
            return 0;
        }
        $table = self::table_name();
        return (int) $wpdb->delete( $table, array( 'variant_id' => $vid ), array( '%s' ) );
    }

    /**
     * When live scraping a variable product on shaghafi.de returns its active variant matrix,
     * purge any stale variant rows in wp_shaghafi_feed_items for that product_id that are no longer on shaghafi.de
     */
    public static function sync_product_variants_with_live_matrix( $product_id, $live_variant_ids ) {
        global $wpdb;
        $pid = strtolower( trim( (string) $product_id ) );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $pid ) || empty( $live_variant_ids ) || ! is_array( $live_variant_ids ) ) {
            return 0;
        }

        $clean_vids = array();
        foreach ( $live_variant_ids as $v ) {
            $v = strtolower( trim( (string) $v ) );
            if ( preg_match( '/^[a-f0-9]{32}$/', $v ) ) {
                $clean_vids[] = $v;
            }
        }
        $clean_vids = array_values( array_unique( $clean_vids ) );
        if ( empty( $clean_vids ) ) {
            return 0;
        }

        $table        = self::table_name();
        $placeholders = implode( ', ', array_fill( 0, count( $clean_vids ), '%s' ) );
        $params       = array_merge( array( $pid ), $clean_vids );

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE product_id = %s AND variant_id NOT IN ({$placeholders})",
                $params
            )
        );
    }

    /**
     * Execute bulk upsert batch
     */
    private static function flush_import_batch( $batch ) {
        global $wpdb;
        if ( empty( $batch ) ) {
            return;
        }
        $table  = self::table_name();
        $values = implode( ', ', $batch );
        $sql    = "INSERT INTO {$table}
            (product_id, variant_id, artikelnummer, base_sku, sku_suffix_norm, variantenauspraegung, attr_norm, bestand, status, last_updated)
            VALUES {$values}
            ON DUPLICATE KEY UPDATE
                product_id = VALUES(product_id),
                artikelnummer = VALUES(artikelnummer),
                base_sku = VALUES(base_sku),
                sku_suffix_norm = VALUES(sku_suffix_norm),
                variantenauspraegung = VALUES(variantenauspraegung),
                attr_norm = VALUES(attr_norm),
                bestand = VALUES(bestand),
                status = VALUES(status),
                live_checked_at = IF(VALUES(last_updated) <> last_updated, NULL, live_checked_at),
                last_updated = VALUES(last_updated)";
        $wpdb->query( $sql );
    }

    /**
     * Get feed statistics
     */
    public static function get_feed_stats() {
        global $wpdb;
        self::ensure_initialized();
        $table = self::table_name();

        $total_rows      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $unique_products = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$table}" );
        $simple_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE product_id = variant_id" );
        $variant_count   = $total_rows - $simple_count;
        $in_stock_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE bestand > 0" );
        $live_checked    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE live_checked_at IS NOT NULL" );
        $feed_meta       = get_option( 'shaghafi_feed_meta', array() );

        return array(
            'total_rows'      => $total_rows,
            'unique_products' => $unique_products,
            'simple_count'    => $simple_count,
            'variant_count'   => $variant_count,
            'in_stock_count'  => $in_stock_count,
            'live_checked'    => $live_checked,
            'imported_at'     => isset( $feed_meta['imported_at'] ) ? $feed_meta['imported_at'] : '—',
            'source'          => isset( $feed_meta['source'] ) ? $feed_meta['source'] : 'bundled_reference',
        );
    }

    /**
     * Find single row by variant_id
     */
    public static function get_by_variant_id( $variant_id ) {
        global $wpdb;
        $variant_id = trim( (string) $variant_id );
        if ( '' === $variant_id ) {
            return null;
        }
        $table = self::table_name();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE variant_id = %s LIMIT 1", $variant_id ),
            ARRAY_A
        );
    }

    /**
     * Find single row by exact artikelnummer (SKU)
     */
    public static function get_by_sku( $sku ) {
        global $wpdb;
        $sku = trim( (string) $sku );
        if ( '' === $sku ) {
            return null;
        }
        $table = self::table_name();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE artikelnummer = %s ORDER BY (variant_id != product_id) DESC LIMIT 1", $sku ),
            ARRAY_A
        );
    }

    /**
     * Alias for get_by_sku()
     */
    public static function find_by_sku( $sku ) {
        return self::get_by_sku( $sku );
    }

    /**
     * Find all variants belonging to a Shaghafi product_id
     */
    public static function get_variants_by_product_id( $product_id ) {
        global $wpdb;
        $product_id = trim( (string) $product_id );
        if ( '' === $product_id ) {
            return array();
        }
        $table = self::table_name();
        $rows  = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %s ORDER BY artikelnummer ASC", $product_id ),
            ARRAY_A
        );
        if ( count( $rows ) > 1 ) {
            $filtered = array();
            foreach ( $rows as $r ) {
                if ( strcasecmp( $r['variant_id'], $r['product_id'] ) === 0 ) {
                    $wpdb->delete( $table, array( 'id' => (int) $r['id'] ), array( '%d' ) );
                    continue;
                }
                $filtered[] = $r;
            }
            if ( ! empty( $filtered ) ) {
                return $filtered;
            }
        }
        return $rows;
    }

    /**
     * Find all variants matching a base_sku (e.g., "3000060")
     */
    public static function get_variants_by_base_sku( $base_sku ) {
        global $wpdb;
        $base_sku = trim( (string) $base_sku );
        if ( '' === $base_sku ) {
            return array();
        }
        $table = self::table_name();
        $rows  = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE base_sku = %s OR artikelnummer = %s ORDER BY (variant_id != product_id) DESC, artikelnummer ASC", $base_sku, $base_sku ),
            ARRAY_A
        );
        if ( count( $rows ) > 1 ) {
            $filtered = array();
            foreach ( $rows as $r ) {
                if ( strcasecmp( $r['variant_id'], $r['product_id'] ) === 0 ) {
                    $wpdb->delete( $table, array( 'id' => (int) $r['id'] ), array( '%d' ) );
                    continue;
                }
                $filtered[] = $r;
            }
            if ( ! empty( $filtered ) ) {
                return $filtered;
            }
        }
        return $rows;
    }

    /**
     * Fuzzy match a broken SKU (like "3000060-6" -> "3000060-006")
     */
    public static function find_by_fuzzy_sku( $broken_sku ) {
        global $wpdb;
        $broken_sku = trim( (string) $broken_sku );
        if ( '' === $broken_sku ) {
            return null;
        }
        // First try exact
        $exact = self::get_by_sku( $broken_sku );
        if ( $exact ) {
            return $exact;
        }
        $base   = self::extract_base_sku( $broken_sku );
        $suffix = self::extract_sku_suffix_norm( $broken_sku, $base );
        if ( '' !== $base && '' !== $suffix ) {
            $table = self::table_name();
            $match = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE base_sku = %s AND sku_suffix_norm = %s LIMIT 1",
                    $base,
                    $suffix
                ),
                ARRAY_A
            );
            if ( $match ) {
                return $match;
            }
        }
        return null;
    }

    /**
     * Update live scraped data for a variant or upsert if discovered live
     */
    public static function save_live_variant_data( $variant_id, $data ) {
        global $wpdb;
        $variant_id = trim( (string) $variant_id );
        if ( '' === $variant_id ) {
            return false;
        }
        $table   = self::table_name();
        $updates = array(
            'live_checked_at' => current_time( 'mysql' ),
        );
        $formats = array( '%s' );

        if ( isset( $data['price'] ) && '' !== (string) $data['price'] && (float) $data['price'] > 0 ) {
            $updates['live_price'] = (string) $data['price'];
            $formats[]             = '%s';
            // Record whether this price is an exact per-variant SKU match (1) or a generic fallback (0).
            $updates['live_price_exact'] = ! empty( $data['price_exact'] ) ? 1 : 0;
            $formats[]                   = '%d';
        } elseif ( ! empty( $data['clear_price'] ) ) {
            $updates['live_price']       = null;
            $updates['live_price_exact'] = 0;
            $formats[]                   = '%s';
            $formats[]                   = '%d';
        }
        if ( isset( $data['stock'] ) && null !== $data['stock'] && '' !== (string) $data['stock'] ) {
            $updates['live_stock'] = (int) $data['stock'];
            $formats[]             = '%d';
        }
        if ( ! empty( $data['image'] ) ) {
            $updates['live_image_url'] = (string) $data['image'];
            $formats[]                 = '%s';
        }
        if ( ! empty( $data['gallery'] ) && is_array( $data['gallery'] ) ) {
            $updates['live_gallery_json'] = wp_json_encode( $data['gallery'] );
            $formats[]                    = '%s';
        }
        if ( ! empty( $data['name'] ) ) {
            $updates['live_title'] = (string) $data['name'];
            $formats[]             = '%s';
        }
        if ( ! empty( $data['attr_name'] ) ) {
            $updates['live_attr_name'] = (string) $data['attr_name'];
            $formats[]                 = '%s';
        }
        if ( ! empty( $data['product_url'] ) ) {
            $updates['live_product_url'] = (string) $data['product_url'];
            $formats[]                   = '%s';
        }

        // Only update existing feed rows (never insert phantom rows from live scraping)
        $existing = self::get_by_variant_id( $variant_id );
        if ( $existing ) {
            return $wpdb->update(
                $table,
                $updates,
                array( 'variant_id' => $variant_id ),
                $formats,
                array( '%s' )
            );
        }
        return false;
    }

    /**
     * Search feed items by SKU, product_id, variant_id, or attribute
     */
    public static function search_feed( $query, $limit = 100 ) {
        global $wpdb;
        self::ensure_initialized();
        $table = self::table_name();
        $query = trim( (string) $query );
        if ( '' === $query ) {
            return $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$table} LIMIT %d", $limit ),
                ARRAY_A
            );
        }
        $like = '%' . $wpdb->esc_like( $query ) . '%';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE artikelnummer LIKE %s
                    OR base_sku = %s
                    OR product_id = %s
                    OR variant_id = %s
                    OR variantenauspraegung LIKE %s
                 ORDER BY base_sku ASC, artikelnummer ASC
                 LIMIT %d",
                $like,
                $query,
                $query,
                $query,
                $like,
                $limit
            ),
            ARRAY_A
        );
    }
}
