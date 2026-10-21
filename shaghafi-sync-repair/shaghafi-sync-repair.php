<?php
/**
 * Plugin Name: Shaghafi Sync & Repair Pro (پلاگین هوشمند سینک، بررسی زنده و تعمیر شقفی)
 * Plugin URI: https://shaghafi.de/
 * Description: مقایسه هوشمند محصولات و وریشن‌های ووکامرس با فایل مرجع likadeo.csv و بررسی زنده از سایت shaghafi.de (صفحه‌بندی کامل، تشخیص و بازیابی خودکار عکس‌های وریشن و تصویر شاخص حذف‌شده/خراب‌شده از گالری محصول پس از ریستور بکاپ، اصلاح قطعی ویژگی وریشن‌های دارای نام/کلید ناهماهنگ مانند Variante:، رفع نمایش مضاعف/شکسته سواچ‌های WoodMart، تبدیل بدون برش عکس وریشن‌ها به فرمت .webp با کیفیت ۹۴٪ و ابعاد 700×800، ثبت نام محصول به عنوان نام و Alt عکس بدون نام شقفی، تشخیص محصولات سایزی بدون عکس، حذف کامل وریشن‌های غیرفعال و حذف‌شده از شقفی، پنل تعمیر یک‌کلیکی با انتخاب تیک‌ها و زمان‌بندی خودکار).
 * Version: 3.0.1
 * Author: Shaghafi WooCommerce Integration
 * Text Domain: shaghafi-sync-repair
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SHAGHAFI_SYNC_VERSION', '3.0.1' );
define( 'SHAGHAFI_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SHAGHAFI_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once SHAGHAFI_SYNC_PATH . 'includes/class-shaghafi-db.php';
require_once SHAGHAFI_SYNC_PATH . 'includes/class-shaghafi-auth-guard.php';
require_once SHAGHAFI_SYNC_PATH . 'includes/class-shaghafi-category-matcher.php';
require_once SHAGHAFI_SYNC_PATH . 'includes/class-shaghafi-feed.php';
require_once SHAGHAFI_SYNC_PATH . 'includes/class-shaghafi-live-scraper.php';
require_once SHAGHAFI_SYNC_PATH . 'includes/class-shaghafi-scanner.php';
require_once SHAGHAFI_SYNC_PATH . 'includes/class-shaghafi-repairer.php';
require_once SHAGHAFI_SYNC_PATH . 'includes/class-shaghafi-product-metabox.php';
require_once SHAGHAFI_SYNC_PATH . 'includes/class-shaghafi-ajax.php';

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
 */
add_action(
    'before_woocommerce_init',
    function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

/**
 * Main Plugin Class
 */
class Shaghafi_Sync_Repair_Plugin {

    public static function init() {
        register_activation_hook( __FILE__, array( __CLASS__, 'on_activate' ) );
        register_deactivation_hook( __FILE__, array( __CLASS__, 'on_deactivate' ) );

        // Dedicated username/password gate for the whole panel + every plugin AJAX endpoint.
        Shaghafi_Auth_Guard::init();

        add_action( 'init', array( __CLASS__, 'maybe_run_upgrade_heals' ), 20 );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menus' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_form_submissions' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
        add_action( 'shaghafi_daily_feed_sync_event', array( 'Shaghafi_Feed', 'run_daily_cron_sync' ) );
        add_action( 'shaghafi_bg_batch_sync_event', array( 'Shaghafi_Feed', 'run_bg_batch_sync' ) );

        // Auto-detect products added/edited manually or via WP All Import / WooCommerce Importer
        add_action( 'save_post_product', array( 'Shaghafi_Feed', 'on_product_saved_or_imported' ), 20, 1 );
        add_action( 'pmxi_saved_post', array( 'Shaghafi_Feed', 'on_product_saved_or_imported' ), 20, 1 );
        add_action( 'woocommerce_process_product_meta', array( 'Shaghafi_Repairer', 'preserve_and_sync_swatches_on_save' ), 999, 1 );
        add_action( 'wp_head', array( __CLASS__, 'render_frontend_swatch_protection_css' ), 99 );

        // Always allow high-quality .webp uploads in WordPress Media Library
        add_filter( 'upload_mimes', array( 'Shaghafi_Repairer', 'allow_webp_upload_mime' ), 20 );
        add_filter( 'wp_check_filetype_and_ext', array( 'Shaghafi_Repairer', 'fix_webp_filetype_and_ext' ), 20, 4 );

        Shaghafi_Product_Metabox::init();
        Shaghafi_Ajax::init();
    }

    /**
     * Ensure one-time upgrade heals (such as v2.9.3 swatch & variation attribute repair) run immediately
     * upon plugin update on the first request without waiting for the admin page to be opened.
     *
     * v3.0.0 also detects plugin replacement (new files over an older install) and force-flushes every
     * cached response/transient plus PHP OPcache so WordPress can never keep executing the previous version.
     */
    public static function maybe_run_upgrade_heals() {
        $stored_version = get_option( 'shaghafi_plugin_version', '' );

        if ( SHAGHAFI_SYNC_VERSION !== $stored_version ) {
            try {
                global $wpdb;

                // Drop all cached remote lookups so new parsing/pricing logic takes effect immediately.
                $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_shaghafi_%' OR option_name LIKE '_transient_timeout_shaghafi_%'" );
                if ( function_exists( 'wp_cache_flush' ) ) {
                    wp_cache_flush();
                }

                // Some hosts keep stale bytecode after a manual FTP/cPanel file replacement; reset it when allowed.
                if ( function_exists( 'opcache_reset' ) && @opcache_get_status() ) {
                    @opcache_reset();
                }

                Shaghafi_DB::ensure_initialized();
            } catch ( Throwable $e ) {
                // An upgrade routine must never take the whole site down.
                if ( function_exists( 'error_log' ) ) {
                    error_log( '[Shaghafi upgrade] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
                }
            }
            update_option( 'shaghafi_plugin_version', SHAGHAFI_SYNC_VERSION, false );
            return;
        }

        if ( ! get_option( 'shaghafi_healed_v293' ) ) {
            Shaghafi_DB::ensure_initialized();
        }
    }

    /**
     * Prevent WoodMart's duplicate .wd-swatches-product row from rendering broken <img src="45737"> icons
     * alongside WCBoost Variation Swatches on single product pages.
     */
    public static function render_frontend_swatch_protection_css() {
        if ( is_admin() ) {
            return;
        }
        ?>
        <style id="shaghafi-swatch-protection-css">
            form.variations_form td.value:has(.wcboost-variation-swatches) .wd-swatches-product,
            .wd-swatches-product:has(+ .wcboost-variation-swatches),
            .wd-swatches-product:has(~ .wcboost-variation-swatches),
            .wd-swatches-product .wd-swatch img:not([src*="/"]) {
                display: none !important;
            }
        </style>
        <?php
    }

    public static function register_cron_schedules( $schedules ) {
        $schedules['shaghafi_6hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'هر ۶ ساعت (Shaghafi Auto-Sync)',
        );
        return $schedules;
    }

    /**
     * Activation: create custom indexed table, load bundled likadeo.csv, schedule daily cron
     */
    public static function on_activate() {
        Shaghafi_DB::create_tables();

        $defaults = array(
            'feed_url'                        => Shaghafi_Feed::DEFAULT_FEED_URL,
            'target_img_width'                => 700,
            'target_img_height'               => 800,
            'price_multiplier'                => '1',
            'price_add_fixed'                 => '0',
            'price_decimals'                  => '2',
            'disable_mode'                    => 'permanent_delete',
            'default_attribute_taxonomy'      => '',
            'cron_schedule_interval'          => 'daily',
            'sync_download_feed'              => 1,
            'sync_stock'                      => 1,
            'sync_sku_meta'                   => 1,
            'sync_attributes_swatches'        => 1,
            'sync_create_missing_variations'  => 1,
            'sync_delete_obsolete_variations' => 1,
            'sync_images'                     => 1,
            'sync_retail_prices'              => 1,
            'sync_remove_obsolete_products'   => 0,
            'cron_auto_stock'                 => 1,
            'cron_auto_repair'                => 1,
            'cron_auto_disable'               => 1,
            'cron_auto_create'                => 1,
            'shaghafi_email'                  => '',
            'shaghafi_password'               => '',
        );
        $existing = get_option( 'shaghafi_sync_settings', array() );
        update_option( 'shaghafi_sync_settings', array_merge( $defaults, is_array( $existing ) ? $existing : array() ) );

        if ( ! wp_next_scheduled( 'shaghafi_daily_feed_sync_event' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'shaghafi_daily_feed_sync_event' );
        }

        Shaghafi_DB::ensure_initialized();
        update_option( 'shaghafi_plugin_version', SHAGHAFI_SYNC_VERSION, false );
    }

    public static function on_deactivate() {
        wp_clear_scheduled_hook( 'shaghafi_daily_feed_sync_event' );
    }

    /**
     * Register top-level and WooCommerce submenu pages
     */
    public static function register_admin_menus() {
        add_menu_page(
            'شقفی سینک و تعمیر (Shaghafi Sync & Repair)',
            'شقفی سینک و تعمیر',
            'manage_woocommerce',
            'shaghafi-sync-repair',
            array( __CLASS__, 'render_admin_page' ),
            'dashicons-hammer',
            56
        );

        add_submenu_page(
            'woocommerce',
            'شقفی سینک و تعمیر (Shaghafi Sync & Repair)',
            'شقفی سینک و تعمیر',
            'manage_woocommerce',
            'shaghafi-sync-repair',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    /**
     * Enqueue CSS & JS on plugin admin page and WooCommerce product edit screen
     */
    public static function enqueue_admin_assets( $hook ) {
        $screen    = get_current_screen();
        $is_plugin = ( false !== strpos( (string) $hook, 'shaghafi-sync-repair' ) );
        $is_prod   = ( $screen && 'product' === $screen->post_type );

        if ( ! $is_plugin && ! $is_prod ) {
            return;
        }

        wp_enqueue_style(
            'shaghafi-sync-admin-css',
            SHAGHAFI_SYNC_URL . 'assets/css/admin.css',
            array(),
            SHAGHAFI_SYNC_VERSION
        );

        wp_enqueue_script(
            'shaghafi-sync-admin-js',
            SHAGHAFI_SYNC_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            SHAGHAFI_SYNC_VERSION,
            true
        );

        $dims = Shaghafi_Scanner::get_target_image_dimensions();
        wp_localize_script(
            'shaghafi-sync-admin-js',
            'ShaghafiSyncData',
            array(
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'nonce'             => wp_create_nonce( 'shaghafi_sync_nonce' ),
                'feed_url'          => Shaghafi_Feed::get_feed_url(),
                'target_img_width'  => $dims['width'],
                'target_img_height' => $dims['height'],
            )
        );
    }

    /**
     * Handle manual CSV upload & settings form POST
     */
    public static function handle_form_submissions() {
        if ( ! is_admin() ) {
            return;
        }
        Shaghafi_DB::ensure_initialized();

        // 1. Handle manual CSV upload
        if ( isset( $_POST['shaghafi_upload_csv_submit'] ) && check_admin_referer( 'shaghafi_upload_csv_action', 'shaghafi_upload_csv_nonce' ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
                return;
            }
            if ( ! empty( $_FILES['likadeo_csv_file']['tmp_name'] ) ) {
                $res = Shaghafi_Feed::handle_uploaded_csv( $_FILES['likadeo_csv_file'] );
                if ( is_wp_error( $res ) ) {
                    add_settings_error( 'shaghafi_notices', 'upload_err', $res->get_error_message(), 'error' );
                } else {
                    Shaghafi_Scanner::clear_scan_caches();
                    add_settings_error(
                        'shaghafi_notices',
                        'upload_ok',
                        sprintf( 'فایل جدید likadeo.csv با موفقیت آپلود و %d ردیف ایندکس شد.', (int) $res['total_rows'] ),
                        'updated'
                    );
                }
            }
        }

        // 2. Handle Settings save
        if ( isset( $_POST['shaghafi_save_settings_submit'] ) && check_admin_referer( 'shaghafi_save_settings_action', 'shaghafi_save_settings_nonce' ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
                return;
            }
            $settings = array(
                'feed_url'                   => isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : Shaghafi_Feed::DEFAULT_FEED_URL,
                'target_img_width'           => isset( $_POST['target_img_width'] ) ? max( 100, (int) $_POST['target_img_width'] ) : 700,
                'target_img_height'          => isset( $_POST['target_img_height'] ) ? max( 100, (int) $_POST['target_img_height'] ) : 800,
                'price_multiplier'           => isset( $_POST['price_multiplier'] ) ? sanitize_text_field( wp_unslash( $_POST['price_multiplier'] ) ) : '1',
                'price_add_fixed'            => isset( $_POST['price_add_fixed'] ) ? sanitize_text_field( wp_unslash( $_POST['price_add_fixed'] ) ) : '0',
                'price_decimals'             => isset( $_POST['price_decimals'] ) ? (int) $_POST['price_decimals'] : 2,
                'disable_mode'               => isset( $_POST['disable_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['disable_mode'] ) ) : 'private_and_zero',
                'default_attribute_taxonomy' => isset( $_POST['default_attribute_taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['default_attribute_taxonomy'] ) ) : '',
                'cron_interval'              => isset( $_POST['cron_interval'] ) ? sanitize_text_field( wp_unslash( $_POST['cron_interval'] ) ) : 'daily',
                'cron_auto_stock'            => ! empty( $_POST['cron_auto_stock'] ) ? 1 : 0,
                'cron_auto_repair'           => ! empty( $_POST['cron_auto_repair'] ) ? 1 : 0,
                'cron_auto_disable'          => ! empty( $_POST['cron_auto_disable'] ) ? 1 : 0,
                'cron_auto_create'           => ! empty( $_POST['cron_auto_create'] ) ? 1 : 0,
                'shaghafi_email'             => isset( $_POST['shaghafi_email'] ) ? sanitize_text_field( wp_unslash( $_POST['shaghafi_email'] ) ) : '',
                'shaghafi_password'          => isset( $_POST['shaghafi_password'] ) ? sanitize_text_field( wp_unslash( $_POST['shaghafi_password'] ) ) : '',
            );
            update_option( 'shaghafi_sync_settings', $settings );
            wp_clear_scheduled_hook( 'shaghafi_daily_feed_sync_event' );
            wp_schedule_event( time() + 300, $settings['cron_interval'], 'shaghafi_daily_feed_sync_event' );
            delete_transient( 'shaghafi_b2b_cookies' );
            Shaghafi_Scanner::clear_scan_caches();
            add_settings_error( 'shaghafi_notices', 'settings_ok', 'تنظیمات پلاگین شقفی با موفقیت ذخیره شد.', 'updated' );
        }
    }

    /**
     * Render Main Admin Page
     */
    public static function render_admin_page() {
        Shaghafi_DB::ensure_initialized();
        $feed_stats = Shaghafi_DB::get_feed_stats();
        $settings   = get_option( 'shaghafi_sync_settings', array() );
        $dims       = Shaghafi_Scanner::get_target_image_dimensions();
        $export_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=shaghafi_export_audit_csv' ), 'shaghafi_sync_nonce', 'nonce' );
        ?>
        <div class="wrap shaghafi-wrap" dir="rtl">
            <div class="shaghafi-header">
                <div>
                    <?php $sh_logout_url = Shaghafi_Auth_Guard::get_logout_url(); ?>
                    <div style="margin-bottom:8px;">
                        <span class="shaghafi-pill" style="background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;">🔒 نسخه فعال: <strong>v<?php echo esc_html( SHAGHAFI_SYNC_VERSION ); ?></strong></span>
                        <a href="<?php echo esc_url( $sh_logout_url ); ?>" class="button button-small" style="margin-right:8px;" onclick="return confirm('از پنل شقفی خارج می‌شوید؟');">🚪 خروج از پنل (Logout)</a>
                    </div>
                    <h1>🛠 پلاگین جامع سینک، بررسی زنده و تعمیر محصولات شقفی (Shaghafi Sync &amp; Repair Pro v<?php echo esc_html( SHAGHAFI_SYNC_VERSION ); ?>)</h1>
                    <p class="shaghafi-subtitle">
                        مقایسه هوشمند با فید مرجع <code>likadeo.csv</code> و بررسی زنده از <code>https://shaghafi.de</code> | تبدیل بدون برش و بدون افت کیفیت عکس‌ها به فرمت <code>.webp (۹۴٪)</code> در ابعاد <code><?php echo (int) $dims['width'] . '×' . (int) $dims['height']; ?></code> با نام و Alt محصول (بدون نام شقفی) | تشخیص وریشن‌های سایزی | حذف دائمی وریشن‌های غیرفعال
                    </p>
                </div>
                <div class="shaghafi-header-badges">
                    <span class="shaghafi-pill">تعداد کل ردیف‌های فید: <strong id="shaghafi-stat-feed-rows"><?php echo number_format_i18n( $feed_stats['total_rows'] ); ?></strong></span>
                    <span class="shaghafi-pill">محصولات یکتا در فید: <strong><?php echo number_format_i18n( $feed_stats['unique_products'] ); ?></strong></span>
                    <span class="shaghafi-pill">ابعاد استاندارد عکس: <strong><?php echo (int) $dims['width'] . '×' . (int) $dims['height']; ?> (بدون برش)</strong></span>
                    <span class="shaghafi-pill">آخرین بروزرسانی فید: <strong id="shaghafi-stat-feed-date"><?php echo esc_html( $feed_stats['imported_at'] ); ?></strong></span>
                </div>
            </div>

            <?php settings_errors( 'shaghafi_notices' ); ?>

            <!-- Navigation Tabs -->
            <nav class="shaghafi-tabs-nav">
                <button type="button" class="shaghafi-tab-btn active" data-tab="tab-queue">📊 داشبورد و صف عیب‌یابی و تعمیر (صفحه‌بندی شده)</button>
                <button type="button" class="shaghafi-tab-btn" data-tab="tab-obsolete" id="shaghafi-tab-btn-obsolete">🗑️ محصولات حذف‌شده / غیرفعال در شقفی (نیازمند حذف از سایت)</button>
                <button type="button" class="shaghafi-tab-btn" data-tab="tab-feed">📁 مدیریت فید مرجع likadeo.csv و لینک روزانه</button>
                <button type="button" class="shaghafi-tab-btn" data-tab="tab-explorer">🔎 کاوشگر فید شقفی و ساخت محصول جدید</button>
                <button type="button" class="shaghafi-tab-btn" data-tab="tab-settings">⚙️ تنظیمات (سایز عکس 700×800، قیمت و کرون)</button>
            </nav>

            <!-- TAB 1: DASHBOARD & REPAIR QUEUE -->
            <div id="tab-queue" class="shaghafi-tab-panel active">
                <?php
                $chk_download_feed = isset( $settings['sync_download_feed'] ) ? (int) $settings['sync_download_feed'] : 1;
                $chk_stock         = isset( $settings['sync_stock'] ) ? (int) $settings['sync_stock'] : 1;
                $chk_sku_meta      = isset( $settings['sync_sku_meta'] ) ? (int) $settings['sync_sku_meta'] : 1;
                $chk_attrs         = isset( $settings['sync_attributes_swatches'] ) ? (int) $settings['sync_attributes_swatches'] : 1;
                $chk_create_vars   = isset( $settings['sync_create_missing_variations'] ) ? (int) $settings['sync_create_missing_variations'] : 1;
                $chk_delete_vars   = isset( $settings['sync_delete_obsolete_variations'] ) ? (int) $settings['sync_delete_obsolete_variations'] : 1;
                $chk_images        = isset( $settings['sync_images'] ) ? (int) $settings['sync_images'] : 1;
                $chk_prices        = isset( $settings['sync_retail_prices'] ) ? (int) $settings['sync_retail_prices'] : 1;
                $chk_obs_prods     = isset( $settings['sync_remove_obsolete_products'] ) ? (int) $settings['sync_remove_obsolete_products'] : 0;
                $sched_interval    = isset( $settings['cron_schedule_interval'] ) ? $settings['cron_schedule_interval'] : ( isset( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'daily' );
                $next_cron_ts      = wp_next_scheduled( 'shaghafi_daily_feed_sync_event' );
                $last_cron_info    = get_option( 'shaghafi_last_cron_log', array() );
                ?>
                <!-- 1-CLICK MASTER SYNC & REPAIR CONTROL CENTER WITH CHECKBOXES + SCHEDULE -->
                <div class="shaghafi-card shaghafi-master-sync-card" style="margin-bottom:18px;border:2px solid #2563eb;background:linear-gradient(135deg,#f8fafc 0%,#eff6ff 100%);">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                        <div>
                            <h2 style="margin:0;color:#1e3a8a;font-size:16px;">⚡ پنل جامع بروزرسانی و تعمیر یک‌کلیکی کل محصولات با انتخاب تیک‌ها + زمان‌بندی خودکار</h2>
                            <p style="margin:4px 0 0 0;color:#475569;font-size:13px;">
                                تیک مواردی که می‌خواهید در سایت بروزرسانی و اصلاح شوند را بزنید؛ سپس با <strong>یک کلیک</strong> کل محصولات را اصلاح کنید یا <strong>حالت اتوماتیک زمان‌بندی‌شده</strong> را فعال بگذارید تا خودش بدون دخالت شما انجام دهد.
                            </p>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <span class="shaghafi-pill" style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;">
                                ⏰ اجرای خودکار بعدی: <strong id="shaghafi-master-next-cron"><?php echo $next_cron_ts ? esc_html( wp_date( 'Y-m-d H:i:s', $next_cron_ts ) ) : 'غیرفعال'; ?></strong>
                            </span>
                        </div>
                    </div>

                    <div class="shaghafi-master-checkbox-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:10px;background:#ffffff;padding:14px;border-radius:8px;border:1px solid #cbd5e1;margin-bottom:14px;">
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;color:#0f172a;">
                            <input type="checkbox" id="ms-download-feed" <?php checked( 1 === $chk_download_feed ); ?> style="margin-top:3px;" />
                            <span>📥 ۱. دریافت و تشخیص خودکار آخرین فایل مرجع <code>likadeo.csv</code> از شقفی (و پاکسازی کدهای منسوخ‌شده)</span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;color:#0f172a;">
                            <input type="checkbox" id="ms-stock" <?php checked( 1 === $chk_stock ); ?> style="margin-top:3px;" />
                            <span>📦 ۲. بروزرسانی موجودی (<code>bestand</code>) تمام وریشن‌ها و محصولات ساده طبق شقفی</span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;color:#0f172a;">
                            <input type="checkbox" id="ms-sku-meta" <?php checked( 1 === $chk_sku_meta ); ?> style="margin-top:3px;" />
                            <span>🏷️ ۳. اصلاح کد محصول (SKU) و ذخیره شناسه‌های اتصال شقفی (<code>PID / VID</code>)</span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;color:#0f172a;">
                            <input type="checkbox" id="ms-attrs-swatches" <?php checked( 1 === $chk_attrs ); ?> style="margin-top:3px;" />
                            <span>🎨 ۴. اصلاح ویژگی‌ها (Attributes)، مرتب‌سازی صعودی (کوچک به بزرگ) و دایره‌های رنگ (Swatches)</span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;color:#0f172a;">
                            <input type="checkbox" id="ms-create-vars" <?php checked( 1 === $chk_create_vars ); ?> style="margin-top:3px;" />
                            <span>➕ ۵. افزودن خودکار وریشن‌های جاافتاده که در فایل مرجع شقفی موجود هستند</span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;color:#b91c1c;">
                            <input type="checkbox" id="ms-delete-vars" <?php checked( 1 === $chk_delete_vars ); ?> style="margin-top:3px;" />
                            <span>🗑️ ۶. حذف کامل و دائمی وریشن‌های غیرفعال یا حذف‌شده از شقفی (حذف کامل از صفحه محصول و ویرایش محصول)</span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;color:#0f172a;">
                            <input type="checkbox" id="ms-images" <?php checked( 1 === $chk_images ); ?> style="margin-top:3px;" />
                            <span>🖼️ ۷. تبدیل عکس‌ها به <code>.webp</code> (کیفیت ۹۴٪ بدون افت کیفیت، ابعاد <code>700×800</code> بدون برش)، ثبت نام محصول به عنوان نام و Alt عکس (بدون نام شقفی) و پاکسازی عکس از وریشن‌های سایزی</span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;color:#0f172a;">
                            <input type="checkbox" id="ms-prices" <?php checked( 1 === $chk_prices ); ?> style="margin-top:3px;" />
                            <span>💶 ۸. بروزرسانی قیمت از ۳ سایت خرده‌فروشی مرجع (AkzentShop / MeinAkzent / Raptor-Watches)</span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;color:#7c2d12;">
                            <input type="checkbox" id="ms-obsolete-prods" <?php checked( 1 === $chk_obs_prods ); ?> style="margin-top:3px;" />
                            <span>🚫 ۹. انتقال به زباله‌دان برای محصولات کلّی که کاملاً از شقفی حذف شده‌اند (تب محصولات حذف‌شده)</span>
                        </label>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <button type="button" id="shaghafi-btn-master-sync-all" class="button button-primary button-hero" style="background:#16a34a;border-color:#15803d;font-weight:700;padding:4px 20px;">
                                🚀 شروع فوری بروزرسانی و تعمیر یک‌کلیکی کل محصولات (طبق تیک‌های بالا)
                            </button>
                            <button type="button" id="shaghafi-btn-run-cron-now" class="button button-secondary" title="اجرای فوری موتور اتوماسیون پس‌زمینه سرور">
                                ⚡ اجرای فوری سینک پس‌زمینه سرور
                            </button>
                        </div>

                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#ffffff;padding:8px 12px;border-radius:8px;border:1px solid #cbd5e1;">
                            <label for="ms-cron-interval" style="font-weight:700;color:#1e293b;">⏱️ زمان‌بندی اجرای خودکار (اتوماتیک):</label>
                            <select id="ms-cron-interval">
                                <option value="shaghafi_6hours" <?php selected( $sched_interval, 'shaghafi_6hours' ); ?>>هر ۶ ساعت یک‌بار (۴ بار در شبانه‌روز)</option>
                                <option value="twicedaily" <?php selected( $sched_interval, 'twicedaily' ); ?>>هر ۱۲ ساعت یک‌بار (۲ بار در شبانه‌روز)</option>
                                <option value="daily" <?php selected( $sched_interval, 'daily' ); ?>>روزانه (هر ۲۴ ساعت یک‌بار)</option>
                                <option value="disabled" <?php selected( $sched_interval, 'disabled' ); ?>>غیرفعال (فقط با کلیک دستی)</option>
                            </select>
                            <button type="button" id="shaghafi-btn-save-master-prefs" class="button button-primary">
                                💾 ذخیره تیک‌ها و زمان‌بندی
                            </button>
                        </div>
                    </div>

                    <?php if ( ! empty( $last_cron_info['time'] ) ) : ?>
                        <div style="margin-top:10px;font-size:12px;color:#334155;background:#f1f5f9;padding:6px 10px;border-radius:6px;">
                            <strong>📌 گزارش آخرین اجرای خودکار:</strong>
                            <code><?php echo esc_html( $last_cron_info['time'] ); ?></code> —
                            <?php echo esc_html( isset( $last_cron_info['message'] ) ? $last_cron_info['message'] : '' ); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="shaghafi-kpi-grid">
                    <div class="shaghafi-kpi-card kpi-total" data-filter="ALL">
                        <span class="kpi-label">کل وریشن‌ها / آیتم‌های بررسی‌شده</span>
                        <strong class="kpi-num" id="kpi-total-items">—</strong>
                        <small>کلیک برای نمایش همه</small>
                    </div>
                    <div class="shaghafi-kpi-card kpi-repair" data-filter="REPAIR_AND_LINK">
                        <span class="kpi-label">نیاز به تعمیر ساختاری (REPAIR_AND_LINK)</span>
                        <strong class="kpi-num" id="kpi-repair-link">—</strong>
                        <small>SKU / اتریبیوت / آیدی / عکس 700×800</small>
                    </div>
                    <div class="shaghafi-kpi-card kpi-sync" data-filter="KEEP_AND_SYNC">
                        <span class="kpi-label">نیاز به سینک (KEEP_AND_SYNC)</span>
                        <strong class="kpi-num" id="kpi-keep-sync">—</strong>
                        <small>اختلاف موجودی، قیمت یا متای ذخیره نشده</small>
                    </div>
                    <div class="shaghafi-kpi-card kpi-create" data-filter="CREATE_NEW_VARIATION">
                        <span class="kpi-label">وریشن‌های جاافتاده (CREATE_NEW)</span>
                        <strong class="kpi-num" id="kpi-create-new">—</strong>
                        <small>موجود در فید شقفی، ناموجود در سایت شما</small>
                    </div>
                    <div class="shaghafi-kpi-card kpi-disable" data-filter="DISABLE_MISSING">
                        <span class="kpi-label">وریشن‌های حذف‌شده از شقفی (DISABLE_MISSING)</span>
                        <strong class="kpi-num" id="kpi-disable-missing">—</strong>
                        <small>نیازمند حذف کامل و دائمی از محصول</small>
                    </div>
                    <div class="shaghafi-kpi-card kpi-manual" data-filter="MANUAL_REVIEW">
                        <span class="kpi-label">بررسی دستی (MANUAL_REVIEW)</span>
                        <strong class="kpi-num" id="kpi-manual-review">—</strong>
                        <small>محصولات خارج از فید یا نیازمند اتصال دستی</small>
                    </div>
                    <div class="shaghafi-kpi-card kpi-healthy" data-filter="HEALTHY">
                        <span class="kpi-label">سالم و منطبق (HEALTHY)</span>
                        <strong class="kpi-num" id="kpi-healthy">—</strong>
                        <small>کاملاً هماهنگ با مرجع</small>
                    </div>
                </div>

                <!-- Action Toolbar -->
                <div class="shaghafi-toolbar">
                    <div class="shaghafi-toolbar-right">
                        <input type="text" id="shaghafi-scan-search" placeholder="جستجوی نام محصول، SKU، product_id..." class="regular-text" />
                        <select id="shaghafi-action-filter">
                            <option value="ALL">نمایش همه وضعیت‌ها</option>
                            <option value="ISSUES_ONLY">فقط موارد دارای مشکل (نیازمند اقدام)</option>
                            <option value="REPAIR_AND_LINK">REPAIR_AND_LINK (تعمیر SKU/ویژگی/آیدی/عکس 700×800)</option>
                            <option value="KEEP_AND_SYNC">KEEP_AND_SYNC (سینک موجودی/قیمت/متا)</option>
                            <option value="CREATE_NEW_VARIATION">CREATE_NEW_VARIATION (وریشن‌های جاافتاده برای افزودن)</option>
                            <option value="DISABLE_MISSING">DISABLE_MISSING (وریشن‌های ناموجود در فید)</option>
                            <option value="MANUAL_REVIEW">MANUAL_REVIEW (نیازمند بررسی دستی)</option>
                            <option value="HEALTHY">HEALTHY (سالم و بدون مشکل)</option>
                        </select>
                        <select id="shaghafi-per-page" title="تعداد نمایش در هر صفحه">
                            <option value="25">۲۵ ردیف در صفحه</option>
                            <option value="50" selected>۵۰ ردیف در صفحه</option>
                            <option value="100">۱۰۰ ردیف در صفحه</option>
                            <option value="200">۲۰۰ ردیف در صفحه</option>
                        </select>
                        <button type="button" id="shaghafi-btn-scan" class="button button-primary">
                            🔍 اعمال فیلتر / نمایش
                        </button>
                        <button type="button" id="shaghafi-btn-force-rescan" class="button" title="اسکن مجدد کل محصولات از دیتابیس">
                            🔄 اسکن مجدد کامل
                        </button>
                    </div>

                    <div class="shaghafi-toolbar-left">
                        <button type="button" id="shaghafi-btn-bulk-live" class="button button-secondary">
                            🌐 بررسی زنده دسته‌جمعی این صفحه از Shaghafi.de
                        </button>
                        <button type="button" id="shaghafi-btn-bulk-repair" class="button button-primary">
                            🛠 اصلاح خودکار موارد انتخاب‌شده طبق مرجع
                        </button>
                        <button type="button" id="shaghafi-btn-bulk-create" class="button button-secondary">
                            ➕ افزودن وریشن‌های جاافتاده انتخاب‌شده
                        </button>
                        <button type="button" id="shaghafi-btn-bulk-disable" class="button" style="background:#dc2626;color:#fff;border-color:#b91c1c;">
                            🗑️ حذف دائمی وریشن‌های منقضی انتخاب‌شده
                        </button>
                        <a href="<?php echo esc_url( $export_url ); ?>" class="button">📥 دانلود CSV/Excel</a>
                    </div>
                </div>

                <!-- Live Progress Bar -->
                <div id="shaghafi-progress-box" class="shaghafi-progress-box" style="display:none;">
                    <div class="shaghafi-progress-header">
                        <strong id="shaghafi-progress-title">در حال انجام عملیات...</strong>
                        <span id="shaghafi-progress-counter">0 / 0</span>
                    </div>
                    <div class="shaghafi-progress-track">
                        <div id="shaghafi-progress-bar" class="shaghafi-progress-fill" style="width:0%;"></div>
                    </div>
                </div>

                <!-- Top Pagination Bar -->
                <div class="shaghafi-pagination-bar" id="shaghafi-pagination-top"></div>

                <!-- Diagnostic & Repair Table -->
                <div class="shaghafi-table-container">
                    <table class="widefat striped shaghafi-table" id="shaghafi-queue-table">
                        <thead>
                            <tr>
                                <th style="width:28px;"><input type="checkbox" id="shaghafi-select-all" /></th>
                                <th>محصول و وریشن</th>
                                <th>وضعیت / اکشن</th>
                                <th>عکس وریشن (سایت 700×800 ← شقفی)</th>
                                <th>SKU (فعلی ← مرجع)</th>
                                <th>ویژگی / Attribute (فعلی ← مرجع)</th>
                                <th>موجودی (فعلی ← فید / زنده)</th>
                                <th>قیمت و لینک مرجع خرده‌فروشی (AkzentShop / MeinAkzent / Raptor)</th>
                                <th>شناسه‌های شقفی (PID / VID)</th>
                                <th>جزئیات مشکلات</th>
                                <th style="min-width:170px;">عملیات (یک کلیکی)</th>
                            </tr>
                        </thead>
                        <tbody id="shaghafi-queue-tbody">
                            <tr>
                                <td colspan="11" style="text-align:center;padding:30px;">
                                    در حال بارگذاری و اسکن محصولات...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Bottom Pagination Bar -->
                <div class="shaghafi-pagination-bar" id="shaghafi-pagination-bottom"></div>
            </div>

            <!-- TAB 2: OBSOLETE / REMOVED FROM SHAGHAFI PRODUCTS -->
            <div id="tab-obsolete" class="shaghafi-tab-panel" style="display:none;">
                <div class="shaghafi-card" style="margin-bottom:16px;">
                    <h2 style="margin-top:0;">🗑️ شناسایی محصولات و وریشن‌هایی که دیگر در دامنه مرجع (Shaghafi.de / likadeo.csv) استفاده نمی‌شوند</h2>
                    <p style="margin-bottom:0;color:#475569;">
                        در این بخش، تمام محصولاتی که در سایت شما وجود دارند اما **از فید مرجع شقفی حذف شده‌اند**، یا **موجودی تمام وریشن‌های آن‌ها در شقفی صفر شده است**، یا **وریشن‌های تکی که دیگر در شقفی وجود ندارند** لیست می‌شوند تا بتوانید با یک کلیک آن‌ها را به زباله‌دان منتقل کنید یا پیش‌نویس/ناموجود نمایید.
                    </p>
                </div>

                <div class="shaghafi-kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                    <div class="shaghafi-kpi-card obs-kpi-card kpi-total" data-obs-filter="ALL">
                        <span class="kpi-label">کل موارد منقضی / نیازمند تعیین تکلیف</span>
                        <strong class="kpi-num" id="obs-kpi-total">—</strong>
                        <small>کلیک برای نمایش همه</small>
                    </div>
                    <div class="shaghafi-kpi-card obs-kpi-card kpi-repair" data-obs-filter="REMOVED_FROM_FEED">
                        <span class="kpi-label">محصولات کاملاً حذف‌شده از فید شقفی</span>
                        <strong class="kpi-num" id="obs-kpi-removed">—</strong>
                        <small>کد یا شناسه آن‌ها دیگر در likadeo.csv نیست</small>
                    </div>
                    <div class="shaghafi-kpi-card obs-kpi-card kpi-sync" data-obs-filter="ALL_ZERO_STOCK_IN_FEED">
                        <span class="kpi-label">محصولات با موجودی صفر کامل در شقفی</span>
                        <strong class="kpi-num" id="obs-kpi-zerostock">—</strong>
                        <small>تمام وریشن‌ها در شقفی موجودی 0 دارند</small>
                    </div>
                    <div class="shaghafi-kpi-card obs-kpi-card kpi-disable" data-obs-filter="ORPHAN_VARIATION">
                        <span class="kpi-label">وریشن‌های تکی حذف‌شده از فید</span>
                        <strong class="kpi-num" id="obs-kpi-orphan">—</strong>
                        <small>وریشن‌هایی که از محصول مادر در شقفی حذف شده‌اند</small>
                    </div>
                </div>

                <div class="shaghafi-toolbar">
                    <div class="shaghafi-toolbar-right">
                        <input type="text" id="shaghafi-obs-search" placeholder="جستجوی نام محصول، SKU یا آیدی..." class="regular-text" />
                        <select id="shaghafi-obs-type-filter">
                            <option value="ALL">همه موارد منقضی و حذف‌شده</option>
                            <option value="REMOVED_FROM_FEED">فقط محصولات کاملاً حذف‌شده از فید شقفی</option>
                            <option value="ALL_ZERO_STOCK_IN_FEED">فقط محصولات با موجودی صفر کامل در شقفی</option>
                            <option value="ORPHAN_VARIATION">فقط وریشن‌های تکی حذف‌شده از فید</option>
                        </select>
                        <select id="shaghafi-obs-per-page">
                            <option value="25">۲۵ مورد در صفحه</option>
                            <option value="50" selected>۵۰ مورد در صفحه</option>
                            <option value="100">۱۰۰ مورد در صفحه</option>
                        </select>
                        <button type="button" id="shaghafi-btn-scan-obsolete" class="button button-primary">
                            🔍 جستجو و فیلتر لیست منقضی‌ها
                        </button>
                        <button type="button" id="shaghafi-btn-rescan-obsolete" class="button">
                            🔄 اسکن مجدد
                        </button>
                    </div>
                    <div class="shaghafi-toolbar-left">
                        <button type="button" id="shaghafi-btn-bulk-obs-draft" class="button button-secondary">
                            📦 پیش‌نویس + موجودی صفر برای انتخاب‌شده‌ها
                        </button>
                        <button type="button" id="shaghafi-btn-bulk-obs-trash" class="button button-primary" style="background:#dc2626;border-color:#b91c1c;">
                            🗑️ انتقال انتخاب‌شده‌ها به زباله‌دان (برداشتن از سایت)
                        </button>
                    </div>
                </div>

                <div class="shaghafi-pagination-bar" id="shaghafi-obs-pagination-top"></div>

                <div class="shaghafi-table-container">
                    <table class="widefat striped shaghafi-table" id="shaghafi-obsolete-table">
                        <thead>
                            <tr>
                                <th style="width:28px;"><input type="checkbox" id="shaghafi-obs-select-all" /></th>
                                <th>عکس</th>
                                <th>نام محصول / وریشن در سایت</th>
                                <th>نوع وضعیت در مرجع شقفی</th>
                                <th>SKU در سایت</th>
                                <th>وضعیت فعلی پست</th>
                                <th>موجودی (سایت / شقفی)</th>
                                <th>علت تشخیص به عنوان منقضی</th>
                                <th style="min-width:190px;">عملیات برداشتن از سایت</th>
                            </tr>
                        </thead>
                        <tbody id="shaghafi-obsolete-tbody">
                            <tr>
                                <td colspan="9" style="text-align:center;padding:30px;">
                                    برای مشاهده لیست محصولات منقضی و حذف‌شده از شقفی، روی دکمه اسکن کلیک کنید.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="shaghafi-pagination-bar" id="shaghafi-obs-pagination-bottom"></div>
            </div>

            <!-- TAB 3: FEED MANAGEMENT -->
            <div id="tab-feed" class="shaghafi-tab-panel" style="display:none;">
                <div class="shaghafi-card">
                    <h2>🔄 ۱. بروزرسانی مستقیم و زنده فایل مرجع <code>likadeo.csv</code> از سرور شقفی</h2>
                    <p>لینک اختصاصی اکسپورت روزانه شقفی شما به صورت پیش‌فرض تنظیم شده است. با کلیک روی دکمه زیر، آخرین نسخه فایل مستقیماً از سرور <code>shaghafi.de</code> دانلود و ایندکس می‌شود:</p>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:15px 0;">
                        <input type="url" id="shaghafi-remote-feed-url" value="<?php echo esc_attr( Shaghafi_Feed::get_feed_url() ); ?>" style="width:620px;direction:ltr;font-family:monospace;" />
                        <button type="button" id="shaghafi-btn-fetch-remote-feed" class="button button-primary button-large">
                            ⬇️ دریافت مستقیم آخرین فید از Shaghafi.de و بروزرسانی دیتابیس
                        </button>
                        <button type="button" id="shaghafi-btn-sync-linked-stocks" class="button button-secondary button-large">
                            ⚡ سینک فوری موجودی همه وریشن‌های متصل
                        </button>
                    </div>
                </div>

                <div class="shaghafi-card" style="margin-top:20px;">
                    <h2>📤 ۲. آپلود دستی فایل <code>likadeo.csv</code></h2>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'shaghafi_upload_csv_action', 'shaghafi_upload_csv_nonce' ); ?>
                        <p>اگر فایل <code>likadeo.csv</code> را روی کامپیوتر خود دارید، می‌توانید آن را مستقیماً آپلود کنید:</p>
                        <input type="file" name="likadeo_csv_file" accept=".csv,text/csv" required />
                        <button type="submit" name="shaghafi_upload_csv_submit" class="button button-primary">آپلود و ایندکس فایل CSV</button>
                        <button type="button" id="shaghafi-btn-reload-bundled" class="button" style="margin-right:10px;">
                            ♻️ بارگذاری مجدد فایل مرجع داخلی پلاگین (21,759 ردیف)
                        </button>
                    </form>
                </div>
            </div>

            <!-- TAB 4: FEED EXPLORER & 1-CLICK PRODUCT CREATOR -->
            <div id="tab-explorer" class="shaghafi-tab-panel" style="display:none;">
                <div class="shaghafi-card">
                    <h2>✨ ساخت محصول جدید (تکی یا دسته‌جمعی مرحله‌به‌مرحله) از روی آخرین فید مرجع و بررسی زنده</h2>
                    <p>می‌توانید <strong>یک SKU</strong> (مثلاً <code>5010034</code>) یا <strong>چندین SKU / Product ID</strong> (در خط‌های جداگانه یا جداشده با ویرگول <code>,</code>) وارد کنید. پلاگین از روی آخرین فایل دانلودشده <code>likadeo.csv</code> به همراه بررسی زنده از <code>shaghafi.de</code> و ۳ سایت خرده‌فروشی، <strong>نام کامل محصول، موجودی دقیق، قیمت خرده‌فروشی، توضیحات بلند، جدول مشخصات فنی، ویژگی‌ها، تمام وریشن‌ها و عکس‌های اختصاصی هر وریشن (<code>700×800 WebP</code> بدون برش)</strong> را استخراج کرده و محصولات را یکی‌یکی و مرحله‌به‌مرحله در ووکامرس می‌سازد:</p>
                    <div style="margin:14px 0;">
                        <textarea id="shaghafi-create-prod-input" rows="4" placeholder="یک یا چند کد محصول (SKU) یا Product ID وارد کنید — مثلاً:&#10;5010034&#10;5050833&#10;RA20362" style="width:100%;max-width:680px;direction:ltr;font-family:monospace;padding:10px;border-radius:6px;border:1px solid #cbd5e1;"></textarea>
                        <div style="margin-top:10px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                            <button type="button" id="shaghafi-btn-create-full-product" class="button button-primary button-large">
                                ➕ شروع ساخت کامل محصول(ها) به صورت مرحله‌به‌مرحله (700×800 WebP)
                            </button>
                            <label style="font-weight:600;color:#1e293b;cursor:pointer;">
                                <input type="checkbox" id="shaghafi-create-refresh-feed" value="1" />
                                🔄 ابتدا آخرین فایل مرجع <code>likadeo.csv</code> از سرور شقفی دانلود و بروزرسانی شود
                            </label>
                        </div>
                    </div>
                    <div id="shaghafi-create-prod-result" style="margin-top:12px;"></div>
                </div>

                <div class="shaghafi-card" style="margin-top:20px;">
                    <h2>🔎 جستجو در دیتابیس مرجع <code>likadeo.csv</code> (۲۱,۷۵۹ ردیف) و تست زنده</h2>
                    <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;">
                        <input type="text" id="shaghafi-feed-search-input" placeholder="جستجوی SKU (مثلاً 5020141-003)، product_id یا ویژگی..." style="width:360px;" />
                        <button type="button" id="shaghafi-btn-search-feed" class="button button-secondary">جستجو در فید</button>
                    </div>
                    <table class="widefat striped shaghafi-table">
                        <thead>
                            <tr>
                                <th>SKU (artikelnummer)</th>
                                <th>کد پایه</th>
                                <th>ویژگی (variantenauspraegung)</th>
                                <th>موجودی فید (bestand)</th>
                                <th>قیمت زنده شقفی</th>
                                <th>عکس زنده</th>
                                <th>Product ID</th>
                                <th>Variant ID</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="shaghafi-feed-search-tbody">
                            <tr><td colspan="9" style="text-align:center;">عبارت مورد نظر را جستجو کنید.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 5: SETTINGS -->
            <div id="tab-settings" class="shaghafi-tab-panel" style="display:none;">
                <div class="shaghafi-card">
                    <h2>⚙️ تنظیمات پلاگین شقفی</h2>
                    <form method="post">
                        <?php wp_nonce_field( 'shaghafi_save_settings_action', 'shaghafi_save_settings_nonce' ); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">لینک فید روزانه شقفی (Store-API CSV)</th>
                                <td>
                                    <input type="url" name="feed_url" value="<?php echo esc_attr( isset( $settings['feed_url'] ) ? $settings['feed_url'] : Shaghafi_Feed::DEFAULT_FEED_URL ); ?>" class="large-text code" style="direction:ltr;" />
                                    <p class="description">لینک رسمی که روزانه توسط شقفی آپدیت می‌شود.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">ابعاد استاندارد عکس وریشن‌ها در سایت شما (بدون برش)</th>
                                <td>
                                    <label>عرض (Width): </label>
                                    <input type="number" name="target_img_width" value="<?php echo esc_attr( $dims['width'] ); ?>" style="width:90px;direction:ltr;" /> px
                                    <span style="margin:0 12px;">×</span>
                                    <label>ارتفاع (Height): </label>
                                    <input type="number" name="target_img_height" value="<?php echo esc_attr( $dims['height'] ); ?>" style="width:90px;direction:ltr;" /> px
                                    <p class="description">
                                        پیش‌فرض اختصاصی سایت شما: <strong>700 در 800 پیکسل</strong>. تمام عکس‌های دریافتی از شقفی یا موجود در گالری، <strong>بدون هیچ‌گونه برش (No Crop / Fit Inside)</strong> در مرکز بوم سفید <code>700×800</code> قرار می‌گیرند تا هیچ بخشی از محصول بریده نشود.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">منابع قیمت خرده‌فروشی و لینک مرجع (Retail Price Sources)</th>
                                <td>
                                    <div style="padding:10px 12px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;margin-bottom:10px;color:#14532d;">
                                        <strong>✅ محافظت ۱۰۰٪ قیمت فعال است:</strong> قیمت‌های عمده‌فروشی <code>shaghafi.de</code> (بدون مالیات B2B) <strong>هرگز</strong> روی محصولات اعمال نمی‌شوند!<br/>
                                        قیمت‌ها و لینک مستقیم هر محصول منحصراً از ۳ وب‌سایت رسمی خرده‌فروشی زیر استخراج و لینک‌دهی می‌شوند:
                                        <ul style="margin:6px 0 0 0;padding-right:18px;">
                                            <li><a href="https://akzentshop.de/" target="_blank"><code>https://akzentshop.de/</code></a> (فروشگاه رسمی AkzentShop)</li>
                                            <li><a href="https://meinakzent.de/" target="_blank"><code>https://meinakzent.de/</code></a> (فروشگاه رسمی MeinAkzent)</li>
                                            <li><a href="https://www.raptor-watches.com/" target="_blank"><code>https://www.raptor-watches.com/</code></a> (فروشگاه رسمی Raptor Watches)</li>
                                        </ul>
                                    </div>
                                    <label>ضریب قیمت (Price Multiplier): </label>
                                    <input type="number" step="0.01" name="price_multiplier" value="<?php echo esc_attr( isset( $settings['price_multiplier'] ) ? $settings['price_multiplier'] : '1' ); ?>" style="width:120px;direction:ltr;" />
                                    <span style="margin:0 10px;">+ مبلغ ثابت اضافی:</span>
                                    <input type="number" step="0.01" name="price_add_fixed" value="<?php echo esc_attr( isset( $settings['price_add_fixed'] ) ? $settings['price_add_fixed'] : '0' ); ?>" style="width:120px;direction:ltr;" />
                                    <p class="description">به صورت پیش‌فرض <code>1</code> است (یعنی دقیقاً همان قیمت خرده‌فروشی سایت‌های مرجع بالا مثل <code>29.99 €</code> یا <code>49.95 €</code> روی محصول قرار می‌گیرد).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">رفتار دکمه «غیرفعال‌سازی وریشن‌های ناموجود در فید» (DISABLE_MISSING)</th>
                                <td>
                                    <select name="disable_mode">
                                        <option value="private_and_zero" <?php selected( isset( $settings['disable_mode'] ) ? $settings['disable_mode'] : '', 'private_and_zero' ); ?>>تغییر وضعیت وریشن به خصوصی (غیرفعال) + صفر کردن موجودی</option>
                                        <option value="zero_stock_only" <?php selected( isset( $settings['disable_mode'] ) ? $settings['disable_mode'] : '', 'zero_stock_only' ); ?>>فقط صفر کردن موجودی (Out of Stock) و حفظ نمایش وریشن</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">تاکسونومی پیش‌فرض برای محصولات بدون ویژگی</th>
                                <td>
                                    <input type="text" name="default_attribute_taxonomy" value="<?php echo esc_attr( isset( $settings['default_attribute_taxonomy'] ) ? $settings['default_attribute_taxonomy'] : '' ); ?>" placeholder="مثلاً pa_variante یا خالی برای تشخیص خودکار" style="direction:ltr;" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">اتوماسیون و سینک خودکار پس‌زمینه (WP-Cron Auto-Pilot)</th>
                                <td>
                                    <div style="margin-bottom:10px;">
                                        <label>بازه زمانی بررسی خودکار لینک شقفی: </label>
                                        <select name="cron_interval">
                                            <option value="shaghafi_6hours" <?php selected( isset( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'daily', 'shaghafi_6hours' ); ?>>هر ۶ ساعت یک‌بار</option>
                                            <option value="twicedaily" <?php selected( isset( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'daily', 'twicedaily' ); ?>>هر ۱۲ ساعت یک‌بار (دو بار در روز)</option>
                                            <option value="daily" <?php selected( isset( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'daily', 'daily' ); ?>>روزانه (هر ۲۴ ساعت)</option>
                                        </select>
                                    </div>
                                    <p><label><input type="checkbox" name="cron_auto_stock" value="1" <?php checked( ! empty( $settings['cron_auto_stock'] ) ); ?> /> ۱. دانلود خودکار <code>likadeo.csv</code> از لینک شقفی و بروزرسانی موجودی (<code>bestand</code>) تمام وریشن‌ها</label></p>
                                    <p><label><input type="checkbox" name="cron_auto_repair" value="1" <?php checked( ! empty( $settings['cron_auto_repair'] ) ); ?> /> ۲. اتصال و تعمیر خودکار محصولاتی که تازه دستی یا با پلاگین ایمپورت وارد شده‌اند (ذخیره آیدی‌ها، اصلاح SKU و ویژگی)</label></p>
                                    <p><label><input type="checkbox" name="cron_auto_disable" value="1" <?php checked( ! empty( $settings['cron_auto_disable'] ) ); ?> /> ۳. غیرفعال‌سازی خودکار وریشن‌هایی که در فید جدید شقفی حذف شده‌اند (<code>DISABLE_MISSING</code>)</label></p>
                                    <p><label><input type="checkbox" name="cron_auto_create" value="1" <?php checked( ! empty( $settings['cron_auto_create'] ) ); ?> /> ۴. ساخت خودکار وریشن‌های جدیدی که شقفی به محصولات موجود اضافه کرده است (<code>CREATE_NEW_VARIATION</code>)</label></p>
                                    <?php
                                    $secret_key = get_option( 'shaghafi_cron_secret_key', '' );
                                    if ( empty( $secret_key ) ) {
                                        $secret_key = wp_generate_password( 24, false );
                                        update_option( 'shaghafi_cron_secret_key', $secret_key );
                                    }
                                    $webhook_url = admin_url( 'admin-ajax.php?action=shaghafi_cron_webhook&key=' . $secret_key );
                                    $next_cron   = wp_next_scheduled( 'shaghafi_daily_feed_sync_event' );
                                    $last_log    = get_option( 'shaghafi_last_cron_log', array() );
                                    ?>
                                    <div style="margin-top:12px;padding:12px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;">
                                        <strong>📊 وضعیت فعلی اتوماسیون پس‌زمینه:</strong><br/>
                                        <span>زمان اجرای خودکار بعدی (WP-Cron): <code><?php echo $next_cron ? esc_html( wp_date( 'Y-m-d H:i:s', $next_cron ) ) : 'ثبت نشده (ذخیره تنظیمات را بزنید)'; ?></code></span><br/>
                                        <?php if ( ! empty( $last_log['time'] ) ) : ?>
                                            <span>آخرین اجرای خودکار: <code><?php echo esc_html( $last_log['time'] ); ?></code> — <?php echo esc_html( $last_log['message'] ); ?></span><br/>
                                        <?php endif; ?>
                                        <span style="display:block;margin-top:6px;font-size:12px;color:#475569;">
                                            💡 لینک مستقیم Cron سرور (اختیاری - قابل قرار دادن در Cron Jobs سی‌پنل/هاست برای اجرای ۱۰۰٪ دقیق راس ساعت):<br/>
                                            <code style="direction:ltr;display:inline-block;margin-top:4px;user-select:all;"><?php echo esc_url( $webhook_url ); ?></code>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">اطلاعات ورود به حساب کاربری Shaghafi.de (اختیاری)</th>
                                <td>
                                    <input type="email" name="shaghafi_email" value="<?php echo esc_attr( isset( $settings['shaghafi_email'] ) ? $settings['shaghafi_email'] : '' ); ?>" placeholder="Email / Username" style="direction:ltr;width:240px;" />
                                    <input type="password" name="shaghafi_password" value="<?php echo esc_attr( isset( $settings['shaghafi_password'] ) ? $settings['shaghafi_password'] : '' ); ?>" placeholder="Password" style="direction:ltr;width:200px;" />
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" name="shaghafi_save_settings_submit" class="button button-primary button-large">💾 ذخیره تنظیمات</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}

Shaghafi_Sync_Repair_Plugin::init();
