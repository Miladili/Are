<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Feed Manager: Live Store-API Export Fetch, Manual Upload & Full Auto-Pilot Scheduled Cron Sync
 */
class Shaghafi_Feed {

    const DEFAULT_FEED_URL = 'https://shaghafi.de/store-api/product-export/SWPEULJTTLNVAXDNTVYWVHLVNG/likadeo.csv';

    /**
     * Get configured live feed URL
     */
    public static function get_feed_url() {
        $settings = get_option( 'shaghafi_sync_settings', array() );
        if ( ! empty( $settings['feed_url'] ) ) {
            return trim( $settings['feed_url'] );
        }
        return self::DEFAULT_FEED_URL;
    }

    /**
     * Get upload directory for storing downloaded/uploaded likadeo.csv
     */
    public static function get_storage_dir() {
        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit( $upload_dir['basedir'] ) . 'shaghafi-sync';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    /**
     * Fetch latest likadeo.csv directly from Shaghafi Store-API URL and index into DB
     */
    public static function fetch_and_import_remote_feed( $custom_url = '' ) {
        $url = ! empty( $custom_url ) ? trim( $custom_url ) : self::get_feed_url();
        if ( empty( $url ) ) {
            return new WP_Error( 'empty_url', 'لینک فید روزانه شقفی تنظیم نشده است.' );
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 60,
                'redirection' => 5,
                'sslverify'   => false,
                'headers'     => array(
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    'Accept'     => 'text/csv,text/plain,*/*',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'http_error', 'خطا در اتصال به سرور شقفی: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'http_status', sprintf( 'سرور شقفی کد وضعیت %d را برگرداند.', $code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) || strpos( $body, 'product_id' ) === false ) {
            return new WP_Error( 'invalid_csv_body', 'محتوای دریافتی از لینک شقفی یک فایل CSV معتبر (شامل ستون product_id) نیست.' );
        }

        $dir      = self::get_storage_dir();
        $filepath = trailingslashit( $dir ) . 'likadeo.csv';

        $written = file_put_contents( $filepath, $body );
        if ( false === $written ) {
            return new WP_Error( 'write_error', 'امکان ذخیره فایل likadeo.csv در پوشه آپلود وردپرس وجود ندارد.' );
        }

        return Shaghafi_DB::import_csv_file( $filepath, 'live_remote_feed' );
    }

    /**
     * Handle uploaded CSV file from Admin UI
     */
    public static function handle_uploaded_csv( $file_array ) {
        if ( empty( $file_array['tmp_name'] ) || ! file_exists( $file_array['tmp_name'] ) ) {
            return new WP_Error( 'upload_missing', 'فایلی انتخاب نشده یا آپلود با خطا مواجه شده است.' );
        }

        $dir      = self::get_storage_dir();
        $filepath = trailingslashit( $dir ) . 'likadeo.csv';

        if ( ! @move_uploaded_file( $file_array['tmp_name'], $filepath ) ) {
            if ( ! @copy( $file_array['tmp_name'], $filepath ) ) {
                return new WP_Error( 'move_failed', 'خطا در انتقال فایل آپلود شده به پوشه shaghafi-sync.' );
            }
        }

        return Shaghafi_DB::import_csv_file( $filepath, 'manual_upload' );
    }

    /**
     * Re-import bundled likadeo.csv reference file
     */
    public static function import_bundled_reference() {
        $bundled = SHAGHAFI_SYNC_PATH . 'data/likadeo.csv';
        if ( ! file_exists( $bundled ) ) {
            return new WP_Error( 'bundled_missing', 'فایل مرجع داخلی در پوشه data/likadeo.csv یافت نشد.' );
        }
        return Shaghafi_DB::import_csv_file( $bundled, 'bundled_reference' );
    }

    /**
     * Scheduled WP-Cron / Auto-Pilot execution:
     * 1. Downloads latest likadeo.csv from Shaghafi Store-API URL
     * 2. Auto-discovers & links any newly imported products (manual or importer plugin)
     * 3. Syncs stock for all linked variations
     * 4. Optionally auto-repairs SKUs/IDs, disables removed variations, or creates missing variations based on settings
     */
    /**
     * Scheduled WP-Cron / Auto-Pilot execution:
     * Executes according to the user's saved selective checkboxes:
     * 1. Downloads & validates latest likadeo.csv from Shaghafi Store-API URL (and purges discontinued variants)
     * 2. Syncs stock, SKUs/Meta, Attributes/Swatches, 700x800 Images, and Retail Prices according to checkboxes
     * 3. Permanently deletes disabled/missing variations and creates missing variations according to checkboxes
     */
    public static function run_daily_cron_sync() {
        @set_time_limit( 0 );
        @ignore_user_abort( true );

        $settings = get_option( 'shaghafi_sync_settings', array() );

        $do_download_feed = ! isset( $settings['sync_download_feed'] ) || ! empty( $settings['sync_download_feed'] );
        $do_stock         = ! isset( $settings['sync_stock'] ) ? ( isset( $settings['cron_auto_stock'] ) ? (bool) $settings['cron_auto_stock'] : true ) : ! empty( $settings['sync_stock'] );
        $do_sku_meta      = ! isset( $settings['sync_sku_meta'] ) ? ! empty( $settings['cron_auto_repair'] ) : ! empty( $settings['sync_sku_meta'] );
        $do_attrs         = ! isset( $settings['sync_attributes_swatches'] ) ? ! empty( $settings['cron_auto_repair'] ) : ! empty( $settings['sync_attributes_swatches'] );
        $do_images        = ! isset( $settings['sync_images'] ) ? ! empty( $settings['cron_auto_repair'] ) : ! empty( $settings['sync_images'] );
        $do_prices        = ! isset( $settings['sync_retail_prices'] ) ? ! empty( $settings['cron_auto_repair'] ) : ! empty( $settings['sync_retail_prices'] );
        $do_delete_vars   = ! isset( $settings['sync_delete_obsolete_variations'] ) ? ! empty( $settings['cron_auto_disable'] ) : ! empty( $settings['sync_delete_obsolete_variations'] );
        $do_create_vars   = ! isset( $settings['sync_create_missing_variations'] ) ? ! empty( $settings['cron_auto_create'] ) : ! empty( $settings['sync_create_missing_variations'] );
        $do_obsolete_prod = ! empty( $settings['sync_remove_obsolete_products'] );

        $feed_rows    = 0;
        $removed_feed = 0;
        if ( $do_download_feed ) {
            $res = self::fetch_and_import_remote_feed();
            if ( is_wp_error( $res ) ) {
                // Also check if a newer local CSV exists on disk
                $local_res = Shaghafi_DB::detect_and_import_latest_local_csv();
                if ( is_array( $local_res ) ) {
                    $res = $local_res;
                } else {
                    update_option(
                        'shaghafi_last_cron_log',
                        array(
                            'status'  => 'error',
                            'time'    => current_time( 'mysql' ),
                            'message' => $res->get_error_message(),
                        )
                    );
                    return $res;
                }
            }
            $feed_rows    = isset( $res['total_rows'] ) ? (int) $res['total_rows'] : 0;
            $removed_feed = isset( $res['removed_rows'] ) ? (int) $res['removed_rows'] : 0;
        } else {
            Shaghafi_DB::detect_and_import_latest_local_csv();
            $fstats    = Shaghafi_DB::get_feed_stats();
            $feed_rows = isset( $fstats['total_rows'] ) ? (int) $fstats['total_rows'] : 0;
        }

        $stock_synced   = 0;
        $repaired_count = 0;
        $disabled_count = 0;
        $created_count  = 0;
        $obsolete_count = 0;

        if ( $do_stock && class_exists( 'Shaghafi_Repairer' ) ) {
            $stock_synced = Shaghafi_Repairer::sync_all_linked_stocks_from_feed();
        }

        $do_any_repair = ( $do_sku_meta || $do_attrs || $do_stock || $do_images || $do_prices );
        $remaining_ids = array();

        if ( ( $do_any_repair || $do_delete_vars || $do_create_vars ) && class_exists( 'Shaghafi_Scanner' ) && class_exists( 'Shaghafi_Repairer' ) ) {
            Shaghafi_Scanner::clear_scan_caches();
            $scan = Shaghafi_Scanner::scan_catalog(
                array(
                    'action_filter' => 'ISSUES_ONLY',
                    'page'          => 1,
                    'per_page'      => 10000,
                    'force_rescan'  => true,
                )
            );
            $repair_opts = array(
                'sync_sku_meta'                   => $do_sku_meta ? 1 : 0,
                'sync_attributes_swatches'        => $do_attrs ? 1 : 0,
                'sync_stock'                      => $do_stock ? 1 : 0,
                'sync_images'                     => $do_images ? 1 : 0,
                'sync_retail_prices'              => $do_prices ? 1 : 0,
                'sync_create_missing_variations'  => $do_create_vars ? 1 : 0,
                'sync_delete_obsolete_variations' => $do_delete_vars ? 1 : 0,
                'ensure_price'                    => $do_prices,
            );

            $parent_ids = array();
            if ( ! empty( $scan['rows'] ) ) {
                foreach ( $scan['rows'] as $r ) {
                    $pid = isset( $r['parent_id'] ) ? (int) $r['parent_id'] : 0;
                    if ( $pid <= 0 || isset( $parent_ids[ $pid ] ) ) {
                        continue;
                    }
                    if ( 'MANUAL_REVIEW' === $r['action'] && '—' === $r['feed_product_id'] ) {
                        continue;
                    }
                    $parent_ids[ $pid ] = $pid;
                }
            }

            $start_time = time();
            $all_pids   = array_values( $parent_ids );
            foreach ( $all_pids as $idx => $pid ) {
                $full = Shaghafi_Repairer::sync_and_repair_entire_product( $pid, $repair_opts );
                if ( ! is_wp_error( $full ) ) {
                    $repaired_count += (int) $full['repaired'];
                    $created_count  += (int) $full['created'];
                    $disabled_count += (int) $full['deleted'];
                }
                // Safe time budget per background HTTP worker (45s); schedule continuation batch if more remain
                if ( ( time() - $start_time ) >= 45 && ( $idx + 1 ) < count( $all_pids ) ) {
                    $remaining_ids = array_slice( $all_pids, $idx + 1 );
                    break;
                }
            }
        }

        if ( ! empty( $remaining_ids ) ) {
            update_option( 'shaghafi_bg_pending_parents', $remaining_ids );
            if ( ! wp_next_scheduled( 'shaghafi_bg_batch_sync_event' ) ) {
                wp_schedule_single_event( time() + 20, 'shaghafi_bg_batch_sync_event' );
            }
        } else {
            delete_option( 'shaghafi_bg_pending_parents' );
        }

        if ( $do_obsolete_prod && class_exists( 'Shaghafi_Scanner' ) && class_exists( 'Shaghafi_Repairer' ) ) {
            $obs = Shaghafi_Scanner::scan_obsolete_products(
                array(
                    'type_filter'  => 'REMOVED_FROM_FEED',
                    'page'         => 1,
                    'per_page'     => 500,
                    'force_rescan' => true,
                )
            );
            $obs_list = ! empty( $obs['items'] ) ? $obs['items'] : ( ! empty( $obs['rows'] ) ? $obs['rows'] : array() );
            if ( ! empty( $obs_list ) ) {
                foreach ( $obs_list as $orow ) {
                    $o_vid  = (int) $orow['variation_id'];
                    $o_mode = $o_vid > 0 ? 'delete' : 'trash';
                    $ores   = Shaghafi_Repairer::handle_obsolete_item( (int) $orow['parent_id'], $o_vid, $o_mode );
                    if ( ! is_wp_error( $ores ) ) {
                        $obsolete_count++;
                    }
                }
            }
        }

        Shaghafi_Scanner::clear_scan_caches();

        $log = array(
            'status'         => 'success',
            'time'           => current_time( 'mysql' ),
            'feed_rows'      => $feed_rows,
            'removed_feed'   => $removed_feed,
            'stock_synced'   => $stock_synced,
            'repaired_count' => $repaired_count,
            'disabled_count' => $disabled_count,
            'created_count'  => $created_count,
            'obsolete_count' => $obsolete_count,
            'remaining_bg'   => count( $remaining_ids ),
            'message'        => sprintf(
                'فید مرجع (%d ردیف فعال، %d ردیف منسوخ حذف شد) | سینک موجودی: %d | تعمیر و بررسی زنده: %d | حذف کامل وریشن‌های منسوخ: %d | ساخت وریشن جدید: %d%s%s',
                $feed_rows,
                $removed_feed,
                $stock_synced,
                $repaired_count,
                $disabled_count,
                $created_count,
                $obsolete_count > 0 ? sprintf( ' | حذف محصولات منسوخ: %d', $obsolete_count ) : '',
                ! empty( $remaining_ids ) ? sprintf( ' (ادامه خودکار %d محصول در پس‌زمینه...)', count( $remaining_ids ) ) : ''
            ),
        );
        update_option( 'shaghafi_last_cron_log', $log );
        return $log;
    }

    /**
     * Self-continuing Background Batch Worker: finishes any remaining parent products from run_daily_cron_sync()
     */
    public static function run_bg_batch_sync() {
        @set_time_limit( 0 );
        @ignore_user_abort( true );

        $pending = get_option( 'shaghafi_bg_pending_parents', array() );
        if ( empty( $pending ) || ! is_array( $pending ) ) {
            delete_option( 'shaghafi_bg_pending_parents' );
            return;
        }

        $settings        = get_option( 'shaghafi_sync_settings', array() );
        $do_sku_meta     = ! isset( $settings['sync_sku_meta'] ) || ! empty( $settings['sync_sku_meta'] );
        $do_attrs        = ! isset( $settings['sync_attributes_swatches'] ) || ! empty( $settings['sync_attributes_swatches'] );
        $do_stock        = ! isset( $settings['sync_stock'] ) || ! empty( $settings['sync_stock'] );
        $do_images       = ! isset( $settings['sync_images'] ) || ! empty( $settings['sync_images'] );
        $do_prices       = ! isset( $settings['sync_retail_prices'] ) || ! empty( $settings['sync_retail_prices'] );
        $do_delete_vars  = ! isset( $settings['sync_delete_obsolete_variations'] ) || ! empty( $settings['sync_delete_obsolete_variations'] );
        $do_create_vars  = ! isset( $settings['sync_create_missing_variations'] ) || ! empty( $settings['sync_create_missing_variations'] );

        $repair_opts = array(
            'sync_sku_meta'                   => $do_sku_meta ? 1 : 0,
            'sync_attributes_swatches'        => $do_attrs ? 1 : 0,
            'sync_stock'                      => $do_stock ? 1 : 0,
            'sync_images'                     => $do_images ? 1 : 0,
            'sync_retail_prices'              => $do_prices ? 1 : 0,
            'sync_create_missing_variations'  => $do_create_vars ? 1 : 0,
            'sync_delete_obsolete_variations' => $do_delete_vars ? 1 : 0,
            'ensure_price'                    => $do_prices,
        );

        $start_time    = time();
        $remaining_ids = array();
        $all_pids      = array_values( $pending );

        foreach ( $all_pids as $idx => $pid ) {
            Shaghafi_Repairer::sync_and_repair_entire_product( (int) $pid, $repair_opts );
            if ( ( time() - $start_time ) >= 45 && ( $idx + 1 ) < count( $all_pids ) ) {
                $remaining_ids = array_slice( $all_pids, $idx + 1 );
                break;
            }
        }

        if ( ! empty( $remaining_ids ) ) {
            update_option( 'shaghafi_bg_pending_parents', $remaining_ids );
            if ( ! wp_next_scheduled( 'shaghafi_bg_batch_sync_event' ) ) {
                wp_schedule_single_event( time() + 20, 'shaghafi_bg_batch_sync_event' );
            }
        } else {
            delete_option( 'shaghafi_bg_pending_parents' );
            Shaghafi_Scanner::clear_scan_caches();
        }
    }

    /**
     * Hook called automatically when a product is created/updated manually or by an import plugin (WP All Import, WC Importer, etc.)
     */
    public static function on_product_saved_or_imported( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        $post_type = get_post_type( $post_id );
        if ( 'product_variation' === $post_type ) {
            $post_id   = (int) wp_get_post_parent_id( $post_id );
            $post_type = 'product';
        }
        if ( 'product' !== $post_type || $post_id <= 0 ) {
            return;
        }

        // Clear cached scan so next view reflects the newly imported/edited product immediately
        Shaghafi_Scanner::clear_scan_caches();

        // Auto-link _shaghafi_product_id if matched in likadeo.csv
        $saved_pid = trim( (string) get_post_meta( $post_id, '_shaghafi_product_id', true ) );
        if ( '' === $saved_pid ) {
            $identity = Shaghafi_Scanner::resolve_parent_shaghafi_identity( $post_id );
            if ( $identity && ! empty( $identity['product_id'] ) ) {
                update_post_meta( $post_id, '_shaghafi_product_id', $identity['product_id'] );
            }
        }
    }
}
