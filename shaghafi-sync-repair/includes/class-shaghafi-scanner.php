<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deep WooCommerce vs. likadeo.csv + Live Shaghafi.de Diagnostic Scanner
 * Includes:
 * - Pagination & Fast Scan Cache
 * - 700x800 Uncropped Image Dimension Validator
 * - Obsolete / Removed from Shaghafi Product Detector
 */
class Shaghafi_Scanner {

    const SCAN_CACHE_KEY     = 'shaghafi_catalog_scan_cache_v291_fix';
    const OBSOLETE_CACHE_KEY = 'shaghafi_obsolete_scan_cache_v291_fix';

    /**
     * Get configured target image dimensions (default: 700 x 800)
     */
    public static function get_target_image_dimensions() {
        $settings = get_option( 'shaghafi_sync_settings', array() );
        $w        = isset( $settings['target_img_width'] ) && (int) $settings['target_img_width'] > 0
            ? (int) $settings['target_img_width']
            : 700;
        $h        = isset( $settings['target_img_height'] ) && (int) $settings['target_img_height'] > 0
            ? (int) $settings['target_img_height']
            : 800;
        return array(
            'width'  => $w,
            'height' => $h,
        );
    }

    /**
     * Verify whether an attachment's current `_wp_attached_file` physically exists on disk right now
     * and is a valid readable image file (never trusting stale DB metadata when the file is 404/deleted).
     * When $check_admin_subsizes is true, also verifies that 'medium'/'thumbnail' subsize files exist on disk
     * so WP Admin's Featured Image (#postimagediv) box is never blank/broken.
     */
    public static function is_attachment_currently_valid_on_disk( $attachment_id, $check_admin_subsizes = false ) {
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 || ! get_post( $attachment_id ) ) {
            return false;
        }
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) || filesize( $file ) <= 64 ) {
            return false;
        }
        $info = @getimagesize( $file );
        if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
            return false;
        }

        if ( $check_admin_subsizes ) {
            $meta = wp_get_attachment_metadata( $attachment_id );
            if ( ! is_array( $meta ) || empty( $meta['file'] ) || basename( (string) $meta['file'] ) !== basename( $file ) ) {
                return false;
            }
            if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                $dir = dirname( $file );
                foreach ( array( 'medium', 'thumbnail', 'woocommerce_thumbnail' ) as $sz_key ) {
                    if ( ! empty( $meta['sizes'][ $sz_key ]['file'] ) ) {
                        $sub_path = trailingslashit( $dir ) . $meta['sizes'][ $sz_key ]['file'];
                        if ( ! file_exists( $sub_path ) || filesize( $sub_path ) <= 64 ) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Check whether the Parent Product's Featured Image (تصویر شاخص) is set and 100% healthy on disk
     * (including its WP Admin preview subsize).
     */
    public static function is_parent_featured_image_healthy( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return false;
        }
        $p_thumb = (int) get_post_meta( $parent_id, '_thumbnail_id', true );
        if ( $p_thumb <= 0 ) {
            return false;
        }
        return self::is_attachment_currently_valid_on_disk( $p_thumb, true );
    }

    /**
     * Inspect an attachment in the Product Gallery / Media Library:
     * Returns usable info if either:
     *  (a) its current `_wp_attached_file` exists on disk right now, OR
     *  (b) its `.webp`/`.jpg`/`.png` sibling or subsize thumbnail exists on disk (after a DB backup restore)
     *      so `Shaghafi_Repairer` can heal & restore it from the gallery!
     */
    public static function get_usable_or_healable_attachment_info( $attachment_id, $sku = '', $image_hash = '' ) {
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 || ! get_post( $attachment_id ) ) {
            return null;
        }

        if ( self::is_attachment_currently_valid_on_disk( $attachment_id, false ) ) {
            return array(
                'attachment_id' => $attachment_id,
                'valid_now'     => true,
                'healable'      => true,
                'url'           => wp_get_attachment_url( $attachment_id ),
                'dims'          => self::get_attachment_dimensions( $attachment_id ),
            );
        }

        // Check if a sibling .webp/.jpg/.png or subsize exists on disk that can be healed on repair
        if ( class_exists( 'Shaghafi_Repairer' ) && method_exists( 'Shaghafi_Repairer', 'find_healable_file_for_attachment' ) ) {
            $heal_info = Shaghafi_Repairer::find_healable_file_for_attachment( $attachment_id, $sku, $image_hash );
            if ( $heal_info && ! empty( $heal_info['file_path'] ) ) {
                return array(
                    'attachment_id' => $attachment_id,
                    'valid_now'     => false,
                    'healable'      => true,
                    'url'           => ! empty( $heal_info['url'] ) ? $heal_info['url'] : wp_get_attachment_url( $attachment_id ),
                    'dims'          => ! empty( $heal_info['dims'] ) ? $heal_info['dims'] : null,
                );
            }
        }

        return null;
    }

    /**
     * Get actual pixel dimensions (width x height) of a WordPress attachment.
     * NEVER returns dimensions from stale DB `_wp_attachment_metadata` if the physical file on disk is missing/404!
     */
    public static function get_attachment_dimensions( $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 ) {
            return null;
        }
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) || filesize( $file ) <= 64 ) {
            return null;
        }
        $info = @getimagesize( $file );
        if ( $info && ! empty( $info[0] ) && ! empty( $info[1] ) ) {
            return array(
                'width'  => (int) $info[0],
                'height' => (int) $info[1],
            );
        }
        return null;
    }

    /**
     * Calculate expected store price from raw Shaghafi EUR price based on plugin settings
     */
    public static function calculate_target_price( $raw_shaghafi_price ) {
        if ( null === $raw_shaghafi_price || '' === (string) $raw_shaghafi_price ) {
            return null;
        }
        $price = (float) str_replace( ',', '.', (string) $raw_shaghafi_price );
        if ( $price <= 0 ) {
            return null;
        }
        $settings   = get_option( 'shaghafi_sync_settings', array() );
        $multiplier = isset( $settings['price_multiplier'] ) && '' !== (string) $settings['price_multiplier']
            ? (float) $settings['price_multiplier']
            : 1.0;
        $add_fixed  = isset( $settings['price_add_fixed'] ) && '' !== (string) $settings['price_add_fixed']
            ? (float) $settings['price_add_fixed']
            : 0.0;
        $decimals   = isset( $settings['price_decimals'] ) && '' !== (string) $settings['price_decimals']
            ? (int) $settings['price_decimals']
            : 2;

        $final = ( $price * $multiplier ) + $add_fixed;
        return round( $final, $decimals );
    }

    /**
     * Extract normalized variant suffix from an attachment filename/title if it contains base_sku + [-_] + suffix
     * Supports both hyphen (image_RA20340-008_1.png) and underscore (RA20340_007-01ojby.png)
     */
    public static function extract_sku_suffix_from_attachment( $attachment_id, $base_sku ) {
        $attachment_id = (int) $attachment_id;
        $base_sku      = trim( (string) $base_sku );
        if ( $attachment_id <= 0 || '' === $base_sku ) {
            return '';
        }

        $file     = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        $title    = (string) get_the_title( $attachment_id );
        $haystack = basename( $file ) . ' ' . $title;

        if ( preg_match( '/(?<![0-9a-zA-Z])' . preg_quote( $base_sku, '/' ) . '[\-_]([0-9]{1,5})(?![0-9])/i', $haystack, $m ) ) {
            return Shaghafi_DB::normalize_attr( $m[1] );
        }
        return '';
    }

    /**
     * Check whether a variation's current _thumbnail_id ($thumb_id) is a shared duplicate image
     * across multiple sibling variations (e.g. Parent #35928 / 5050833 where #45801, #45803, #45805 all share dds.webp)
     * as opposed to a unique per-variation image (e.g. Parent #42302 / 5010034 where #42306=A, #42308=B, #42310=C each have their own distinct _thumbnail_id).
     */
    public static function is_variation_thumbnail_shared_duplicate( $parent_id, $variation_id, $thumb_id, $fv ) {
        global $wpdb;
        $parent_id    = (int) $parent_id;
        $variation_id = (int) $variation_id;
        $thumb_id     = (int) $thumb_id;

        if ( $parent_id <= 0 || $variation_id <= 0 || $variation_id === $parent_id || $thumb_id <= 0 || empty( $fv['live_image_url'] ) ) {
            return false;
        }

        $expected_hash = Shaghafi_Live_Scraper::extract_image_hash( $fv['live_image_url'] );
        if ( '' === $expected_hash ) {
            return false;
        }

        $current_hash = strtolower( trim( (string) get_post_meta( $thumb_id, '_shaghafi_media_hash', true ) ) );
        if ( '' !== $current_hash && $current_hash === $expected_hash ) {
            return false;
        }
        $img_suff_cur = ! empty( $fv['base_sku'] ) ? self::extract_sku_suffix_from_attachment( $thumb_id, $fv['base_sku'] ) : '';
        if ( '' !== $img_suff_cur && ! empty( $fv['sku_suffix_norm'] ) && $img_suff_cur === $fv['sku_suffix_norm'] ) {
            return false;
        }

        // Verify that Shaghafi actually has distinct live_image_url hashes across variants of this product
        if ( ! empty( $fv['product_id'] ) ) {
            $all_fvs     = Shaghafi_DB::get_variants_by_product_id( $fv['product_id'] );
            $live_hashes = array();
            foreach ( $all_fvs as $afv ) {
                if ( ! empty( $afv['live_image_url'] ) ) {
                    $h = Shaghafi_Live_Scraper::extract_image_hash( $afv['live_image_url'] );
                    if ( '' !== $h ) {
                        $live_hashes[ $h ] = true;
                    }
                }
            }
            if ( count( $live_hashes ) <= 1 ) {
                return false;
            }
        }

        // Count how many active child variations of $parent_id share this exact $thumb_id
        $shared_sib_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
                 WHERE p.post_parent = %d
                   AND p.post_type = 'product_variation'
                   AND p.post_status IN ('publish', 'private', 'draft', 'pending')
                   AND pm.meta_value = %s",
                $parent_id,
                (string) $thumb_id
            )
        );

        // Only treat as a shared duplicate if 2 or more sibling variations of this parent share the exact same _thumbnail_id!
        if ( $shared_sib_count > 1 ) {
            return true;
        }

        return false;
    }

    /**
     * Check whether a single attribute option value represents a Size / Length / Width / Ring-size
     * (e.g. "45 cm", "50 cm", "18 mm", "52", "54", "56", "XL", etc.)
     */
    public static function is_size_attribute_value( $val ) {
        $val = trim( (string) $val );
        if ( '' === $val ) {
            return false;
        }
        // 1. Contains length/width/diameter units (cm, mm, m, zoll, inch, ø)
        if ( preg_match( '/\b\d+([.,]\d+)?\s*(cm|mm|m|zoll|inch)\b/iu', $val ) || strpos( $val, 'Ø' ) !== false || strpos( $val, 'ø' ) !== false ) {
            return true;
        }
        // 2. Ring sizes (2-digit numbers from 44 to 76, e.g. 48, 50, 52, 54, 56, 58, 60, 62, 64, 66, 68, 70, 72)
        if ( preg_match( '/^\d{2}$/', $val ) ) {
            $num = (int) $val;
            if ( $num >= 44 && $num <= 76 ) {
                return true;
            }
        }
        // 3. Standard letter sizes (XS, S, M, L, XL, XXL, etc.)
        if ( preg_match( '/^(XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|ONE\s*SIZE)$/i', $val ) ) {
            return true;
        }
        return false;
    }

    /**
     * Determine whether a Variable Product's variations represent Sizes/Lengths (which share 1 main product image/gallery
     * and must NOT have individual variation images or trigger variation image warnings).
     */
    public static function is_size_based_product( $parent_id, $feed_variants = array(), $attr_val = '' ) {
        if ( self::is_size_attribute_value( $attr_val ) ) {
            return true;
        }

        if ( ! empty( $feed_variants ) && is_array( $feed_variants ) ) {
            foreach ( $feed_variants as $fv ) {
                if ( ! empty( $fv['variantenauspraegung'] ) && self::is_size_attribute_value( $fv['variantenauspraegung'] ) ) {
                    return true;
                }
                if ( ! empty( $fv['live_attr_name'] ) && preg_match( '/(gr[oö]ße|groesse|size|l[aä]nge|laenge|ringgr|armbandl|durchmesser|breite|سایز|اندازه|طول|عرض|قطر)/iu', (string) $fv['live_attr_name'] ) ) {
                    return true;
                }
            }
        }

        $parent_id = (int) $parent_id;
        if ( $parent_id > 0 ) {
            $parent_attrs = get_post_meta( $parent_id, '_product_attributes', true );
            if ( is_array( $parent_attrs ) ) {
                foreach ( $parent_attrs as $k => $cfg ) {
                    if ( empty( $cfg['is_variation'] ) ) {
                        continue;
                    }
                    $label = (string) $k . ' ' . ( isset( $cfg['name'] ) ? (string) $cfg['name'] : '' );
                    if ( preg_match( '/(gr[oö]ße|groesse|size|l[aä]nge|laenge|ringgr|armbandl|durchmesser|breite|سایز|اندازه|طول|عرض|قطر)/iu', $label ) ) {
                        return true;
                    }
                    if ( ! empty( $cfg['value'] ) ) {
                        foreach ( explode( '|', (string) $cfg['value'] ) as $opt ) {
                            if ( self::is_size_attribute_value( $opt ) ) {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Find a matching attachment ID in WordPress Media Library / Parent Gallery / Sibling Variations / WCBoost Swatches.
     * Supports both currently valid attachments AND attachments whose .webp/.jpg/.png file or subsize exists on disk
     * after a database backup restore!
     */
    public static function find_local_wp_image_for_variant( $parent_id, $sku, $variant_id = '', $live_image_url = '', $option_val = '' ) {
        global $wpdb;

        $image_hash  = Shaghafi_Live_Scraper::extract_image_hash( $live_image_url );
        $base_sku    = Shaghafi_DB::extract_base_sku( $sku );
        $target_suff = Shaghafi_DB::extract_sku_suffix_norm( $sku );
        $opt_norm    = Shaghafi_DB::normalize_attr( $option_val );
        $sku_clean   = strtolower( trim( (string) $sku ) );
        $sku_under   = str_replace( '-', '_', $sku_clean );

        // 1. Check by _shaghafi_media_hash first (100% exact cryptographic match to Shaghafi's variant image)
        if ( '' !== $image_hash ) {
            $att_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                     WHERE (pm.meta_key = '_shaghafi_media_hash' AND pm.meta_value = %s)
                        OR (pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s)
                     ORDER BY pm.post_id DESC
                     LIMIT 1",
                    $image_hash,
                    '%' . $wpdb->esc_like( $image_hash ) . '%'
                )
            );
            if ( $att_id > 0 ) {
                $att_info = self::get_usable_or_healable_attachment_info( $att_id, $sku, $image_hash );
                if ( $att_info ) {
                    return array(
                        'attachment_id' => $att_id,
                        'url'           => $att_info['url'],
                        'dims'          => $att_info['dims'],
                        'source'        => 'wp_media_hash',
                    );
                }
            }
        }

        // 2. Check by _shaghafi_variant_id in postmeta (only if hash matches or filename has variant SKU when image_hash is known)
        if ( '' !== $variant_id ) {
            $att_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                     WHERE pm.meta_key = '_shaghafi_variant_id' AND pm.meta_value = %s
                     ORDER BY pm.post_id DESC
                     LIMIT 5",
                    $variant_id
                )
            );
            foreach ( $att_ids as $cand_aid ) {
                $att_id = (int) $cand_aid;
                if ( $att_id <= 0 ) {
                    continue;
                }
                $att_hash = strtolower( trim( (string) get_post_meta( $att_id, '_shaghafi_media_hash', true ) ) );
                $att_file = strtolower( (string) get_post_meta( $att_id, '_wp_attached_file', true ) );
                $att_suff = self::extract_sku_suffix_from_attachment( $att_id, $base_sku );
                if ( '' !== $image_hash ) {
                    $hash_ok = ( $att_hash === $image_hash || strpos( $att_file, $image_hash ) !== false );
                    $sku_ok  = ( '' === $att_hash && '' !== $sku_clean && ( strpos( $att_file, $sku_clean ) !== false || strpos( $att_file, $sku_under ) !== false || ( '' !== $target_suff && $att_suff === $target_suff ) ) );
                    if ( ! $hash_ok && ! $sku_ok ) {
                        continue;
                    }
                }
                if ( '' !== $att_suff && '' !== $target_suff && $att_suff !== $target_suff ) {
                    continue;
                }
                $att_info = self::get_usable_or_healable_attachment_info( $att_id, $sku, $image_hash );
                if ( $att_info ) {
                    return array(
                        'attachment_id' => $att_id,
                        'url'           => $att_info['url'],
                        'dims'          => $att_info['dims'],
                        'source'        => 'wp_meta_variant_id',
                    );
                }
            }
        }

        // 3. Check Parent Product Gallery (_product_image_gallery), Child Variations' Thumbnails, WCBoost Swatches & Child Attachments
        $candidate_ids      = array();
        $parent_gallery_ids = array();
        $p_thumb            = 0;
        if ( $parent_id > 0 ) {
            $gallery_raw = (string) get_post_meta( $parent_id, '_product_image_gallery', true );
            if ( '' !== $gallery_raw ) {
                foreach ( explode( ',', $gallery_raw ) as $gid ) {
                    $gid = (int) trim( $gid );
                    if ( $gid > 0 ) {
                        $candidate_ids[]      = $gid;
                        $parent_gallery_ids[] = $gid;
                    }
                }
            }
            $p_thumb = (int) get_post_meta( $parent_id, '_thumbnail_id', true );
            if ( $p_thumb > 0 ) {
                $candidate_ids[]      = $p_thumb;
                $parent_gallery_ids[] = $p_thumb;
            }
            // Include all child variations' thumbnails on this parent product
            $sib_vars = get_posts(
                array(
                    'post_parent' => $parent_id,
                    'post_type'   => 'product_variation',
                    'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
                    'numberposts' => -1,
                    'fields'      => 'ids',
                )
            );
            foreach ( $sib_vars as $svid ) {
                $st = (int) get_post_meta( (int) $svid, '_thumbnail_id', true );
                if ( $st > 0 ) {
                    $candidate_ids[] = $st;
                }
            }
            // Include all swatch image IDs stored in wcboost_variation_swatches on this parent product
            $swatches_meta = get_post_meta( $parent_id, 'wcboost_variation_swatches', true );
            if ( is_array( $swatches_meta ) ) {
                $sw_img_counts = array();
                foreach ( $swatches_meta as $sw_cfg ) {
                    if ( ! empty( $sw_cfg['swatches'] ) && is_array( $sw_cfg['swatches'] ) ) {
                        foreach ( $sw_cfg['swatches'] as $sw_item ) {
                            if ( ! empty( $sw_item['image'] ) && (int) $sw_item['image'] > 0 ) {
                                $sid = (int) $sw_item['image'];
                                $sw_img_counts[ $sid ] = isset( $sw_img_counts[ $sid ] ) ? $sw_img_counts[ $sid ] + 1 : 1;
                            }
                        }
                    }
                }
                foreach ( $swatches_meta as $sw_cfg ) {
                    if ( ! empty( $sw_cfg['swatches'] ) && is_array( $sw_cfg['swatches'] ) ) {
                        foreach ( $sw_cfg['swatches'] as $sw_key => $sw_item ) {
                            if ( ! empty( $sw_item['image'] ) && (int) $sw_item['image'] > 0 ) {
                                $sw_img_id       = (int) $sw_item['image'];
                                $candidate_ids[] = $sw_img_id;
                                // Only trust wcboost_swatch_slot if it is NOT a shared duplicate image across multiple slots
                                // AND (when image_hash is known) its hash or SKU matches this variant!
                                $is_shared_sw = ( isset( $sw_img_counts[ $sw_img_id ] ) && $sw_img_counts[ $sw_img_id ] > 1 );
                                if ( ! $is_shared_sw && '' !== $option_val && ( (string) $sw_key === (string) $option_val || sanitize_title( (string) $sw_key ) === sanitize_title( (string) $option_val ) ) ) {
                                    $sw_hash = strtolower( trim( (string) get_post_meta( $sw_img_id, '_shaghafi_media_hash', true ) ) );
                                    $sw_suff = self::extract_sku_suffix_from_attachment( $sw_img_id, $base_sku );
                                    $sw_ok   = ( '' === $image_hash )
                                        ? ( '' === $sw_suff || '' === $target_suff || $sw_suff === $target_suff )
                                        : ( $sw_hash === $image_hash || ( '' === $sw_hash && '' !== $target_suff && $sw_suff === $target_suff ) );
                                    if ( $sw_ok ) {
                                        $sw_info = self::get_usable_or_healable_attachment_info( $sw_img_id, $sku, $image_hash );
                                        if ( $sw_info ) {
                                            return array(
                                                'attachment_id' => $sw_img_id,
                                                'url'           => $sw_info['url'],
                                                'dims'          => $sw_info['dims'],
                                                'source'        => 'wcboost_swatch_slot',
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $child_atts = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'attachment'",
                    $parent_id
                )
            );
            foreach ( $child_atts as $cid ) {
                $candidate_ids[]      = (int) $cid;
                $parent_gallery_ids[] = (int) $cid;
            }
        }
        $candidate_ids      = array_values( array_unique( array_filter( $candidate_ids ) ) );
        $parent_gallery_ids = array_values( array_unique( array_filter( $parent_gallery_ids ) ) );

        // Look up variant live_title if available so we can also match gallery images by color/variant title (only when distinct per variant)
        $variant_color_tail = '';
        if ( '' === $image_hash && '' !== $variant_id ) {
            $fv_row = Shaghafi_DB::get_by_variant_id( $variant_id );
            if ( $fv_row && ! empty( $fv_row['live_title'] ) ) {
                $v_title = strtolower( trim( preg_replace( '/\s*\|\s*[^|]+\|\s*[A-Za-z0-9\-_.]+\s*$/u', '', (string) $fv_row['live_title'] ) ) );
                if ( false !== strrpos( $v_title, ',' ) ) {
                    $cand_tail = trim( substr( $v_title, strrpos( $v_title, ',' ) + 1 ) );
                    // Do not use tail if it is just a pack/lot indicator like (ve12) or present in parent title
                    $p_title_low = $parent_id > 0 ? strtolower( (string) get_the_title( $parent_id ) ) : '';
                    if ( '' !== $cand_tail && ! preg_match( '/\(ve\s*\d+\)|lot\s+de\s+\d+/i', $cand_tail ) && ( '' === $p_title_low || strpos( $p_title_low, $cand_tail ) === false ) ) {
                        $variant_color_tail = $cand_tail;
                    }
                }
            }
        }

        foreach ( $candidate_ids as $aid ) {
            $att_info = self::get_usable_or_healable_attachment_info( $aid, $sku, $image_hash );
            if ( ! $att_info ) {
                continue;
            }
            $att_hash = strtolower( trim( (string) get_post_meta( $aid, '_shaghafi_media_hash', true ) ) );
            $file     = strtolower( (string) get_post_meta( $aid, '_wp_attached_file', true ) );
            $title    = strtolower( (string) get_the_title( $aid ) );
            $alt      = strtolower( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) );
            if ( '' !== $image_hash && ( $att_hash === $image_hash || strpos( $file, $image_hash ) !== false || strpos( $title, $image_hash ) !== false ) ) {
                return array(
                    'attachment_id' => $aid,
                    'url'           => $att_info['url'],
                    'dims'          => $att_info['dims'],
                    'source'        => 'parent_gallery_hash',
                );
            }
            // If image_hash is known and this attachment has a DIFFERENT _shaghafi_media_hash, skip it!
            if ( '' !== $image_hash && '' !== $att_hash && $att_hash !== $image_hash ) {
                continue;
            }
            if ( '' !== $sku_clean && ( strpos( $file, $sku_clean ) !== false || strpos( $title, $sku_clean ) !== false || strpos( $alt, $sku_clean ) !== false || strpos( $file, $sku_under ) !== false || strpos( $title, $sku_under ) !== false ) ) {
                return array(
                    'attachment_id' => $aid,
                    'url'           => $att_info['url'],
                    'dims'          => $att_info['dims'],
                    'source'        => 'parent_gallery_sku',
                );
            }
            if ( '' === $image_hash && '' !== $base_sku && '' !== $target_suff ) {
                $cand_suff = self::extract_sku_suffix_from_attachment( $aid, $base_sku );
                if ( '' !== $cand_suff && $cand_suff === $target_suff ) {
                    return array(
                        'attachment_id' => $aid,
                        'url'           => $att_info['url'],
                        'dims'          => $att_info['dims'],
                        'source'        => 'parent_gallery_sku',
                    );
                }
            }
            if ( '' === $image_hash && '' !== $variant_color_tail && strlen( $variant_color_tail ) >= 3 && in_array( $aid, $parent_gallery_ids, true ) && $aid !== $p_thumb ) {
                if ( strpos( $title, $variant_color_tail ) !== false || strpos( $alt, $variant_color_tail ) !== false ) {
                    return array(
                        'attachment_id' => $aid,
                        'url'           => $att_info['url'],
                        'dims'          => $att_info['dims'],
                        'source'        => 'parent_gallery_title',
                    );
                }
            }
        }

        // 3b. Check Parent Product Gallery by option number in attachment title/filename ONLY when image_hash is unknown
        if ( '' === $image_hash && '' !== $opt_norm && preg_match( '/^\d{1,2}$/', $opt_norm ) && ! empty( $parent_gallery_ids ) ) {
            foreach ( $parent_gallery_ids as $aid ) {
                $att_info = self::get_usable_or_healable_attachment_info( $aid, $sku, $image_hash );
                if ( ! $att_info ) {
                    continue;
                }
                if ( '' !== $base_sku && '' !== $target_suff ) {
                    $cand_suff = self::extract_sku_suffix_from_attachment( $aid, $base_sku );
                    if ( '' !== $cand_suff && $cand_suff !== $target_suff ) {
                        continue;
                    }
                }
                $bname = strtolower( pathinfo( (string) get_post_meta( $aid, '_wp_attached_file', true ), PATHINFO_FILENAME ) );
                $title = strtolower( trim( (string) get_the_title( $aid ) ) );
                if (
                    $title === $opt_norm ||
                    preg_match( '/^' . preg_quote( $opt_norm, '/' ) . '\s*\(\d+\)$/', $title ) ||
                    $bname === $opt_norm ||
                    preg_match( '/^' . preg_quote( $opt_norm, '/' ) . '-\d{1,3}$/', $bname )
                ) {
                    return array(
                        'attachment_id' => $aid,
                        'url'           => $att_info['url'],
                        'dims'          => $att_info['dims'],
                        'source'        => 'parent_gallery_option_num',
                    );
                }
            }
        }

        // 4. Check global media library by exact full variant SKU (hyphen or underscore) in filename or post_title
        if ( '' !== $sku_clean && strlen( $sku_clean ) >= 5 && false !== strpos( $sku_clean, '-' ) ) {
            $att_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
                     WHERE p.post_type = 'attachment'
                       AND (p.post_title LIKE %s OR pm.meta_value LIKE %s OR p.post_title LIKE %s OR pm.meta_value LIKE %s)
                     ORDER BY p.ID DESC
                     LIMIT 5",
                    '%' . $wpdb->esc_like( $sku ) . '%',
                    '%' . $wpdb->esc_like( $sku ) . '%',
                    '%' . $wpdb->esc_like( str_replace( '-', '_', $sku ) ) . '%',
                    '%' . $wpdb->esc_like( str_replace( '-', '_', $sku ) ) . '%'
                )
            );
            foreach ( $att_ids as $cand_aid ) {
                $att_id = (int) $cand_aid;
                if ( $att_id <= 0 ) {
                    continue;
                }
                $att_hash = strtolower( trim( (string) get_post_meta( $att_id, '_shaghafi_media_hash', true ) ) );
                if ( '' !== $image_hash && '' !== $att_hash && $att_hash !== $image_hash ) {
                    continue;
                }
                $att_info = self::get_usable_or_healable_attachment_info( $att_id, $sku, $image_hash );
                if ( $att_info ) {
                    return array(
                        'attachment_id' => $att_id,
                        'url'           => $att_info['url'],
                        'dims'          => $att_info['dims'],
                        'source'        => 'wp_library_sku',
                    );
                }
            }
        }

        return null;
    }

    /**
     * Resolve a WooCommerce Parent Product to its Shaghafi product_id and feed variants
     */
    public static function resolve_parent_shaghafi_identity( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return null;
        }

        $saved_pid  = trim( (string) get_post_meta( $parent_id, '_shaghafi_product_id', true ) );
        $parent_sku = trim( (string) get_post_meta( $parent_id, '_sku', true ) );

        // 1. Check saved _shaghafi_product_id
        if ( '' !== $saved_pid ) {
            $variants = Shaghafi_DB::get_variants_by_product_id( $saved_pid );
            if ( ! empty( $variants ) ) {
                return array(
                    'product_id' => $variants[0]['product_id'],
                    'matched_by' => 'parent_meta_product_id',
                    'variants'   => $variants,
                );
            }
        }

        // 2. Check child variations' meta or SKUs directly from $wpdb->posts (bypassing object cache & language filters)
        global $wpdb;
        $child_ids = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_parent = %d
                       AND post_type = 'product_variation'
                       AND post_status IN ('publish', 'private', 'draft', 'pending')
                     ORDER BY ID ASC",
                    $parent_id
                )
            )
        );

        foreach ( $child_ids as $cid ) {
            $c_pid = trim( (string) get_post_meta( $cid, '_shaghafi_product_id', true ) );
            if ( '' !== $c_pid ) {
                $variants = Shaghafi_DB::get_variants_by_product_id( $c_pid );
                if ( ! empty( $variants ) ) {
                    return array(
                        'product_id' => $variants[0]['product_id'],
                        'matched_by' => 'child_meta_product_id',
                        'variants'   => $variants,
                    );
                }
            }
            $c_vid = trim( (string) get_post_meta( $cid, '_shaghafi_variant_id', true ) );
            if ( '' !== $c_vid ) {
                $row = Shaghafi_DB::get_by_variant_id( $c_vid );
                if ( $row ) {
                    return array(
                        'product_id' => $row['product_id'],
                        'matched_by' => 'child_meta_variant_id',
                        'variants'   => Shaghafi_DB::get_variants_by_product_id( $row['product_id'] ),
                    );
                }
            }
        }

        foreach ( $child_ids as $cid ) {
            $c_sku = trim( (string) get_post_meta( $cid, '_sku', true ) );
            if ( '' !== $c_sku ) {
                $row = Shaghafi_DB::find_by_fuzzy_sku( $c_sku );
                if ( $row ) {
                    return array(
                        'product_id' => $row['product_id'],
                        'matched_by' => 'child_sku',
                        'variants'   => Shaghafi_DB::get_variants_by_product_id( $row['product_id'] ),
                    );
                }
            }
        }

        // 3. Check parent _sku
        if ( '' !== $parent_sku ) {
            $row = Shaghafi_DB::find_by_fuzzy_sku( $parent_sku );
            if ( $row ) {
                return array(
                    'product_id' => $row['product_id'],
                    'matched_by' => 'parent_sku_exact',
                    'variants'   => Shaghafi_DB::get_variants_by_product_id( $row['product_id'] ),
                );
            }
            $base_sku = Shaghafi_DB::extract_base_sku( $parent_sku );
            $variants = Shaghafi_DB::get_variants_by_base_sku( $base_sku );
            if ( ! empty( $variants ) ) {
                return array(
                    'product_id' => $variants[0]['product_id'],
                    'matched_by' => 'parent_base_sku',
                    'variants'   => Shaghafi_DB::get_variants_by_product_id( $variants[0]['product_id'] ),
                );
            }
        }

        // 4. Check parent title or post_name (slug) for a Shaghafi SKU token
        $post = get_post( $parent_id );
        if ( $post ) {
            $haystack = $post->post_title . ' ' . rawurldecode( $post->post_name );
            if ( preg_match_all( '/\b([0-9]{4,12}(?:-[0-9A-Za-z]+)?|SW[0-9]+(?:\.[0-9]+)?)\b/i', $haystack, $tokens ) ) {
                foreach ( $tokens[1] as $tok ) {
                    $variants = Shaghafi_DB::get_variants_by_base_sku( $tok );
                    if ( ! empty( $variants ) ) {
                        return array(
                            'product_id' => $variants[0]['product_id'],
                            'matched_by' => 'title_or_slug_sku',
                            'variants'   => Shaghafi_DB::get_variants_by_product_id( $variants[0]['product_id'] ),
                        );
                    }
                    $row = Shaghafi_DB::find_by_fuzzy_sku( $tok );
                    if ( $row ) {
                        return array(
                            'product_id' => $row['product_id'],
                            'matched_by' => 'title_or_slug_fuzzy_sku',
                            'variants'   => Shaghafi_DB::get_variants_by_product_id( $row['product_id'] ),
                        );
                    }
                }
            }
        }

        return null;
    }

    /**
     * Inspect variation attributes on a variation post and parent product
     */
    public static function inspect_variation_attributes( $parent_id, $variation_id ) {
        $parent_attrs = get_post_meta( $parent_id, '_product_attributes', true );
        if ( ! is_array( $parent_attrs ) ) {
            $parent_attrs = array();
        }

        $variation_attr_keys = array();
        $cleaned_corrupted   = false;
        $normalized_attrs    = array();
        foreach ( $parent_attrs as $k => $cfg ) {
            if ( is_int( $k ) || '' === trim( (string) $k ) || ! is_array( $cfg ) || empty( $cfg['name'] ) || '' === trim( (string) $cfg['name'] ) ) {
                $cleaned_corrupted = true;
                continue;
            }
            $k_str  = trim( (string) $k );
            $is_tax = ( ( ! empty( $cfg['is_taxonomy'] ) || 0 === strpos( $k_str, 'pa_' ) ) && taxonomy_exists( $k_str ) );
            if ( $is_tax ) {
                $normalized_attrs[ $k_str ] = $cfg;
                if ( ! empty( $cfg['is_variation'] ) ) {
                    $variation_attr_keys[] = $k_str;
                }
            } else {
                $clean_name = trim( rtrim( trim( (string) $cfg['name'] ), ':;؛' ) );
                if ( '' === $clean_name ) {
                    $clean_name = 'Variante';
                }
                if ( $clean_name !== $cfg['name'] ) {
                    $cfg['name']       = $clean_name;
                    $cleaned_corrupted = true;
                }
                $norm_k = sanitize_title( $clean_name );
                if ( '' === $norm_k ) {
                    $norm_k = strtolower( $k_str );
                }
                if ( $norm_k !== $k_str ) {
                    $cleaned_corrupted = true;
                }
                $normalized_attrs[ $norm_k ] = $cfg;
                if ( ! empty( $cfg['is_variation'] ) ) {
                    $variation_attr_keys[] = $norm_k;
                }
            }
        }
        $parent_attrs = $normalized_attrs;
        if ( $cleaned_corrupted && $parent_id > 0 ) {
            update_post_meta( $parent_id, '_product_attributes', $parent_attrs );
            clean_post_cache( $parent_id );
            wp_cache_delete( $parent_id, 'post_meta' );
        }

        // Collect all attribute_* meta on the variation (including case-mismatched keys like attribute_Variante)
        $all_meta      = get_post_meta( $variation_id );
        $var_attr_meta = array();
        $case_mismatch = false;
        foreach ( $all_meta as $mk => $mv ) {
            if ( 0 === stripos( (string) $mk, 'attribute_' ) ) {
                $raw_slug  = substr( (string) $mk, strlen( 'attribute_' ) );
                $attr_slug = strtolower( trim( rtrim( $raw_slug, ':;؛' ) ) );
                $val       = isset( $mv[0] ) ? (string) $mv[0] : '';
                if ( '' !== $attr_slug ) {
                    if ( ! isset( $var_attr_meta[ $attr_slug ] ) || '' === $var_attr_meta[ $attr_slug ] ) {
                        $var_attr_meta[ $attr_slug ] = $val;
                    }
                    if ( 'attribute_' . $attr_slug !== (string) $mk ) {
                        $case_mismatch = true;
                    }
                }
            }
        }

        // Resolve canonical key
        $canonical_key = '';
        foreach ( $variation_attr_keys as $vk ) {
            if ( isset( $var_attr_meta[ $vk ] ) && '' !== $var_attr_meta[ $vk ] ) {
                $canonical_key = $vk;
                break;
            }
        }
        if ( '' === $canonical_key && ! empty( $variation_attr_keys ) ) {
            $canonical_key = $variation_attr_keys[0];
        }
        if ( '' === $canonical_key && ! empty( $var_attr_meta ) ) {
            $canonical_key = array_key_first( $var_attr_meta );
        }

        $raw_val = ( '' !== $canonical_key && isset( $var_attr_meta[ $canonical_key ] ) ) ? $var_attr_meta[ $canonical_key ] : '';

        // If the variation had a case-mismatched meta_key (e.g. attribute_Variante) or had a value under an alias attribute_* key, heal it in DB
        if ( '' === $raw_val && '' !== $canonical_key && ! empty( $var_attr_meta ) ) {
            foreach ( $var_attr_meta as $cand_val ) {
                if ( '' !== trim( (string) $cand_val ) ) {
                    $raw_val       = trim( (string) $cand_val );
                    $case_mismatch = true;
                    break;
                }
            }
        }
        if ( $case_mismatch && $variation_id > 0 && '' !== $canonical_key && '' !== $raw_val && class_exists( 'Shaghafi_Repairer' ) ) {
            Shaghafi_Repairer::set_clean_variation_attribute_meta( $variation_id, $canonical_key, $raw_val );
            $var_attr_meta = array( $canonical_key => $raw_val );
        }

        // Auto-heal empty variation attribute if this variation is already linked to Shaghafi (_shaghafi_variant_id)
        if ( '' === $raw_val && $variation_id > 0 && class_exists( 'Shaghafi_Repairer' ) ) {
            $saved_vid = trim( (string) get_post_meta( $variation_id, '_shaghafi_variant_id', true ) );
            if ( '' !== $saved_vid ) {
                $fv_linked = Shaghafi_DB::get_by_variant_id( $saved_vid );
                if ( $fv_linked && '' !== trim( (string) $fv_linked['variantenauspraegung'] ) ) {
                    $pref_lbl = ! empty( $fv_linked['live_attr_name'] ) ? $fv_linked['live_attr_name'] : 'Variante';
                    Shaghafi_Repairer::repair_product_and_variation_attributes(
                        $parent_id,
                        $variation_id,
                        $fv_linked['variantenauspraegung'],
                        $pref_lbl
                    );
                    Shaghafi_Repairer::sync_clean_parent_attributes( $parent_id );
                    $parent_attrs = get_post_meta( $parent_id, '_product_attributes', true );
                    if ( ! is_array( $parent_attrs ) ) {
                        $parent_attrs = array();
                    }
                    $variation_attr_keys = array();
                    foreach ( $parent_attrs as $k => $cfg ) {
                        if ( ! empty( $cfg['is_variation'] ) ) {
                            $variation_attr_keys[] = (string) $k;
                        }
                    }
                    if ( ! empty( $variation_attr_keys ) ) {
                        $canonical_key = $variation_attr_keys[0];
                    }
                    $raw_val       = trim( (string) get_post_meta( $variation_id, 'attribute_' . $canonical_key, true ) );
                    $var_attr_meta = array( $canonical_key => $raw_val );
                }
            }
        }

        $display_val = $raw_val;

        if ( '' !== $canonical_key && '' !== $raw_val && 0 === strpos( $canonical_key, 'pa_' ) && taxonomy_exists( $canonical_key ) ) {
            $term = get_term_by( 'slug', $raw_val, $canonical_key );
            if ( $term && ! is_wp_error( $term ) ) {
                $display_val = $term->name;
            } else {
                global $wpdb;
                $db_name = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT t.name FROM {$wpdb->terms} t
                         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                         WHERE tt.taxonomy = %s AND (t.slug = %s OR t.name = %s) LIMIT 1",
                        $canonical_key,
                        $raw_val,
                        rawurldecode( $raw_val )
                    )
                );
                $display_val = $db_name ? $db_name : rawurldecode( $raw_val );
            }
        } elseif ( '' !== $raw_val ) {
            $display_val = rawurldecode( $raw_val );
        }

        $stray_keys = array();
        foreach ( $var_attr_meta as $k => $v ) {
            if ( $k !== $canonical_key ) {
                $stray_keys[] = $k;
            }
        }

        $in_parent_options = true;
        if ( '' !== $canonical_key && '' !== $raw_val ) {
            if ( 0 === strpos( $canonical_key, 'pa_' ) && taxonomy_exists( $canonical_key ) ) {
                $parent_terms = wp_get_object_terms( $parent_id, $canonical_key, array( 'fields' => 'slugs' ) );
                if ( is_wp_error( $parent_terms ) || ! in_array( $raw_val, $parent_terms, true ) ) {
                    // Fallback direct DB check in case WPML/Polylang filters wp_get_object_terms by admin language
                    global $wpdb;
                    $direct_assigned = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                             WHERE tr.object_id = %d AND tt.taxonomy = %s AND (t.slug = %s OR t.name = %s)",
                            $parent_id,
                            $canonical_key,
                            $raw_val,
                            $display_val
                        )
                    );
                    if ( $direct_assigned <= 0 ) {
                        $in_parent_options = false;
                    }
                }
            } elseif ( isset( $parent_attrs[ $canonical_key ]['value'] ) ) {
                $pipe_vals = array_map( 'trim', explode( '|', (string) $parent_attrs[ $canonical_key ]['value'] ) );
                $norm_disp = Shaghafi_DB::normalize_attr( $display_val );
                $matched_p = false;
                foreach ( $pipe_vals as $pv ) {
                    if ( Shaghafi_DB::normalize_attr( $pv ) === $norm_disp ) {
                        $matched_p = true;
                        break;
                    }
                }
                if ( ! $matched_p ) {
                    $in_parent_options = false;
                }
            } else {
                $in_parent_options = false;
            }

            // Auto-heal parent attribute options if this variation was already linked to Shaghafi (_shaghafi_variant_id)
            if ( ! $in_parent_options && $variation_id > 0 && '' !== $display_val && class_exists( 'Shaghafi_Repairer' ) ) {
                $saved_vid = trim( (string) get_post_meta( $variation_id, '_shaghafi_variant_id', true ) );
                if ( '' !== $saved_vid ) {
                    Shaghafi_Repairer::repair_product_and_variation_attributes( $parent_id, $variation_id, $display_val );
                    Shaghafi_Repairer::sync_clean_parent_attributes( $parent_id );
                    $in_parent_options   = true;
                    $variation_attr_keys = array( $canonical_key );
                    $stray_keys          = array();
                }
            }
        }

        return array(
            'canonical_key'       => $canonical_key,
            'raw_val'             => $raw_val,
            'display_val'         => $display_val,
            'var_attr_meta'       => $var_attr_meta,
            'parent_var_keys'     => $variation_attr_keys,
            'stray_keys'          => $stray_keys,
            'has_duplicate_attrs' => ( ! empty( $stray_keys ) || count( $variation_attr_keys ) > 1 ),
            'in_parent_options'   => $in_parent_options,
        );
    }

    /**
     * Scan a single WooCommerce parent product and return full diagnostic rows
     */
    public static function scan_single_product( $parent_id ) {
        $parent_id = (int) $parent_id;
        $product   = wc_get_product( $parent_id );
        if ( ! $product ) {
            return array();
        }

        $identity         = self::resolve_parent_shaghafi_identity( $parent_id );
        $parent_title     = $product->get_name();
        $parent_sku       = (string) $product->get_sku();
        $parent_saved_pid = trim( (string) get_post_meta( $parent_id, '_shaghafi_product_id', true ) );
        $edit_link        = get_edit_post_link( $parent_id, 'raw' );

        // Handle case where product cannot be linked to likadeo.csv
        if ( ! $identity || empty( $identity['variants'] ) ) {
            $thumb_id = (int) $product->get_image_id();
            $dims     = self::get_attachment_dimensions( $thumb_id );
            return array(
                array(
                    'row_id'              => 'p_' . $parent_id,
                    'parent_id'           => $parent_id,
                    'variation_id'        => 0,
                    'product_title'       => $parent_title,
                    'edit_url'            => $edit_link,
                    'action'              => 'MANUAL_REVIEW',
                    'status_label'        => 'عدم وجود در فید مرجع (نیازمند بررسی یا حذف)',
                    'woo_sku'             => $parent_sku,
                    'feed_sku'            => '—',
                    'woo_product_id_meta' => $parent_saved_pid,
                    'feed_product_id'     => '—',
                    'woo_variant_id_meta' => '—',
                    'feed_variant_id'     => '—',
                    'woo_attr'            => '—',
                    'feed_attr'           => '—',
                    'woo_stock'           => (int) $product->get_stock_quantity(),
                    'feed_stock'          => null,
                    'live_stock'          => null,
                    'woo_price'           => $product->get_regular_price(),
                    'live_price'          => null,
                    'target_price'        => null,
                    'woo_image_url'       => $thumb_id > 0 ? ( wp_get_attachment_url( $thumb_id ) ?: '' ) : '',
                    'woo_image_dims'      => $dims ? ( $dims['width'] . '×' . $dims['height'] ) : '',
                    'woo_image_dims_ok'   => true,
                    'live_image_url'      => '',
                    'local_gallery_match' => null,
                    'issues'              => array(
                        array(
                            'code' => 'unlinked_product',
                            'text' => 'این محصول در فایل مرجع likadeo.csv یافت نشد (در تب «محصولات حذف‌شده از شقفی» نیز قابل مدیریت است).',
                        ),
                    ),
                    'candidate_variants'  => array(),
                ),
            );
        }

        $feed_pid      = $identity['product_id'];
        $feed_variants = $identity['variants'];

        // Auto-backfill _shaghafi_product_id on the parent product if empty so parent and variations stay consistent
        if ( '' === $parent_saved_pid && '' !== $feed_pid ) {
            update_post_meta( $parent_id, '_shaghafi_product_id', $feed_pid );
            $parent_saved_pid = $feed_pid;
        }

        $rows_out = array();

        global $wpdb;
        $child_ids = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_parent = %d
                       AND post_type = 'product_variation'
                       AND post_status IN ('publish', 'private', 'draft', 'pending')
                     ORDER BY ID ASC",
                    $parent_id
                )
            )
        );

        // Auto-heal Simple Products that were accidentally converted to Variable with 0 or 1 child variation
        // when likadeo.csv only has 1 row for this product (e.g. 5010662-001 where variantenauspraegung is '1' or '')
        if ( 1 === count( $feed_variants ) && $product->is_type( 'variable' ) && count( $child_ids ) <= 1 ) {
            $single_fv   = $feed_variants[0];
            $should_heal = false;
            if ( empty( $child_ids ) ) {
                $should_heal = true;
            } else {
                $only_cid  = (int) $child_ids[0];
                $only_attr = self::inspect_variation_attributes( $parent_id, $only_cid );
                if ( empty( $only_attr['parent_var_keys'] ) || ! $only_attr['in_parent_options'] || in_array( trim( (string) $single_fv['variantenauspraegung'] ), array( '', '1' ), true ) ) {
                    $should_heal = true;
                    // Copy price, stock, and thumbnail from child variation to parent before removing the bogus variation
                    $c_thumb = (int) get_post_meta( $only_cid, '_thumbnail_id', true );
                    if ( $c_thumb > 0 && (int) get_post_meta( $parent_id, '_thumbnail_id', true ) <= 0 ) {
                        set_post_thumbnail( $parent_id, $c_thumb );
                    }
                    $c_reg_p = get_post_meta( $only_cid, '_regular_price', true );
                    if ( '' !== (string) $c_reg_p && '' === (string) get_post_meta( $parent_id, '_regular_price', true ) ) {
                        update_post_meta( $parent_id, '_regular_price', $c_reg_p );
                        update_post_meta( $parent_id, '_price', $c_reg_p );
                    }
                    wp_delete_post( $only_cid, true );
                    $child_ids = array();
                }
            }
            if ( $should_heal ) {
                wp_set_object_terms( $parent_id, 'simple', 'product_type', false );
                update_post_meta( $parent_id, '_sku', $single_fv['artikelnummer'] );
                update_post_meta( $parent_id, '_shaghafi_product_id', $single_fv['product_id'] );
                update_post_meta( $parent_id, '_shaghafi_variant_id', $single_fv['variant_id'] );
                update_post_meta( $parent_id, '_manage_stock', 'yes' );
                wc_update_product_stock( $parent_id, (int) $single_fv['bestand'], 'set' );
                wc_update_product_stock_status( $parent_id, (int) $single_fv['bestand'] > 0 ? 'instock' : 'outofstock' );
                delete_post_meta( $parent_id, '_product_attributes' );
                clean_post_cache( $parent_id );
                wc_delete_product_transients( $parent_id );
                $product = wc_get_product( $parent_id );
            }
        }

        // Check if TRUE Simple Product in Shaghafi (1 row in feed and Simple Product in WooCommerce with no child variations)
        if ( 1 === count( $feed_variants ) && ! $product->is_type( 'variable' ) && empty( $child_ids ) ) {
            $matched_fv = $feed_variants[0];
            $rows_out[] = self::build_comparison_row(
                $parent_id,
                $parent_id,
                $parent_title,
                $edit_link,
                $matched_fv,
                $feed_pid,
                true
            );
            return $rows_out;
        }

        $unmatched_feed = array();
        foreach ( $feed_variants as $fv ) {
            $unmatched_feed[ strtolower( $fv['variant_id'] ) ] = $fv;
        }

        // If Shaghafi has MULTIPLE variants (count > 1, e.g. 5050833 with 9 variants),
        // but the product on the site is currently saved as a Simple Product (or has 0 child variations):
        // Flag the existing simple product row as REPAIR_AND_LINK (needs conversion to Variable Product)
        // and let all other variants in $unmatched_feed emit as CREATE_NEW_VARIATION rows!
        if ( count( $feed_variants ) > 1 && empty( $child_ids ) ) {
            $matched_fv = $feed_variants[0];
            $p_vid      = strtolower( trim( (string) get_post_meta( $parent_id, '_shaghafi_variant_id', true ) ) );
            foreach ( $feed_variants as $cand_fv ) {
                if (
                    ( '' !== $p_vid && strtolower( $cand_fv['variant_id'] ) === $p_vid ) ||
                    ( '' !== $parent_sku && 0 === strcasecmp( $cand_fv['artikelnummer'], $parent_sku ) )
                ) {
                    $matched_fv = $cand_fv;
                    break;
                }
            }
            $base_row                 = self::build_comparison_row(
                $parent_id,
                $parent_id,
                $parent_title,
                $edit_link,
                $matched_fv,
                $feed_pid,
                true
            );
            $base_row['action']       = 'REPAIR_AND_LINK';
            $base_row['status_label'] = sprintf( 'نیاز به تبدیل از ساده به متغیر (%d وریشن در شقفی)', count( $feed_variants ) );
            array_unshift(
                $base_row['issues'],
                array(
                    'code' => 'simple_needs_variable_conversion',
                    'text' => sprintf(
                        'این محصول در مرجع شقفی دارای %d وریشن است اما در سایت به صورت «محصول ساده» ثبت شده است (با کلیک روی اصلاح یا بررسی زنده، خودکار به محصول متغیر تبدیل شده و تمام %d وریشن با عکس 700×800، سواچ و موجودی ساخته می‌شوند).',
                        count( $feed_variants ),
                        count( $feed_variants )
                    ),
                )
            );
            $rows_out[] = $base_row;
            unset( $unmatched_feed[ strtolower( $matched_fv['variant_id'] ) ] );
        }

        $is_size_product = self::is_size_based_product( $parent_id, $feed_variants );
        $matched_pairs   = array();
        $unmatched_woo   = array();
        $woo_var_details = array();

        foreach ( $child_ids as $vid ) {
            $attr_info = self::inspect_variation_attributes( $parent_id, $vid );
            $thumb_id  = (int) get_post_meta( $vid, '_thumbnail_id', true );

            // If NOT a size product and variation has no _thumbnail_id of its own, check if WCBoost Variation Swatches has a physically valid image for its current option
            if ( ! $is_size_product && $thumb_id <= 0 && '' !== $attr_info['raw_val'] && class_exists( 'Shaghafi_Repairer' ) ) {
                $sw_img = Shaghafi_Repairer::get_swatch_image_for_option( $parent_id, $attr_info['canonical_key'], $attr_info['raw_val'] );
                if ( $sw_img <= 0 && '' !== $attr_info['display_val'] ) {
                    $sw_img = Shaghafi_Repairer::get_swatch_image_for_option( $parent_id, $attr_info['canonical_key'], $attr_info['display_val'] );
                }
                if ( $sw_img > 0 && self::is_attachment_currently_valid_on_disk( $sw_img, false ) ) {
                    update_post_meta( $vid, '_thumbnail_id', $sw_img );
                    $thumb_id = $sw_img;
                }
            }

            $woo_var_details[ $vid ] = array(
                'id'        => $vid,
                'sku'       => trim( (string) get_post_meta( $vid, '_sku', true ) ),
                'meta_pid'  => trim( (string) get_post_meta( $vid, '_shaghafi_product_id', true ) ),
                'meta_vid'  => strtolower( trim( (string) get_post_meta( $vid, '_shaghafi_variant_id', true ) ) ),
                'attr_info' => $attr_info,
                'thumb_id'  => $thumb_id,
            );
        }

        // Pass 1: Match by exact _shaghafi_variant_id FIRST (32-char UUID is the strongest unique identifier)
        foreach ( $woo_var_details as $vid => $w ) {
            if ( isset( $matched_pairs[ $vid ] ) || '' === $w['meta_vid'] ) {
                continue;
            }
            if ( isset( $unmatched_feed[ $w['meta_vid'] ] ) ) {
                $matched_pairs[ $vid ] = $unmatched_feed[ $w['meta_vid'] ];
                unset( $unmatched_feed[ $w['meta_vid'] ] );
            }
        }

        // Pass 2: Match by exact SKU
        foreach ( $woo_var_details as $vid => $w ) {
            if ( isset( $matched_pairs[ $vid ] ) || '' === $w['sku'] ) {
                continue;
            }
            foreach ( $unmatched_feed as $f_vid => $fv ) {
                if ( strcasecmp( $w['sku'], $fv['artikelnummer'] ) === 0 ) {
                    $matched_pairs[ $vid ] = $fv;
                    unset( $unmatched_feed[ $f_vid ] );
                    break;
                }
            }
        }

        // Pass 2.5: Match by explicit SKU in variation/swatch image filename (only for color/model variations, not size variations)
        $feed_base_sku = ! empty( $feed_variants[0]['base_sku'] ) ? $feed_variants[0]['base_sku'] : '';
        if ( ! $is_size_product ) {
            foreach ( $woo_var_details as $vid => $w ) {
                if ( isset( $matched_pairs[ $vid ] ) || $w['thumb_id'] <= 0 || '' === $feed_base_sku ) {
                    continue;
                }
                $img_suff = self::extract_sku_suffix_from_attachment( $w['thumb_id'], $feed_base_sku );
                if ( '' === $img_suff ) {
                    continue;
                }
                foreach ( $unmatched_feed as $f_vid => $fv ) {
                    if ( '' !== $fv['sku_suffix_norm'] && $img_suff === $fv['sku_suffix_norm'] ) {
                        $matched_pairs[ $vid ] = $fv;
                        unset( $unmatched_feed[ $f_vid ] );
                        break;
                    }
                }
            }
        }

        // Pass 3: Match by normalized/unpadded SKU suffix (e.g. "3000060-6" -> "3000060-006")
        foreach ( $woo_var_details as $vid => $w ) {
            if ( isset( $matched_pairs[ $vid ] ) || '' === $w['sku'] ) {
                continue;
            }
            $w_suffix = Shaghafi_DB::extract_sku_suffix_norm( $w['sku'] );
            if ( '' === $w_suffix ) {
                continue;
            }
            foreach ( $unmatched_feed as $f_vid => $fv ) {
                if ( '' !== $fv['sku_suffix_norm'] && $w_suffix === $fv['sku_suffix_norm'] ) {
                    $matched_pairs[ $vid ] = $fv;
                    unset( $unmatched_feed[ $f_vid ] );
                    break;
                }
            }
        }

        // Pass 4: Match by normalized attribute value
        foreach ( $woo_var_details as $vid => $w ) {
            if ( isset( $matched_pairs[ $vid ] ) ) {
                continue;
            }
            $w_attr_norm = Shaghafi_DB::normalize_attr( $w['attr_info']['display_val'] );
            $w_attr_raw  = Shaghafi_DB::normalize_attr( $w['attr_info']['raw_val'] );
            $w_unitless  = Shaghafi_DB::normalize_attr_unitless( $w['attr_info']['display_val'] );

            if ( '' === $w_attr_norm && '' === $w_attr_raw ) {
                continue;
            }

            foreach ( $unmatched_feed as $f_vid => $fv ) {
                $f_norm     = $fv['attr_norm'];
                $f_unitless = Shaghafi_DB::normalize_attr_unitless( $fv['variantenauspraegung'] );
                if ( '' !== $f_norm && ( $w_attr_norm === $f_norm || $w_attr_raw === $f_norm || ( '' !== $w_unitless && $w_unitless === $f_unitless ) ) ) {
                    $matched_pairs[ $vid ] = $fv;
                    unset( $unmatched_feed[ $f_vid ] );
                    break;
                }
            }
        }

        // Pass 5: Match by variation thumbnail filename if it contains feed SKU
        foreach ( $woo_var_details as $vid => $w ) {
            if ( isset( $matched_pairs[ $vid ] ) || $w['thumb_id'] <= 0 ) {
                continue;
            }
            $att_file = strtolower( (string) get_post_meta( $w['thumb_id'], '_wp_attached_file', true ) );
            if ( '' === $att_file ) {
                continue;
            }
            foreach ( $unmatched_feed as $f_vid => $fv ) {
                $f_sku = strtolower( $fv['artikelnummer'] );
                if ( '' !== $f_sku && strpos( $att_file, $f_sku ) !== false ) {
                    $matched_pairs[ $vid ] = $fv;
                    unset( $unmatched_feed[ $f_vid ] );
                    break;
                }
            }
        }

        // Pass 6: Single-variant fallback
        if ( 1 === count( $child_ids ) && empty( $matched_pairs ) && 1 === count( $unmatched_feed ) ) {
            $only_vid                   = $child_ids[0];
            $only_fv                    = reset( $unmatched_feed );
            $matched_pairs[ $only_vid ] = $only_fv;
            $unmatched_feed             = array();
        }

        // Build rows for matched variations
        foreach ( $child_ids as $vid ) {
            if ( isset( $matched_pairs[ $vid ] ) ) {
                $rows_out[] = self::build_comparison_row(
                    $parent_id,
                    $vid,
                    $parent_title,
                    $edit_link,
                    $matched_pairs[ $vid ],
                    $feed_pid,
                    false
                );
            } else {
                $unmatched_woo[] = $vid;
            }
        }

        // Build rows for unmatched WooCommerce variations (DISABLE_MISSING or MANUAL_REVIEW)
        // Automatically purge any already-disabled (private/draft/pending/trash) or previously deleted variations that do not exist in Shaghafi's feed!
        $candidate_list   = array_values( $unmatched_feed );
        $purged_disabled  = false;
        foreach ( $unmatched_woo as $vid ) {
            $w        = $woo_var_details[ $vid ];
            $v_status = get_post_status( $vid );
            $is_manual = ( ! empty( $candidate_list ) && ( '' === $w['sku'] || '' === $w['attr_info']['display_val'] ) );

            $was_deleted_before = (
                class_exists( 'Shaghafi_Repairer' ) &&
                method_exists( 'Shaghafi_Repairer', 'is_variation_marked_permanently_deleted' ) &&
                Shaghafi_Repairer::is_variation_marked_permanently_deleted(
                    $parent_id,
                    (int) $vid,
                    $w['sku'],
                    $w['meta_vid'],
                    $w['attr_info']['display_val'],
                    $feed_pid
                )
            );

            // If this variation is disabled (private/draft/pending) or was already deleted by the user, permanently purge it right away!
            if ( ! $is_manual && ( 'publish' !== $v_status || $was_deleted_before ) ) {
                if ( class_exists( 'Shaghafi_Repairer' ) ) {
                    Shaghafi_Repairer::disable_missing_variation( (int) $vid, $parent_id );
                } else {
                    wp_delete_post( (int) $vid, true );
                }
                $purged_disabled = true;
                continue;
            }

            $v_obj   = wc_get_product( $vid );
            $v_stock = $v_obj ? (int) $v_obj->get_stock_quantity() : 0;
            $v_price = $v_obj ? $v_obj->get_regular_price() : '';
            $v_img   = $w['thumb_id'] > 0 ? ( wp_get_attachment_url( $w['thumb_id'] ) ?: '' ) : '';
            $dims    = self::get_attachment_dimensions( $w['thumb_id'] );

            $action = $is_manual ? 'MANUAL_REVIEW' : 'DISABLE_MISSING';

            $issues = array();
            if ( $is_manual ) {
                $issues[] = array(
                    'code' => 'ambiguous_variation',
                    'text' => 'ابهام در تشخیص وریشن (SKU یا ویژگی خالی/نامشخص است)؛ وریشن متناظر شقفی را از لیست انتخاب و تعمیر کنید.',
                );
            } else {
                $issues[] = array(
                    'code' => 'missing_in_feed',
                    'text' => 'این وریشن در فایل مرجع شقفی (likadeo.csv) وجود ندارد و باید به طور کامل از محصول و صفحه ویرایش حذف شود.',
                );
            }

            $rows_out[] = array(
                'row_id'              => 'v_' . $vid,
                'parent_id'           => $parent_id,
                'variation_id'        => $vid,
                'variation_status'    => $v_status,
                'product_title'       => $parent_title,
                'edit_url'            => $edit_link,
                'action'              => $action,
                'status_label'        => $is_manual ? 'نیاز به بررسی دستی / انتخاب وریشن' : 'عدم وجود در فید شقفی (نیاز به حذف کامل از محصول)',
                'woo_sku'             => $w['sku'],
                'feed_sku'            => '— (حذف شده از فید)',
                'woo_product_id_meta' => $w['meta_pid'],
                'feed_product_id'     => $feed_pid,
                'woo_variant_id_meta' => $w['meta_vid'],
                'feed_variant_id'     => '—',
                'woo_attr'            => $w['attr_info']['display_val'] ?: '— (بدون ویژگی)',
                'feed_attr'           => '—',
                'woo_stock'           => $v_stock,
                'feed_stock'          => 0,
                'live_stock'          => 0,
                'woo_price'           => $v_price,
                'live_price'          => null,
                'target_price'        => null,
                'woo_image_url'       => $v_img,
                'woo_image_dims'      => $dims ? ( $dims['width'] . '×' . $dims['height'] ) : '',
                'woo_image_dims_ok'   => true,
                'live_image_url'      => '',
                'local_gallery_match' => null,
                'issues'              => $issues,
                'candidate_variants'  => $candidate_list,
            );
        }

        // Build rows for missing variations present in likadeo.csv (CREATE_NEW_VARIATION)
        $sibling_price = '';
        foreach ( $child_ids as $cid ) {
            $cp = get_post_meta( $cid, '_regular_price', true );
            if ( '' !== (string) $cp && (float) $cp > 0 ) {
                $sibling_price = $cp;
                break;
            }
        }
        if ( '' === $sibling_price ) {
            $p_reg = get_post_meta( $parent_id, '_regular_price', true );
            if ( '' === (string) $p_reg ) {
                $p_reg = get_post_meta( $parent_id, '_price', true );
            }
            if ( '' !== (string) $p_reg && (float) $p_reg > 0 ) {
                $sibling_price = $p_reg;
            }
        }

        foreach ( $unmatched_feed as $f_vid => $fv ) {
            $local_img = self::find_local_wp_image_for_variant(
                $parent_id,
                $fv['artikelnummer'],
                $fv['variant_id'],
                $fv['live_image_url'],
                $fv['variantenauspraegung']
            );
            $parent_retail_price  = get_post_meta( $parent_id, '_shaghafi_retail_price', true );
            $parent_retail_source = (string) get_post_meta( $parent_id, '_shaghafi_retail_source', true );
            $parent_retail_url    = (string) get_post_meta( $parent_id, '_shaghafi_retail_url', true );
            $parent_retail_links  = json_decode( (string) get_post_meta( $parent_id, '_shaghafi_retail_links_json', true ), true );

            $raw_retail_price = ( null !== $fv['live_price'] && (float) $fv['live_price'] > 0 )
                ? (float) $fv['live_price']
                : ( '' !== (string) $parent_retail_price && (float) $parent_retail_price > 0 ? (float) $parent_retail_price : null );

            $target_price = self::calculate_target_price( $raw_retail_price );
            if ( null === $target_price && '' !== $sibling_price ) {
                $target_price = (float) $sibling_price;
            }

            $v_retail_links = is_array( $parent_retail_links ) && ! empty( $parent_retail_links )
                ? Shaghafi_Live_Scraper::build_variant_retail_links_list( $parent_retail_links, $fv['artikelnummer'] )
                : Shaghafi_Live_Scraper::get_fallback_retail_search_links( $fv['artikelnummer'], $fv['base_sku'], $fv['variant_id'] );

            $v_retail_url = ! empty( $parent_retail_url )
                ? Shaghafi_Live_Scraper::build_variant_retail_url( $parent_retail_url, $parent_retail_source, $fv['artikelnummer'] )
                : '';

            $issues = array(
                array(
                    'code' => 'missing_in_woo',
                    'text' => sprintf(
                        'وریشن جدید در فید شقفی (SKU: %s | ویژگی: %s | موجودی: %d) در سایت وجود ندارد و آماده افزودن است (قیمت از سایت‌های خرده‌فروشی مرجع و عکس 700×800 بدون برش تنظیم می‌شود).',
                        $fv['artikelnummer'],
                        $fv['variantenauspraegung'] ?: '—',
                        (int) $fv['bestand']
                    ),
                ),
            );

            $rows_out[] = array(
                'row_id'              => 'new_' . $parent_id . '_' . $fv['variant_id'],
                'parent_id'           => $parent_id,
                'variation_id'        => 0,
                'is_size_variation'   => $is_size_product,
                'live_checked'        => ! empty( $fv['live_checked_at'] ),
                'variation_status'    => 'missing',
                'product_title'       => $parent_title,
                'edit_url'            => $edit_link,
                'action'              => 'CREATE_NEW_VARIATION',
                'status_label'        => 'وریشن جاافتاده (آماده افزودن)',
                'woo_sku'             => '— (موجود نیست)',
                'feed_sku'            => $fv['artikelnummer'],
                'woo_product_id_meta' => $parent_saved_pid,
                'feed_product_id'     => $fv['product_id'],
                'woo_variant_id_meta' => '—',
                'feed_variant_id'     => $fv['variant_id'],
                'woo_attr'            => '—',
                'feed_attr'           => $fv['variantenauspraegung'],
                'woo_stock'           => null,
                'feed_stock'          => (int) $fv['bestand'],
                'live_stock'          => null !== $fv['live_stock'] ? (int) $fv['live_stock'] : null,
                'woo_price'           => '—',
                'live_price'          => $raw_retail_price,
                'target_price'        => $target_price,
                'retail_source'       => $parent_retail_source,
                'retail_url'          => $v_retail_url,
                'retail_links'        => $v_retail_links,
                'woo_image_url'       => '',
                'woo_image_dims'      => '',
                'woo_image_dims_ok'   => false,
                'live_image_url'      => (string) $fv['live_image_url'],
                'local_gallery_match' => $local_img,
                'issues'              => $issues,
                'candidate_variants'  => array(),
            );
        }

        // Auto-sync parent attributes order (4, 5, 7, 8, 9) and WCBoost Variation Swatches images
        if ( ( ! empty( $matched_pairs ) || $purged_disabled ) && class_exists( 'Shaghafi_Repairer' ) ) {
            Shaghafi_Repairer::sync_clean_parent_attributes( $parent_id );
            if ( $purged_disabled && class_exists( 'WC_Product_Variable' ) ) {
                WC_Product_Variable::sync( $parent_id );
                wc_delete_product_transients( $parent_id );
            }
        }

        // Sort output rows in natural ascending order of feed_attr / feed_sku (e.g. 4, 5, 7, 8, 9)
        if ( count( $rows_out ) > 1 ) {
            usort(
                $rows_out,
                function ( $a, $b ) {
                    $ka = ( isset( $a['feed_attr'] ) && '—' !== $a['feed_attr'] && '' !== $a['feed_attr'] ) ? (string) $a['feed_attr'] : (string) $a['woo_attr'];
                    $kb = ( isset( $b['feed_attr'] ) && '—' !== $b['feed_attr'] && '' !== $b['feed_attr'] ) ? (string) $b['feed_attr'] : (string) $b['woo_attr'];
                    $cmp = strnatcasecmp( $ka, $kb );
                    if ( 0 !== $cmp ) {
                        return $cmp;
                    }
                    return strnatcasecmp( (string) $a['feed_sku'], (string) $b['feed_sku'] );
                }
            );
        }

        return $rows_out;
    }

    /**
     * Build detailed diagnostic row comparing a WooCommerce variation/product with its matched Shaghafi feed record
     */
    private static function build_comparison_row( $parent_id, $target_post_id, $parent_title, $edit_link, $fv, $feed_pid, $is_simple = false ) {
        $prod_obj      = wc_get_product( $target_post_id );
        $woo_sku       = trim( (string) get_post_meta( $target_post_id, '_sku', true ) );
        $woo_meta_pid  = strtolower( trim( (string) get_post_meta( $target_post_id, '_shaghafi_product_id', true ) ) );
        $woo_meta_vid  = strtolower( trim( (string) get_post_meta( $target_post_id, '_shaghafi_variant_id', true ) ) );
        $ref_pid_low   = strtolower( trim( (string) $fv['product_id'] ) );
        $ref_vid_low   = strtolower( trim( (string) $fv['variant_id'] ) );
        $manage_stock  = get_post_meta( $target_post_id, '_manage_stock', true );
        $woo_stock     = $prod_obj ? (int) $prod_obj->get_stock_quantity() : 0;
        $woo_price     = $prod_obj ? (string) $prod_obj->get_regular_price() : '';
        if ( '' === $woo_price && $prod_obj ) {
            $woo_price = (string) $prod_obj->get_price();
        }

        // Use full uncropped URL so the table preview never shows WordPress's 150x150 hard-cropped square.
        // Verify that the attachment file actually exists on disk right now (never report 700x800 ✓ from stale DB metadata if the file was deleted/broken after backup restore!)
        $thumb_id      = (int) get_post_meta( $target_post_id, '_thumbnail_id', true );
        $thumb_valid   = ( $thumb_id > 0 && self::is_attachment_currently_valid_on_disk( $thumb_id, false ) );
        $woo_image_url = $thumb_valid ? ( wp_get_attachment_url( $thumb_id ) ?: '' ) : '';
        $img_dims      = $thumb_valid ? self::get_attachment_dimensions( $thumb_id ) : null;
        $target_dims   = self::get_target_image_dimensions();
        $dims_str      = $img_dims ? ( $img_dims['width'] . '×' . $img_dims['height'] ) : ( $thumb_id > 0 ? 'فایل عکس موجود نیست' : '' );
        $dims_ok       = $img_dims ? ( $img_dims['width'] === $target_dims['width'] && $img_dims['height'] === $target_dims['height'] ) : false;

        $attr_info = $is_simple
            ? array(
                'canonical_key'       => '',
                'raw_val'             => '',
                'display_val'         => '— (محصول ساده)',
                'has_duplicate_attrs' => false,
                'in_parent_options'   => true,
            )
            : self::inspect_variation_attributes( $parent_id, $target_post_id );

        $issues          = array();
        $needs_structure = false;
        $needs_sync      = false;

        // 1. Check SKU
        if ( '' === $woo_sku ) {
            $needs_structure = true;
            $issues[]        = array(
                'code' => 'missing_sku',
                'text' => sprintf( 'SKU خالی است ← باید به %s تغییر کند.', $fv['artikelnummer'] ),
            );
        } elseif ( $woo_sku !== $fv['artikelnummer'] ) {
            $needs_structure = true;
            $issues[]        = array(
                'code' => 'wrong_sku',
                'text' => sprintf( 'SKU اشتباه است: فعلی «%s» ← مرجع «%s».', $woo_sku, $fv['artikelnummer'] ),
            );
        }

        // 2. Check Shaghafi Meta IDs (_shaghafi_product_id & _shaghafi_variant_id) on this variation
        // Auto-heal if _shaghafi_variant_id was accidentally set to parent product_id earlier
        if ( ! $is_simple && $woo_meta_vid === $ref_pid_low && $ref_vid_low !== $ref_pid_low ) {
            update_post_meta( $target_post_id, '_shaghafi_variant_id', $ref_vid_low );
            $woo_meta_vid = $ref_vid_low;
        }
        if ( '' === $woo_meta_pid && '' !== $ref_pid_low && $woo_meta_vid === $ref_vid_low ) {
            update_post_meta( $target_post_id, '_shaghafi_product_id', $ref_pid_low );
            $woo_meta_pid = $ref_pid_low;
        }

        if ( '' === $woo_meta_pid || '' === $woo_meta_vid ) {
            $needs_sync = true;
            $issues[]   = array(
                'code' => 'missing_shaghafi_ids',
                'text' => 'متاهای _shaghafi_product_id یا _shaghafi_variant_id ذخیره نشده‌اند.',
            );
        } elseif ( $woo_meta_pid !== $ref_pid_low || $woo_meta_vid !== $ref_vid_low ) {
            $needs_structure = true;
            $issues[]        = array(
                'code' => 'wrong_shaghafi_ids',
                'text' => sprintf(
                    'شناسه شقفی ذخیره شده (%s) با فایل مرجع (%s) مغایرت دارد.',
                    substr( $woo_meta_vid, 0, 10 ),
                    substr( $ref_vid_low, 0, 10 )
                ),
            );
        }

        // 3. Check Attributes (for variable products)
        if ( ! $is_simple && '' !== $fv['variantenauspraegung'] ) {
            $woo_attr_norm  = Shaghafi_DB::normalize_attr( $attr_info['display_val'] );
            $feed_attr_norm = $fv['attr_norm'];
            if ( '' === $attr_info['raw_val'] ) {
                $needs_structure = true;
                $issues[]        = array(
                    'code' => 'empty_attribute',
                    'text' => sprintf( 'ویژگی (Attribute) وریشن روی «هر نوع / خالی» است ← باید «%s» تنظیم شود.', $fv['variantenauspraegung'] ),
                );
            } elseif ( $woo_attr_norm !== $feed_attr_norm ) {
                $needs_structure = true;
                $issues[]        = array(
                    'code' => 'mismatch_attribute',
                    'text' => sprintf( 'مقدار ویژگی مغایرت دارد: فعلی «%s» ← مرجع «%s».', $attr_info['display_val'], $fv['variantenauspraegung'] ),
                );
            }
            if ( $attr_info['has_duplicate_attrs'] ) {
                $needs_structure = true;
                $issues[]        = array(
                    'code' => 'duplicate_attributes',
                    'text' => 'اتریبیوت‌های تکراری یا اضافی در متای وریشن/محصول مادر وجود دارد که باید پاکسازی و یکپارچه شود.',
                );
            }
            if ( ! $attr_info['in_parent_options'] ) {
                $needs_structure = true;
                $issues[]        = array(
                    'code' => 'attr_missing_on_parent',
                    'text' => sprintf( 'مقدار ویژگی «%s» در لیست ویژگی‌های محصول مادر ثبت نشده است.', $fv['variantenauspraegung'] ),
                );
            }
        }

        // 4. Check Stock (Feed + Live)
        $reference_stock = ( null !== $fv['live_stock'] && '' !== (string) $fv['live_stock'] )
            ? (int) $fv['live_stock']
            : (int) $fv['bestand'];

        if ( 'yes' !== $manage_stock || $woo_stock !== $reference_stock ) {
            $needs_sync = true;
            $issues[]   = array(
                'code' => 'stock_mismatch',
                'text' => sprintf(
                    'اختلاف موجودی: فعلی سایت (%s) ← مرجع شقفی (%d عدد)%s.',
                    'yes' === $manage_stock ? (string) $woo_stock : 'مدیریت موجودی غیرفعال',
                    $reference_stock,
                    null !== $fv['live_stock'] ? ' [بررسی زنده]' : ' [فید CSV]'
                ),
            );
        }

        // 5. Check Price ONLY against Retail Reference Sites (akzentshop.de / meinakzent.de / raptor-watches.com) — NEVER Shaghafi B2B wholesale!
        $saved_retail_price  = get_post_meta( $target_post_id, '_shaghafi_retail_price', true );
        if ( '' === (string) $saved_retail_price ) {
            $saved_retail_price = get_post_meta( $parent_id, '_shaghafi_retail_price', true );
        }
        $saved_retail_source = (string) get_post_meta( $target_post_id, '_shaghafi_retail_source', true );
        if ( '' === $saved_retail_source ) {
            $saved_retail_source = (string) get_post_meta( $parent_id, '_shaghafi_retail_source', true );
        }
        $saved_retail_url = (string) get_post_meta( $target_post_id, '_shaghafi_retail_url', true );
        if ( '' === $saved_retail_url ) {
            $p_url = (string) get_post_meta( $parent_id, '_shaghafi_retail_url', true );
            $saved_retail_url = Shaghafi_Live_Scraper::build_variant_retail_url( $p_url, $saved_retail_source, $fv['artikelnummer'] );
        }
        $saved_retail_links = json_decode( (string) get_post_meta( $target_post_id, '_shaghafi_retail_links_json', true ), true );
        if ( empty( $saved_retail_links ) ) {
            $p_links = json_decode( (string) get_post_meta( $parent_id, '_shaghafi_retail_links_json', true ), true );
            if ( is_array( $p_links ) && ! empty( $p_links ) ) {
                $saved_retail_links = Shaghafi_Live_Scraper::build_variant_retail_links_list( $p_links, $fv['artikelnummer'] );
            }
        }
        if ( empty( $saved_retail_links ) ) {
            $saved_retail_links = Shaghafi_Live_Scraper::get_fallback_retail_search_links( $fv['artikelnummer'], $fv['base_sku'], $fv['variant_id'] );
        }

        $retail_price_val = ( null !== $fv['live_price'] && (float) $fv['live_price'] > 0 )
            ? (float) $fv['live_price']
            : ( '' !== (string) $saved_retail_price && (float) $saved_retail_price > 0 ? (float) $saved_retail_price : null );

        $target_price = self::calculate_target_price( $retail_price_val );
        $source_name  = ! empty( $saved_retail_source ) ? $saved_retail_source : 'سایت مرجع خرده‌فروشی';

        // Preserve any existing valid site price on the parent product so WC_Product_Variable::sync() never loses it
        if ( '' !== $woo_price && (float) str_replace( ',', '.', $woo_price ) > 0 ) {
            if ( '' === trim( (string) get_post_meta( $parent_id, '_shaghafi_preserved_site_price', true ) ) ) {
                update_post_meta( $parent_id, '_shaghafi_preserved_site_price', (string) (float) str_replace( ',', '.', $woo_price ) );
            }
        } elseif ( class_exists( 'Shaghafi_Repairer' ) ) {
            // Auto-heal empty variation/product price from target_price or existing catalog/sibling/parent fallback price (e.g. 44.90 € on 5050833)
            $auto_price = null !== $target_price
                ? (string) $target_price
                : Shaghafi_Repairer::resolve_existing_catalog_fallback_price( $parent_id, $fv );
            if ( '' !== (string) $auto_price && (float) str_replace( ',', '.', (string) $auto_price ) > 0 ) {
                $healed_p = (string) (float) str_replace( ',', '.', (string) $auto_price );
                update_post_meta( $target_post_id, '_regular_price', $healed_p );
                if ( '' === (string) get_post_meta( $target_post_id, '_sale_price', true ) ) {
                    update_post_meta( $target_post_id, '_price', $healed_p );
                }
                update_post_meta( $parent_id, '_shaghafi_preserved_site_price', $healed_p );
                if ( ! $is_simple && class_exists( 'WC_Product_Variable' ) ) {
                    WC_Product_Variable::sync( $parent_id );
                }
                clean_post_cache( $target_post_id );
                wp_cache_delete( $target_post_id, 'post_meta' );
                $woo_price = $healed_p;
            }
        }

        if ( null !== $target_price ) {
            $woo_price_float = '' !== $woo_price ? (float) str_replace( ',', '.', $woo_price ) : -1.0;
            if ( $woo_price_float < 0 || abs( $woo_price_float - $target_price ) > 0.009 ) {
                $needs_sync = true;
                $issues[]   = array(
                    'code' => 'price_mismatch',
                    'text' => sprintf(
                        'اختلاف قیمت با %s: فعلی سایت (%s) ← قیمت مرجع (%s €%s).',
                        $source_name,
                        '' !== $woo_price ? $woo_price : 'بدون قیمت',
                        $retail_price_val,
                        abs( $target_price - (float) $retail_price_val ) > 0.009 ? ' / قیمت هدف: ' . $target_price : ''
                    ),
                );
            }
        } elseif ( '' === $woo_price ) {
            $needs_sync = true;
            $issues[]   = array(
                'code' => 'missing_price',
                'text' => 'قیمت وریشن در سایت خالی است (با «بررسی زنده» یا «اصلاح طبق فایل مرجع» قیمت ثبت می‌شود).',
            );
        } elseif ( empty( $fv['live_checked_at'] ) ) {
            $needs_sync = true;
            $issues[]   = array(
                'code' => 'needs_initial_live_check',
                'text' => 'بررسی زنده قیمت مرجع (AkzentShop / MeinAkzent / Raptor) و وضعیت زنده شقفی هنوز برای این محصول ثبت نشده است (در بروزرسانی یک‌کلیکی یا زمان‌بندی خودکار به صورت ۱۰۰٪ اتوماتیک انجام می‌شود).',
            );
        }

        // 6. Check Variation Image, 700x800 Dimensions (Without Cropping), .webp Format (>=90% quality), and Clean Product Name/Alt
        $is_size_var = ( ! $is_simple && self::is_size_based_product( $parent_id, array( $fv ), $fv['variantenauspraegung'] ) );
        $local_img   = null;

        if ( $is_size_var ) {
            // Size variations (e.g. 45 cm, 50 cm, 55 cm, ring sizes 52, 54, 56) use the Parent Product's main image & gallery
            // and must NOT have separate variation images or trigger variation image warnings!
            if ( $thumb_id > 0 ) {
                $p_thumb_check = (int) get_post_meta( $parent_id, '_thumbnail_id', true );
                if ( $p_thumb_check <= 0 ) {
                    set_post_thumbnail( $parent_id, $thumb_id );
                }
                delete_post_meta( $target_post_id, '_thumbnail_id' );
                $thumb_id = 0;
            }
            $p_thumb = (int) get_post_meta( $parent_id, '_thumbnail_id', true );
            if ( $p_thumb > 0 && self::is_attachment_currently_valid_on_disk( $p_thumb, false ) ) {
                if ( class_exists( 'Shaghafi_Repairer' ) ) {
                    Shaghafi_Repairer::apply_product_name_to_attachment_seo( $p_thumb, $parent_id, $parent_title );
                }
                $woo_image_url = wp_get_attachment_url( $p_thumb ) ?: '';
            } else {
                $woo_image_url = '';
            }
            $dims_str = 'سایزی (تصویر مادر)';
            $dims_ok  = true;
            if ( ! self::is_parent_featured_image_healthy( $parent_id ) ) {
                $needs_structure = true;
                $issues[]        = array(
                    'code' => 'missing_parent_featured_image',
                    'text' => 'تصویر شاخص محصول مادر (Featured Image) حذف یا فاقد پیش‌نمایش است ← با زدن دکمه اصلاح به صورت خودکار از گالری محصول بازیابی می‌شود.',
                );
            }
        } else {
            $local_img = self::find_local_wp_image_for_variant(
                $parent_id,
                $fv['artikelnummer'],
                $fv['variant_id'],
                $fv['live_image_url'],
                $fv['variantenauspraegung']
            );

            $expected_hash = ! empty( $fv['live_image_url'] ) ? Shaghafi_Live_Scraper::extract_image_hash( $fv['live_image_url'] ) : '';
            $current_hash  = $thumb_id > 0 ? strtolower( trim( (string) get_post_meta( $thumb_id, '_shaghafi_media_hash', true ) ) ) : '';
            $img_suff_cur  = ( $thumb_id > 0 && ! empty( $fv['base_sku'] ) ) ? self::extract_sku_suffix_from_attachment( $thumb_id, $fv['base_sku'] ) : '';
            $is_shared_dup_img = (
                ! $is_simple &&
                $thumb_id > 0 &&
                self::is_variation_thumbnail_shared_duplicate( $parent_id, $target_post_id, $thumb_id, $fv )
            );

            // Auto-heal variation image immediately if missing or if multiple sibling variations share a duplicate image
            // and the exact local/library attachment (e.g. by _shaghafi_media_hash or variant SKU) is already on disk!
            if ( ( '' === $woo_image_url || $is_shared_dup_img ) && $local_img && ! empty( $local_img['attachment_id'] ) && (int) $local_img['attachment_id'] !== $thumb_id && class_exists( 'Shaghafi_Repairer' ) ) {
                $healed_att_id = (int) $local_img['attachment_id'];
                Shaghafi_Repairer::ensure_attachment_is_700x800( $healed_att_id, $parent_id, $fv['artikelnummer'], $parent_title );
                if ( self::is_attachment_currently_valid_on_disk( $healed_att_id, false ) ) {
                    update_post_meta( $target_post_id, '_thumbnail_id', $healed_att_id );
                    update_post_meta( $healed_att_id, '_shaghafi_variant_id', $fv['variant_id'] );
                    Shaghafi_Repairer::sync_clean_parent_attributes( $parent_id );
                    $thumb_id          = $healed_att_id;
                    $woo_image_url     = wp_get_attachment_url( $thumb_id ) ?: '';
                    $img_dims          = self::get_attachment_dimensions( $thumb_id );
                    $dims_str          = $img_dims ? ( $img_dims['width'] . '×' . $img_dims['height'] ) : '';
                    $dims_ok           = true;
                    $current_hash      = strtolower( trim( (string) get_post_meta( $thumb_id, '_shaghafi_media_hash', true ) ) );
                    $img_suff_cur      = ! empty( $fv['base_sku'] ) ? self::extract_sku_suffix_from_attachment( $thumb_id, $fv['base_sku'] ) : '';
                    $is_shared_dup_img = false;
                }
            }

            if ( '' === $woo_image_url ) {
                $needs_structure = true;
                $img_source_hint = $local_img
                    ? 'موجود در گالری محصول (ID: ' . $local_img['attachment_id'] . ') و آماده بازیابی خودکار'
                    : ( ! empty( $fv['live_image_url'] ) ? 'آماده دانلود و تبدیل بدون برش به ' . $target_dims['width'] . '×' . $target_dims['height'] . ' WebP' : 'قابل دریافت با بررسی زنده از shaghafi.de' );
                $issues[] = array(
                    'code' => 'missing_image',
                    'text' => $thumb_id > 0
                        ? sprintf( 'عکس فعلی این وریشن در سرور موجود نیست (حذف یا خراب شده پس از ریستور بکاپ) ← %s.', $img_source_hint )
                        : sprintf( 'عکس وریشن تنظیم نشده است (%s).', $img_source_hint ),
                );
            } else {
                // Always enforce clean Product Name as Title & Alt text on the attachment (stripping any "shaghafi" reference)
                if ( $thumb_id > 0 && class_exists( 'Shaghafi_Repairer' ) ) {
                    Shaghafi_Repairer::apply_product_name_to_attachment_seo( $thumb_id, $parent_id, $parent_title );
                }

                $att_file = $thumb_id > 0 ? (string) get_post_meta( $thumb_id, '_wp_attached_file', true ) : '';
                $is_webp  = ( '' !== $att_file && (bool) preg_match( '/\.webp$/i', $att_file ) );
                if ( $is_webp && '' !== $dims_str ) {
                    $dims_str .= ' WebP';
                }

                // Check 700x800 dimensions
                if ( $img_dims && ( $img_dims['width'] !== $target_dims['width'] || $img_dims['height'] !== $target_dims['height'] ) ) {
                    $dims_ok         = false;
                    $needs_structure = true;
                    $issues[]        = array(
                        'code' => 'wrong_image_dimensions',
                        'text' => sprintf(
                            'ابعاد عکس فعلی (%d×%d) است و با استاندارد سایت (%d×%d) مطابقت ندارد ← با زدن اصلاح، بدون برش به %d×%d WebP تبدیل می‌شود.',
                            $img_dims['width'],
                            $img_dims['height'],
                            $target_dims['width'],
                            $target_dims['height'],
                            $target_dims['width'],
                            $target_dims['height']
                        ),
                    );
                }

                if ( $is_shared_dup_img ) {
                    $needs_structure = true;
                    $issues[]        = array(
                        'code' => 'wrong_variant_image',
                        'text' => 'عکس فعلی بین چند وریشن مشترک/تکراری است ← با زدن دکمه اصلاح، عکس اختصاصی این وریشن از شقفی (700×800 WebP بدون برش) جایگزین می‌شود.',
                    );
                } elseif ( ! empty( $fv['base_sku'] ) && ! empty( $fv['sku_suffix_norm'] ) && ( '' === $expected_hash || $expected_hash !== $current_hash ) ) {
                    // Check if current thumbnail filename explicitly belongs to a different variant SKU AND a distinct replacement exists
                    $img_suff = self::extract_sku_suffix_from_attachment( $thumb_id, $fv['base_sku'] );
                    if ( '' !== $img_suff && $img_suff !== $fv['sku_suffix_norm'] ) {
                        $has_better_local = ( $local_img && ! empty( $local_img['attachment_id'] ) && (int) $local_img['attachment_id'] !== $thumb_id );
                        if ( $has_better_local || ! empty( $fv['live_image_url'] ) ) {
                            $needs_structure = true;
                            $issues[]        = array(
                                'code' => 'wrong_variant_image',
                                'text' => sprintf(
                                    'عکس فعلی این وریشن متعلق به کد دیگر (%s-%s) است و باید با عکس صحیح (%s) جابه‌جا شود.',
                                    $fv['base_sku'],
                                    $img_suff,
                                    $fv['artikelnummer']
                                ),
                            );
                        }
                    }
                }
            }

            // Also check if the Parent Product's Featured Image (تصویر شاخص) is missing or has broken WP Admin subsize preview
            if ( ! self::is_parent_featured_image_healthy( $parent_id ) ) {
                $needs_structure = true;
                $issues[]        = array(
                    'code' => 'missing_parent_featured_image',
                    'text' => 'تصویر شاخص محصول مادر (Featured Image) حذف شده یا پیش‌نمایش آن خراب است ← با زدن دکمه اصلاح به صورت خودکار از گالری محصول بازیابی می‌شود.',
                );
            }
        }

        if ( $needs_structure ) {
            $action       = 'REPAIR_AND_LINK';
            $status_label = 'نیاز به تعمیر و اتصال (REPAIR_AND_LINK)';
        } elseif ( $needs_sync ) {
            $action       = 'KEEP_AND_SYNC';
            $status_label = 'نیاز به بروزرسانی موجودی/متا/قیمت (KEEP_AND_SYNC)';
        } else {
            $action       = 'HEALTHY';
            $status_label = 'سالم و هماهنگ با مرجع';
        }

        return array(
            'row_id'              => ( $is_simple ? 's_' : 'v_' ) . $target_post_id,
            'parent_id'           => $parent_id,
            'variation_id'        => $is_simple ? 0 : $target_post_id,
            'is_simple'           => $is_simple,
            'is_size_variation'   => $is_size_var,
            'live_checked'        => ! empty( $fv['live_checked_at'] ),
            'variation_status'    => get_post_status( $target_post_id ),
            'product_title'       => $parent_title,
            'edit_url'            => $edit_link,
            'action'              => $action,
            'status_label'        => $status_label,
            'woo_sku'             => $woo_sku,
            'feed_sku'            => $fv['artikelnummer'],
            'woo_product_id_meta' => $woo_meta_pid,
            'feed_product_id'     => $ref_pid_low,
            'woo_variant_id_meta' => $woo_meta_vid,
            'feed_variant_id'     => $ref_vid_low,
            'woo_attr'            => $attr_info['display_val'],
            'feed_attr'           => $fv['variantenauspraegung'] ?: '—',
            'woo_stock'           => $woo_stock,
            'feed_stock'          => (int) $fv['bestand'],
            'live_stock'          => null !== $fv['live_stock'] ? (int) $fv['live_stock'] : null,
            'woo_price'           => $woo_price,
            'live_price'          => $retail_price_val,
            'target_price'        => $target_price,
            'retail_source'       => $saved_retail_source,
            'retail_url'          => $saved_retail_url,
            'retail_links'        => $saved_retail_links,
            'woo_image_url'       => $woo_image_url,
            'woo_image_dims'      => $dims_str,
            'woo_image_dims_ok'   => $dims_ok,
            'live_image_url'      => (string) $fv['live_image_url'],
            'local_gallery_match' => $local_img,
            'issues'              => $issues,
            'candidate_variants'  => array(),
        );
    }

    /**
     * Update a single parent product's rows inside the cached catalog scan so pagination stays fast & up-to-date
     */
    public static function update_product_in_scan_cache( $parent_id, $new_rows = null ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return;
        }
        $cached = get_transient( self::SCAN_CACHE_KEY );
        if ( ! is_array( $cached ) || empty( $cached['all_rows'] ) ) {
            return;
        }
        if ( null === $new_rows ) {
            $new_rows = self::scan_single_product( $parent_id );
        }
        $updated_all = array();
        $inserted    = false;
        foreach ( $cached['all_rows'] as $r ) {
            if ( (int) $r['parent_id'] === $parent_id ) {
                if ( ! $inserted ) {
                    foreach ( $new_rows as $nr ) {
                        $updated_all[] = $nr;
                    }
                    $inserted = true;
                }
            } else {
                $updated_all[] = $r;
            }
        }
        if ( ! $inserted && ! empty( $new_rows ) ) {
            foreach ( $new_rows as $nr ) {
                $updated_all[] = $nr;
            }
        }
        $cached['all_rows'] = $updated_all;
        $cached['counts']   = self::compute_counts_from_rows( $updated_all, $cached['scanned_products'] );
        set_transient( self::SCAN_CACHE_KEY, $cached, 30 * MINUTE_IN_SECONDS );
    }

    /**
     * Clear scan caches when feed changes or forced rescan is requested
     */
    public static function clear_scan_caches() {
        delete_transient( self::SCAN_CACHE_KEY );
        delete_transient( self::OBSOLETE_CACHE_KEY );
    }

    private static function compute_counts_from_rows( $rows, $scanned_products = 0 ) {
        $counts                     = self::empty_counts();
        $counts['scanned_products'] = (int) $scanned_products;
        foreach ( $rows as $r ) {
            $act = $r['action'];
            $counts['total_items']++;
            if ( isset( $counts[ $act ] ) ) {
                $counts[ $act ]++;
            }
        }
        return $counts;
    }

    /**
     * Scan WooCommerce catalog with full pagination & filtering
     */
    public static function scan_catalog( $args = array() ) {
        Shaghafi_DB::ensure_initialized();

        $search_query  = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
        $filter_action = isset( $args['action_filter'] ) ? trim( (string) $args['action_filter'] ) : 'ALL';
        $page          = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
        $per_page      = isset( $args['per_page'] ) ? max( 10, min( 500, (int) $args['per_page'] ) ) : 50;
        $force_rescan  = ! empty( $args['force_rescan'] );

        $cached = ! $force_rescan ? get_transient( self::SCAN_CACHE_KEY ) : false;

        if ( ! is_array( $cached ) || ! isset( $cached['all_rows'] ) ) {
            global $wpdb;
            $parent_ids = array_map(
                'intval',
                $wpdb->get_col(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'product'
                       AND post_status IN ('publish', 'private', 'draft', 'pending')
                     ORDER BY ID DESC
                     LIMIT 2500"
                )
            );

            $all_rows = array();
            $scanned_cnt = 0;
            foreach ( $parent_ids as $pid ) {
                // Skip products that were already archived/removed via Obsolete Products handler
                if ( 'publish' !== get_post_status( $pid ) && '' !== trim( (string) get_post_meta( $pid, '_shaghafi_obsolete_handled', true ) ) ) {
                    continue;
                }
                $scanned_cnt++;
                $prod_rows = self::scan_single_product( $pid );
                foreach ( $prod_rows as $r ) {
                    $all_rows[] = $r;
                }
            }

            $counts = self::compute_counts_from_rows( $all_rows, $scanned_cnt );
            $cached = array(
                'all_rows'         => $all_rows,
                'counts'           => $counts,
                'scanned_products' => $scanned_cnt,
                'scanned_at'       => current_time( 'mysql' ),
            );
            set_transient( self::SCAN_CACHE_KEY, $cached, 30 * MINUTE_IN_SECONDS );
        }

        $all_rows = $cached['all_rows'];
        $counts   = $cached['counts'];

        // Apply action filter & search filter
        $filtered = array();
        $q_low    = mb_strtolower( $search_query, 'UTF-8' );

        foreach ( $all_rows as $r ) {
            $act = $r['action'];
            if ( '' !== $filter_action && 'ALL' !== $filter_action ) {
                if ( 'ISSUES_ONLY' === $filter_action ) {
                    if ( 'HEALTHY' === $act ) {
                        continue;
                    }
                } elseif ( $act !== $filter_action ) {
                    continue;
                }
            }

            if ( '' !== $q_low ) {
                $haystack = mb_strtolower(
                    $r['product_title'] . ' ' .
                    $r['woo_sku'] . ' ' .
                    $r['feed_sku'] . ' ' .
                    $r['feed_product_id'] . ' ' .
                    $r['feed_variant_id'] . ' ' .
                    $r['parent_id'] . ' ' .
                    $r['variation_id'],
                    'UTF-8'
                );
                if ( false === mb_strpos( $haystack, $q_low ) ) {
                    continue;
                }
            }

            $filtered[] = $r;
        }

        $total_filtered = count( $filtered );
        $total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
        if ( $page > $total_pages ) {
            $page = $total_pages;
        }
        $offset     = ( $page - 1 ) * $per_page;
        $paged_rows = array_slice( $filtered, $offset, $per_page );

        return array(
            'rows'       => $paged_rows,
            'counts'     => $counts,
            'pagination' => array(
                'current_page' => $page,
                'per_page'     => $per_page,
                'total_rows'   => $total_filtered,
                'total_pages'  => $total_pages,
                'from_item'    => $total_filtered > 0 ? $offset + 1 : 0,
                'to_item'      => min( $total_filtered, $offset + $per_page ),
            ),
        );
    }

    /**
     * Scan for Obsolete / Discontinued Products & Variations that are no longer used on Shaghafi.de
     * Categories:
     * 1. REMOVED_FROM_FEED: Product on our site is NOT present in likadeo.csv at all anymore
     * 2. ALL_ZERO_STOCK_IN_FEED: Product exists in likadeo.csv, but 100% of its variants have 0 stock (bestand = 0)
     * 3. ORPHAN_VARIATION: A variation on our site that was removed from the parent product in likadeo.csv
     */
    public static function scan_obsolete_products( $args = array() ) {
        Shaghafi_DB::ensure_initialized();

        $search_query = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
        $type_filter  = isset( $args['type_filter'] ) ? trim( (string) $args['type_filter'] ) : 'ALL';
        $page         = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
        $per_page     = isset( $args['per_page'] ) ? max( 10, min( 500, (int) $args['per_page'] ) ) : 50;
        $force_rescan = ! empty( $args['force_rescan'] );

        $cached = ! $force_rescan ? get_transient( self::OBSOLETE_CACHE_KEY ) : false;

        if ( ! is_array( $cached ) || ! isset( $cached['items'] ) ) {
            global $wpdb;
            $parent_ids = array_map(
                'intval',
                $wpdb->get_col(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'product'
                       AND post_status = 'publish'
                     ORDER BY ID DESC
                     LIMIT 2500"
                )
            );

            $items  = array();
            $counts = array(
                'total'                  => 0,
                'REMOVED_FROM_FEED'      => 0,
                'ALL_ZERO_STOCK_IN_FEED' => 0,
                'ORPHAN_VARIATION'       => 0,
            );

            foreach ( $parent_ids as $pid ) {
                $product = wc_get_product( $pid );
                if ( ! $product ) {
                    continue;
                }
                $p_status    = get_post_status( $pid );
                if ( 'publish' !== $p_status ) {
                    continue;
                }
                $p_title     = $product->get_name();
                $p_sku       = trim( (string) get_post_meta( $pid, '_sku', true ) );
                $p_saved_pid = trim( (string) get_post_meta( $pid, '_shaghafi_product_id', true ) );
                $thumb_id    = (int) $product->get_image_id();
                $img_url     = $thumb_id > 0 ? ( wp_get_attachment_url( $thumb_id ) ?: '' ) : '';
                $edit_link   = get_edit_post_link( $pid, 'raw' );
                $view_link   = get_permalink( $pid );

                $child_ids = array_map(
                    'intval',
                    $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts}
                             WHERE post_parent = %d
                               AND post_type = 'product_variation'
                               AND post_status IN ('publish', 'private', 'draft', 'pending')
                             ORDER BY ID ASC",
                            $pid
                        )
                    )
                );

                // Collect sample child SKUs if parent SKU is empty
                $sample_skus = array();
                if ( '' !== $p_sku ) {
                    $sample_skus[] = $p_sku;
                }
                foreach ( array_slice( $child_ids, 0, 5 ) as $cid ) {
                    $cs = trim( (string) get_post_meta( $cid, '_sku', true ) );
                    if ( '' !== $cs ) {
                        $sample_skus[] = $cs;
                    }
                    if ( '' === $p_saved_pid ) {
                        $cpid = trim( (string) get_post_meta( $cid, '_shaghafi_product_id', true ) );
                        if ( '' !== $cpid ) {
                            $p_saved_pid = $cpid;
                        }
                    }
                }
                $sample_skus = array_values( array_unique( $sample_skus ) );

                $identity = self::resolve_parent_shaghafi_identity( $pid );

                if ( ! $identity || empty( $identity['variants'] ) ) {
                    // Product is completely absent from likadeo.csv!
                    $counts['total']++;
                    $counts['REMOVED_FROM_FEED']++;
                    $items[] = array(
                        'item_id'        => 'obs_p_' . $pid,
                        'parent_id'      => $pid,
                        'variation_id'   => 0,
                        'obsolete_type'  => 'REMOVED_FROM_FEED',
                        'type_label'     => 'حذف کامل از فید شقفی (ناموجود در مرجع)',
                        'product_title'  => $p_title,
                        'post_status'    => $p_status,
                        'woo_sku'        => ! empty( $sample_skus ) ? implode( ' | ', array_slice( $sample_skus, 0, 3 ) ) : '—',
                        'shaghafi_id'    => $p_saved_pid ?: '—',
                        'variations_cnt' => count( $child_ids ),
                        'woo_stock'      => (int) $product->get_stock_quantity(),
                        'feed_stock'     => 0,
                        'image_url'      => $img_url,
                        'edit_url'       => $edit_link,
                        'view_url'       => $view_link,
                        'reason'         => 'این محصول و هیچ‌یک از وریشن‌ها/کدهای آن دیگر در فید مرجع likadeo.csv وجود ندارند و باید از سایت برداشته شود.',
                    );
                    continue;
                }

                // Check if 100% of feed variants for this product have 0 stock or inactive status
                $feed_variants    = $identity['variants'];
                $total_feed_stock = 0;
                $active_feed_cnt  = 0;
                foreach ( $feed_variants as $fv ) {
                    $total_feed_stock += (int) $fv['bestand'];
                    if ( 'active' === strtolower( $fv['status'] ) && (int) $fv['bestand'] > 0 ) {
                        $active_feed_cnt++;
                    }
                }

                if ( 0 === $total_feed_stock || 0 === $active_feed_cnt ) {
                    $counts['total']++;
                    $counts['ALL_ZERO_STOCK_IN_FEED']++;
                    $items[] = array(
                        'item_id'        => 'obs_z_' . $pid,
                        'parent_id'      => $pid,
                        'variation_id'   => 0,
                        'obsolete_type'  => 'ALL_ZERO_STOCK_IN_FEED',
                        'type_label'     => 'اتمام کامل موجودی در شقفی (موجودی کل = ۰)',
                        'product_title'  => $p_title,
                        'post_status'    => $p_status,
                        'woo_sku'        => $p_sku ?: $feed_variants[0]['base_sku'],
                        'shaghafi_id'    => $identity['product_id'],
                        'variations_cnt' => count( $child_ids ),
                        'woo_stock'      => (int) $product->get_stock_quantity(),
                        'feed_stock'     => 0,
                        'image_url'      => $img_url,
                        'edit_url'       => $edit_link,
                        'view_url'       => $view_link,
                        'reason'         => sprintf(
                            'تمام %d وریشن این محصول در فید شقفی دارای موجودی صفر (0) هستند.',
                            count( $feed_variants )
                        ),
                    );
                }

                // Also check if this product has individual orphan variations (DISABLE_MISSING)
                $prod_scan = self::scan_single_product( $pid );
                foreach ( $prod_scan as $pr ) {
                    if ( 'DISABLE_MISSING' === $pr['action'] && (int) $pr['variation_id'] > 0 ) {
                        $counts['total']++;
                        $counts['ORPHAN_VARIATION']++;
                        $items[] = array(
                            'item_id'        => 'obs_v_' . $pr['variation_id'],
                            'parent_id'      => $pid,
                            'variation_id'   => (int) $pr['variation_id'],
                            'obsolete_type'  => 'ORPHAN_VARIATION',
                            'type_label'     => 'وریشن حذف‌شده از فید شقفی',
                            'product_title'  => $p_title . ' (Variation #' . $pr['variation_id'] . ')',
                            'post_status'    => $pr['variation_status'],
                            'woo_sku'        => $pr['woo_sku'] ?: '—',
                            'shaghafi_id'    => $pr['woo_variant_id_meta'] ?: $identity['product_id'],
                            'variations_cnt' => 1,
                            'woo_stock'      => (int) $pr['woo_stock'],
                            'feed_stock'     => 0,
                            'image_url'      => $pr['woo_image_url'] ?: $img_url,
                            'edit_url'       => $edit_link,
                            'view_url'       => $view_link,
                            'reason'         => sprintf(
                                'وریشن با SKU «%s» و ویژگی «%s» دیگر در فید شقفی وجود ندارد و باید حذف/غیرفعال شود.',
                                $pr['woo_sku'] ?: '—',
                                $pr['woo_attr'] ?: '—'
                            ),
                        );
                    }
                }
            }

            $cached = array(
                'items'  => $items,
                'counts' => $counts,
            );
            set_transient( self::OBSOLETE_CACHE_KEY, $cached, 30 * MINUTE_IN_SECONDS );
        }

        $items  = $cached['items'];
        $counts = $cached['counts'];

        $filtered = array();
        $q_low    = mb_strtolower( $search_query, 'UTF-8' );
        foreach ( $items as $it ) {
            if ( '' !== $type_filter && 'ALL' !== $type_filter && $it['obsolete_type'] !== $type_filter ) {
                continue;
            }
            if ( '' !== $q_low ) {
                $hay = mb_strtolower( $it['product_title'] . ' ' . $it['woo_sku'] . ' ' . $it['shaghafi_id'] . ' ' . $it['parent_id'], 'UTF-8' );
                if ( false === mb_strpos( $hay, $q_low ) ) {
                    continue;
                }
            }
            $filtered[] = $it;
        }

        $total_filtered = count( $filtered );
        $total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
        if ( $page > $total_pages ) {
            $page = $total_pages;
        }
        $offset      = ( $page - 1 ) * $per_page;
        $paged_items = array_slice( $filtered, $offset, $per_page );

        return array(
            'items'      => $paged_items,
            'counts'     => $counts,
            'pagination' => array(
                'current_page' => $page,
                'per_page'     => $per_page,
                'total_rows'   => $total_filtered,
                'total_pages'  => $total_pages,
                'from_item'    => $total_filtered > 0 ? $offset + 1 : 0,
                'to_item'      => min( $total_filtered, $offset + $per_page ),
            ),
        );
    }

    private static function empty_counts() {
        return array(
            'scanned_products'     => 0,
            'total_items'          => 0,
            'REPAIR_AND_LINK'      => 0,
            'KEEP_AND_SYNC'        => 0,
            'CREATE_NEW_VARIATION' => 0,
            'DISABLE_MISSING'      => 0,
            'MANUAL_REVIEW'        => 0,
            'HEALTHY'              => 0,
        );
    }
}
