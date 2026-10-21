<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AJAX Controller for Shaghafi Sync & Repair Admin UI, Pagination, 700x800 Image Repair & Obsolete Products
 */
class Shaghafi_Ajax {

    public static function init() {
        $actions = array(
            'scan_catalog',
            'scan_obsolete',
            'handle_obsolete_item',
            'fetch_remote_feed',
            'import_bundled_feed',
            'live_check_product',
            'repair_single',
            'create_variation',
            'disable_variation',
            'repair_product_all',
            'create_complete_product',
            'search_feed',
            'sync_all_linked_stocks',
            'export_audit_csv',
            'run_cron_now',
            'save_master_sync_prefs',
            'get_master_sync_queue',
        );

        foreach ( $actions as $act ) {
            add_action( 'wp_ajax_shaghafi_' . $act, array( __CLASS__, 'ajax_' . $act ) );
        }
        add_action( 'wp_ajax_nopriv_shaghafi_cron_webhook', array( __CLASS__, 'ajax_cron_webhook' ) );
        add_action( 'wp_ajax_shaghafi_cron_webhook', array( __CLASS__, 'ajax_cron_webhook' ) );
    }

    private static function verify_access() {
        check_ajax_referer( 'shaghafi_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.' ) );
        }
        // Defense in depth: every panel AJAX action also requires the dedicated Shaghafi panel login.
        if ( class_exists( 'Shaghafi_Auth_Guard' ) && ! Shaghafi_Auth_Guard::is_authenticated() ) {
            wp_send_json_error(
                array(
                    'message' => 'نشست ورود به پنل شقفی معتبر نیست. صفحه را بارگذاری مجدد کرده و وارد شوید.',
                    'relogin' => true,
                ),
                403
            );
        }
    }

    /**
     * Scan WooCommerce catalog with pagination and return rows + summary counts + pagination meta
     */
    public static function ajax_scan_catalog() {
        self::verify_access();

        $search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $filter       = isset( $_POST['action_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['action_filter'] ) ) : 'ALL';
        $page         = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
        $per_page     = isset( $_POST['per_page'] ) ? max( 10, min( 500, (int) $_POST['per_page'] ) ) : 50;
        $force_rescan = ! empty( $_POST['force_rescan'] );

        $result = Shaghafi_Scanner::scan_catalog(
            array(
                'search'        => $search,
                'action_filter' => $filter,
                'page'          => $page,
                'per_page'      => $per_page,
                'force_rescan'  => $force_rescan,
            )
        );

        wp_send_json_success( $result );
    }

    /**
     * Scan Obsolete / Removed from Shaghafi Products & Variations with pagination
     */
    public static function ajax_scan_obsolete() {
        self::verify_access();

        $search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $type_filter  = isset( $_POST['type_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['type_filter'] ) ) : 'ALL';
        $page         = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
        $per_page     = isset( $_POST['per_page'] ) ? max( 10, min( 500, (int) $_POST['per_page'] ) ) : 50;
        $force_rescan = ! empty( $_POST['force_rescan'] );

        $result = Shaghafi_Scanner::scan_obsolete_products(
            array(
                'search'       => $search,
                'type_filter'  => $type_filter,
                'page'         => $page,
                'per_page'     => $per_page,
                'force_rescan' => $force_rescan,
            )
        );

        wp_send_json_success( $result );
    }

    /**
     * Remove / Trash / Draft an Obsolete Product or Variation no longer in Shaghafi
     */
    public static function ajax_handle_obsolete_item() {
        self::verify_access();

        $parent_id    = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
        $variation_id = isset( $_POST['variation_id'] ) ? (int) $_POST['variation_id'] : 0;
        $mode         = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'trash';

        $res = Shaghafi_Repairer::handle_obsolete_item( $parent_id, $variation_id, $mode );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        $msg_map = array(
            'trash'      => 'محصول/وریشن با موفقیت به زباله‌دان منتقل شد و از فروشگاه برداشته شد.',
            'draft_zero' => 'وضعیت محصول/وریشن به پیش‌نویس/خصوصی تغییر یافت و موجودی آن صفر شد.',
            'delete'     => 'محصول/وریشن به صورت دائمی حذف شد.',
        );

        wp_send_json_success(
            array(
                'message' => isset( $msg_map[ $mode ] ) ? $msg_map[ $mode ] : 'عملیات با موفقیت انجام شد.',
                'result'  => $res,
            )
        );
    }

    /**
     * Download latest daily likadeo.csv from shaghafi.de store-api export link
     */
    public static function ajax_fetch_remote_feed() {
        self::verify_access();

        $custom_url = isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : '';
        if ( '' !== $custom_url ) {
            $settings             = get_option( 'shaghafi_sync_settings', array() );
            $settings['feed_url'] = $custom_url;
            update_option( 'shaghafi_sync_settings', $settings );
        }

        $res = Shaghafi_Feed::fetch_and_import_remote_feed( $custom_url );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        Shaghafi_Scanner::clear_scan_caches();

        $removed = isset( $res['removed_rows'] ) ? (int) $res['removed_rows'] : 0;
        wp_send_json_success(
            array(
                'message' => sprintf(
                    'فید روزانه شقفی با موفقیت دریافت و اعتبارسنجی شد (%d ردیف فعال ایندکس شد%s).',
                    (int) $res['total_rows'],
                    $removed > 0 ? sprintf( '، %d ردیف منسوخ از دیتابیس حذف شد', $removed ) : ''
                ),
                'stats'   => Shaghafi_DB::get_feed_stats(),
                'result'  => $res,
            )
        );
    }

    /**
     * Re-import bundled likadeo.csv reference file
     */
    public static function ajax_import_bundled_feed() {
        self::verify_access();
        $res = Shaghafi_Feed::import_bundled_reference();
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }
        Shaghafi_Scanner::clear_scan_caches();
        wp_send_json_success(
            array(
                'message' => sprintf( 'فایل مرجع داخلی likadeo.csv با موفقیت بارگذاری شد (%d ردیف).', (int) $res['total_rows'] ),
                'stats'   => Shaghafi_DB::get_feed_stats(),
            )
        );
    }

    /**
     * Build native WordPress Featured Image (#postimagediv .inside) HTML payload
     * so the Featured Image box on post.php updates immediately after AJAX repair!
     */
    private static function get_featured_image_ui_payload( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return null;
        }
        $thumb_id = (int) get_post_meta( $parent_id, '_thumbnail_id', true );
        if ( $thumb_id <= 0 || ! Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $thumb_id, false ) ) {
            return null;
        }
        $html = '';
        if ( ! function_exists( '_wp_post_thumbnail_html' ) && file_exists( ABSPATH . 'wp-admin/includes/post.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
        if ( function_exists( '_wp_post_thumbnail_html' ) ) {
            $html = _wp_post_thumbnail_html( $thumb_id, $parent_id );
        }
        return array(
            'attachment_id' => $thumb_id,
            'url'           => wp_get_attachment_url( $thumb_id ) ?: '',
            'html'          => $html,
        );
    }

    /**
     * Perform Live Check on shaghafi.de + 3 Retail Sites AND automatically sync/repair all variations of the product!
     */
    public static function ajax_live_check_product() {
        self::verify_access();

        $parent_id  = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
        $product_id = isset( $_POST['product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['product_id'] ) ) : '';
        $variant_id = isset( $_POST['variant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['variant_id'] ) ) : '';
        $sku        = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';

        // If called on an existing WooCommerce product ($parent_id > 0), run the full autonomous Live Check + Repair + Variation Sync!
        if ( $parent_id > 0 ) {
            $sync_res = Shaghafi_Repairer::sync_and_repair_entire_product( $parent_id );
            if ( is_wp_error( $sync_res ) ) {
                wp_send_json_error( array( 'message' => $sync_res->get_error_message() ) );
            }
            $live         = isset( $sync_res['live'] ) && is_array( $sync_res['live'] ) ? $sync_res['live'] : array();
            $updated_rows = Shaghafi_Scanner::scan_single_product( $parent_id );
            Shaghafi_Scanner::update_product_in_scan_cache( $parent_id, $updated_rows );
            ob_start();
            Shaghafi_Product_Metabox::render_product_rows_table( $updated_rows, $parent_id );
            $metabox_html = ob_get_clean();

            $src_info = ! empty( $live['retail_source'] ) ? ( ' از ' . $live['retail_source'] ) : '';
            $p_txt    = ! empty( $live['price'] ) ? ( $live['price'] . ' €' ) : 'در سایت‌های خرده‌فروشی یافت نشد (قیمت فعلی سایت حفظ شد)';

            wp_send_json_success(
                array(
                    'message'           => sprintf(
                        'بررسی زنده و همگام‌سازی کامل انجام شد: %d تعمیر، %d وریشن جدید اضافه شد، %d وریشن حذف شد | قیمت مرجع%s: %s',
                        (int) $sync_res['repaired'],
                        (int) $sync_res['created'],
                        (int) $sync_res['deleted'],
                        $src_info,
                        $p_txt
                    ),
                    'live'              => $live,
                    'result'            => $sync_res,
                    'updated_rows'       => $updated_rows,
                    'metabox_html'       => $metabox_html,
                    'swatches_ui'        => Shaghafi_Repairer::get_swatches_ui_payload( $parent_id ),
                    'featured_image_ui'  => self::get_featured_image_ui_payload( $parent_id ),
                    'variations_attr_ui' => Shaghafi_Repairer::get_variations_attr_ui_payload( $parent_id ),
                )
            );
        }

        // Feed Explorer standalone live check (when $parent_id === 0)
        $live = Shaghafi_Live_Scraper::fetch_product_live( $product_id, $variant_id, $sku, true );
        if ( is_wp_error( $live ) ) {
            wp_send_json_error( array( 'message' => $live->get_error_message() ) );
        }

        $src_info = ! empty( $live['retail_source'] ) ? ( ' از ' . $live['retail_source'] ) : '';
        wp_send_json_success(
            array(
                'message'      => sprintf(
                    'بررسی زنده انجام شد: %d وریشن بررسی شد | قیمت مرجع خرده‌فروشی%s: %s',
                    isset( $live['variants'] ) ? count( $live['variants'] ) : 1,
                    $src_info,
                    ! empty( $live['price'] ) ? ( $live['price'] . ' €' ) : 'در سایت‌های خرده‌فروشی یافت نشد'
                ),
                'live'         => $live,
                'updated_rows' => array(),
                'metabox_html' => '',
            )
        );
    }

    /**
     * Repair a single variation or simple product
     */
    public static function ajax_repair_single() {
        self::verify_access();

        $parent_id    = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
        $variation_id = isset( $_POST['variation_id'] ) ? (int) $_POST['variation_id'] : 0;
        $feed_vid     = isset( $_POST['feed_vid'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_vid'] ) ) : '';
        $force_image  = ! empty( $_POST['force_image'] );
        $fetch_live   = ! empty( $_POST['fetch_live'] );
        $custom_price = isset( $_POST['custom_price'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_price'] ) ) : '';

        if ( $parent_id <= 0 || '' === $feed_vid ) {
            wp_send_json_error( array( 'message' => 'پارامترهای تعمیر ناقص است (parent_id یا feed_vid ارسال نشده).' ) );
        }

        $repair_opts = array(
            'force_image'  => $force_image,
            'fetch_live'   => $fetch_live,
            'ensure_price' => true,
            'custom_price' => $custom_price,
        );

        // Support selective checkboxes passed from the Master Sync & Repair runner
        $selective_keys = array(
            'sync_sku_meta',
            'sync_attributes_swatches',
            'sync_stock',
            'sync_retail_prices',
            'sync_images',
        );
        foreach ( $selective_keys as $sk ) {
            if ( isset( $_POST[ $sk ] ) ) {
                $repair_opts[ $sk ] = (int) $_POST[ $sk ] ? 1 : 0;
            }
        }
        if ( isset( $repair_opts['sync_retail_prices'] ) && empty( $repair_opts['sync_retail_prices'] ) ) {
            $repair_opts['ensure_price'] = false;
        }

        $res = Shaghafi_Repairer::repair_single_item(
            $parent_id,
            $variation_id,
            $feed_vid,
            $repair_opts
        );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        $updated_rows = Shaghafi_Scanner::scan_single_product( $parent_id );
        Shaghafi_Scanner::update_product_in_scan_cache( $parent_id, $updated_rows );

        ob_start();
        Shaghafi_Product_Metabox::render_product_rows_table( $updated_rows, $parent_id );
        $metabox_html = ob_get_clean();

        wp_send_json_success(
            array(
                'message'           => 'تعمیر و همگام‌سازی انجام شد: ' . implode( '، ', $res['changes'] ),
                'result'            => $res,
                'updated_rows'       => $updated_rows,
                'metabox_html'       => $metabox_html,
                'swatches_ui'        => Shaghafi_Repairer::get_swatches_ui_payload( $parent_id ),
                'featured_image_ui'  => self::get_featured_image_ui_payload( $parent_id ),
                'variations_attr_ui' => Shaghafi_Repairer::get_variations_attr_ui_payload( $parent_id ),
            )
        );
    }

    /**
     * Create a single missing variation on a parent product
     */
    public static function ajax_create_variation() {
        self::verify_access();

        $parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
        $feed_vid  = isset( $_POST['feed_vid'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_vid'] ) ) : '';

        if ( $parent_id <= 0 || '' === $feed_vid ) {
            wp_send_json_error( array( 'message' => 'شناسه محصول مادر یا variant_id شقفی ارسال نشده است.' ) );
        }

        $res = Shaghafi_Repairer::create_missing_variation(
            $parent_id,
            $feed_vid,
            array(
                'force_image'  => true,
                'ensure_price' => true,
            )
        );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        $updated_rows = Shaghafi_Scanner::scan_single_product( $parent_id );
        Shaghafi_Scanner::update_product_in_scan_cache( $parent_id, $updated_rows );

        ob_start();
        Shaghafi_Product_Metabox::render_product_rows_table( $updated_rows, $parent_id );
        $metabox_html = ob_get_clean();

        wp_send_json_success(
            array(
                'message'            => sprintf( 'وریشن جدید با SKU «%s» با موفقیت اضافه شد.', $res['sku'] ),
                'result'             => $res,
                'updated_rows'       => $updated_rows,
                'metabox_html'       => $metabox_html,
                'swatches_ui'        => Shaghafi_Repairer::get_swatches_ui_payload( $parent_id ),
                'featured_image_ui'  => self::get_featured_image_ui_payload( $parent_id ),
                'variations_attr_ui' => Shaghafi_Repairer::get_variations_attr_ui_payload( $parent_id ),
            )
        );
    }

    /**
     * Permanently delete an orphan/disabled variation not present in likadeo.csv
     */
    public static function ajax_disable_variation() {
        self::verify_access();

        $variation_id  = isset( $_POST['variation_id'] ) ? (int) $_POST['variation_id'] : 0;
        $req_parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
        if ( $variation_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'شناسه وریشن نامعتبر است.' ) );
        }

        $res = Shaghafi_Repairer::disable_missing_variation( $variation_id, $req_parent_id );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        $parent_id        = (int) $res['parent_id'] > 0 ? (int) $res['parent_id'] : $req_parent_id;
        $affected_parents = ! empty( $res['affected_parents'] ) && is_array( $res['affected_parents'] )
            ? $res['affected_parents']
            : ( $parent_id > 0 ? array( $parent_id ) : array() );

        $updated_rows = array();
        $metabox_html = '';
        foreach ( $affected_parents as $ap ) {
            $ap_rows = Shaghafi_Scanner::scan_single_product( (int) $ap );
            Shaghafi_Scanner::update_product_in_scan_cache( (int) $ap, $ap_rows );
            if ( (int) $ap === $parent_id ) {
                $updated_rows = $ap_rows;
            }
        }
        if ( $parent_id > 0 ) {
            if ( empty( $updated_rows ) ) {
                $updated_rows = Shaghafi_Scanner::scan_single_product( $parent_id );
                Shaghafi_Scanner::update_product_in_scan_cache( $parent_id, $updated_rows );
            }
            ob_start();
            Shaghafi_Product_Metabox::render_product_rows_table( $updated_rows, $parent_id );
            $metabox_html = ob_get_clean();
        }

        wp_send_json_success(
            array(
                'message'            => sprintf( 'وریشن #%d به طور کامل از محصول و صفحه ویرایش حذف شد.', $variation_id ),
                'result'             => $res,
                'updated_rows'       => $updated_rows,
                'metabox_html'       => $metabox_html,
                'swatches_ui'        => $parent_id > 0 ? Shaghafi_Repairer::get_swatches_ui_payload( $parent_id ) : array(),
                'variations_attr_ui' => $parent_id > 0 ? Shaghafi_Repairer::get_variations_attr_ui_payload( $parent_id ) : null,
            )
        );
    }

    /**
     * Repair & sync all variations of a single parent product (including Live Check, Simple->Variable conversion, missing variations creation, and obsolete variations deletion)
     */
    public static function ajax_repair_product_all() {
        self::verify_access();

        $parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
        if ( $parent_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'شناسه محصول مادر نامعتبر است.' ) );
        }

        $opts = array();
        $selective_keys = array(
            'sync_sku_meta',
            'sync_attributes_swatches',
            'sync_stock',
            'sync_retail_prices',
            'sync_images',
            'sync_create_missing_variations',
            'sync_delete_obsolete_variations',
        );
        foreach ( $selective_keys as $sk ) {
            if ( isset( $_POST[ $sk ] ) ) {
                $opts[ $sk ] = (int) $_POST[ $sk ] ? 1 : 0;
            }
        }

        $sync_res = Shaghafi_Repairer::sync_and_repair_entire_product( $parent_id, $opts );
        if ( is_wp_error( $sync_res ) ) {
            wp_send_json_error( array( 'message' => $sync_res->get_error_message() ) );
        }

        $updated_rows = Shaghafi_Scanner::scan_single_product( $parent_id );
        Shaghafi_Scanner::update_product_in_scan_cache( $parent_id, $updated_rows );

        ob_start();
        Shaghafi_Product_Metabox::render_product_rows_table( $updated_rows, $parent_id );
        $metabox_html = ob_get_clean();

        wp_send_json_success(
            array(
                'message'           => sprintf(
                    'عملیات کامل و بررسی زنده روی محصول #%d انجام شد: %d تعمیر، %d وریشن جدید اضافه شد، %d وریشن حذف شد.',
                    $parent_id,
                    (int) $sync_res['repaired'],
                    (int) $sync_res['created'],
                    (int) $sync_res['deleted']
                ),
                'result'             => $sync_res,
                'updated_rows'       => $updated_rows,
                'metabox_html'       => $metabox_html,
                'swatches_ui'        => Shaghafi_Repairer::get_swatches_ui_payload( $parent_id ),
                'featured_image_ui'  => self::get_featured_image_ui_payload( $parent_id ),
                'variations_attr_ui' => Shaghafi_Repairer::get_variations_attr_ui_payload( $parent_id ),
            )
        );
    }

    /**
     * Build the deduplicated Parent Product Queue for the 1-Click Master Sync & Repair
     */
    public static function ajax_get_master_sync_queue() {
        self::verify_access();

        $scan = Shaghafi_Scanner::scan_catalog(
            array(
                'search'        => '',
                'action_filter' => 'ISSUES_ONLY',
                'page'          => 1,
                'per_page'      => 10000,
                'force_rescan'  => true,
            )
        );

        $rows          = isset( $scan['rows'] ) && is_array( $scan['rows'] ) ? $scan['rows'] : array();
        $parent_queue  = array();
        $seen_parents  = array();

        foreach ( $rows as $r ) {
            $pid = isset( $r['parent_id'] ) ? (int) $r['parent_id'] : 0;
            if ( $pid <= 0 || isset( $seen_parents[ $pid ] ) ) {
                continue;
            }
            if ( 'MANUAL_REVIEW' === $r['action'] && '—' === $r['feed_product_id'] ) {
                continue;
            }
            $seen_parents[ $pid ] = true;
            $parent_queue[]       = array(
                'parent_id'       => $pid,
                'product_title'   => isset( $r['product_title'] ) ? $r['product_title'] : ( 'Product #' . $pid ),
                'feed_product_id' => isset( $r['feed_product_id'] ) ? $r['feed_product_id'] : '',
            );
        }

        wp_send_json_success(
            array(
                'total_parents' => count( $parent_queue ),
                'queue'         => $parent_queue,
                'counts'        => isset( $scan['counts'] ) ? $scan['counts'] : array(),
            )
        );
    }

    /**
     * Create a complete new WooCommerce product from Shaghafi SKU or product_id
     */
    public static function ajax_create_complete_product() {
        self::verify_access();

        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        $res   = Shaghafi_Repairer::create_complete_product_from_shaghafi( $query );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    'محصول «%s» با %d وریشن، قیمت، موجودی و عکس 700×800 بدون برش از شقفی ساخته شد!',
                    $res['title'],
                    (int) $res['variations_made']
                ),
                'product' => $res,
            )
        );
    }

    /**
     * Search the indexed likadeo.csv feed table
     */
    public static function ajax_search_feed() {
        self::verify_access();
        $q    = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        $rows = Shaghafi_DB::search_feed( $q, 100 );
        wp_send_json_success( array( 'rows' => $rows ) );
    }

    /**
     * Fast bulk stock sync for all linked variations
     */
    public static function ajax_sync_all_linked_stocks() {
        self::verify_access();
        $count = Shaghafi_Repairer::sync_all_linked_stocks_from_feed();
        wp_send_json_success(
            array(
                'message' => sprintf( 'موجودی %d وریشن متصل به شقفی طبق فید بروزرسانی شد.', (int) $count ),
                'count'   => $count,
            )
        );
    }

    /**
     * Export current diagnostic scan as CSV
     */
    public static function ajax_export_audit_csv() {
        self::verify_access();

        $scan = Shaghafi_Scanner::scan_catalog( array( 'per_page' => 5000, 'page' => 1 ) );
        $rows = $scan['rows'];

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=Shaghafi_Woo_Master_Audit_' . gmdate( 'Y-m-d_Hi' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        fputcsv(
            $out,
            array(
                'parent_id',
                'variation_id',
                'product_title',
                'action',
                'woo_sku',
                'shaghafi_sku',
                'woo_attr',
                'shaghafi_attr',
                'woo_stock',
                'feed_stock',
                'live_stock',
                'woo_price',
                'shaghafi_live_price_eur',
                'target_price',
                'image_dimensions',
                'meta:_shaghafi_product_id',
                'meta:_shaghafi_variant_id',
                'issues',
            )
        );

        foreach ( $rows as $r ) {
            $issue_texts = array();
            if ( ! empty( $r['issues'] ) ) {
                foreach ( $r['issues'] as $iss ) {
                    $issue_texts[] = $iss['text'];
                }
            }
            fputcsv(
                $out,
                array(
                    $r['parent_id'],
                    $r['variation_id'],
                    $r['product_title'],
                    $r['action'],
                    $r['woo_sku'],
                    $r['feed_sku'],
                    $r['woo_attr'],
                    $r['feed_attr'],
                    $r['woo_stock'],
                    $r['feed_stock'],
                    $r['live_stock'],
                    $r['woo_price'],
                    $r['live_price'],
                    $r['target_price'],
                    isset( $r['woo_image_dims'] ) ? $r['woo_image_dims'] : '',
                    $r['feed_product_id'],
                    $r['feed_variant_id'],
                    implode( ' | ', $issue_texts ),
                )
            );
        }

        fclose( $out );
        exit;
    }

    /**
     * Run full Auto-Pilot Cron immediately from Admin UI
     */
    public static function ajax_run_cron_now() {
        self::verify_access();
        $res = Shaghafi_Feed::run_daily_cron_sync();
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }
        wp_send_json_success( $res );
    }

    /**
     * Save Master Sync & Repair Checkboxes and Scheduled Auto-Pilot Interval
     */
    public static function ajax_save_master_sync_prefs() {
        self::verify_access();

        $settings = get_option( 'shaghafi_sync_settings', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $bool_keys = array(
            'sync_download_feed',
            'sync_stock',
            'sync_sku_meta',
            'sync_attributes_swatches',
            'sync_create_missing_variations',
            'sync_delete_obsolete_variations',
            'sync_images',
            'sync_retail_prices',
            'sync_remove_obsolete_products',
        );
        foreach ( $bool_keys as $bk ) {
            $settings[ $bk ] = ! empty( $_POST[ $bk ] ) ? 1 : 0;
        }

        $interval = isset( $_POST['cron_schedule_interval'] )
            ? sanitize_text_field( wp_unslash( $_POST['cron_schedule_interval'] ) )
            : 'daily';
        if ( ! in_array( $interval, array( 'shaghafi_6hours', 'twicedaily', 'daily', 'disabled' ), true ) ) {
            $interval = 'daily';
        }
        $settings['cron_schedule_interval'] = $interval;

        // Keep legacy cron_* keys synchronized
        $settings['cron_auto_stock']   = $settings['sync_stock'];
        $settings['cron_auto_repair']  = ( $settings['sync_sku_meta'] || $settings['sync_attributes_swatches'] || $settings['sync_images'] || $settings['sync_retail_prices'] ) ? 1 : 0;
        $settings['cron_auto_disable'] = $settings['sync_delete_obsolete_variations'];
        $settings['cron_auto_create']  = $settings['sync_create_missing_variations'];

        update_option( 'shaghafi_sync_settings', $settings );

        // Update WP-Cron schedule
        wp_clear_scheduled_hook( 'shaghafi_daily_feed_sync_event' );
        if ( 'disabled' !== $interval ) {
            wp_schedule_event( time() + 300, $interval, 'shaghafi_daily_feed_sync_event' );
        }

        $next_run = wp_next_scheduled( 'shaghafi_daily_feed_sync_event' );
        wp_send_json_success(
            array(
                'message'       => 'تیک‌های انتخابی و زمان‌بندی اجرای خودکار با موفقیت ذخیره شد.',
                'settings'      => $settings,
                'next_run_text' => $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : 'غیرفعال (فقط اجرای دستی)',
            )
        );
    }

    /**
     * External cPanel / Server Cron Webhook endpoint for 100% guaranteed scheduled execution
     */
    public static function ajax_cron_webhook() {
        $key        = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        $stored_key = get_option( 'shaghafi_cron_secret_key', '' );
        if ( empty( $stored_key ) || ! hash_equals( (string) $stored_key, (string) $key ) ) {
            status_header( 403 );
            wp_send_json_error( array( 'message' => 'کلید امنیتی کرون نامعتبر است.' ) );
        }
        $res = Shaghafi_Feed::run_daily_cron_sync();
        if ( is_wp_error( $res ) ) {
            status_header( 500 );
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }
        wp_send_json_success( $res );
    }
}
