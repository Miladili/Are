<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product Edit Page Meta Box & Per-Variation Custom Fields (_shaghafi_product_id, _shaghafi_variant_id)
 */
class Shaghafi_Product_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
        add_action( 'edit_form_top', array( __CLASS__, 'pre_heal_product_on_edit_screen' ), 5, 1 );
        add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 999, 2 );
        add_action( 'woocommerce_ajax_save_product_variations', array( __CLASS__, 'after_ajax_save_variations' ), 999, 1 );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_parent_fields' ), 999, 1 );
    }

    /**
     * Runs before WooCommerce renders #product_attributes (ویژگی‌ها) and #variable_product_options (متغیرها) on post.php
     * so linked variations with missing/case-mismatched attributes (e.g. post=35928 / 5050833) are healed before the dropdowns render!
     */
    public static function pre_heal_product_on_edit_screen( $post ) {
        if ( ! $post || 'product' !== $post->post_type || empty( $post->ID ) ) {
            return;
        }
        Shaghafi_DB::ensure_initialized();
        $parent_id = (int) $post->ID;
        if ( class_exists( 'Shaghafi_Repairer' ) ) {
            Shaghafi_Repairer::sync_clean_parent_attributes( $parent_id );
        }
    }

    public static function after_ajax_save_variations( $product_id ) {
        $product_id = (int) $product_id;
        if ( $product_id > 0 && class_exists( 'Shaghafi_Repairer' ) ) {
            Shaghafi_Repairer::sync_clean_parent_attributes( $product_id );
            if ( class_exists( 'WC_Product_Variable' ) ) {
                WC_Product_Variable::sync( $product_id );
            }
            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $product_id );
            }
        }
    }

    public static function register_meta_box() {
        add_meta_box(
            'shaghafi_sync_product_metabox',
            'پنل هوشمند عیب‌یابی، بررسی زنده و تعمیر شقفی (Shaghafi Sync & Repair)',
            array( __CLASS__, 'render_meta_box' ),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Whether the current admin user has unlocked the dedicated Shaghafi panel.
     */
    private static function panel_unlocked() {
        return ! class_exists( 'Shaghafi_Auth_Guard' ) || Shaghafi_Auth_Guard::is_authenticated();
    }

    /**
     * Render _shaghafi_product_id and _shaghafi_variant_id inside each WooCommerce variation panel
     */
    public static function render_variation_fields( $loop, $variation_data, $variation ) {
        // Hidden until the admin unlocks the dedicated Shaghafi panel.
        if ( ! self::panel_unlocked() ) {
            return;
        }
        $vid      = $variation->ID;
        $sh_pid   = get_post_meta( $vid, '_shaghafi_product_id', true );
        $sh_vid   = get_post_meta( $vid, '_shaghafi_variant_id', true );
        $feed_row = $sh_vid ? Shaghafi_DB::get_by_variant_id( $sh_vid ) : null;
        ?>
        <div class="shaghafi-variation-fields" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;padding:10px 12px;margin:10px 0;direction:rtl;text-align:right;">
            <strong style="display:block;margin-bottom:6px;color:#0f172a;">اطلاعات اتصال شقفی (Shaghafi Meta IDs):</strong>
            <p class="form-row form-row-first">
                <label>Shaghafi Product ID (<code>_shaghafi_product_id</code>)</label>
                <input type="text" name="shaghafi_var_pid[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $sh_pid ); ?>" placeholder="32-char UUID" style="direction:ltr;font-family:monospace;" />
            </p>
            <p class="form-row form-row-last">
                <label>Shaghafi Variant ID (<code>_shaghafi_variant_id</code>)</label>
                <input type="text" name="shaghafi_var_vid[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $sh_vid ); ?>" placeholder="32-char UUID" style="direction:ltr;font-family:monospace;" />
            </p>
            <?php if ( $feed_row ) : ?>
                <div style="clear:both;font-size:12px;color:#334155;padding-top:4px;">
                    <span><strong>SKU مرجع:</strong> <code><?php echo esc_html( $feed_row['artikelnummer'] ); ?></code></span> |
                    <span><strong>ویژگی مرجع:</strong> <code><?php echo esc_html( $feed_row['variantenauspraegung'] ?: '—' ); ?></code></span> |
                    <span><strong>موجودی فید:</strong> <code><?php echo (int) $feed_row['bestand']; ?></code></span>
                    <?php if ( null !== $feed_row['live_price'] ) : ?>
                        | <span><strong>قیمت زنده شقفی:</strong> <code><?php echo esc_html( $feed_row['live_price'] ); ?> €</code></span>
                    <?php endif; ?>
                    | <a href="<?php echo esc_url( 'https://shaghafi.de/detail/' . $feed_row['variant_id'] ); ?>" target="_blank" rel="noopener">مشاهده در Shaghafi.de ↗</a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function save_variation_fields( $variation_id, $i ) {
        $variation_id = (int) $variation_id;
        if ( isset( $_POST['shaghafi_var_pid'][ $i ] ) ) {
            update_post_meta( $variation_id, '_shaghafi_product_id', sanitize_text_field( wp_unslash( $_POST['shaghafi_var_pid'][ $i ] ) ) );
        }
        if ( isset( $_POST['shaghafi_var_vid'][ $i ] ) ) {
            update_post_meta( $variation_id, '_shaghafi_variant_id', sanitize_text_field( wp_unslash( $_POST['shaghafi_var_vid'][ $i ] ) ) );
        }

        // Prevent stale empty attribute_* dropdowns on post.php from clearing a linked variation's attribute
        $parent_id = (int) wp_get_post_parent_id( $variation_id );
        $saved_vid = trim( (string) get_post_meta( $variation_id, '_shaghafi_variant_id', true ) );
        if ( $parent_id > 0 && '' !== $saved_vid && class_exists( 'Shaghafi_Repairer' ) ) {
            $fv = Shaghafi_DB::get_by_variant_id( $saved_vid );
            if ( $fv && '' !== trim( (string) $fv['variantenauspraegung'] ) ) {
                $pref_lbl = ! empty( $fv['live_attr_name'] ) ? $fv['live_attr_name'] : 'Variante';
                Shaghafi_Repairer::repair_product_and_variation_attributes(
                    $parent_id,
                    $variation_id,
                    $fv['variantenauspraegung'],
                    $pref_lbl
                );
            }
        }
    }

    public static function save_parent_fields( $post_id ) {
        $post_id = (int) $post_id;
        if ( isset( $_POST['shaghafi_parent_product_id'] ) ) {
            $sh_pid = sanitize_text_field( wp_unslash( $_POST['shaghafi_parent_product_id'] ) );
            if ( '' !== $sh_pid ) {
                update_post_meta( $post_id, '_shaghafi_product_id', $sh_pid );
            }
        }

        $saved_pid = trim( (string) get_post_meta( $post_id, '_shaghafi_product_id', true ) );
        if ( '' !== $saved_pid ) {
            $feed_variants = Shaghafi_DB::get_variants_by_product_id( $saved_pid );
            if ( 1 === count( $feed_variants ) ) {
                $single_fv   = $feed_variants[0];
                $single_attr = trim( (string) $single_fv['variantenauspraegung'] );
                $children    = get_posts(
                    array(
                        'post_parent' => $post_id,
                        'post_type'   => 'product_variation',
                        'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
                        'numberposts' => -1,
                        'fields'      => 'ids',
                    )
                );
                if ( empty( $children ) || in_array( $single_attr, array( '', '1' ), true ) ) {
                    foreach ( $children as $cid ) {
                        wp_delete_post( (int) $cid, true );
                    }
                    wp_set_object_terms( $post_id, 'simple', 'product_type', false );
                    delete_post_meta( $post_id, '_product_attributes' );
                    update_post_meta( $post_id, '_shaghafi_variant_id', $single_fv['variant_id'] );
                    Shaghafi_Repairer::set_clean_sku( $post_id, $single_fv['artikelnummer'] );
                    clean_post_cache( $post_id );
                    wp_cache_delete( $post_id, 'post_meta' );
                    wc_delete_product_transients( $post_id );
                    return;
                }
            }
        }

        // For variable products, ensure parent attributes stay clean and in sync with child variations
        Shaghafi_Repairer::sync_clean_parent_attributes( $post_id );
    }

    /**
     * Render interactive diagnostic & 1-click repair box inside the WooCommerce Product Edit screen
     */
    public static function render_meta_box( $post ) {
        if ( ! self::panel_unlocked() ) {
            echo '<div dir="rtl" style="padding:12px 14px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;color:#92400e;line-height:1.9;">'
                . '🔒 اطلاعات پنل هوشمند شقفی قفل است. برای مشاهده عیب‌یابی، قیمت زنده و دکمه‌های تعمیر، ابتدا وارد '
                . '<a href="' . esc_url( add_query_arg( 'page', 'shaghafi-sync-repair', admin_url( 'admin.php' ) ) ) . '">پنل اختصاصی شقفی</a> شوید.'
                . '</div>';
            return;
        }
        Shaghafi_DB::ensure_initialized();
        $parent_id = (int) $post->ID;
        $saved_pid = trim( (string) get_post_meta( $parent_id, '_shaghafi_product_id', true ) );
        $rows      = Shaghafi_Scanner::scan_single_product( $parent_id );
        $resolved  = Shaghafi_Scanner::resolve_parent_shaghafi_identity( $parent_id );
        $eff_pid   = $resolved ? $resolved['product_id'] : $saved_pid;
        ?>
        <div class="shaghafi-wrap shaghafi-metabox-wrap" dir="rtl" data-parent-id="<?php echo esc_attr( $parent_id ); ?>">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;background:#f1f5f9;padding:12px 15px;border-radius:8px;margin-bottom:14px;">
                <div>
                    <label for="shaghafi_parent_product_id" style="font-weight:700;">شناسه محصول مادر در شقفی (<code>_shaghafi_product_id</code>):</label>
                    <input type="text" id="shaghafi_parent_product_id" name="shaghafi_parent_product_id" value="<?php echo esc_attr( $eff_pid ); ?>" style="width:290px;direction:ltr;font-family:monospace;margin-right:8px;" placeholder="مثلاً 68b953fd2ed84d88bcda5d1778f02e6e" />
                    <?php if ( $eff_pid ) : ?>
                        <a href="<?php echo esc_url( 'https://shaghafi.de/detail/' . $eff_pid ); ?>" target="_blank" class="button button-small" style="margin-right:6px;">مشاهده محصول در Shaghafi.de ↗</a>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="button button-secondary shaghafi-btn-live-product" data-parent-id="<?php echo esc_attr( $parent_id ); ?>" data-product-id="<?php echo esc_attr( $eff_pid ); ?>">
                        🔍 بررسی زنده قیمت، موجودی و عکس از Shaghafi.de
                    </button>
                    <button type="button" class="button button-primary shaghafi-btn-repair-product-all" data-parent-id="<?php echo esc_attr( $parent_id ); ?>">
                        🛠 تعمیر و همگام‌سازی کامل همه وریشن‌های این محصول (عکس 700×800 بدون برش)
                    </button>
                </div>
            </div>

            <div class="shaghafi-metabox-results">
                <?php self::render_product_rows_table( $rows, $parent_id ); ?>
            </div>
        </div>
        <?php
    }

    public static function render_product_rows_table( $rows, $parent_id ) {
        if ( empty( $rows ) ) {
            echo '<p>هیچ اطلاعاتی برای این محصول یافت نشد.</p>';
            return;
        }
        ?>
        <table class="widefat striped shaghafi-table" style="direction:rtl;text-align:right;">
            <thead>
                <tr>
                    <th>وضعیت / اکشن</th>
                    <th>عکس (سایت 700×800 / شقفی)</th>
                    <th>SKU (فعلی ← مرجع)</th>
                    <th>ویژگی (فعلی ← مرجع)</th>
                    <th>موجودی (فعلی ← فید / زنده)</th>
                    <th>قیمت (فعلی ← شقفی)</th>
                    <th>شناسه Variant ID شقفی</th>
                    <th>مشکلات تشخیص داده شده</th>
                    <th>عملیات سریع</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $r ) : ?>
                    <tr data-row-id="<?php echo esc_attr( $r['row_id'] ); ?>">
                        <td>
                            <span class="shaghafi-badge shaghafi-badge-<?php echo esc_attr( strtolower( $r['action'] ) ); ?>">
                                <?php echo esc_html( $r['action'] ); ?>
                            </span>
                            <div style="font-size:11px;color:#475569;margin-top:3px;" dir="ltr">
                                <?php echo $r['variation_id'] ? 'Variation #' . (int) $r['variation_id'] : 'Parent #' . (int) $r['parent_id']; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ( ! empty( $r['is_size_variation'] ) ) : ?>
                                <span style="display:inline-block;padding:4px 8px;background:#e0f2fe;color:#0369a1;font-size:11px;font-weight:600;border-radius:4px;border:1px solid #bae6fd;">
                                    📏 وریشن سایزی (بدون نیاز به عکس مجزا)
                                </span>
                            <?php else : ?>
                                <div style="display:flex;gap:6px;align-items:center;">
                                    <?php if ( ! empty( $r['woo_image_url'] ) ) : ?>
                                        <div style="text-align:center;">
                                            <a href="<?php echo esc_url( $r['woo_image_url'] ); ?>" target="_blank">
                                                <img src="<?php echo esc_url( $r['woo_image_url'] ); ?>" style="width:49px;height:56px;object-fit:contain;background:#fff;border-radius:4px;border:1px solid #cbd5e1;" title="عکس فعلی در وردپرس (بدون برش)" />
                                            </a>
                                            <?php if ( ! empty( $r['woo_image_dims'] ) ) : ?>
                                                <div style="font-size:10px;direction:ltr;color:<?php echo ! empty( $r['woo_image_dims_ok'] ) ? '#16a34a' : '#dc2626'; ?>;font-weight:700;">
                                                    <?php echo esc_html( $r['woo_image_dims'] . ( ! empty( $r['woo_image_dims_ok'] ) ? ' ✓' : ' ⚠' ) ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else : ?>
                                        <span style="display:inline-block;width:49px;height:56px;background:#fee2e2;color:#991b1b;font-size:10px;text-align:center;line-height:18px;padding-top:10px;box-sizing:border-box;border-radius:4px;border:1px solid #fecaca;">بدون عکس<br/>(حذف شده)</span>
                                    <?php endif; ?>
                                    <?php if ( empty( $r['woo_image_url'] ) && ! empty( $r['local_gallery_match']['url'] ) ) : ?>
                                        <span>←</span>
                                        <div style="text-align:center;">
                                            <a href="<?php echo esc_url( $r['local_gallery_match']['url'] ); ?>" target="_blank">
                                                <img src="<?php echo esc_url( $r['local_gallery_match']['url'] ); ?>" style="width:49px;height:56px;object-fit:contain;background:#fff;border-radius:4px;border:2px solid #2563eb;" title="موجود در گالری محصول (آماده بازیابی)" />
                                            </a>
                                            <div style="font-size:10px;color:#1d4ed8;font-weight:700;">گالری محصول</div>
                                        </div>
                                    <?php elseif ( ! empty( $r['live_image_url'] ) ) : ?>
                                        <span>←</span>
                                        <a href="<?php echo esc_url( $r['live_image_url'] ); ?>" target="_blank">
                                            <img src="<?php echo esc_url( $r['live_image_url'] ); ?>" style="width:49px;height:56px;object-fit:contain;background:#fff;border-radius:4px;border:2px solid #22c55e;" title="عکس اصلی در Shaghafi.de" />
                                        </a>
                                    <?php elseif ( ! empty( $r['local_gallery_match']['url'] ) ) : ?>
                                        <span>←</span>
                                        <img src="<?php echo esc_url( $r['local_gallery_match']['url'] ); ?>" style="width:49px;height:56px;object-fit:contain;background:#fff;border-radius:4px;border:2px solid #3b82f6;" title="یافت شده در گالری وردپرس" />
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="direction:ltr;text-align:left;font-family:monospace;font-size:12px;">
                            <div>فعلی: <strong><?php echo esc_html( $r['woo_sku'] ?: '—' ); ?></strong></div>
                            <div style="color:#0284c7;">مرجع: <strong><?php echo esc_html( $r['feed_sku'] ?: '—' ); ?></strong></div>
                        </td>
                        <td>
                            <div>فعلی: <strong><?php echo esc_html( $r['woo_attr'] ?: '—' ); ?></strong></div>
                            <div style="color:#0284c7;">مرجع: <strong><?php echo esc_html( $r['feed_attr'] ?: '—' ); ?></strong></div>
                        </td>
                        <td>
                            <div>فعلی: <strong><?php echo null !== $r['woo_stock'] ? (int) $r['woo_stock'] : '—'; ?></strong></div>
                            <div style="color:#15803d;">فید: <strong><?php echo null !== $r['feed_stock'] ? (int) $r['feed_stock'] : '—'; ?></strong>
                                <?php if ( null !== $r['live_stock'] ) : ?>
                                    | زنده: <strong><?php echo (int) $r['live_stock']; ?></strong>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div>فعلی سایت: <strong><?php echo esc_html( '' !== (string) $r['woo_price'] ? $r['woo_price'] : '—' ); ?></strong></div>
                            <?php if ( null !== $r['live_price'] && '' !== (string) $r['live_price'] ) : ?>
                                <div style="color:#7c3aed;font-weight:700;">
                                    مرجع<?php echo ! empty( $r['retail_source'] ) ? ' (' . esc_html( $r['retail_source'] ) . ')' : ''; ?>:
                                    <strong><?php echo esc_html( $r['live_price'] . ' €' ); ?></strong>
                                </div>
                            <?php elseif ( ! empty( $r['live_checked'] ) ) : ?>
                                <div style="font-size:11px;color:#15803d;font-weight:600;">✓ بررسی زنده انجام شد (قیمت فعلی سایت حفظ شد)</div>
                            <?php else : ?>
                                <div style="font-size:11px;color:#d97706;font-weight:600;">⏳ در صف بررسی زنده خودکار قیمت از ۳ سایت مرجع</div>
                            <?php endif; ?>
                            <?php if ( ! empty( $r['retail_links'] ) && is_array( $r['retail_links'] ) ) : ?>
                                <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:3px;">
                                    <?php foreach ( $r['retail_links'] as $lnk ) : ?>
                                        <a href="<?php echo esc_url( $lnk['url'] ); ?>" target="_blank" style="display:inline-block;padding:1px 5px;border-radius:3px;font-size:10px;text-decoration:none;background:#ede9fe;color:#5b21b6;border:1px solid #cbd5e1;">
                                            🔗 <?php echo esc_html( $lnk['label'] ); ?> ↗
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="direction:ltr;text-align:left;font-family:monospace;font-size:11px;">
                            <div>PID: <?php echo esc_html( $r['woo_product_id_meta'] ? substr( $r['woo_product_id_meta'], 0, 10 ) . '…' : '—' ); ?></div>
                            <div>VID: <?php echo esc_html( $r['woo_variant_id_meta'] ? substr( $r['woo_variant_id_meta'], 0, 10 ) . '…' : '—' ); ?></div>
                        </td>
                        <td>
                            <?php if ( empty( $r['issues'] ) ) : ?>
                                <span style="color:#15803d;font-weight:600;">✓ تمام موارد صحیح و منطبق با مرجع است</span>
                            <?php else : ?>
                                <ul style="margin:0;padding-right:14px;font-size:12px;color:#b91c1c;">
                                    <?php foreach ( $r['issues'] as $iss ) : ?>
                                        <li><?php echo esc_html( $iss['text'] ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( 'CREATE_NEW_VARIATION' === $r['action'] ) : ?>
                                <button type="button" class="button button-primary button-small shaghafi-btn-create-var"
                                    data-parent-id="<?php echo esc_attr( $r['parent_id'] ); ?>"
                                    data-feed-vid="<?php echo esc_attr( $r['feed_variant_id'] ); ?>">
                                    ➕ افزودن وریشن
                                </button>
                            <?php elseif ( 'DISABLE_MISSING' === $r['action'] ) : ?>
                                <button type="button" class="button button-small shaghafi-btn-disable-var"
                                    style="background:#dc2626;color:#fff;border-color:#b91c1c;"
                                    data-variation-id="<?php echo esc_attr( $r['variation_id'] ); ?>">
                                    🗑️ حذف کامل و دائمی
                                </button>
                            <?php elseif ( 'MANUAL_REVIEW' === $r['action'] && ! empty( $r['candidate_variants'] ) ) : ?>
                                <select class="shaghafi-manual-select" style="max-width:140px;font-size:11px;margin-bottom:4px;">
                                    <option value="">انتخاب وریشن مرجع...</option>
                                    <?php foreach ( $r['candidate_variants'] as $cand ) : ?>
                                        <option value="<?php echo esc_attr( $cand['variant_id'] ); ?>">
                                            <?php echo esc_html( $cand['artikelnummer'] . ' (' . $cand['variantenauspraegung'] . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button button-primary button-small shaghafi-btn-manual-link"
                                    data-parent-id="<?php echo esc_attr( $r['parent_id'] ); ?>"
                                    data-variation-id="<?php echo esc_attr( $r['variation_id'] ); ?>">
                                    🔗 اتصال و تعمیر
                                </button>
                            <?php elseif ( 'MANUAL_REVIEW' !== $r['action'] ) : ?>
                                <button type="button" class="button button-primary button-small shaghafi-btn-repair-row"
                                    data-parent-id="<?php echo esc_attr( $r['parent_id'] ); ?>"
                                    data-variation-id="<?php echo esc_attr( $r['variation_id'] ); ?>"
                                    data-feed-vid="<?php echo esc_attr( $r['feed_variant_id'] ); ?>">
                                    🔧 اصلاح طبق مرجع
                                </button>
                                <?php if ( empty( $r['is_size_variation'] ) ) : ?>
                                    <button type="button" class="button button-small shaghafi-btn-force-image"
                                        style="margin-top:4px;"
                                        title="تبدیل/دریافت عکس با ابعاد 700×800 بدون برش"
                                        data-parent-id="<?php echo esc_attr( $r['parent_id'] ); ?>"
                                        data-variation-id="<?php echo esc_attr( $r['variation_id'] ); ?>"
                                        data-feed-vid="<?php echo esc_attr( $r['feed_variant_id'] ); ?>">
                                        🖼 دریافت/اصلاح عکس (700×800)
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
