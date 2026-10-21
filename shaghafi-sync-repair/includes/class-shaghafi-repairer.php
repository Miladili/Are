<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Safe Repair, Sync, Attribute Normalizer, 700x800 Uncropped Image Processor & Obsolete Product Handler
 */
class Shaghafi_Repairer {

    /**
     * Guaranteed high WebP quality (>= 90% as requested; 94% preserves 100% of visual detail without artifacts)
     */
    const WEBP_QUALITY = 94;

    /**
     * Filter callback to enforce 94% quality across WordPress image editors & sub-sizes
     */
    public static function force_high_image_quality( $quality, $mime_type = '' ) {
        return self::WEBP_QUALITY;
    }

    /**
     * Ensure WordPress always allows .webp uploads/sideloads
     */
    public static function allow_webp_upload_mime( $mimes ) {
        if ( is_array( $mimes ) ) {
            $mimes['webp'] = 'image/webp';
        }
        return $mimes;
    }

    /**
     * Ensure wp_check_filetype_and_ext recognizes .webp files properly during sideload
     */
    public static function fix_webp_filetype_and_ext( $data, $file, $filename, $mimes ) {
        if ( preg_match( '/\.webp$/i', (string) $filename ) ) {
            $data['ext']             = 'webp';
            $data['type']            = 'image/webp';
            $data['proper_filename'] = false;
        }
        return $data;
    }

    /**
     * Build clean Product Name for image Title & Alt text (strictly stripping any "Shaghafi" / "شقفی" site name)
     */
    public static function build_clean_product_image_title( $parent_id = 0, $fallback_title = '' ) {
        $parent_id = (int) $parent_id;
        $title     = '';
        if ( $parent_id > 0 ) {
            $title = trim( (string) get_the_title( $parent_id ) );
        }
        if ( '' === $title && '' !== trim( (string) $fallback_title ) ) {
            $title = trim( (string) $fallback_title );
        }

        $title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        // Strip trailing pipe/dash segments containing Shaghafi
        $title = preg_replace( '/\s*[\|\-–—]\s*[^\|\-–—]*shaghafi[^\|\-–—]*$/iu', '', $title );
        // Strip any remaining occurrence of shaghafi.de, shaghafi gmbh, shaghafi, or شقفی
        $title = preg_replace( '/\bshaghafi(?:\.de|\s+gmbh)?\b/iu', '', $title );
        $title = str_replace( array( 'شقفی', 'Shaghafi', 'shaghafi' ), '', $title );
        $title = trim( preg_replace( '/\s{2,}/u', ' ', $title ), " \t\n\r\0\x0B|-–—," );

        if ( '' === $title && $parent_id > 0 ) {
            $sku = trim( (string) get_post_meta( $parent_id, '_sku', true ) );
            if ( '' !== $sku ) {
                $title = $sku;
            }
        }
        if ( '' === $title ) {
            $title = 'Product';
        }
        return $title;
    }

    /**
     * Remove every SKU-like token from a scraped product title.
     *
     * The reference sites sometimes embed the order number in the page title
     * (e.g. "Kette 5010034-001" or "Armband RA10256-002"), which previously made the plugin
     * create products whose NAME contained their SKU. This guarantees the WooCommerce product
     * name is purely the human-readable product name.
     *
     * @param string $raw_title  Scraped title.
     * @param string $base_sku   Parent base SKU (e.g. 5010034).
     * @param array  $all_skus   Every variant artikelnummer / SKU of the product.
     * @return string
     */
    public static function sanitize_product_title_without_sku( $raw_title, $base_sku = '', array $all_skus = array() ) {
        $title = html_entity_decode( wp_strip_all_tags( (string) $raw_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $title = preg_replace( '/\s*[\|\-–—]\s*[^\|\-–—]*shaghafi[^\|\-–—]*$/iu', '', $title );
        $title = preg_replace( '/\bshaghafi(?:\.de|\s+gmbh)?\b/iu', '', $title );
        $title = str_replace( array( 'شقفی', 'Shaghafi', 'shaghafi' ), '', $title );

        // Generic SKU patterns first (numeric article numbers 5010034 / 5010034-001 and RA-prefixed Raptor codes),
        // so fuzzy variants like "3000060-6" are removed as a whole before exact-SKU stripping could split them.
        $title = preg_replace( '/\bRA\d{3,}(?:-[A-Za-z0-9]{1,6})?\b/iu', ' ', $title );
        $title = preg_replace( '/\b\d{5,}(?:-\d{1,6})?\b/u', ' ', $title );

        // Remove every concrete SKU known for this product (base SKU + all variant article numbers).
        $skus = array_merge( array( $base_sku ), $all_skus );
        $skus = array_filter( array_map( 'trim', $skus ) );
        usort( $skus, array( __CLASS__, 'sort_strings_longest_first' ) );
        foreach ( array_unique( $skus ) as $sku ) {
            if ( '' === $sku ) {
                continue;
            }
            $title = preg_replace( '/(?<![A-Za-z0-9])' . preg_quote( $sku, '/' ) . '(?![A-Za-z0-9])/iu', ' ', $title );
        }

        // Sweep any detached SKU suffix left behind (e.g. "-001", " - 6").
        $title = preg_replace( '/\s+[\-–—]\s*[A-Za-z0-9]{1,6}(?=\s|$)/u', ' ', $title );

        // Tidy leftover separators, commas and repeated whitespace.
        $title = preg_replace( '/\s*[\|\-–—_,،]+\s*$/u', '', trim( $title ) );
        $title = preg_replace( '/^[\|\-–—_,،\s]+/u', '', $title );
        $title = trim( preg_replace( '/\s{2,}/u', ' ', $title ), " \t\n\r\0\x0B|-–—_,،" );

        return trim( (string) $title );
    }

    /**
     * usort() callback: longest strings first so long SKUs are stripped before their prefixes.
     */
    private static function sort_strings_longest_first( $a, $b ) {
        return mb_strlen( $b, 'UTF-8' ) - mb_strlen( $a, 'UTF-8' );
    }

    /**
     * Build clean SEO-friendly .webp filename using Product Name + SKU (strictly zero "shaghafi" in filename)
     */
    public static function build_clean_webp_filename( $parent_id = 0, $sku = '', $fallback_title = '' ) {
        $clean_title = self::build_clean_product_image_title( $parent_id, $fallback_title );
        $translit    = strtr(
            $clean_title,
            array(
                'Ä' => 'Ae',
                'Ö' => 'Oe',
                'Ü' => 'Ue',
                'ä' => 'ae',
                'ö' => 'oe',
                'ü' => 'ue',
                'ß' => 'ss',
            )
        );
        $slug = sanitize_title( $translit );
        $slug = str_replace( 'shaghafi', '', $slug );
        $slug = trim( preg_replace( '/-+/', '-', $slug ), '-' );
        if ( strlen( $slug ) > 70 ) {
            $slug = substr( $slug, 0, 70 );
            $slug = rtrim( $slug, '-' );
        }
        if ( '' === $slug ) {
            $slug = 'product';
        }

        $sku_clean = trim( (string) $sku );
        $sku_clean = preg_replace( '/[^A-Za-z0-9\-_]/', '', $sku_clean );
        if ( '' !== $sku_clean && stripos( $slug, strtolower( $sku_clean ) ) === false ) {
            $base_name = $slug . '-' . $sku_clean;
        } else {
            $base_name = $slug;
        }

        return sanitize_file_name( $base_name . '.webp' );
    }

    /**
     * Set attachment Title (post_title), Slug (post_name), and Alt Text (_wp_attachment_image_alt)
     * to the exact clean Product Name, removing any "Shaghafi" reference.
     */
    public static function apply_product_name_to_attachment_seo( $attachment_id, $parent_id = 0, $fallback_title = '' ) {
        global $wpdb;
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 ) {
            return;
        }
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            $parent_id = (int) wp_get_post_parent_id( $attachment_id );
        }

        $clean_title = self::build_clean_product_image_title( $parent_id, $fallback_title );
        if ( '' === $clean_title ) {
            return;
        }

        // 1. Always set _wp_attachment_image_alt to the clean Product Name
        $cur_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
        if ( $cur_alt !== $clean_title || stripos( $cur_alt, 'shaghafi' ) !== false || strpos( $cur_alt, 'شقفی' ) !== false ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $clean_title );
        }

        // 2. Ensure attachment post_title, post_name, post_excerpt, and post_content use the Product Name and never contain "shaghafi"
        $att_post = get_post( $attachment_id );
        if ( $att_post ) {
            $cur_title   = trim( (string) $att_post->post_title );
            $cur_slug    = (string) $att_post->post_name;
            $cur_content = (string) $att_post->post_content;
            $cur_excerpt = (string) $att_post->post_excerpt;

            $needs_post_update = (
                $cur_title !== $clean_title ||
                stripos( $cur_title, 'shaghafi' ) !== false ||
                stripos( $cur_slug, 'shaghafi' ) !== false ||
                stripos( $cur_content, 'shaghafi' ) !== false ||
                stripos( $cur_excerpt, 'shaghafi' ) !== false
            );

            if ( $needs_post_update ) {
                $clean_slug = sanitize_title( $clean_title . '-' . $attachment_id );
                $wpdb->update(
                    $wpdb->posts,
                    array(
                        'post_title'   => $clean_title,
                        'post_name'    => $clean_slug,
                        'post_content' => $clean_title,
                        'post_excerpt' => '',
                    ),
                    array( 'ID' => $attachment_id ),
                    array( '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
                clean_post_cache( $attachment_id );
            }
        }
    }

    /**
     * Filter callback to prevent WordPress/WooCommerce from hard-cropping intermediate sizes during sideload
     */
    public static function disable_hard_crop_on_subsizes( $sizes ) {
        if ( ! is_array( $sizes ) ) {
            return $sizes;
        }
        foreach ( $sizes as $name => &$cfg ) {
            if ( isset( $cfg['crop'] ) ) {
                $cfg['crop'] = false;
            }
        }
        unset( $cfg );
        return $sizes;
    }

    /**
     * Convert an image file on disk to exact target canvas (default 700x800) WITHOUT CROPPING (بدون برش)
     * and encode as high-quality WebP (94% quality >= 90%, zero visual loss) inside a pure white (#FFFFFF) 700x800 canvas.
     */
    public static function convert_file_to_700x800_uncropped( $file_path, $target_w = 0, $target_h = 0, $output_path = '' ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return new WP_Error( 'img_missing', 'فایل تصویر برای تغییر سایز یافت نشد.' );
        }

        if ( '' === $output_path ) {
            $output_path = $file_path;
        }

        if ( $target_w <= 0 || $target_h <= 0 ) {
            $dims     = Shaghafi_Scanner::get_target_image_dimensions();
            $target_w = $dims['width'];
            $target_h = $dims['height'];
        }

        $info = @getimagesize( $file_path );
        if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
            return new WP_Error( 'img_invalid', 'ابعاد فایل تصویر قابل خواندن نیست.' );
        }

        $src_w = (int) $info[0];
        $src_h = (int) $info[1];
        $mime  = isset( $info['mime'] ) ? strtolower( $info['mime'] ) : 'image/jpeg';

        // Only skip if already exact target dimensions (700x800) AND already image/webp AND same file path!
        if ( $src_w === $target_w && $src_h === $target_h && 'image/webp' === $mime && $output_path === $file_path ) {
            return array(
                'width'     => $target_w,
                'height'    => $target_h,
                'mime'      => 'image/webp',
                'ext'       => 'webp',
                'file_path' => $output_path,
                'changed'   => false,
            );
        }

        // Proportional scale factor (CONTAIN - zero cropping!)
        $scale = min( $target_w / $src_w, $target_h / $src_h );
        $new_w = max( 1, (int) round( $src_w * $scale ) );
        $new_h = max( 1, (int) round( $src_h * $scale ) );
        $dst_x = (int) floor( ( $target_w - $new_w ) / 2 );
        $dst_y = (int) floor( ( $target_h - $new_h ) / 2 );

        $work_webp = $output_path . '.work.webp';
        $work_jpg  = $output_path . '.work.jpg';

        // 1. Primary Engine: PHP GD (fast, reliable on .tmp files, supports imagewebp at 94% quality)
        if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagecopyresampled' ) ) {
            $src_im = null;
            if ( 'image/jpeg' === $mime || 'image/jpg' === $mime ) {
                $src_im = @imagecreatefromjpeg( $file_path );
            } elseif ( 'image/png' === $mime ) {
                $src_im = @imagecreatefrompng( $file_path );
            } elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
                $src_im = @imagecreatefromwebp( $file_path );
            } elseif ( 'image/gif' === $mime ) {
                $src_im = @imagecreatefromgif( $file_path );
            }
            if ( ! $src_im ) {
                $raw = @file_get_contents( $file_path );
                if ( $raw ) {
                    $src_im = @imagecreatefromstring( $raw );
                }
            }

            if ( $src_im ) {
                $canvas = imagecreatetruecolor( $target_w, $target_h );
                if ( function_exists( 'imagepalettetotruecolor' ) ) {
                    @imagepalettetotruecolor( $canvas );
                }
                $white = imagecolorallocate( $canvas, 255, 255, 255 );
                imagefill( $canvas, 0, 0, $white );
                imagealphablending( $canvas, true );

                imagecopyresampled(
                    $canvas,
                    $src_im,
                    $dst_x,
                    $dst_y,
                    0,
                    0,
                    $new_w,
                    $new_h,
                    $src_w,
                    $src_h
                );

                // 1a. Try saving as high-quality WebP (94% quality) and verify with getimagesize()
                if ( function_exists( 'imagewebp' ) ) {
                    @unlink( $work_webp );
                    $webp_ok = @imagewebp( $canvas, $work_webp, self::WEBP_QUALITY );
                    clearstatcache( true, $work_webp );
                    if ( $webp_ok && file_exists( $work_webp ) && filesize( $work_webp ) > 64 ) {
                        $chk = @getimagesize( $work_webp );
                        if ( $chk && ! empty( $chk[0] ) && ( ! isset( $chk['mime'] ) || 'image/webp' === strtolower( $chk['mime'] ) ) ) {
                            imagedestroy( $src_im );
                            imagedestroy( $canvas );
                            @rename( $work_webp, $output_path );
                            clearstatcache( true, $output_path );
                            return array(
                                'width'     => $target_w,
                                'height'    => $target_h,
                                'mime'      => 'image/webp',
                                'ext'       => 'webp',
                                'file_path' => $output_path,
                                'changed'   => true,
                            );
                        }
                    }
                    @unlink( $work_webp );
                }

                // 1b. If GD lacks WebP, try Imagick for WebP before falling back to GD JPEG
                if ( class_exists( 'Imagick' ) ) {
                    try {
                        @unlink( $work_webp );
                        $im = new Imagick();
                        $im->readImage( $file_path );
                        $im->thumbnailImage( $target_w, $target_h, true, false );
                        $im_canvas = new Imagick();
                        $im_canvas->newImage( $target_w, $target_h, new ImagickPixel( '#ffffff' ) );
                        $geo = $im->getImageGeometry();
                        $x   = (int) floor( ( $target_w - $geo['width'] ) / 2 );
                        $y   = (int) floor( ( $target_h - $geo['height'] ) / 2 );
                        $im_canvas->compositeImage( $im, Imagick::COMPOSITE_OVER, $x, $y );
                        $im_canvas->setImageFormat( 'webp' );
                        $im_canvas->setImageCompressionQuality( self::WEBP_QUALITY );
                        $im_canvas->writeImage( $work_webp );
                        $im->clear();
                        $im_canvas->clear();
                        clearstatcache( true, $work_webp );
                        if ( file_exists( $work_webp ) && filesize( $work_webp ) > 64 ) {
                            $chk = @getimagesize( $work_webp );
                            if ( $chk && ! empty( $chk[0] ) && isset( $chk['mime'] ) && 'image/webp' === strtolower( $chk['mime'] ) ) {
                                imagedestroy( $src_im );
                                imagedestroy( $canvas );
                                @rename( $work_webp, $output_path );
                                clearstatcache( true, $output_path );
                                return array(
                                    'width'     => $target_w,
                                    'height'    => $target_h,
                                    'mime'      => 'image/webp',
                                    'ext'       => 'webp',
                                    'file_path' => $output_path,
                                    'changed'   => true,
                                );
                            }
                        }
                        @unlink( $work_webp );
                    } catch ( Throwable $e ) {
                        @unlink( $work_webp );
                    }
                }

                // 1c. Guaranteed fallback: save 94% JPEG via GD
                @unlink( $work_jpg );
                $jpg_ok = @imagejpeg( $canvas, $work_jpg, self::WEBP_QUALITY );
                imagedestroy( $src_im );
                imagedestroy( $canvas );
                clearstatcache( true, $work_jpg );
                if ( $jpg_ok && file_exists( $work_jpg ) && filesize( $work_jpg ) > 64 ) {
                    @rename( $work_jpg, $output_path );
                    clearstatcache( true, $output_path );
                    return array(
                        'width'     => $target_w,
                        'height'    => $target_h,
                        'mime'      => 'image/jpeg',
                        'ext'       => 'jpg',
                        'file_path' => $output_path,
                        'changed'   => true,
                    );
                }
                @unlink( $work_jpg );
            }
        }

        // 2. Standalone Imagick fallback (if PHP GD is disabled on server)
        if ( class_exists( 'Imagick' ) ) {
            try {
                @unlink( $work_webp );
                $im = new Imagick();
                $im->readImage( $file_path );
                $im->thumbnailImage( $target_w, $target_h, true, false );
                $canvas = new Imagick();
                $canvas->newImage( $target_w, $target_h, new ImagickPixel( '#ffffff' ) );
                $geo = $im->getImageGeometry();
                $x   = (int) floor( ( $target_w - $geo['width'] ) / 2 );
                $y   = (int) floor( ( $target_h - $geo['height'] ) / 2 );
                $canvas->compositeImage( $im, Imagick::COMPOSITE_OVER, $x, $y );
                $canvas->setImageFormat( 'webp' );
                $canvas->setImageCompressionQuality( self::WEBP_QUALITY );
                $canvas->writeImage( $work_webp );
                $im->clear();
                $canvas->clear();
                clearstatcache( true, $work_webp );
                if ( file_exists( $work_webp ) && filesize( $work_webp ) > 64 ) {
                    @rename( $work_webp, $output_path );
                    clearstatcache( true, $output_path );
                    return array(
                        'width'     => $target_w,
                        'height'    => $target_h,
                        'mime'      => 'image/webp',
                        'ext'       => 'webp',
                        'file_path' => $output_path,
                        'changed'   => true,
                    );
                }
                @unlink( $work_webp );
            } catch ( Throwable $e ) {
                @unlink( $work_webp );
                return new WP_Error( 'imagick_error', $e->getMessage() );
            }
        }

        return new WP_Error( 'resize_failed', 'امکان تبدیل تصویر به فرمت WebP با ابعاد 700×800 بدون برش فراهم نشد.' );
    }

    /**
     * Locate a sibling .webp/.jpg/.jpeg/.png file or subsize thumbnail on disk for an attachment
     * whose DB `_wp_attached_file` was rolled back by a database backup restore.
     */
    public static function find_healable_file_for_attachment( $attachment_id, $sku = '', $image_hash = '' ) {
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 || ! get_post( $attachment_id ) ) {
            return null;
        }

        $file       = get_attached_file( $attachment_id );
        $upload     = wp_upload_dir();
        $base_dir   = ! empty( $upload['basedir'] ) ? $upload['basedir'] : '';
        $base_url   = ! empty( $upload['baseurl'] ) ? $upload['baseurl'] : '';
        $search_dir = ( $file && '' !== dirname( $file ) && '.' !== dirname( $file ) && is_dir( dirname( $file ) ) )
            ? dirname( $file )
            : $base_dir;

        $found_file = '';
        $is_subsize = false;

        // 1. Check exact same stem with .webp / .jpg / .jpeg / .png right next to $file
        if ( $file && '' !== $search_dir ) {
            $stem = preg_replace( '/\.[a-zA-Z0-9]+$/', '', $file );
            foreach ( array( '.webp', '.jpg', '.jpeg', '.png', '.WEBP', '.JPG', '.JPEG', '.PNG' ) as $ext ) {
                $cand = $stem . $ext;
                if ( file_exists( $cand ) && filesize( $cand ) > 64 && @getimagesize( $cand ) ) {
                    $found_file = $cand;
                    break;
                }
            }
        }

        // 2. Check by SKU or Shaghafi media hash in $search_dir
        if ( '' === $found_file && '' !== $search_dir && is_dir( $search_dir ) ) {
            $bname = $file ? basename( $file ) : '';
            if ( '' === $sku && preg_match( '/([A-Za-z0-9]+-\d{3})/', $bname, $sm ) ) {
                $sku = $sm[1];
            }
            if ( '' === $image_hash && preg_match( '/([a-f0-9]{32,40})/i', $bname, $hm ) ) {
                $image_hash = strtolower( $hm[1] );
            }
            if ( '' === $image_hash ) {
                $image_hash = strtolower( trim( (string) get_post_meta( $attachment_id, '_shaghafi_media_hash', true ) ) );
            }

            $patterns = array();
            if ( '' !== $sku ) {
                $patterns[] = trailingslashit( $search_dir ) . '*' . $sku . '*.webp';
            }
            if ( '' !== $image_hash && strlen( $image_hash ) >= 16 ) {
                $patterns[] = trailingslashit( $search_dir ) . '*' . $image_hash . '*.webp';
            }

            foreach ( $patterns as $pat ) {
                $matches = @glob( $pat );
                if ( ! empty( $matches ) && is_array( $matches ) ) {
                    foreach ( $matches as $m_file ) {
                        if ( preg_match( '/-\d{2,3}x\d{2,3}\.[a-zA-Z0-9]+$/', $m_file ) && ! preg_match( '/-700x800(-1)?\.webp$/i', $m_file ) ) {
                            continue;
                        }
                        if ( file_exists( $m_file ) && filesize( $m_file ) > 64 && @getimagesize( $m_file ) ) {
                            $found_file = $m_file;
                            break 2;
                        }
                    }
                }
            }
        }

        // 3. Check if subsize thumbnails (e.g. -320x366.jpeg or -150x171.jpeg) exist on disk for this stem
        if ( '' === $found_file && $file && '' !== $search_dir && is_dir( $search_dir ) ) {
            $stem        = preg_replace( '/\.[a-zA-Z0-9]+$/', '', $file );
            $sub_matches = @glob( $stem . '-*.*' );
            $best_sub    = '';
            $best_area   = 0;
            if ( ! empty( $sub_matches ) && is_array( $sub_matches ) ) {
                foreach ( $sub_matches as $s_file ) {
                    if ( file_exists( $s_file ) && filesize( $s_file ) > 64 ) {
                        $s_info = @getimagesize( $s_file );
                        if ( $s_info && ! empty( $s_info[0] ) && ! empty( $s_info[1] ) ) {
                            $area = (int) $s_info[0] * (int) $s_info[1];
                            if ( $area > $best_area ) {
                                $best_area = $area;
                                $best_sub  = $s_file;
                            }
                        }
                    }
                }
            }
            if ( '' !== $best_sub ) {
                $found_file = $best_sub;
                $is_subsize = true;
            }
        }

        if ( '' === $found_file || ! file_exists( $found_file ) ) {
            return null;
        }

        $info = @getimagesize( $found_file );
        $dims = ( $info && ! empty( $info[0] ) && ! empty( $info[1] ) )
            ? array(
                'width'  => (int) $info[0],
                'height' => (int) $info[1],
            )
            : null;

        $found_url = '';
        if ( '' !== $base_dir && '' !== $base_url && 0 === strpos( $found_file, $base_dir ) ) {
            $rel       = ltrim( str_replace( '\\', '/', substr( $found_file, strlen( $base_dir ) ) ), '/' );
            $found_url = trailingslashit( $base_url ) . $rel;
        } else {
            $att_url = wp_get_attachment_url( $attachment_id );
            if ( $att_url ) {
                $found_url = trailingslashit( dirname( $att_url ) ) . basename( $found_file );
            }
        }

        return array(
            'file_path'  => $found_file,
            'is_subsize' => $is_subsize,
            'url'        => $found_url,
            'dims'       => $dims,
        );
    }

    /**
     * Automatically heal an attachment whose DB `_wp_attached_file` was rolled back to `.jpeg`/`.jpg`/`.png`
     * after a database backup restore while the converted `.webp` file (or subsize) exists on disk!
     */
    public static function heal_attachment_after_backup_restore( $attachment_id, $parent_id = 0, $sku = '', $image_hash = '' ) {
        global $wpdb;
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 || ! get_post( $attachment_id ) ) {
            return false;
        }

        $file      = get_attached_file( $attachment_id );
        $orig_file = $file;

        if ( ! $file || ! file_exists( $file ) || filesize( $file ) <= 64 ) {
            $heal_info = self::find_healable_file_for_attachment( $attachment_id, $sku, $image_hash );
            if ( ! $heal_info || empty( $heal_info['file_path'] ) ) {
                return false;
            }

            $found_file = $heal_info['file_path'];
            if ( ! empty( $heal_info['is_subsize'] ) && $file ) {
                $stem         = preg_replace( '/\.[a-zA-Z0-9]+$/', '', $file );
                $rebuilt_webp = $stem . '.webp';
                $target_dims  = Shaghafi_Scanner::get_target_image_dimensions();
                $conv         = self::convert_file_to_700x800_uncropped( $found_file, $target_dims['width'], $target_dims['height'], $rebuilt_webp );
                if ( ! is_wp_error( $conv ) && ! empty( $conv['file_path'] ) && file_exists( $conv['file_path'] ) ) {
                    $found_file = $conv['file_path'];
                }
            }

            if ( ! file_exists( $found_file ) ) {
                return false;
            }

            // Copy $found_file to the old $orig_file path too so cached frontend HTML requesting .jpeg/.png never 404s!
            if ( $orig_file && $orig_file !== $found_file && ! file_exists( $orig_file ) ) {
                @copy( $found_file, $orig_file );
            }

            $info        = @getimagesize( $found_file );
            $actual_mime = ( $info && ! empty( $info['mime'] ) ) ? strtolower( $info['mime'] ) : 'image/webp';

            update_attached_file( $attachment_id, $found_file );
            $wpdb->update(
                $wpdb->posts,
                array( 'post_mime_type' => $actual_mime ),
                array( 'ID' => $attachment_id ),
                array( '%s' ),
                array( '%d' )
            );
            clean_post_cache( $attachment_id );
            wp_cache_delete( $attachment_id, 'post_meta' );
            $file = $found_file;
        }

        // Verify that _wp_attachment_metadata and its subsize files (especially 'medium' & 'thumbnail' used by WP Admin Featured Image & Gallery boxes) exist on disk!
        $meta           = wp_get_attachment_metadata( $attachment_id );
        $dir            = dirname( $file );
        $need_meta_sync = false;
        if ( ! is_array( $meta ) || empty( $meta['file'] ) || basename( (string) $meta['file'] ) !== basename( $file ) ) {
            $need_meta_sync = true;
        } elseif ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
            $need_meta_sync = true;
        } else {
            foreach ( $meta['sizes'] as $sz_cfg ) {
                if ( ! empty( $sz_cfg['file'] ) ) {
                    $sub_path = trailingslashit( $dir ) . $sz_cfg['file'];
                    if ( ! file_exists( $sub_path ) ) {
                        $need_meta_sync = true;
                        break;
                    }
                }
            }
        }

        if ( $need_meta_sync ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $target_dims = Shaghafi_Scanner::get_target_image_dimensions();
            add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'disable_hard_crop_on_subsizes' ), 99 );
            add_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_high_image_quality' ), 99, 2 );
            add_filter( 'jpeg_quality', array( __CLASS__, 'force_high_image_quality' ), 99, 2 );
            $new_meta = wp_generate_attachment_metadata( $attachment_id, $file );
            remove_filter( 'jpeg_quality', array( __CLASS__, 'force_high_image_quality' ), 99 );
            remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_high_image_quality' ), 99 );
            remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'disable_hard_crop_on_subsizes' ), 99 );
            if ( is_array( $new_meta ) && ! empty( $new_meta ) ) {
                $info = @getimagesize( $file );
                $new_meta['width']  = ( $info && ! empty( $info[0] ) ) ? (int) $info[0] : $target_dims['width'];
                $new_meta['height'] = ( $info && ! empty( $info[1] ) ) ? (int) $info[1] : $target_dims['height'];
                wp_update_attachment_metadata( $attachment_id, $new_meta );
            }
        }

        return true;
    }

    /**
     * Heal all attachments on a product (Featured Image, Product Gallery, Variation Thumbnails, and WCBoost Swatches),
     * restore missing/broken Parent Featured Image (تصویر شاخص) from Product Gallery, and re-attach variation images
     * from Product Gallery (_product_image_gallery) if they were removed or broken when restoring a database backup!
     */
    public static function heal_product_gallery_and_variation_images( $parent_id, $feed_variants = array() ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return;
        }

        if ( empty( $feed_variants ) && class_exists( 'Shaghafi_Scanner' ) ) {
            $identity      = Shaghafi_Scanner::resolve_parent_shaghafi_identity( $parent_id );
            $feed_variants = ( $identity && ! empty( $identity['variants'] ) ) ? $identity['variants'] : array();
        }

        $parent_title = trim( (string) get_the_title( $parent_id ) );
        $parent_sku   = trim( (string) get_post_meta( $parent_id, '_sku', true ) );

        // 1. Collect all attachment IDs associated with this product and heal their files/metadata on disk
        $p_thumb     = (int) get_post_meta( $parent_id, '_thumbnail_id', true );
        $gallery_raw = (string) get_post_meta( $parent_id, '_product_image_gallery', true );
        $gallery_ids = array();
        if ( '' !== $gallery_raw ) {
            foreach ( explode( ',', $gallery_raw ) as $gid ) {
                $gid = (int) trim( $gid );
                if ( $gid > 0 ) {
                    $gallery_ids[] = $gid;
                }
            }
        }

        if ( $p_thumb > 0 ) {
            self::heal_attachment_after_backup_restore( $p_thumb, $parent_id, $parent_sku );
        }
        $valid_gallery_ids = array();
        foreach ( $gallery_ids as $gid ) {
            if ( self::heal_attachment_after_backup_restore( $gid, $parent_id, $parent_sku ) ) {
                self::ensure_attachment_is_700x800( $gid, $parent_id, $parent_sku, $parent_title );
                $valid_gallery_ids[] = $gid;
            }
        }

        $child_ids = get_posts(
            array(
                'post_parent' => $parent_id,
                'post_type'   => 'product_variation',
                'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
                'numberposts' => -1,
                'orderby'     => 'menu_order',
                'order'       => 'ASC',
                'fields'      => 'ids',
            )
        );

        foreach ( $child_ids as $cid ) {
            $cid     = (int) $cid;
            $c_thumb = (int) get_post_meta( $cid, '_thumbnail_id', true );
            $c_sku   = trim( (string) get_post_meta( $cid, '_sku', true ) );
            if ( $c_thumb > 0 ) {
                self::heal_attachment_after_backup_restore( $c_thumb, $parent_id, $c_sku );
            }
        }

        // 2. If Parent Featured Image (_thumbnail_id) is missing or broken while Product Gallery has valid images, restore it!
        if ( ( $p_thumb <= 0 || ! Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $p_thumb, false ) ) && ! empty( $valid_gallery_ids ) ) {
            $first_gal = (int) $valid_gallery_ids[0];
            self::ensure_attachment_is_700x800( $first_gal, $parent_id, $parent_sku, $parent_title );
            set_post_thumbnail( $parent_id, $first_gal );
            $p_thumb = $first_gal;
        } elseif ( $p_thumb > 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $p_thumb, false ) ) {
            self::ensure_attachment_is_700x800( $p_thumb, $parent_id, $parent_sku, $parent_title );
        }

        // 3. For non-size variable products: if any child variation lost its image (_thumbnail_id = 0 or invalid)
        // when restoring a backup, restore its image from the Product Gallery (_product_image_gallery) or Media Library!
        $is_size_prod = Shaghafi_Scanner::is_size_based_product( $parent_id, $feed_variants );
        if ( ! $is_size_prod && ! empty( $child_ids ) ) {
            $fv_by_vid = array();
            $fv_by_sku = array();
            foreach ( (array) $feed_variants as $fv ) {
                if ( ! empty( $fv['variant_id'] ) ) {
                    $fv_by_vid[ strtolower( trim( (string) $fv['variant_id'] ) ) ] = $fv;
                }
                if ( ! empty( $fv['artikelnummer'] ) ) {
                    $fv_by_sku[ strtolower( trim( (string) $fv['artikelnummer'] ) ) ] = $fv;
                }
            }

            $any_restored = false;
            foreach ( $child_ids as $cid ) {
                $cid     = (int) $cid;
                $c_thumb = (int) get_post_meta( $cid, '_thumbnail_id', true );
                $c_sku   = trim( (string) get_post_meta( $cid, '_sku', true ) );
                $c_vid   = strtolower( trim( (string) get_post_meta( $cid, '_shaghafi_variant_id', true ) ) );

                $matched_fv = null;
                if ( '' !== $c_vid && isset( $fv_by_vid[ $c_vid ] ) ) {
                    $matched_fv = $fv_by_vid[ $c_vid ];
                } elseif ( '' !== $c_sku && isset( $fv_by_sku[ strtolower( $c_sku ) ] ) ) {
                    $matched_fv = $fv_by_sku[ strtolower( $c_sku ) ];
                }

                if ( $c_thumb > 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $c_thumb, false ) ) {
                    self::ensure_attachment_is_700x800( $c_thumb, $parent_id, $c_sku ?: $parent_sku, $parent_title );
                    continue;
                }

                $live_img = ( $matched_fv && ! empty( $matched_fv['live_image_url'] ) ) ? $matched_fv['live_image_url'] : '';
                $opt_val  = ( $matched_fv && isset( $matched_fv['variantenauspraegung'] ) ) ? $matched_fv['variantenauspraegung'] : '';
                $use_sku  = '' !== $c_sku ? $c_sku : ( $matched_fv ? $matched_fv['artikelnummer'] : '' );
                $use_vid  = '' !== $c_vid ? $c_vid : ( $matched_fv ? $matched_fv['variant_id'] : '' );

                $local_match = Shaghafi_Scanner::find_local_wp_image_for_variant(
                    $parent_id,
                    $use_sku,
                    $use_vid,
                    $live_img,
                    $opt_val
                );

                if ( $local_match && ! empty( $local_match['attachment_id'] ) ) {
                    $restored_id = (int) $local_match['attachment_id'];
                    self::ensure_attachment_is_700x800( $restored_id, $parent_id, $use_sku ?: $parent_sku, $parent_title );
                    if ( Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $restored_id, false ) ) {
                        update_post_meta( $cid, '_thumbnail_id', $restored_id );
                        if ( '' !== $use_vid ) {
                            update_post_meta( $restored_id, '_shaghafi_variant_id', $use_vid );
                        }
                        if ( $p_thumb <= 0 ) {
                            set_post_thumbnail( $parent_id, $restored_id );
                            $p_thumb = $restored_id;
                        }
                        $any_restored = true;
                    }
                }
            }

            if ( $any_restored ) {
                clean_post_cache( $parent_id );
                wp_cache_delete( $parent_id, 'post_meta' );
            }
        }
    }

    /**
     * Ensure an existing WordPress attachment is a 700x800 (uncropped) WebP image (quality >= 90%),
     * has no "shaghafi" in its filename, and has the Product Name set as both its Title and Alt text!
     */
    public static function ensure_attachment_is_700x800( $attachment_id, $parent_id = 0, $sku = '', $fallback_title = '' ) {
        global $wpdb;
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 ) {
            return false;
        }
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            $parent_id = (int) wp_get_post_parent_id( $attachment_id );
        }

        // Always enforce clean Product Name on attachment Title, Slug, and Alt text (zero "shaghafi")
        self::apply_product_name_to_attachment_seo( $attachment_id, $parent_id, $fallback_title );

        // Auto-heal if DB backup restore reverted _wp_attached_file to .jpeg/.png while .webp exists on disk
        self::heal_attachment_after_backup_restore( $attachment_id, $parent_id, $sku );

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return false;
        }

        $dir          = dirname( $file );
        $basename     = basename( $file );
        $is_webp_ext  = (bool) preg_match( '/\.webp$/i', $basename );
        $has_shaghafi = ( false !== stripos( $basename, 'shaghafi' ) );
        $target_file  = $file;

        if ( ! $is_webp_ext || $has_shaghafi ) {
            if ( $has_shaghafi ) {
                $desired_name = self::build_clean_webp_filename( $parent_id, $sku, $fallback_title );
            } else {
                $desired_name = preg_replace( '/\.[a-zA-Z0-9]+$/', '', $basename ) . '.webp';
            }
            $unique_webp = wp_unique_filename( $dir, $desired_name );
            $target_file = trailingslashit( $dir ) . $unique_webp;
        }

        $target_dims = Shaghafi_Scanner::get_target_image_dimensions();
        $res         = self::convert_file_to_700x800_uncropped( $file, $target_dims['width'], $target_dims['height'], $target_file );
        if ( is_wp_error( $res ) ) {
            return false;
        }

        // If the server only supported JPEG fallback, adjust extension to .jpg so file extension matches actual MIME
        if ( ! empty( $res['ext'] ) && 'jpg' === $res['ext'] && preg_match( '/\.webp$/i', $target_file ) ) {
            $jpg_name = wp_unique_filename( $dir, preg_replace( '/\.webp$/i', '.jpg', basename( $target_file ) ) );
            $jpg_file = trailingslashit( $dir ) . $jpg_name;
            if ( @rename( $target_file, $jpg_file ) ) {
                $target_file = $jpg_file;
            }
        }

        $file_switched = false;
        if ( $target_file !== $file && file_exists( $target_file ) && filesize( $target_file ) > 64 ) {
            $actual_mime = isset( $res['mime'] ) ? $res['mime'] : 'image/webp';
            update_attached_file( $attachment_id, $target_file );
            $wpdb->update(
                $wpdb->posts,
                array( 'post_mime_type' => $actual_mime ),
                array( 'ID' => $attachment_id ),
                array( '%s' ),
                array( '%d' )
            );
            clean_post_cache( $attachment_id );
            // Keep a copy at the old path ($file) when only extension changed so restoring a DB backup never causes 404s!
            if ( $has_shaghafi && file_exists( $file ) ) {
                @unlink( $file );
            } elseif ( file_exists( $target_file ) ) {
                @copy( $target_file, $file );
            }
            $file          = $target_file;
            $file_switched = true;
        } elseif ( isset( $res['mime'] ) && $res['mime'] !== get_post_mime_type( $attachment_id ) ) {
            $wpdb->update(
                $wpdb->posts,
                array( 'post_mime_type' => $res['mime'] ),
                array( 'ID' => $attachment_id ),
                array( '%s' ),
                array( '%d' )
            );
            clean_post_cache( $attachment_id );
        }

        $meta                = wp_get_attachment_metadata( $attachment_id );
        $any_subsize_missing = false;
        if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $sz_cfg ) {
                if ( ! empty( $sz_cfg['file'] ) && ! file_exists( trailingslashit( $dir ) . $sz_cfg['file'] ) ) {
                    $any_subsize_missing = true;
                    break;
                }
            }
        } else {
            $any_subsize_missing = true;
        }

        $meta_needs_update = (
            $file_switched ||
            $any_subsize_missing ||
            ! is_array( $meta ) ||
            empty( $meta['file'] ) ||
            basename( (string) $meta['file'] ) !== basename( $file ) ||
            empty( $meta['width'] ) ||
            (int) $meta['width'] !== $target_dims['width'] ||
            (int) $meta['height'] !== $target_dims['height']
        );

        if ( ! empty( $res['changed'] ) || $meta_needs_update ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'disable_hard_crop_on_subsizes' ), 99 );
            add_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_high_image_quality' ), 99, 2 );
            add_filter( 'jpeg_quality', array( __CLASS__, 'force_high_image_quality' ), 99, 2 );
            $new_meta = wp_generate_attachment_metadata( $attachment_id, $file );
            remove_filter( 'jpeg_quality', array( __CLASS__, 'force_high_image_quality' ), 99 );
            remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_high_image_quality' ), 99 );
            remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'disable_hard_crop_on_subsizes' ), 99 );
            if ( is_array( $new_meta ) && ! empty( $new_meta ) ) {
                $new_meta['width']  = $target_dims['width'];
                $new_meta['height'] = $target_dims['height'];
                wp_update_attachment_metadata( $attachment_id, $new_meta );
            }
        }

        return true;
    }

    /**
     * Safely set SKU on a post/product, clearing duplicate SKU from trashed/other posts if needed
     */
    public static function set_clean_sku( $post_id, $target_sku ) {
        global $wpdb;
        $post_id    = (int) $post_id;
        $target_sku = trim( (string) $target_sku );
        if ( $post_id <= 0 || '' === $target_sku ) {
            return false;
        }

        $existing_holders = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s AND post_id != %d",
                $target_sku,
                $post_id
            )
        );
        if ( ! empty( $existing_holders ) ) {
            foreach ( $existing_holders as $holder_id ) {
                $holder_id   = (int) $holder_id;
                $holder_post = get_post( $holder_id );
                if ( ! $holder_post || 'trash' === $holder_post->post_status || (int) get_post_field( 'post_parent', $post_id ) === $holder_id ) {
                    $base_sku = Shaghafi_DB::extract_base_sku( $target_sku );
                    if ( (int) get_post_field( 'post_parent', $post_id ) === $holder_id && $base_sku !== $target_sku ) {
                        update_post_meta( $holder_id, '_sku', $base_sku );
                    } else {
                        delete_post_meta( $holder_id, '_sku' );
                    }
                }
            }
        }

        update_post_meta( $post_id, '_sku', $target_sku );
        return true;
    }

    /**
     * Get the attachment ID assigned to a specific attribute option inside WCBoost Variation Swatches (wcboost_variation_swatches)
     */
    public static function get_swatch_image_for_option( $parent_id, $attr_key, $option_val ) {
        $parent_id  = (int) $parent_id;
        $attr_key   = trim( (string) $attr_key );
        $option_val = trim( (string) $option_val );
        if ( $parent_id <= 0 || '' === $option_val ) {
            return 0;
        }

        $swatches_meta = get_post_meta( $parent_id, 'wcboost_variation_swatches', true );
        if ( ! is_array( $swatches_meta ) || empty( $swatches_meta ) ) {
            return 0;
        }

        $attr_slug = sanitize_title( $attr_key );
        $opt_slug  = sanitize_title( $option_val );

        foreach ( $swatches_meta as $sw_attr => $sw_cfg ) {
            if ( '' !== $attr_key && $sw_attr !== $attr_key && $sw_attr !== $attr_slug && sanitize_title( $sw_attr ) !== $attr_slug ) {
                continue;
            }
            if ( empty( $sw_cfg['swatches'] ) || ! is_array( $sw_cfg['swatches'] ) ) {
                continue;
            }
            if ( isset( $sw_cfg['swatches'][ $opt_slug ]['image'] ) && (int) $sw_cfg['swatches'][ $opt_slug ]['image'] > 0 ) {
                return (int) $sw_cfg['swatches'][ $opt_slug ]['image'];
            }
            if ( isset( $sw_cfg['swatches'][ $option_val ]['image'] ) && (int) $sw_cfg['swatches'][ $option_val ]['image'] > 0 ) {
                return (int) $sw_cfg['swatches'][ $option_val ]['image'];
            }
        }

        return 0;
    }

    /**
     * Write exact lowercase attribute_{canonical_key} meta to wp_postmeta on a variation,
     * removing any case-mismatched (e.g. attribute_Variante) or stray attribute_* rows first
     * so MySQL's case-insensitive collation never preserves an uppercase meta_key that breaks PHP's case-sensitive get_post_meta().
     */
    public static function set_clean_variation_attribute_meta( $variation_id, $canonical_key, $attr_value ) {
        global $wpdb;
        $variation_id  = (int) $variation_id;
        $canonical_key = strtolower( trim( (string) $canonical_key ) );
        $attr_value    = trim( (string) $attr_value );
        if ( $variation_id <= 0 || '' === $canonical_key ) {
            return;
        }
        $target_meta_key = 'attribute_' . $canonical_key;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND LOWER(meta_key) LIKE 'attribute\_%'",
                $variation_id
            )
        );

        $wpdb->insert(
            $wpdb->postmeta,
            array(
                'post_id'    => $variation_id,
                'meta_key'   => $target_meta_key,
                'meta_value' => $attr_value,
            ),
            array( '%d', '%s', '%s' )
        );

        clean_post_cache( $variation_id );
        wp_cache_delete( $variation_id, 'post_meta' );
        wp_cache_delete( 'product-' . $variation_id, 'products' );
    }

    /**
     * Repair and normalize variation attribute on both Parent Product and Variation
     * Prevents duplicate/corrupted attributes and registers taxonomy terms or custom pipe values cleanly
     */
    public static function repair_product_and_variation_attributes( $parent_id, $variation_id, $attr_value, $preferred_label = 'Variante' ) {
        $parent_id    = (int) $parent_id;
        $variation_id = (int) $variation_id;
        $attr_value   = trim( (string) $attr_value );

        if ( $parent_id <= 0 || $variation_id <= 0 || '' === $attr_value ) {
            return false;
        }

        $parent_attrs = get_post_meta( $parent_id, '_product_attributes', true );
        if ( ! is_array( $parent_attrs ) ) {
            $parent_attrs = array();
        }

        // Remove any corrupted/empty attribute entries and normalize custom attribute keys/names on the parent product
        $normalized_parent_attrs = array();
        foreach ( $parent_attrs as $k => $cfg ) {
            if ( is_int( $k ) || '' === trim( (string) $k ) || ! is_array( $cfg ) || empty( $cfg['name'] ) || '' === trim( (string) $cfg['name'] ) ) {
                continue;
            }
            $k_str  = trim( (string) $k );
            $is_tax = ( ( ! empty( $cfg['is_taxonomy'] ) || 0 === strpos( $k_str, 'pa_' ) ) && taxonomy_exists( $k_str ) );
            if ( $is_tax ) {
                $normalized_parent_attrs[ $k_str ] = $cfg;
            } else {
                $clean_name = trim( rtrim( trim( (string) $cfg['name'] ), ':;؛' ) );
                if ( '' === $clean_name ) {
                    $clean_name = 'Variante';
                }
                $cfg['name'] = $clean_name;
                $norm_k      = sanitize_title( $clean_name );
                if ( '' === $norm_k ) {
                    $norm_k = strtolower( $k_str );
                }
                if ( isset( $normalized_parent_attrs[ $norm_k ] ) && ! empty( $cfg['value'] ) ) {
                    $merged_opts = array_unique(
                        array_filter(
                            array_map(
                                'trim',
                                array_merge(
                                    explode( '|', (string) $normalized_parent_attrs[ $norm_k ]['value'] ),
                                    explode( '|', (string) $cfg['value'] )
                                )
                            ),
                            'strlen'
                        )
                    );
                    $normalized_parent_attrs[ $norm_k ]['value'] = implode( ' | ', $merged_opts );
                    if ( ! empty( $cfg['is_variation'] ) ) {
                        $normalized_parent_attrs[ $norm_k ]['is_variation'] = 1;
                    }
                } else {
                    $normalized_parent_attrs[ $norm_k ] = $cfg;
                }
            }
        }
        $parent_attrs = $normalized_parent_attrs;

        $canonical_key  = '';
        $variation_keys = array();
        foreach ( $parent_attrs as $k => $cfg ) {
            if ( ! empty( $cfg['is_variation'] ) ) {
                $variation_keys[] = $k;
            }
        }

        // Prefer taxonomy attribute (pa_*) if one is already set on the parent
        foreach ( $variation_keys as $vk ) {
            if ( 0 === strpos( $vk, 'pa_' ) && taxonomy_exists( $vk ) ) {
                $canonical_key = $vk;
                break;
            }
        }
        if ( '' === $canonical_key && ! empty( $variation_keys ) ) {
            $canonical_key = $variation_keys[0];
        }

        // If parent has no variation attribute yet, inspect existing child variations
        if ( '' === $canonical_key ) {
            $all_meta = get_post_meta( $variation_id );
            foreach ( $all_meta as $mk => $mv ) {
                if ( 0 === stripos( $mk, 'attribute_' ) ) {
                    $candidate = strtolower( trim( substr( $mk, strlen( 'attribute_' ) ) ) );
                    if ( '' !== $candidate ) {
                        $canonical_key = ( 0 === strpos( $candidate, 'pa_' ) && taxonomy_exists( $candidate ) )
                            ? $candidate
                            : sanitize_title( rtrim( $candidate, ':;؛' ) );
                        if ( '' !== $canonical_key ) {
                            break;
                        }
                    }
                }
            }
        }

        if ( '' === $canonical_key ) {
            $settings = get_option( 'shaghafi_sync_settings', array() );
            $def_tax  = isset( $settings['default_attribute_taxonomy'] ) ? trim( $settings['default_attribute_taxonomy'] ) : '';
            if ( '' !== $def_tax && taxonomy_exists( $def_tax ) ) {
                $canonical_key = $def_tax;
            } else {
                $clean_pref    = trim( rtrim( trim( (string) ( $preferred_label ?: 'Variante' ) ), ':;؛' ) );
                $canonical_key = sanitize_title( $clean_pref ?: 'variante' );
                if ( '' === $canonical_key ) {
                    $canonical_key = 'variante';
                }
            }
        }

        $is_taxonomy = ( 0 === strpos( $canonical_key, 'pa_' ) && taxonomy_exists( $canonical_key ) );
        if ( ! $is_taxonomy ) {
            $attr_display_name = isset( $parent_attrs[ $canonical_key ]['name'] ) && '' !== trim( rtrim( trim( (string) $parent_attrs[ $canonical_key ]['name'] ), ':;؛' ) )
                ? trim( rtrim( trim( (string) $parent_attrs[ $canonical_key ]['name'] ), ':;؛' ) )
                : trim( rtrim( trim( (string) ( $preferred_label ?: 'Variante' ) ), ':;؛' ) );
            if ( '' === $attr_display_name ) {
                $attr_display_name = 'Variante';
            }
            $expected_slug = sanitize_title( $attr_display_name );
            if ( '' !== $expected_slug && $expected_slug !== $canonical_key ) {
                if ( isset( $parent_attrs[ $canonical_key ] ) && ! isset( $parent_attrs[ $expected_slug ] ) ) {
                    $parent_attrs[ $expected_slug ] = $parent_attrs[ $canonical_key ];
                    unset( $parent_attrs[ $canonical_key ] );
                }
                $canonical_key = $expected_slug;
            }
        }

        // Clean up duplicate variation attributes on the parent
        foreach ( $parent_attrs as $k => $cfg ) {
            if ( $k !== $canonical_key && ! empty( $cfg['is_variation'] ) ) {
                $parent_attrs[ $k ]['is_variation'] = 0;
            }
        }

        // Before overwriting the variation's attribute value, rescue its swatch image if _thumbnail_id is empty
        $old_attr_val = trim( (string) get_post_meta( $variation_id, 'attribute_' . $canonical_key, true ) );
        if ( (int) get_post_meta( $variation_id, '_thumbnail_id', true ) <= 0 && '' !== $old_attr_val ) {
            $rescued_img = self::get_swatch_image_for_option( $parent_id, $canonical_key, $old_attr_val );
            if ( $rescued_img > 0 && wp_get_attachment_url( $rescued_img ) ) {
                update_post_meta( $variation_id, '_thumbnail_id', $rescued_img );
            }
        }

        if ( $is_taxonomy ) {
            global $wpdb;
            $term = get_term_by( 'name', $attr_value, $canonical_key );
            if ( ! $term || is_wp_error( $term ) ) {
                $term = get_term_by( 'slug', sanitize_title( $attr_value ), $canonical_key );
            }
            if ( ! $term || is_wp_error( $term ) ) {
                $inserted = wp_insert_term( $attr_value, $canonical_key );
                if ( ! is_wp_error( $inserted ) && ! empty( $inserted['term_id'] ) ) {
                    $term = get_term( (int) $inserted['term_id'], $canonical_key );
                } elseif ( is_wp_error( $inserted ) && 'term_exists' === $inserted->get_error_code() ) {
                    $existing_tid = (int) $inserted->get_error_data( 'term_exists' );
                    if ( $existing_tid > 0 ) {
                        $term = get_term( $existing_tid, $canonical_key );
                    }
                }
            }

            // Direct DB fallback if WPML/Polylang language filter hid the term from get_term_by/get_term
            $term_id          = ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
            $term_slug        = ( $term && ! is_wp_error( $term ) ) ? (string) $term->slug : '';
            $term_taxonomy_id = ( $term && ! is_wp_error( $term ) && ! empty( $term->term_taxonomy_id ) ) ? (int) $term->term_taxonomy_id : 0;

            if ( $term_id <= 0 || '' === $term_slug ) {
                $db_row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT t.term_id, t.slug, tt.term_taxonomy_id
                         FROM {$wpdb->terms} t
                         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                         WHERE tt.taxonomy = %s AND (t.name = %s OR t.slug = %s)
                         LIMIT 1",
                        $canonical_key,
                        $attr_value,
                        sanitize_title( $attr_value )
                    ),
                    ARRAY_A
                );
                if ( $db_row ) {
                    $term_id          = (int) $db_row['term_id'];
                    $term_slug        = (string) $db_row['slug'];
                    $term_taxonomy_id = (int) $db_row['term_taxonomy_id'];
                }
            }

            if ( $term_id > 0 && '' !== $term_slug ) {
                wp_set_object_terms( $parent_id, array( $term_id ), $canonical_key, true );
                if ( $term_taxonomy_id > 0 ) {
                    $wpdb->replace(
                        $wpdb->term_relationships,
                        array(
                            'object_id'        => $parent_id,
                            'term_taxonomy_id' => $term_taxonomy_id,
                            'term_order'       => 0,
                        ),
                        array( '%d', '%d', '%d' )
                    );
                }
                self::set_clean_variation_attribute_meta( $variation_id, $canonical_key, $term_slug );
            }

            $parent_attrs[ $canonical_key ] = array(
                'name'         => $canonical_key,
                'value'        => '',
                'position'     => isset( $parent_attrs[ $canonical_key ]['position'] ) ? (int) $parent_attrs[ $canonical_key ]['position'] : 0,
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => 1,
            );
        } else {
            $existing_val_str = isset( $parent_attrs[ $canonical_key ]['value'] ) ? (string) $parent_attrs[ $canonical_key ]['value'] : '';
            $options          = array_filter( array_map( 'trim', explode( '|', $existing_val_str ) ), 'strlen' );

            $found_exact = false;
            $norm_target = Shaghafi_DB::normalize_attr( $attr_value );
            foreach ( $options as $idx => $opt ) {
                if ( $opt === $attr_value ) {
                    $found_exact = true;
                    break;
                }
                if ( Shaghafi_DB::normalize_attr( $opt ) === $norm_target ) {
                    $options[ $idx ] = $attr_value;
                    $found_exact     = true;
                    break;
                }
            }
            if ( ! $found_exact ) {
                $options[] = $attr_value;
            }
            $options = array_values( array_unique( $options ) );
            usort( $options, 'strnatcasecmp' );

            $parent_attrs[ $canonical_key ] = array(
                'name'         => $attr_display_name,
                'value'        => implode( ' | ', $options ),
                'position'     => isset( $parent_attrs[ $canonical_key ]['position'] ) ? (int) $parent_attrs[ $canonical_key ]['position'] : 0,
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => 0,
            );

            self::set_clean_variation_attribute_meta( $variation_id, $canonical_key, $attr_value );
        }

        update_post_meta( $parent_id, '_product_attributes', $parent_attrs );
        clean_post_cache( $parent_id );
        wp_cache_delete( $parent_id, 'post_meta' );
        wp_cache_delete( 'product-' . $parent_id, 'products' );
        return true;
    }

    /**
     * Rebuild parent product's variation attribute options cleanly from all active child variations,
     * sort options and variation menu_order in natural ascending order (e.g. 4, 5, 7, 8, 9),
     * relocate misplaced variation thumbnails, and synchronize WCBoost Variation Swatches (wcboost_variation_swatches)
     */
    public static function sync_clean_parent_attributes( $parent_id ) {
        global $wpdb;
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return;
        }
        $parent_attrs     = get_post_meta( $parent_id, '_product_attributes', true );
        $orig_attrs_json  = wp_json_encode( $parent_attrs );
        if ( ! is_array( $parent_attrs ) ) {
            $parent_attrs = array();
        }

        // Normalize custom attribute keys/names on the parent product
        $normalized_attrs = array();
        foreach ( $parent_attrs as $k => $cfg ) {
            if ( is_int( $k ) || '' === trim( (string) $k ) || ! is_array( $cfg ) || empty( $cfg['name'] ) || '' === trim( (string) $cfg['name'] ) ) {
                continue;
            }
            $k_str  = trim( (string) $k );
            $is_tax = ( ( ! empty( $cfg['is_taxonomy'] ) || 0 === strpos( $k_str, 'pa_' ) ) && taxonomy_exists( $k_str ) );
            if ( $is_tax ) {
                $normalized_attrs[ $k_str ] = $cfg;
            } else {
                $clean_name = trim( rtrim( trim( (string) $cfg['name'] ), ':;؛' ) );
                if ( '' === $clean_name ) {
                    $clean_name = 'Variante';
                }
                $cfg['name'] = $clean_name;
                $norm_k      = sanitize_title( $clean_name );
                if ( '' === $norm_k ) {
                    $norm_k = strtolower( $k_str );
                }
                $normalized_attrs[ $norm_k ] = $cfg;
            }
        }
        $parent_attrs = $normalized_attrs;

        $child_ids = get_posts(
            array(
                'post_parent' => $parent_id,
                'post_type'   => 'product_variation',
                'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
                'numberposts' => -1,
                'orderby'     => 'ID',
                'order'       => 'ASC',
                'fields'      => 'ids',
            )
        );
        if ( empty( $child_ids ) ) {
            return;
        }

        // If parent has child variations but no variation attribute entry in _product_attributes yet, create default 'variante' entry
        $has_var_attr = false;
        foreach ( $parent_attrs as $cfg_check ) {
            if ( ! empty( $cfg_check['is_variation'] ) ) {
                $has_var_attr = true;
                break;
            }
        }
        if ( ! $has_var_attr ) {
            $parent_attrs['variante'] = array(
                'name'         => 'Variante',
                'value'        => '',
                'position'     => 0,
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => 0,
            );
        }

        $any_change      = false;
        $swatches_meta   = get_post_meta( $parent_id, 'wcboost_variation_swatches', true );
        $orig_sw_json    = wp_json_encode( $swatches_meta );
        $is_size_product = Shaghafi_Scanner::is_size_based_product( $parent_id );

        // Step 0: Permanently delete any disabled (private/draft/pending) child variations that are not in the Shaghafi feed
        $active_child_ids = array();
        foreach ( $child_ids as $cid ) {
            $cid    = (int) $cid;
            $status = get_post_status( $cid );
            if ( 'publish' !== $status ) {
                $c_vid = trim( (string) get_post_meta( $cid, '_shaghafi_variant_id', true ) );
                $c_sku = trim( (string) get_post_meta( $cid, '_sku', true ) );
                $in_feed = false;
                if ( '' !== $c_vid && Shaghafi_DB::get_by_variant_id( $c_vid ) ) {
                    $in_feed = true;
                } elseif ( '' !== $c_sku && Shaghafi_DB::get_by_sku( $c_sku ) ) {
                    $in_feed = true;
                }
                if ( ! $in_feed ) {
                    wp_delete_post( $cid, true );
                    $any_change = true;
                    continue;
                }
            }
            $active_child_ids[] = $cid;
        }
        $child_ids = $active_child_ids;
        if ( empty( $child_ids ) ) {
            return;
        }

        // Step A: For Size products, remove redundant per-variation thumbnails (keeping parent main image);
        // for Color/Model products, relocate misplaced variation thumbnails if they belong to a sibling SKU.
        if ( $is_size_product ) {
            $p_thumb = (int) get_post_meta( $parent_id, '_thumbnail_id', true );
            foreach ( $child_ids as $cid ) {
                $cid     = (int) $cid;
                $c_thumb = (int) get_post_meta( $cid, '_thumbnail_id', true );
                if ( $c_thumb > 0 ) {
                    if ( $p_thumb <= 0 && wp_get_attachment_url( $c_thumb ) ) {
                        set_post_thumbnail( $parent_id, $c_thumb );
                        $p_thumb = $c_thumb;
                    }
                    delete_post_meta( $cid, '_thumbnail_id' );
                    $any_change = true;
                }
            }
        } else {
            foreach ( $child_ids as $cid ) {
                $cid     = (int) $cid;
                $c_sku   = trim( (string) get_post_meta( $cid, '_sku', true ) );
                $c_thumb = (int) get_post_meta( $cid, '_thumbnail_id', true );
                if ( '' !== $c_sku && $c_thumb > 0 ) {
                    $c_base = Shaghafi_DB::extract_base_sku( $c_sku );
                    $c_suff = Shaghafi_DB::extract_sku_suffix_norm( $c_sku );
                    if ( '' !== $c_base && '' !== $c_suff ) {
                        $img_suff = Shaghafi_Scanner::extract_sku_suffix_from_attachment( $c_thumb, $c_base );
                        if ( '' !== $img_suff && $img_suff !== $c_suff ) {
                            $right_img = Shaghafi_Scanner::find_local_wp_image_for_variant(
                                $parent_id,
                                $c_sku,
                                trim( (string) get_post_meta( $cid, '_shaghafi_variant_id', true ) ),
                                '',
                                ''
                            );
                            if ( $right_img && ! empty( $right_img['attachment_id'] ) && (int) $right_img['attachment_id'] !== $c_thumb ) {
                                update_post_meta( $cid, '_thumbnail_id', (int) $right_img['attachment_id'] );
                                $any_change = true;
                            }
                        }
                    }
                }
            }
        }

        foreach ( $parent_attrs as $attr_key => &$cfg ) {
            if ( empty( $cfg['is_variation'] ) ) {
                continue;
            }

            $is_tax        = ( ! empty( $cfg['is_taxonomy'] ) && taxonomy_exists( $attr_key ) );
            $child_records = array();

            foreach ( $child_ids as $cid ) {
                $cid     = (int) $cid;
                $raw_val = trim( (string) get_post_meta( $cid, 'attribute_' . $attr_key, true ) );

                // 1) Check case-mismatched attribute_* keys in wp_postmeta (e.g. attribute_Variante vs attribute_variante)
                if ( '' === $raw_val ) {
                    $c_all_meta = get_post_meta( $cid );
                    if ( is_array( $c_all_meta ) ) {
                        foreach ( $c_all_meta as $mk => $mv ) {
                            if ( 0 === stripos( (string) $mk, 'attribute_' ) && ! empty( $mv[0] ) && '' !== trim( (string) $mv[0] ) ) {
                                $cand_val = trim( (string) $mv[0] );
                                self::set_clean_variation_attribute_meta( $cid, $attr_key, $cand_val );
                                $raw_val    = $cand_val;
                                $any_change = true;
                                break;
                            }
                        }
                    }
                }

                // 2) Auto-heal empty variation attribute from linked Shaghafi feed row (_shaghafi_variant_id or _sku)
                if ( '' === $raw_val ) {
                    $c_vid  = trim( (string) get_post_meta( $cid, '_shaghafi_variant_id', true ) );
                    $c_sku  = trim( (string) get_post_meta( $cid, '_sku', true ) );
                    $fv_row = null;
                    if ( '' !== $c_vid ) {
                        $fv_row = Shaghafi_DB::get_by_variant_id( $c_vid );
                    }
                    if ( ! $fv_row && '' !== $c_sku ) {
                        $fv_row = Shaghafi_DB::get_by_sku( $c_sku );
                    }
                    if ( $fv_row && '' !== trim( (string) $fv_row['variantenauspraegung'] ) ) {
                        $target_opt = trim( (string) $fv_row['variantenauspraegung'] );
                        if ( $is_tax ) {
                            $t_heal = get_term_by( 'name', $target_opt, $attr_key );
                            if ( ! $t_heal || is_wp_error( $t_heal ) ) {
                                $t_heal = get_term_by( 'slug', sanitize_title( $target_opt ), $attr_key );
                            }
                            if ( ! $t_heal || is_wp_error( $t_heal ) ) {
                                $ins = wp_insert_term( $target_opt, $attr_key );
                                if ( ! is_wp_error( $ins ) && ! empty( $ins['term_id'] ) ) {
                                    $t_heal = get_term( (int) $ins['term_id'], $attr_key );
                                }
                            }
                            if ( $t_heal && ! is_wp_error( $t_heal ) && ! empty( $t_heal->slug ) ) {
                                $raw_val = (string) $t_heal->slug;
                            } else {
                                $raw_val = sanitize_title( $target_opt );
                            }
                        } else {
                            $raw_val = $target_opt;
                        }
                        if ( '' !== $raw_val ) {
                            self::set_clean_variation_attribute_meta( $cid, $attr_key, $raw_val );
                            $any_change = true;
                        }
                    }
                }

                if ( '' === $raw_val ) {
                    continue;
                }

                $thumb_id = (int) get_post_meta( $cid, '_thumbnail_id', true );
                if ( ! $is_size_product && $thumb_id <= 0 ) {
                    $sw_img = self::get_swatch_image_for_option( $parent_id, $attr_key, $raw_val );
                    if ( $sw_img > 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $sw_img, false ) ) {
                        update_post_meta( $cid, '_thumbnail_id', $sw_img );
                        $thumb_id   = $sw_img;
                        $any_change = true;
                    }
                }

                $display_val      = $raw_val;
                $term_id          = 0;
                $term_taxonomy_id = 0;

                if ( $is_tax ) {
                    $t = get_term_by( 'slug', $raw_val, $attr_key );
                    if ( ! $t || is_wp_error( $t ) ) {
                        $t = get_term_by( 'name', $raw_val, $attr_key );
                    }
                    if ( $t && ! is_wp_error( $t ) ) {
                        $term_id          = (int) $t->term_id;
                        $term_taxonomy_id = ! empty( $t->term_taxonomy_id ) ? (int) $t->term_taxonomy_id : 0;
                        $display_val      = (string) $t->name;
                    } else {
                        $db_row = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT t.term_id, t.name, tt.term_taxonomy_id
                                 FROM {$wpdb->terms} t
                                 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                                 WHERE tt.taxonomy = %s AND (t.slug = %s OR t.name = %s)
                                 LIMIT 1",
                                $attr_key,
                                $raw_val,
                                $raw_val
                            ),
                            ARRAY_A
                        );
                        if ( $db_row ) {
                            $term_id          = (int) $db_row['term_id'];
                            $term_taxonomy_id = (int) $db_row['term_taxonomy_id'];
                            $display_val      = (string) $db_row['name'];
                        }
                    }
                }

                $child_records[] = array(
                    'cid'              => $cid,
                    'status'           => get_post_status( $cid ),
                    'raw_val'          => $raw_val,
                    'display_val'      => $display_val,
                    'term_id'          => $term_id,
                    'term_taxonomy_id' => $term_taxonomy_id,
                    'thumb_id'         => $thumb_id,
                    'sku'              => trim( (string) get_post_meta( $cid, '_sku', true ) ),
                );
            }

            if ( empty( $child_records ) ) {
                continue;
            }

            // Sort child variation records in natural ascending order (e.g. 4, 5, 7, 8, 9 instead of 9, 8, 7, 4, 5)
            usort(
                $child_records,
                function ( $a, $b ) {
                    $cmp = strnatcasecmp( (string) $a['display_val'], (string) $b['display_val'] );
                    if ( 0 !== $cmp ) {
                        return $cmp;
                    }
                    $cmp_sku = strnatcasecmp( (string) $a['sku'], (string) $b['sku'] );
                    if ( 0 !== $cmp_sku ) {
                        return $cmp_sku;
                    }
                    return (int) $a['cid'] - (int) $b['cid'];
                }
            );

            // Update child variation menu_order in ascending order (1, 2, 3, ...)
            $menu_pos = 1;
            foreach ( $child_records as $rec ) {
                $cur_order = (int) get_post_field( 'menu_order', $rec['cid'] );
                if ( $cur_order !== $menu_pos ) {
                    $wpdb->update(
                        $wpdb->posts,
                        array( 'menu_order' => $menu_pos ),
                        array( 'ID' => $rec['cid'] ),
                        array( '%d' ),
                        array( '%d' )
                    );
                    clean_post_cache( $rec['cid'] );
                    $any_change = true;
                }
                $menu_pos++;
            }

            // Build sorted unique attribute values (prefer publish variations if any exist)
            $has_published = false;
            foreach ( $child_records as $rec ) {
                if ( 'publish' === $rec['status'] ) {
                    $has_published = true;
                    break;
                }
            }

            $used_vals    = array();
            $sorted_terms = array();
            foreach ( $child_records as $rec ) {
                if ( $has_published && 'publish' !== $rec['status'] ) {
                    continue;
                }
                if ( ! in_array( $rec['raw_val'], $used_vals, true ) ) {
                    $used_vals[]    = $rec['raw_val'];
                    $sorted_terms[] = $rec;
                }
            }

            if ( $is_tax ) {
                $term_ids  = array();
                $term_rank = 1;
                foreach ( $sorted_terms as $t_rec ) {
                    if ( $t_rec['term_id'] > 0 ) {
                        $term_ids[] = $t_rec['term_id'];
                        if ( $t_rec['term_taxonomy_id'] > 0 ) {
                            $wpdb->replace(
                                $wpdb->term_relationships,
                                array(
                                    'object_id'        => $parent_id,
                                    'term_taxonomy_id' => $t_rec['term_taxonomy_id'],
                                    'term_order'       => $term_rank,
                                ),
                                array( '%d', '%d', '%d' )
                            );
                        }
                        $term_rank++;
                    }
                }
                if ( ! empty( $term_ids ) ) {
                    wp_set_object_terms( $parent_id, $term_ids, $attr_key, false );
                }
            } else {
                $cfg['value'] = implode( ' | ', $used_vals );
            }

            // Synchronize WCBoost Variation Swatches (wcboost_variation_swatches) on the parent product:
            // For non-size products where variations have images, automatically switch Type from "Default" ('') to "image"
            // and populate all variation images into swatches, while preserving any custom shape/size/settings set by the user!
            $is_size_prod        = Shaghafi_Scanner::is_size_based_product( $parent_id );
            $has_any_child_thumb = false;
            foreach ( $child_records as $rec ) {
                if ( ! empty( $rec['thumb_id'] ) && (int) $rec['thumb_id'] > 0 ) {
                    $has_any_child_thumb = true;
                    break;
                }
            }

            if ( ! is_array( $swatches_meta ) ) {
                $swatches_meta = array();
            }

            if ( ! empty( $swatches_meta ) || ( ! $is_size_prod && $has_any_child_thumb ) ) {
                $attr_slug   = sanitize_title( $attr_key );
                $name_slug   = isset( $cfg['name'] ) ? sanitize_title( $cfg['name'] ) : '';
                $sw_attr_key = '';
                foreach ( array_keys( $swatches_meta ) as $cand_sw_k ) {
                    $cand_slug = sanitize_title( $cand_sw_k );
                    if ( $cand_sw_k === $attr_key || $cand_sw_k === $attr_slug || $cand_slug === $attr_slug || ( '' !== $name_slug && $cand_slug === $name_slug ) ) {
                        $sw_attr_key = $cand_sw_k;
                        break;
                    }
                }
                // Fallback: if the product had image swatches under a renamed/merged attribute key, inherit that swatch configuration
                if ( '' === $sw_attr_key && ! empty( $swatches_meta ) ) {
                    foreach ( $swatches_meta as $cand_sw_k => $cand_sw_cfg ) {
                        if ( is_array( $cand_sw_cfg ) && ( ( isset( $cand_sw_cfg['type'] ) && 'image' === $cand_sw_cfg['type'] ) || 1 === count( $swatches_meta ) ) ) {
                            $sw_attr_key = $cand_sw_k;
                            break;
                        }
                    }
                }
                if ( '' === $sw_attr_key ) {
                    $sw_attr_key = '' !== $attr_slug ? $attr_slug : $name_slug;
                }

                if ( '' !== $sw_attr_key ) {
                    $base_sw_cfg = ( isset( $swatches_meta[ $sw_attr_key ] ) && is_array( $swatches_meta[ $sw_attr_key ] ) )
                        ? $swatches_meta[ $sw_attr_key ]
                        : array(
                            'type'        => ( ! $is_size_prod && $has_any_child_thumb ) ? 'image' : '',
                            'shape'       => '',
                            'size'        => '',
                            'custom_size' => array(
                                'width'  => '',
                                'height' => '',
                            ),
                            'swatches'    => array(),
                        );

                    // If this is a color/model product (non-size) and variations have images, ensure Type is set to 'image' (not Default/'')
                    if ( ! $is_size_prod && $has_any_child_thumb ) {
                        $cur_sw_type = isset( $base_sw_cfg['type'] ) ? strtolower( trim( (string) $base_sw_cfg['type'] ) ) : '';
                        if ( '' === $cur_sw_type || in_array( $cur_sw_type, array( 'default', 'select', 'button', 'label', 'text' ), true ) ) {
                            $base_sw_cfg['type'] = 'image';
                        }
                    }

                    $old_swatches = ( isset( $base_sw_cfg['swatches'] ) && is_array( $base_sw_cfg['swatches'] ) )
                        ? $base_sw_cfg['swatches']
                        : array();

                    $old_sw_img_counts = array();
                    foreach ( $old_swatches as $osw ) {
                        if ( is_array( $osw ) && ! empty( $osw['image'] ) && (int) $osw['image'] > 0 ) {
                            $oid = (int) $osw['image'];
                            $old_sw_img_counts[ $oid ] = isset( $old_sw_img_counts[ $oid ] ) ? $old_sw_img_counts[ $oid ] + 1 : 1;
                        }
                    }

                    $new_swatches = array();
                    foreach ( $child_records as $rec ) {
                        if ( $has_published && 'publish' !== $rec['status'] ) {
                            continue;
                        }
                        $opt_key = ( $is_tax && $rec['term_id'] > 0 )
                            ? (string) $rec['term_id']
                            : sanitize_title( $rec['raw_val'] );
                        if ( '' === $opt_key ) {
                            $opt_key = $rec['raw_val'];
                        }

                        $entry = isset( $old_swatches[ $opt_key ] ) && is_array( $old_swatches[ $opt_key ] )
                            ? $old_swatches[ $opt_key ]
                            : array(
                                'color' => '',
                                'image' => '',
                                'label' => '',
                            );

                        if ( $rec['thumb_id'] > 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $rec['thumb_id'], false ) ) {
                            $entry['image'] = (string) $rec['thumb_id'];
                            if ( $is_tax && $rec['term_id'] > 0 ) {
                                // Remove WoodMart's 'image' / 'not_dropdown' term meta (which causes WoodMart to render a duplicate
                                // .wd-swatches-product bar with broken <img src="45737"> attachment IDs above WCBoost Variation Swatches!)
                                delete_term_meta( $rec['term_id'], 'image' );
                                delete_term_meta( $rec['term_id'], 'not_dropdown' );
                                update_term_meta( $rec['term_id'], 'swatches_image', (string) $rec['thumb_id'] );
                            }
                        } elseif ( ! empty( $entry['image'] ) && (int) $entry['image'] > 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( (int) $entry['image'], false ) ) {
                            $old_img_id   = (int) $entry['image'];
                            $is_shared_sw = ( isset( $old_sw_img_counts[ $old_img_id ] ) && $old_sw_img_counts[ $old_img_id ] > 1 );
                            // Only copy swatch image back to variation thumbnail if it is NOT a shared duplicate placeholder across multiple slots
                            if ( ! $is_shared_sw ) {
                                update_post_meta( $rec['cid'], '_thumbnail_id', $old_img_id );
                            }
                        }
                        $new_swatches[ $opt_key ] = $entry;

                        // Also index by sanitized raw_val if different from term_id so both lookup paths find the swatch image
                        $alt_opt_key = sanitize_title( $rec['raw_val'] );
                        if ( '' !== $alt_opt_key && $alt_opt_key !== $opt_key && ! isset( $new_swatches[ $alt_opt_key ] ) ) {
                            $new_swatches[ $alt_opt_key ] = $entry;
                        }
                    }

                    if ( ! empty( $new_swatches ) ) {
                        $base_sw_cfg['swatches']       = $new_swatches;
                        $swatches_meta[ $sw_attr_key ] = $base_sw_cfg;
                        if ( '' !== $attr_slug ) {
                            $swatches_meta[ $attr_slug ] = $base_sw_cfg;
                        }
                        if ( '' !== $name_slug && $name_slug !== $attr_slug ) {
                            $swatches_meta[ $name_slug ] = $base_sw_cfg;
                        }
                    }
                }
            }
        }
        unset( $cfg );

        if ( wp_json_encode( $parent_attrs ) !== $orig_attrs_json ) {
            update_post_meta( $parent_id, '_product_attributes', $parent_attrs );
            $any_change = true;
        }
        if ( is_array( $swatches_meta ) && ! empty( $swatches_meta ) && wp_json_encode( $swatches_meta ) !== $orig_sw_json ) {
            update_post_meta( $parent_id, 'wcboost_variation_swatches', $swatches_meta );
            $any_change = true;
        }

        if ( $any_change ) {
            clean_post_cache( $parent_id );
            wp_cache_delete( $parent_id, 'post_meta' );
            wp_cache_delete( 'product-' . $parent_id, 'products' );
            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $parent_id );
            }
        }
    }

    /**
     * Hooked to woocommerce_process_product_meta (priority 999) so saving a product in WP Admin
     * never overwrites or reverts WCBoost image swatches, variation attributes, variation prices, or variation thumbnails!
     */
    public static function preserve_and_sync_swatches_on_save( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id > 0 ) {
            $child_ids = get_posts(
                array(
                    'post_parent' => $post_id,
                    'post_type'   => 'product_variation',
                    'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
                    'numberposts' => -1,
                    'fields'      => 'ids',
                )
            );
            foreach ( $child_ids as $cid ) {
                $cid       = (int) $cid;
                $saved_vid = trim( (string) get_post_meta( $cid, '_shaghafi_variant_id', true ) );
                $fv        = '' !== $saved_vid ? Shaghafi_DB::get_by_variant_id( $saved_vid ) : null;
                if ( ! $fv ) {
                    continue;
                }
                // Protect variation price if a stale form submit cleared variable_regular_price
                $cur_reg = trim( (string) get_post_meta( $cid, '_regular_price', true ) );
                if ( '' === $cur_reg || (float) str_replace( ',', '.', $cur_reg ) <= 0 ) {
                    $fb_p = self::resolve_existing_catalog_fallback_price( $post_id, $fv );
                    if ( '' !== $fb_p && (float) $fb_p > 0 ) {
                        $fmt_p = (string) (float) $fb_p;
                        update_post_meta( $cid, '_regular_price', $fmt_p );
                        update_post_meta( $cid, '_price', $fmt_p );
                    }
                }
                // Protect variation thumbnail if a stale form submit cleared or reverted upload_image_id to a shared duplicate image
                if ( ! Shaghafi_Scanner::is_size_based_product( $post_id, array( $fv ), $fv['variantenauspraegung'] ) ) {
                    $cur_tid = (int) get_post_meta( $cid, '_thumbnail_id', true );
                    if (
                        $cur_tid <= 0 ||
                        ! Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $cur_tid, false ) ||
                        Shaghafi_Scanner::is_variation_thumbnail_shared_duplicate( $post_id, $cid, $cur_tid, $fv )
                    ) {
                        self::assign_smart_variation_image( $post_id, $cid, $fv, false );
                    }
                }
            }
            self::sync_clean_parent_attributes( $post_id );
            if ( class_exists( 'WC_Product_Variable' ) ) {
                WC_Product_Variable::sync( $post_id );
            }
            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $post_id );
            }
        }
    }

    /**
     * Resolve an existing valid retail/site fallback price for a product or variation from:
     * 1) _shaghafi_preserved_site_price / _shaghafi_retail_price / _regular_price / _price on $parent_id
     * 2) _regular_price / _price on sibling child variations of $parent_id
     * 3) Any product or variation in wp_postmeta sharing the same _shaghafi_variant_id, _shaghafi_product_id,
     *    artikelnummer, or base_sku (e.g. translated/sibling posts 35929/35930/35931 for 5050833 with 44.90 €)
     */
    public static function resolve_existing_catalog_fallback_price( $parent_id, $fv = array() ) {
        global $wpdb;
        $parent_id = (int) $parent_id;

        // 1. Check parent product meta keys
        if ( $parent_id > 0 ) {
            foreach ( array( '_shaghafi_retail_price', '_shaghafi_preserved_site_price', '_regular_price', '_price' ) as $mk ) {
                $val = trim( (string) get_post_meta( $parent_id, $mk, true ) );
                if ( '' !== $val ) {
                    $num = (float) str_replace( ',', '.', $val );
                    if ( $num > 0 ) {
                        return (string) $num;
                    }
                }
            }

            // 2. Check sibling variations on the same parent product
            $sib_ids = get_posts(
                array(
                    'post_parent' => $parent_id,
                    'post_type'   => 'product_variation',
                    'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
                    'numberposts' => -1,
                    'fields'      => 'ids',
                )
            );
            foreach ( $sib_ids as $sid ) {
                foreach ( array( '_regular_price', '_price', '_shaghafi_retail_price' ) as $mk ) {
                    $val = trim( (string) get_post_meta( (int) $sid, $mk, true ) );
                    if ( '' !== $val ) {
                        $num = (float) str_replace( ',', '.', $val );
                        if ( $num > 0 ) {
                            return (string) $num;
                        }
                    }
                }
            }
        }

        // 3. Check any other product/variation in the WordPress database sharing the same Shaghafi variant_id, product_id, or SKU
        $vid      = ! empty( $fv['variant_id'] ) ? trim( (string) $fv['variant_id'] ) : '';
        $pid      = ! empty( $fv['product_id'] ) ? trim( (string) $fv['product_id'] ) : ( $parent_id > 0 ? trim( (string) get_post_meta( $parent_id, '_shaghafi_product_id', true ) ) : '' );
        $full_sku = ! empty( $fv['artikelnummer'] ) ? trim( (string) $fv['artikelnummer'] ) : '';
        $base_sku = ! empty( $fv['base_sku'] ) ? trim( (string) $fv['base_sku'] ) : ( '' !== $full_sku ? Shaghafi_DB::extract_base_sku( $full_sku ) : '' );

        $where_clauses = array();
        $params        = array();
        if ( '' !== $vid ) {
            $where_clauses[] = "(pm_match.meta_key = '_shaghafi_variant_id' AND pm_match.meta_value = %s)";
            $params[]        = $vid;
        }
        if ( '' !== $pid ) {
            $where_clauses[] = "(pm_match.meta_key = '_shaghafi_product_id' AND pm_match.meta_value = %s)";
            $params[]        = $pid;
        }
        if ( '' !== $full_sku ) {
            $where_clauses[] = "(pm_match.meta_key = '_sku' AND pm_match.meta_value = %s)";
            $params[]        = $full_sku;
        }
        if ( '' !== $base_sku ) {
            $where_clauses[] = "(pm_match.meta_key = '_sku' AND (pm_match.meta_value = %s OR pm_match.meta_value LIKE %s))";
            $params[]        = $base_sku;
            $params[]        = $wpdb->esc_like( $base_sku ) . '-%';
        }

        if ( ! empty( $where_clauses ) ) {
            $sql  = "SELECT pm_price.meta_value
                     FROM {$wpdb->postmeta} pm_match
                     INNER JOIN {$wpdb->postmeta} pm_price
                         ON pm_price.post_id = pm_match.post_id
                        AND pm_price.meta_key IN ('_regular_price', '_price', '_shaghafi_retail_price', '_shaghafi_preserved_site_price')
                     WHERE (" . implode( ' OR ', $where_clauses ) . ")
                       AND pm_price.meta_value != ''
                     ORDER BY pm_price.post_id DESC
                     LIMIT 15";
            $rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $raw_p ) {
                    $num = (float) str_replace( ',', '.', trim( (string) $raw_p ) );
                    if ( $num > 0 ) {
                        if ( $parent_id > 0 ) {
                            update_post_meta( $parent_id, '_shaghafi_preserved_site_price', (string) $num );
                        }
                        return (string) $num;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Build live Variations & Attributes UI payload so WooCommerce's #variable_product_options (متغیرها)
     * and #product_attributes (ویژگی‌ها) tabs on post.php update immediately in the browser DOM after AJAX repairs!
     */
    public static function get_variations_attr_ui_payload( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return null;
        }
        $parent_attrs = get_post_meta( $parent_id, '_product_attributes', true );
        if ( ! is_array( $parent_attrs ) || empty( $parent_attrs ) ) {
            return null;
        }
        $attr_key  = '';
        $attr_name = '';
        $is_tax    = false;
        $pipe_vals = '';
        foreach ( $parent_attrs as $k => $cfg ) {
            if ( empty( $cfg['is_variation'] ) ) {
                continue;
            }
            $attr_key  = (string) $k;
            $attr_name = isset( $cfg['name'] ) ? (string) $cfg['name'] : $attr_key;
            $is_tax    = ( ! empty( $cfg['is_taxonomy'] ) && taxonomy_exists( $attr_key ) );
            $pipe_vals = isset( $cfg['value'] ) ? (string) $cfg['value'] : '';
            break;
        }
        if ( '' === $attr_key ) {
            return null;
        }

        $child_ids = get_posts(
            array(
                'post_parent' => $parent_id,
                'post_type'   => 'product_variation',
                'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
                'numberposts' => -1,
                'orderby'     => 'menu_order',
                'order'       => 'ASC',
                'fields'      => 'ids',
            )
        );

        $variations_map    = array();
        $variation_details = array();
        $options_map       = array();
        if ( ! $is_tax && '' !== $pipe_vals ) {
            foreach ( array_filter( array_map( 'trim', explode( '|', $pipe_vals ) ), 'strlen' ) as $pv ) {
                $options_map[ $pv ] = $pv;
            }
        }

        foreach ( $child_ids as $cid ) {
            $cid = (int) $cid;
            $val = trim( (string) get_post_meta( $cid, 'attribute_' . $attr_key, true ) );
            $tid = (int) get_post_meta( $cid, '_thumbnail_id', true );
            $t_u = $tid > 0 ? ( wp_get_attachment_image_url( $tid, 'thumbnail' ) ?: wp_get_attachment_url( $tid ) ) : '';
            $variation_details[ $cid ] = array(
                'attr_val'      => $val,
                'sku'           => (string) get_post_meta( $cid, '_sku', true ),
                'regular_price' => (string) get_post_meta( $cid, '_regular_price', true ),
                'stock'         => (string) get_post_meta( $cid, '_stock', true ),
                'thumb_id'      => $tid,
                'thumb_url'     => $t_u ? (string) $t_u : '',
            );
            if ( '' === $val ) {
                continue;
            }
            $variations_map[ $cid ] = $val;
            if ( $is_tax ) {
                $t = get_term_by( 'slug', $val, $attr_key );
                $options_map[ $val ] = ( $t && ! is_wp_error( $t ) ) ? (string) $t->name : $val;
            } else {
                $options_map[ $val ] = $val;
            }
        }

        $options_list = array();
        foreach ( $options_map as $ov => $ol ) {
            $options_list[] = array(
                'value' => (string) $ov,
                'label' => (string) $ol,
            );
        }

        return array(
            'attr_key'          => $attr_key,
            'attr_name'         => $attr_name,
            'is_taxonomy'       => $is_tax,
            'pipe_values'       => $pipe_vals,
            'options'           => $options_list,
            'variations'        => $variations_map,
            'variation_details' => $variation_details,
        );
    }

    /**
     * Build live Swatches UI payload (with attachment URLs) so the WCBoost Swatches tab on post.php
     * updates immediately in the browser DOM after AJAX repairs without requiring a page reload!
     */
    public static function get_swatches_ui_payload( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return array();
        }
        $swatches_meta = get_post_meta( $parent_id, 'wcboost_variation_swatches', true );
        if ( ! is_array( $swatches_meta ) || empty( $swatches_meta ) ) {
            return array();
        }
        $out = array();
        foreach ( $swatches_meta as $attr_slug => $cfg ) {
            if ( ! is_array( $cfg ) ) {
                continue;
            }
            $items = array();
            if ( ! empty( $cfg['swatches'] ) && is_array( $cfg['swatches'] ) ) {
                foreach ( $cfg['swatches'] as $opt_key => $sw ) {
                    $img_id  = ! empty( $sw['image'] ) ? (int) $sw['image'] : 0;
                    $img_url = $img_id > 0 ? ( wp_get_attachment_image_url( $img_id, 'thumbnail' ) ?: wp_get_attachment_url( $img_id ) ) : '';
                    $items[ (string) $opt_key ] = array(
                        'image_id'  => $img_id > 0 ? (string) $img_id : '',
                        'image_url' => $img_url ? (string) $img_url : '',
                    );
                }
            }
            $out[ (string) $attr_slug ] = array(
                'type'     => isset( $cfg['type'] ) ? (string) $cfg['type'] : '',
                'swatches' => $items,
            );
        }
        return $out;
    }

    /**
     * Download and sideload an image URL from Shaghafi into WordPress Media Library
     * Automatically converts the image to 700x800 WITHOUT CROPPING (بدون برش) on a white canvas,
     * encodes as high-quality .webp (94% quality >= 90%, zero quality loss), and sets the Product Name
     * as the image Filename, Title, and Alt text (with zero reference to "shaghafi").
     */
    public static function sideload_shaghafi_image( $image_url, $parent_id, $sku = '', $variant_id = '', $title = '', $force_download = false ) {
        $full_url = Shaghafi_Live_Scraper::thumbnail_to_full_media_url( $image_url );
        if ( '' === $full_url ) {
            return new WP_Error( 'empty_image_url', 'آدرس عکس مرجع خالی است.' );
        }

        $clean_product_title = self::build_clean_product_image_title( $parent_id, $title );

        // First check if this exact image already exists in WordPress Media Library (unless force_download is requested)
        if ( ! $force_download ) {
            $local = Shaghafi_Scanner::find_local_wp_image_for_variant( $parent_id, $sku, $variant_id, $full_url );
            if ( $local && ! empty( $local['attachment_id'] ) ) {
                $att_id = (int) $local['attachment_id'];
                self::ensure_attachment_is_700x800( $att_id, $parent_id, $sku, $clean_product_title );
                if ( '' !== $variant_id ) {
                    update_post_meta( $att_id, '_shaghafi_variant_id', $variant_id );
                }
                return $att_id;
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // 1. Download remote image (try download_url first, then fallback to wp_remote_get with sslverify=false)
        $tmp = download_url( $full_url, 25 );
        if ( is_wp_error( $tmp ) || ! file_exists( $tmp ) || filesize( $tmp ) <= 64 ) {
            if ( ! is_wp_error( $tmp ) && file_exists( $tmp ) ) {
                @unlink( $tmp );
            }
            $resp = wp_remote_get(
                $full_url,
                array(
                    'timeout'     => 30,
                    'redirection' => 5,
                    'sslverify'   => false,
                    'headers'     => array(
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    ),
                )
            );
            if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
                return is_wp_error( $resp ) ? $resp : new WP_Error( 'download_http_error', 'خطا در دانلود تصویر از سرور مرجع.' );
            }
            $body = wp_remote_retrieve_body( $resp );
            if ( empty( $body ) || strlen( $body ) <= 64 ) {
                return new WP_Error( 'download_empty', 'فایل تصویر دانلود شده خالی است.' );
            }
            $tmp = wp_tempnam( 'prod-img' );
            if ( ! $tmp || false === @file_put_contents( $tmp, $body ) ) {
                return new WP_Error( 'temp_write_failed', 'امکان ایجاد فایل موقت تصویر وجود ندارد.' );
            }
        }

        $target_dims = Shaghafi_Scanner::get_target_image_dimensions();
        $hash        = Shaghafi_Live_Scraper::extract_image_hash( $full_url );
        $safe_name   = self::build_clean_webp_filename( $parent_id, $sku, $clean_product_title );
        $att_id      = 0;

        // 2. Direct conversion & insertion into wp_upload_dir() (bypasses wp_handle_sideload .tmp MIME rejections!)
        $uploads = wp_upload_dir();
        if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['path'] ) && is_dir( $uploads['path'] ) && is_writable( $uploads['path'] ) ) {
            $unique_name = wp_unique_filename( $uploads['path'], $safe_name );
            $dest_file   = trailingslashit( $uploads['path'] ) . $unique_name;
            $conv_res    = self::convert_file_to_700x800_uncropped( $tmp, $target_dims['width'], $target_dims['height'], $dest_file );

            if ( ! is_wp_error( $conv_res ) && file_exists( $dest_file ) && filesize( $dest_file ) > 64 ) {
                @unlink( $tmp );
                $actual_ext  = ! empty( $conv_res['ext'] ) ? $conv_res['ext'] : 'webp';
                $actual_mime = ! empty( $conv_res['mime'] ) ? $conv_res['mime'] : 'image/webp';

                if ( 'jpg' === $actual_ext && preg_match( '/\.webp$/i', $dest_file ) ) {
                    $jpg_unique = wp_unique_filename( $uploads['path'], preg_replace( '/\.webp$/i', '.jpg', basename( $dest_file ) ) );
                    $jpg_dest   = trailingslashit( $uploads['path'] ) . $jpg_unique;
                    if ( @rename( $dest_file, $jpg_dest ) ) {
                        $dest_file = $jpg_dest;
                    }
                }

                @chmod( $dest_file, 0644 );
                $dest_url = trailingslashit( $uploads['url'] ) . basename( $dest_file );

                $attachment_post = array(
                    'post_mime_type' => $actual_mime,
                    'guid'           => $dest_url,
                    'post_parent'    => (int) $parent_id,
                    'post_title'     => $clean_product_title,
                    'post_name'      => sanitize_title( $clean_product_title . ( $sku ? '-' . $sku : '' ) ),
                    'post_content'   => $clean_product_title,
                    'post_excerpt'   => '',
                    'post_status'    => 'inherit',
                );

                $inserted_id = wp_insert_attachment( $attachment_post, $dest_file, (int) $parent_id, true );
                if ( ! is_wp_error( $inserted_id ) && (int) $inserted_id > 0 ) {
                    $att_id = (int) $inserted_id;
                    add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'disable_hard_crop_on_subsizes' ), 99 );
                    add_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_high_image_quality' ), 99, 2 );
                    add_filter( 'jpeg_quality', array( __CLASS__, 'force_high_image_quality' ), 99, 2 );
                    $attach_meta = wp_generate_attachment_metadata( $att_id, $dest_file );
                    remove_filter( 'jpeg_quality', array( __CLASS__, 'force_high_image_quality' ), 99 );
                    remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_high_image_quality' ), 99 );
                    remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'disable_hard_crop_on_subsizes' ), 99 );

                    if ( ! is_array( $attach_meta ) ) {
                        $attach_meta = array();
                    }
                    $attach_meta['width']  = $target_dims['width'];
                    $attach_meta['height'] = $target_dims['height'];
                    if ( empty( $attach_meta['file'] ) && function_exists( '_wp_relative_upload_path' ) ) {
                        $attach_meta['file'] = _wp_relative_upload_path( $dest_file );
                    }
                    wp_update_attachment_metadata( $att_id, $attach_meta );
                }
            }
        }

        // 3. Secondary fallback via media_handle_sideload if direct upload directory insertion was not used
        if ( $att_id <= 0 ) {
            if ( ! file_exists( $tmp ) ) {
                return new WP_Error( 'sideload_failed', 'خطا در ذخیره فایل تصویر در کتابخانه رسانه.' );
            }
            $conv_res = self::convert_file_to_700x800_uncropped( $tmp, $target_dims['width'], $target_dims['height'], $tmp );
            if ( ! is_wp_error( $conv_res ) && ! empty( $conv_res['ext'] ) && 'webp' !== $conv_res['ext'] ) {
                $safe_name = preg_replace( '/\.webp$/i', '.' . $conv_res['ext'], $safe_name );
            }

            $file_array = array(
                'name'     => $safe_name,
                'tmp_name' => $tmp,
            );

            add_filter( 'upload_mimes', array( __CLASS__, 'allow_webp_upload_mime' ), 99 );
            add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'fix_webp_filetype_and_ext' ), 99, 4 );
            add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'disable_hard_crop_on_subsizes' ), 99 );
            add_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_high_image_quality' ), 99, 2 );
            add_filter( 'jpeg_quality', array( __CLASS__, 'force_high_image_quality' ), 99, 2 );

            $sideload_id = media_handle_sideload(
                $file_array,
                $parent_id,
                $clean_product_title,
                array(
                    'post_title'   => $clean_product_title,
                    'post_content' => $clean_product_title,
                    'post_excerpt' => '',
                )
            );

            remove_filter( 'jpeg_quality', array( __CLASS__, 'force_high_image_quality' ), 99 );
            remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_high_image_quality' ), 99 );
            remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'disable_hard_crop_on_subsizes' ), 99 );
            remove_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'fix_webp_filetype_and_ext' ), 99 );
            remove_filter( 'upload_mimes', array( __CLASS__, 'allow_webp_upload_mime' ), 99 );

            if ( is_wp_error( $sideload_id ) ) {
                @unlink( $tmp );
                return $sideload_id;
            }
            $att_id = (int) $sideload_id;
        }

        // Enforce Product Name as Title & Alt text and verify 700x800 .webp file & metadata
        self::apply_product_name_to_attachment_seo( (int) $att_id, $parent_id, $clean_product_title );
        self::ensure_attachment_is_700x800( (int) $att_id, $parent_id, $sku, $clean_product_title );

        if ( '' !== $hash ) {
            update_post_meta( $att_id, '_shaghafi_media_hash', $hash );
        }
        if ( '' !== $variant_id ) {
            update_post_meta( $att_id, '_shaghafi_variant_id', $variant_id );
        }
        update_post_meta( $att_id, '_shaghafi_source_url', $full_url );

        if ( $parent_id > 0 ) {
            $parent_thumb = (int) get_post_meta( $parent_id, '_thumbnail_id', true );
            if ( $parent_thumb <= 0 ) {
                set_post_thumbnail( $parent_id, $att_id );
            } else {
                $gal_raw = (string) get_post_meta( $parent_id, '_product_image_gallery', true );
                $gal_ids = array_filter( array_map( 'intval', explode( ',', $gal_raw ) ) );
                if ( ! in_array( (int) $att_id, $gal_ids, true ) && (int) $att_id !== $parent_thumb ) {
                    $gal_ids[] = (int) $att_id;
                    update_post_meta( $parent_id, '_product_image_gallery', implode( ',', $gal_ids ) );
                }
            }
        }

        return (int) $att_id;
    }

    /**
     * Smart Variation Image Assignment + 700x800 Uncropped .webp (94% quality) + Clean Product Name/Alt Enforcement:
     * 1. Checks current thumbnail and ensures it is 700x800 .webp without cropping and has Product Name as Title & Alt
     * 2. Checks local WordPress Gallery / Media Library and ensures 700x800 .webp without cropping + Product Name & Alt
     * 3. Downloads from Shaghafi.de, converts to 700x800 .webp (94% quality) without cropping, sets Product Name & Alt, and attaches
     */
    public static function assign_smart_variation_image( $parent_id, $target_post_id, $fv, $force_replace = false ) {
        $is_size_var         = ( (int) $target_post_id !== (int) $parent_id && Shaghafi_Scanner::is_size_based_product( $parent_id, array( $fv ), $fv['variantenauspraegung'] ) );
        $current_thumb       = (int) get_post_meta( $target_post_id, '_thumbnail_id', true );
        $clean_product_title = self::build_clean_product_image_title( $parent_id, isset( $fv['live_title'] ) ? $fv['live_title'] : '' );

        // Always heal & ensure parent main image (تصویر شاخص) is 700x800 .webp with intact WP Admin subsizes,
        // or restore it from _product_image_gallery if it was deleted/broken after backup restore!
        $parent_thumb = (int) get_post_meta( $parent_id, '_thumbnail_id', true );
        if ( $parent_thumb > 0 ) {
            self::heal_attachment_after_backup_restore( $parent_thumb, $parent_id, isset( $fv['base_sku'] ) ? $fv['base_sku'] : '' );
        }
        if ( $parent_thumb > 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $parent_thumb, false ) ) {
            self::ensure_attachment_is_700x800( $parent_thumb, $parent_id, isset( $fv['base_sku'] ) ? $fv['base_sku'] : '', $clean_product_title );
        } else {
            $gal_raw = (string) get_post_meta( $parent_id, '_product_image_gallery', true );
            foreach ( array_filter( array_map( 'intval', explode( ',', $gal_raw ) ) ) as $gid ) {
                self::heal_attachment_after_backup_restore( $gid, $parent_id, isset( $fv['base_sku'] ) ? $fv['base_sku'] : '' );
                if ( Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $gid, false ) ) {
                    self::ensure_attachment_is_700x800( $gid, $parent_id, isset( $fv['base_sku'] ) ? $fv['base_sku'] : '', $clean_product_title );
                    set_post_thumbnail( $parent_id, $gid );
                    $parent_thumb = $gid;
                    break;
                }
            }
        }

        // Size variations (e.g. 45 cm, 50 cm, ring sizes 52, 54, 56) must NOT have individual variation images!
        // They share the parent product's main image & gallery.
        if ( $is_size_var ) {
            if ( $current_thumb > 0 ) {
                self::heal_attachment_after_backup_restore( $current_thumb, $parent_id, isset( $fv['base_sku'] ) ? $fv['base_sku'] : '' );
                if ( $parent_thumb <= 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $current_thumb, false ) ) {
                    self::ensure_attachment_is_700x800( $current_thumb, $parent_id, isset( $fv['base_sku'] ) ? $fv['base_sku'] : '', $clean_product_title );
                    set_post_thumbnail( $parent_id, $current_thumb );
                    $parent_thumb = $current_thumb;
                }
                delete_post_meta( $target_post_id, '_thumbnail_id' );
            }
            return array(
                'attachment_id' => $parent_thumb,
                'source'        => 'سایزی (استفاده از تصویر محصول مادر)',
                'url'           => $parent_thumb > 0 ? wp_get_attachment_url( $parent_thumb ) : '',
            );
        }

        // Attempt to heal current variation thumbnail on disk if DB backup restore reverted its path to .jpeg/.png
        if ( $current_thumb > 0 ) {
            self::heal_attachment_after_backup_restore(
                $current_thumb,
                $parent_id,
                $fv['artikelnummer'],
                ! empty( $fv['live_image_url'] ) ? Shaghafi_Live_Scraper::extract_image_hash( $fv['live_image_url'] ) : ''
            );
        }

        if ( $current_thumb > 0 && ! $force_replace && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $current_thumb, false ) ) {
            $img_suff      = ! empty( $fv['base_sku'] ) ? Shaghafi_Scanner::extract_sku_suffix_from_attachment( $current_thumb, $fv['base_sku'] ) : '';
            $wrong_sku_img = false;

            if ( '' !== $img_suff && ! empty( $fv['sku_suffix_norm'] ) && $img_suff !== $fv['sku_suffix_norm'] ) {
                $local_cand = Shaghafi_Scanner::find_local_wp_image_for_variant(
                    $parent_id,
                    $fv['artikelnummer'],
                    $fv['variant_id'],
                    $fv['live_image_url'],
                    $fv['variantenauspraegung']
                );
                if ( ( $local_cand && ! empty( $local_cand['attachment_id'] ) && (int) $local_cand['attachment_id'] !== $current_thumb ) || ! empty( $fv['live_image_url'] ) ) {
                    $wrong_sku_img = true;
                }
            }

            $is_shared_dup = (
                $target_post_id !== $parent_id &&
                Shaghafi_Scanner::is_variation_thumbnail_shared_duplicate( $parent_id, $target_post_id, $current_thumb, $fv )
            );

            if ( ! $wrong_sku_img && ! $is_shared_dup ) {
                // Ensure existing attachment is 700x800 uncropped .webp (94% quality) and has Product Name as Title & Alt!
                self::ensure_attachment_is_700x800( $current_thumb, $parent_id, $fv['artikelnummer'], $clean_product_title );
                if ( $parent_thumb <= 0 ) {
                    set_post_thumbnail( $parent_id, $current_thumb );
                }
                return array(
                    'attachment_id' => $current_thumb,
                    'source'        => 'kept_and_verified_700x800_webp',
                    'url'           => wp_get_attachment_url( $current_thumb ),
                );
            }
        }

        // 1. Try local WordPress Product Gallery (_product_image_gallery) / Media Library first (unless user clicked Force Replace from Shaghafi)
        if ( ! $force_replace ) {
            $local = Shaghafi_Scanner::find_local_wp_image_for_variant(
                $parent_id,
                $fv['artikelnummer'],
                $fv['variant_id'],
                $fv['live_image_url'],
                $fv['variantenauspraegung']
            );
            if ( $local && ! empty( $local['attachment_id'] ) ) {
                $local_id = (int) $local['attachment_id'];
                self::heal_attachment_after_backup_restore(
                    $local_id,
                    $parent_id,
                    $fv['artikelnummer'],
                    ! empty( $fv['live_image_url'] ) ? Shaghafi_Live_Scraper::extract_image_hash( $fv['live_image_url'] ) : ''
                );
                self::ensure_attachment_is_700x800( $local_id, $parent_id, $fv['artikelnummer'], $clean_product_title );
                if ( Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $local_id, false ) ) {
                    update_post_meta( $target_post_id, '_thumbnail_id', $local_id );
                    update_post_meta( $local_id, '_shaghafi_variant_id', $fv['variant_id'] );
                    if ( $parent_thumb <= 0 ) {
                        set_post_thumbnail( $parent_id, $local_id );
                    }
                    return array(
                        'attachment_id' => $local_id,
                        'source'        => $local['source'] . ' (700×800 WebP)',
                        'url'           => wp_get_attachment_url( $local_id ),
                    );
                }
            }
        }

        // 2. Fetch from shaghafi.de and convert to 700x800 uncropped .webp (94% quality) with Product Name as Title & Alt
        $live_img_url = ! empty( $fv['live_image_url'] ) ? $fv['live_image_url'] : '';
        if ( '' === $live_img_url ) {
            $gal = Shaghafi_Live_Scraper::fetch_variant_gallery_live( $fv['variant_id'] );
            if ( ! empty( $gal ) ) {
                $live_img_url = $gal[0];
                Shaghafi_DB::save_live_variant_data(
                    $fv['variant_id'],
                    array(
                        'image'   => $live_img_url,
                        'gallery' => $gal,
                    )
                );
            } else {
                $live_res = Shaghafi_Live_Scraper::fetch_product_live( $fv['product_id'], $fv['variant_id'], $fv['artikelnummer'], true );
                if ( ! is_wp_error( $live_res ) ) {
                    $vid_low = strtolower( $fv['variant_id'] );
                    if ( ! empty( $live_res['variants'][ $vid_low ]['image'] ) ) {
                        $live_img_url = $live_res['variants'][ $vid_low ]['image'];
                    } elseif ( ! empty( $live_res['image'] ) ) {
                        $live_img_url = $live_res['image'];
                    }
                }
            }
        }

        if ( '' !== $live_img_url ) {
            $att_id = self::sideload_shaghafi_image(
                $live_img_url,
                $parent_id,
                $fv['artikelnummer'],
                $fv['variant_id'],
                $clean_product_title,
                $force_replace
            );
            if ( ! is_wp_error( $att_id ) && $att_id > 0 ) {
                update_post_meta( $target_post_id, '_thumbnail_id', $att_id );
                if ( $parent_thumb <= 0 ) {
                    set_post_thumbnail( $parent_id, $att_id );
                }
                return array(
                    'attachment_id' => $att_id,
                    'source'        => 'webp_700x800_uncropped_94q',
                    'url'           => wp_get_attachment_url( $att_id ),
                );
            }
        }

        if ( $current_thumb > 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $current_thumb, false ) ) {
            // If no remote URL was found or download failed, still convert current image to 700x800 uncropped .webp with Product Name & Alt
            self::ensure_attachment_is_700x800( $current_thumb, $parent_id, $fv['artikelnummer'], $clean_product_title );
            return array(
                'attachment_id' => $current_thumb,
                'source'        => 'resized_existing_700x800_webp',
                'url'           => wp_get_attachment_url( $current_thumb ),
            );
        } elseif ( $parent_thumb > 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $parent_thumb, false ) ) {
            self::ensure_attachment_is_700x800( $parent_thumb, $parent_id, $fv['artikelnummer'], $clean_product_title );
            update_post_meta( $target_post_id, '_thumbnail_id', $parent_thumb );
            return array(
                'attachment_id' => $parent_thumb,
                'source'        => 'parent_shared_700x800_webp',
                'url'           => wp_get_attachment_url( $parent_thumb ),
            );
        }

        return null;
    }

    /**
     * Repair & Sync a single matched variation (or simple product) according to Reference Feed + Live Data
     */
    public static function repair_single_item( $parent_id, $variation_id, $feed_variant_id, $options = array() ) {
        $parent_id       = (int) $parent_id;
        $variation_id    = (int) $variation_id;
        $feed_variant_id = trim( (string) $feed_variant_id );

        $fv = Shaghafi_DB::get_by_variant_id( $feed_variant_id );
        if ( ! $fv ) {
            return new WP_Error( 'feed_row_not_found', 'ردیف مرجع در فید شقفی برای این variant_id یافت نشد.' );
        }

        $do_live_check = ! empty( $options['fetch_live'] ) || ( empty( $options['skip_live'] ) && null === $fv['live_price'] && empty( $fv['live_checked_at'] ) && ! empty( $options['ensure_price'] ) );
        if ( $do_live_check ) {
            Shaghafi_Live_Scraper::fetch_product_live( $fv['product_id'], $fv['variant_id'], $fv['artikelnummer'], true );
            $fv = Shaghafi_DB::get_by_variant_id( $feed_variant_id ) ?: $fv;
        }

        $all_feed_variants = Shaghafi_DB::get_variants_by_product_id( $fv['product_id'] );
        $single_attr_val   = trim( (string) $fv['variantenauspraegung'] );
        if ( 1 === count( $all_feed_variants ) && in_array( $single_attr_val, array( '', '1' ), true ) ) {
            // Single-item product in feed with placeholder/empty attribute ('1' or ''):
            // Ensure it is a Simple Product with its full SKU (e.g. 5010662-001) and remove any accidental child variation
            $existing_children = get_posts(
                array(
                    'post_parent' => $parent_id,
                    'post_type'   => 'product_variation',
                    'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
                    'numberposts' => -1,
                    'fields'      => 'ids',
                )
            );
            if ( count( $existing_children ) <= 1 ) {
                foreach ( $existing_children as $cid ) {
                    $c_thumb = (int) get_post_meta( (int) $cid, '_thumbnail_id', true );
                    if ( $c_thumb > 0 && (int) get_post_meta( $parent_id, '_thumbnail_id', true ) <= 0 ) {
                        update_post_meta( $parent_id, '_thumbnail_id', $c_thumb );
                    }
                    wp_delete_post( (int) $cid, true );
                }
                wp_set_object_terms( $parent_id, 'simple', 'product_type', false );
                delete_post_meta( $parent_id, '_product_attributes' );
                $variation_id = $parent_id;
            }
        } elseif ( count( $all_feed_variants ) > 1 && ( $variation_id <= 0 || $variation_id === $parent_id ) ) {
            // Multi-variant product in Shaghafi (e.g. 5050833 with 9 variants) that was saved as a Simple Product on the site:
            // Automatically convert the parent product from Simple to Variable and create/sync ALL its variations!
            $full_sync = self::sync_and_repair_entire_product( $parent_id, $options );
            if ( ! is_wp_error( $full_sync ) ) {
                return array(
                    'success'      => true,
                    'parent_id'    => $parent_id,
                    'variation_id' => $variation_id,
                    'sku'          => $fv['base_sku'],
                    'stock'        => (int) $fv['bestand'],
                    'price'        => null,
                    'changes'      => array(
                        sprintf(
                            'تبدیل خودکار از محصول ساده به متغیر و ساخت/همگام‌سازی کامل %d وریشن شقفی',
                            count( $all_feed_variants )
                        ),
                    ),
                );
            }
        }

        $is_simple      = ( $variation_id <= 0 || $variation_id === $parent_id );
        $target_post_id = $is_simple ? $parent_id : $variation_id;

        $do_sku_meta = ! isset( $options['sync_sku_meta'] ) || ! empty( $options['sync_sku_meta'] );
        $do_attrs    = ! isset( $options['sync_attributes_swatches'] ) || ! empty( $options['sync_attributes_swatches'] );
        $do_stock    = ! isset( $options['sync_stock'] ) || ! empty( $options['sync_stock'] );
        $do_prices   = ! isset( $options['sync_retail_prices'] ) || ! empty( $options['sync_retail_prices'] );
        $do_images   = ! isset( $options['sync_images'] ) || ! empty( $options['sync_images'] );

        $changes      = array();
        $target_stock = ( null !== $fv['live_stock'] && '' !== (string) $fv['live_stock'] )
            ? (int) $fv['live_stock']
            : (int) $fv['bestand'];
        $target_price = null;

        // 1 & 2. Save _shaghafi_product_id / _shaghafi_variant_id & Fix SKU
        if ( $do_sku_meta ) {
            update_post_meta( $parent_id, '_shaghafi_product_id', $fv['product_id'] );
            update_post_meta( $target_post_id, '_shaghafi_product_id', $fv['product_id'] );
            update_post_meta( $target_post_id, '_shaghafi_variant_id', $fv['variant_id'] );
            $changes[] = 'ذخیره متاهای شقفی';

            if ( ! $is_simple ) {
                $parent_sku = trim( (string) get_post_meta( $parent_id, '_sku', true ) );
                if ( '' === $parent_sku || $parent_sku === $fv['artikelnummer'] ) {
                    update_post_meta( $parent_id, '_sku', $fv['base_sku'] );
                }
            }

            // Simple products always get full artikelnummer e.g. 5010662-001
            self::set_clean_sku( $target_post_id, $fv['artikelnummer'] );
            $changes[] = 'SKU: ' . $fv['artikelnummer'];
        }

        // 3. Fix Attributes cleanly (for variations)
        if ( $do_attrs && ! $is_simple && '' !== $fv['variantenauspraegung'] ) {
            $preferred_label = ! empty( $fv['live_attr_name'] ) ? $fv['live_attr_name'] : 'Variante';
            self::repair_product_and_variation_attributes(
                $parent_id,
                $variation_id,
                $fv['variantenauspraegung'],
                $preferred_label
            );
            $changes[] = 'ویژگی: ' . $fv['variantenauspraegung'];
        }

        // 4. Update Stock
        if ( $do_stock ) {
            update_post_meta( $target_post_id, '_manage_stock', 'yes' );
            wc_update_product_stock( $target_post_id, $target_stock, 'set' );
            wc_update_product_stock_status( $target_post_id, $target_stock > 0 ? 'instock' : 'outofstock' );
            $changes[] = 'موجودی: ' . $target_stock;
        }

        // 5. Fetch & Update Retail Price & Direct Links ONLY from AkzentShop.de / MeinAkzent.de / Raptor-Watches.com (NEVER Shaghafi B2B!)
        if ( $do_prices ) {
            $retail_info = array(
                'price'          => null !== $fv['live_price'] && (float) $fv['live_price'] > 0 ? (float) $fv['live_price'] : null,
                'url'            => ! empty( $fv['live_product_url'] ) ? (string) $fv['live_product_url'] : (string) get_post_meta( $parent_id, '_shaghafi_retail_url', true ),
                'source'         => (string) get_post_meta( $parent_id, '_shaghafi_retail_source', true ),
                'links'          => json_decode( (string) get_post_meta( $parent_id, '_shaghafi_retail_links_json', true ), true ),
                'variant_prices' => array(),
            );

            // Was the stored live price an exact per-variant match, or a generic product-level fallback?
            $stored_is_exact = ! empty( $fv['live_price_exact'] );
            $exact_vp        = null;

            // Only perform an individual HTTP retail lookup if live check wasn't already performed for the parent product
            if ( empty( $options['skip_live'] ) && ! $do_live_check && null === $retail_info['price'] && empty( $fv['live_checked_at'] ) ) {
                $retail_info = Shaghafi_Live_Scraper::fetch_retail_data_for_product(
                    $fv['artikelnummer'],
                    $fv['base_sku'],
                    $fv['variant_id']
                );
                $exact_vp    = Shaghafi_Live_Scraper::pick_exact_variant_price( $retail_info, $fv['artikelnummer'] );
            }

            // Critical per-variant price fix: when the parent live fetch only had a generic/fallback price
            // (e.g. the last size costs more than its siblings), run an exact-SKU retail lookup.
            // Transient-cached (2h), so repeated syncs do not generate extra HTTP traffic.
            if ( null === $exact_vp && ! $stored_is_exact && ! $is_simple ) {
                $individual   = Shaghafi_Live_Scraper::fetch_retail_data_for_product(
                    $fv['artikelnummer'],
                    $fv['base_sku'],
                    $fv['variant_id']
                );
                $exact_vp     = Shaghafi_Live_Scraper::pick_exact_variant_price( $individual, $fv['artikelnummer'] );
                if ( null !== $exact_vp ) {
                    // Adopt the richer payload (links + matrix) while keeping the exact variant price.
                    $retail_info['price']          = $exact_vp['price'];
                    $retail_info['variant_prices'] = ! empty( $individual['variant_prices'] ) ? $individual['variant_prices'] : $retail_info['variant_prices'];
                    if ( ! empty( $exact_vp['url'] ) ) {
                        $retail_info['url']    = $exact_vp['url'];
                        $retail_info['source'] = ! empty( $exact_vp['source'] ) ? $exact_vp['source'] : $retail_info['source'];
                    } elseif ( ! empty( $individual['url'] ) ) {
                        $retail_info['url']    = $individual['url'];
                        $retail_info['source'] = ! empty( $individual['source'] ) ? $individual['source'] : $retail_info['source'];
                    }
                    if ( ! empty( $individual['links'] ) ) {
                        $retail_info['links'] = $individual['links'];
                    }
                } elseif ( is_array( $individual ) ) {
                    // No exact matrix hit, but remember this source's generic price/links if we had none.
                    if ( null === $retail_info['price'] && ! empty( $individual['price'] ) ) {
                        $retail_info['price'] = $individual['price'];
                    }
                    if ( empty( $retail_info['url'] ) && ! empty( $individual['url'] ) ) {
                        $retail_info['url']    = $individual['url'];
                        $retail_info['source'] = ! empty( $individual['source'] ) ? $individual['source'] : '';
                    }
                    if ( ! empty( $individual['links'] ) ) {
                        $retail_info['links'] = $individual['links'];
                    }
                }
            }

            // Even when the stored price was exact, prefer a fresh exact matrix entry if the payload contains one.
            if ( null === $exact_vp && ! empty( $retail_info['variant_prices'] ) ) {
                $exact_vp = Shaghafi_Live_Scraper::pick_exact_variant_price( $retail_info, $fv['artikelnummer'] );
            }
            if ( null !== $exact_vp && ! empty( $exact_vp['price'] ) ) {
                $retail_info['price'] = (float) $exact_vp['price'];
                if ( ! empty( $exact_vp['url'] ) ) {
                    $retail_info['url']    = $exact_vp['url'];
                    $retail_info['source'] = ! empty( $exact_vp['source'] ) ? $exact_vp['source'] : $retail_info['source'];
                }
            }
            $exact_price_match = ( null !== $exact_vp && ! empty( $exact_vp['price'] ) ) ? true : $stored_is_exact;

            if ( ! empty( $retail_info['links'] ) && is_array( $retail_info['links'] ) ) {
                update_post_meta( $parent_id, '_shaghafi_retail_links_json', wp_json_encode( $retail_info['links'] ) );
                update_post_meta( $target_post_id, '_shaghafi_retail_links_json', wp_json_encode( Shaghafi_Live_Scraper::build_variant_retail_links_list( $retail_info['links'], $fv['artikelnummer'] ) ) );
            }
            if ( ! empty( $retail_info['url'] ) ) {
                update_post_meta( $parent_id, '_shaghafi_retail_url', $retail_info['url'] );
                update_post_meta( $parent_id, '_shaghafi_retail_source', $retail_info['source'] );
                update_post_meta(
                    $target_post_id,
                    '_shaghafi_retail_url',
                    Shaghafi_Live_Scraper::build_variant_retail_url( $retail_info['url'], $retail_info['source'], $fv['artikelnummer'] )
                );
                update_post_meta( $target_post_id, '_shaghafi_retail_source', $retail_info['source'] );
            }
            if ( null !== $retail_info['price'] && $retail_info['price'] > 0 ) {
                update_post_meta( $parent_id, '_shaghafi_retail_price', (string) $retail_info['price'] );
                update_post_meta( $target_post_id, '_shaghafi_retail_price', (string) $retail_info['price'] );
                update_post_meta( $target_post_id, '_shaghafi_exact_retail_price', ! empty( $exact_price_match ) ? '1' : '0' );
                Shaghafi_DB::save_live_variant_data(
                    $fv['variant_id'],
                    array(
                        'price'       => (string) $retail_info['price'],
                        'price_exact' => ! empty( $exact_price_match ),
                        'product_url' => $retail_info['url'],
                    )
                );
                $fv['live_price']       = (string) $retail_info['price'];
                $fv['live_price_exact'] = ! empty( $exact_price_match ) ? 1 : 0;
            } else {
                // Stamp live_checked_at even when the product is not sold on the 3 retail B2C sites (e.g. B2B VE12 packs)
                Shaghafi_DB::save_live_variant_data( $fv['variant_id'], array() );
            }

            $custom_price = isset( $options['custom_price'] ) && '' !== (string) $options['custom_price']
                ? (float) str_replace( ',', '.', (string) $options['custom_price'] )
                : null;
            if ( null === $custom_price && ! empty( $options['fallback_price'] ) && '' === (string) get_post_meta( $target_post_id, '_regular_price', true ) ) {
                $custom_price = (float) str_replace( ',', '.', (string) $options['fallback_price'] );
            }
            if ( null === $custom_price && '' === trim( (string) get_post_meta( $target_post_id, '_regular_price', true ) ) ) {
                $cat_fb = self::resolve_existing_catalog_fallback_price( $parent_id, $fv );
                if ( '' !== $cat_fb && (float) $cat_fb > 0 ) {
                    $custom_price = (float) $cat_fb;
                }
            }
            $retail_base  = ( null !== $retail_info['price'] && $retail_info['price'] > 0 )
                ? (float) $retail_info['price']
                : ( null !== $fv['live_price'] && (float) $fv['live_price'] > 0 ? (float) $fv['live_price'] : null );
            $target_price = null !== $retail_base ? Shaghafi_Scanner::calculate_target_price( $retail_base ) : $custom_price;

            if ( null !== $target_price && $target_price > 0 ) {
                $formatted_price = (string) $target_price;
                update_post_meta( $target_post_id, '_regular_price', $formatted_price );
                $sale_price = get_post_meta( $target_post_id, '_sale_price', true );
                if ( '' === (string) $sale_price ) {
                    update_post_meta( $target_post_id, '_price', $formatted_price );
                }
                update_post_meta( $parent_id, '_shaghafi_preserved_site_price', $formatted_price );
                $src_lbl   = ! empty( $retail_info['source'] ) ? ' (' . $retail_info['source'] . ')' : '';
                $exact_lbl = ! empty( $exact_price_match ) ? ' [دقیق همین وریشن]' : '';
                $changes[] = 'قیمت' . $src_lbl . $exact_lbl . ': ' . $formatted_price . ' €';
            }
        } elseif ( '' === trim( (string) get_post_meta( $target_post_id, '_regular_price', true ) ) ) {
            $fb_raw = ! empty( $options['fallback_price'] )
                ? (string) $options['fallback_price']
                : self::resolve_existing_catalog_fallback_price( $parent_id, $fv );
            if ( '' !== $fb_raw && (float) str_replace( ',', '.', $fb_raw ) > 0 ) {
                $fb_p = (string) (float) str_replace( ',', '.', $fb_raw );
                update_post_meta( $target_post_id, '_regular_price', $fb_p );
                update_post_meta( $target_post_id, '_price', $fb_p );
                update_post_meta( $parent_id, '_shaghafi_preserved_site_price', $fb_p );
            }
        }

        // 6. Smart 700x800 Uncropped Image Repair (or Size Variation Image Cleanup)
        if ( $do_images ) {
            $force_img = ! empty( $options['force_image'] );
            $img_res   = self::assign_smart_variation_image( $parent_id, $target_post_id, $fv, $force_img );
            if ( $img_res ) {
                $changes[] = 'عکس (' . $img_res['source'] . ')';
            }
        }

        // 7. Ensure variation is published
        if ( ! $is_simple && 'publish' !== get_post_status( $variation_id ) ) {
            wp_update_post(
                array(
                    'ID'          => $variation_id,
                    'post_status' => 'publish',
                )
            );
        }

        // 8. Re-enforce variation attribute & re-sync parent attributes, ascending option order (4, 5, 7, 8, 9), variation menu_order,
        // and WCBoost Variation Swatches AFTER stock, image & status are finalized (so WC/Object-Cache saves in Step 4/7 never clear attribute_*)
        if ( ! $is_simple ) {
            if ( $do_attrs && '' !== $fv['variantenauspraegung'] ) {
                $preferred_label = ! empty( $fv['live_attr_name'] ) ? $fv['live_attr_name'] : 'Variante';
                self::repair_product_and_variation_attributes(
                    $parent_id,
                    $variation_id,
                    $fv['variantenauspraegung'],
                    $preferred_label
                );
            }
            if ( $do_attrs || $do_images ) {
                self::sync_clean_parent_attributes( $parent_id );
            }
            if ( class_exists( 'WC_Product_Variable' ) ) {
                WC_Product_Variable::sync( $parent_id );
            }
            clean_post_cache( $variation_id );
            wp_cache_delete( $variation_id, 'post_meta' );
            wp_cache_delete( 'product-' . $variation_id, 'products' );
        }
        clean_post_cache( $parent_id );
        wp_cache_delete( $parent_id, 'post_meta' );
        wp_cache_delete( 'product-' . $parent_id, 'products' );
        wc_delete_product_transients( $parent_id );

        return array(
            'success'      => true,
            'parent_id'    => $parent_id,
            'variation_id' => $variation_id,
            'sku'          => $fv['artikelnummer'],
            'stock'        => $target_stock,
            'price'        => $target_price,
            'changes'      => $changes,
        );
    }

    /**
     * Create a brand new WooCommerce Variation from a Shaghafi feed variant_id
     */
    public static function create_missing_variation( $parent_id, $feed_variant_id, $options = array() ) {
        $parent_id       = (int) $parent_id;
        $feed_variant_id = trim( (string) $feed_variant_id );

        if ( $parent_id <= 0 || '' === $feed_variant_id ) {
            return new WP_Error( 'invalid_params', 'شناسه محصول مادر یا variant_id نامعتبر است.' );
        }

        $fv = Shaghafi_DB::get_by_variant_id( $feed_variant_id );
        if ( ! $fv ) {
            return new WP_Error( 'feed_row_not_found', 'ردیف مرجع در فید شقفی یافت نشد.' );
        }

        $all_feed_variants = Shaghafi_DB::get_variants_by_product_id( $fv['product_id'] );
        $single_attr_val   = trim( (string) $fv['variantenauspraegung'] );
        $wc_prod           = wc_get_product( $parent_id );
        $is_wc_simple      = ( $wc_prod && ! $wc_prod->is_type( 'variable' ) );

        // Never convert a Simple Product or a single-row feed product into a Variable Product!
        if ( 1 === count( $all_feed_variants ) && ( $is_wc_simple || in_array( $single_attr_val, array( '', '1' ), true ) ) ) {
            return self::repair_single_item( $parent_id, $parent_id, $feed_variant_id, $options );
        }

        global $wpdb;
        $existing_children = array_map(
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

        // If this product has multiple variants in Shaghafi (e.g. 5050833 with 9 variants)
        // but currently has 0 child variations in WooCommerce (was saved as a Simple Product),
        // convert it to a Variable Product and create/sync ALL its variations at once so no variant is lost!
        if ( count( $all_feed_variants ) > 1 && empty( $existing_children ) && empty( $options['_inside_full_sync'] ) ) {
            $full = self::sync_and_repair_entire_product( $parent_id, $options );
            if ( ! is_wp_error( $full ) ) {
                return array(
                    'success'      => true,
                    'parent_id'    => $parent_id,
                    'variation_id' => 0,
                    'sku'          => $fv['artikelnummer'],
                    'stock'        => (int) $fv['bestand'],
                    'price'        => null,
                    'changes'      => array( 'تبدیل به محصول متغیر و افزودن کامل همه وریشن‌ها' ),
                );
            }
        }

        wp_set_object_terms( $parent_id, 'variable', 'product_type', false );
        if ( ! empty( $fv['base_sku'] ) ) {
            $cur_p_sku = trim( (string) get_post_meta( $parent_id, '_sku', true ) );
            if ( '' === $cur_p_sku || 0 === strcasecmp( $cur_p_sku, $fv['artikelnummer'] ) ) {
                self::set_clean_sku( $parent_id, $fv['base_sku'] );
            }
        }
        delete_post_meta( $parent_id, '_shaghafi_variant_id' );

        foreach ( $existing_children as $cid ) {
            $c_vid  = trim( (string) get_post_meta( $cid, '_shaghafi_variant_id', true ) );
            $c_sku  = trim( (string) get_post_meta( $cid, '_sku', true ) );
            $c_suff = '' !== $c_sku ? Shaghafi_DB::extract_sku_suffix_norm( $c_sku ) : '';
            $c_attr = Shaghafi_Scanner::inspect_variation_attributes( $parent_id, $cid );
            $c_norm = Shaghafi_DB::normalize_attr( $c_attr['display_val'] );
            if (
                0 === strcasecmp( $c_vid, $fv['variant_id'] ) ||
                ( '' !== $c_sku && 0 === strcasecmp( $c_sku, $fv['artikelnummer'] ) ) ||
                ( '' !== $c_suff && '' !== $fv['sku_suffix_norm'] && $c_suff === $fv['sku_suffix_norm'] ) ||
                ( '' !== $fv['attr_norm'] && $c_norm === $fv['attr_norm'] )
            ) {
                return self::repair_single_item( $parent_id, $cid, $feed_variant_id, $options );
            }
        }

        if ( ( null === $fv['live_price'] || empty( $fv['live_image_url'] ) ) && empty( $options['skip_live'] ) ) {
            Shaghafi_Live_Scraper::fetch_product_live( $fv['product_id'], $fv['variant_id'], $fv['artikelnummer'], true );
            $fv = Shaghafi_DB::get_by_variant_id( $feed_variant_id );
            if ( ! $fv ) {
                return new WP_Error( 'variant_discontinued', 'این وریشن دیگر در سایت شقفی وجود ندارد (حذف شده است) و به محصول اضافه نشد.' );
            }
        }

        // Variation titles must carry the option label (size/colour), never the raw SKU/article number.
        $variation_label = trim( (string) $fv['variantenauspraegung'] );
        if ( '' === $variation_label ) {
            $variation_label = 'گزینه ' . ( count( $existing_children ) + 1 );
        }

        $variation_id = wp_insert_post(
            array(
                'post_title'  => get_the_title( $parent_id ) . ' - ' . $variation_label,
                'post_name'   => 'product-' . $parent_id . '-variation-' . sanitize_title( $fv['artikelnummer'] ),
                'post_status' => 'publish',
                'post_parent' => $parent_id,
                'post_type'   => 'product_variation',
                'menu_order'  => count( $existing_children ) + 1,
            ),
            true
        );

        if ( is_wp_error( $variation_id ) || ! $variation_id ) {
            return new WP_Error( 'variation_create_failed', 'خطا در ایجاد پست وریشن جدید در ووکامرس.' );
        }

        if ( null === $fv['live_price'] && empty( $options['custom_price'] ) ) {
            $cat_fb = self::resolve_existing_catalog_fallback_price( $parent_id, $fv );
            if ( '' !== $cat_fb && (float) $cat_fb > 0 ) {
                $options['custom_price'] = $cat_fb;
            }
        }

        return self::repair_single_item( $parent_id, $variation_id, $feed_variant_id, $options );
    }

    /**
     * Check whether an unmatched variation was previously permanently deleted by the user
     * (so even if Polylang/WPML variation sync or object cache tries to resurrect it, it is immediately purged on scan).
     */
    public static function is_variation_marked_permanently_deleted( $parent_id, $variation_id, $sku = '', $feed_vid = '', $attr_val = '', $feed_pid = '' ) {
        $reg = get_option( 'shaghafi_permanently_deleted_variations', array() );
        if ( empty( $reg ) || ! is_array( $reg ) ) {
            return false;
        }

        $variation_id = (int) $variation_id;
        $parent_id    = (int) $parent_id;
        if ( $variation_id > 0 && isset( $reg[ 'vid_id:' . $variation_id ] ) ) {
            return true;
        }

        $feed_vid = strtolower( trim( (string) $feed_vid ) );
        if ( '' !== $feed_vid && isset( $reg[ 'feed_vid:' . $feed_vid ] ) ) {
            return true;
        }

        $sku_low = strtolower( trim( (string) $sku ) );
        if ( '' !== $sku_low && isset( $reg[ 'sku:' . $sku_low ] ) ) {
            return true;
        }

        $attr_norm = Shaghafi_DB::normalize_attr( $attr_val );
        if ( '' !== $attr_norm ) {
            if ( $parent_id > 0 && isset( $reg[ 'p_attr:' . $parent_id . '|' . $attr_norm ] ) ) {
                return true;
            }
            $feed_pid_low = strtolower( trim( (string) $feed_pid ) );
            if ( '' !== $feed_pid_low && isset( $reg[ 'pid_attr:' . $feed_pid_low . '|' . $attr_norm ] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Force-delete a single variation ID from WooCommerce data store, wp_posts, wp_postmeta,
     * wp_term_relationships, wp_wc_product_meta_lookup, and object caches.
     */
    private static function force_purge_variation_post( $vid ) {
        global $wpdb;
        $vid = (int) $vid;
        if ( $vid <= 0 ) {
            return;
        }

        if ( function_exists( 'wc_get_product' ) ) {
            $v_prod = wc_get_product( $vid );
            if ( $v_prod && is_object( $v_prod ) && method_exists( $v_prod, 'delete' ) ) {
                $v_prod->delete( true );
            }
        }

        wp_delete_post( $vid, true );

        // Direct SQL purge to guarantee 100% removal even if pre_delete_post / trash / object-cache hooks intervene
        $wpdb->delete( $wpdb->posts, array( 'ID' => $vid ), array( '%d' ) );
        $wpdb->delete( $wpdb->postmeta, array( 'post_id' => $vid ), array( '%d' ) );
        $wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $vid ), array( '%d' ) );
        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        $prev_err     = $wpdb->suppress_errors( true );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$lookup_table} WHERE product_id = %d", $vid ) );
        $wpdb->suppress_errors( $prev_err );

        clean_post_cache( $vid );
        wp_cache_delete( $vid, 'posts' );
        wp_cache_delete( $vid, 'post_meta' );
        wp_cache_delete( 'product-' . $vid, 'products' );
    }

    /**
     * Permanently delete an orphan/disabled variation that is missing in likadeo.csv
     * Removes it completely from both the frontend product page and the backend product edit page,
     * across Polylang/WPML translated siblings, and records it so it can never return on rescan!
     */
    public static function disable_missing_variation( $variation_id, $fallback_parent_id = 0 ) {
        global $wpdb;
        $variation_id       = (int) $variation_id;
        $fallback_parent_id = (int) $fallback_parent_id;
        if ( $variation_id <= 0 ) {
            return new WP_Error( 'invalid_variation', 'شناسه وریشن نامعتبر است.' );
        }

        $parent_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $variation_id )
        );
        if ( $parent_id <= 0 ) {
            $parent_id = (int) wp_get_post_parent_id( $variation_id );
        }
        if ( $parent_id <= 0 && $fallback_parent_id > 0 ) {
            $parent_id = $fallback_parent_id;
        }

        $var_sku   = trim( (string) get_post_meta( $variation_id, '_sku', true ) );
        $var_vid   = strtolower( trim( (string) get_post_meta( $variation_id, '_shaghafi_variant_id', true ) ) );
        $var_pid   = strtolower( trim( (string) get_post_meta( $variation_id, '_shaghafi_product_id', true ) ) );
        if ( '' === $var_pid && $parent_id > 0 ) {
            $var_pid = strtolower( trim( (string) get_post_meta( $parent_id, '_shaghafi_product_id', true ) ) );
        }
        $attr_info = $parent_id > 0
            ? Shaghafi_Scanner::inspect_variation_attributes( $parent_id, $variation_id )
            : array( 'display_val' => '' );
        $attr_norm = Shaghafi_DB::normalize_attr( isset( $attr_info['display_val'] ) ? $attr_info['display_val'] : '' );

        // Check whether this variation's SKU or variant_id exists in wp_shaghafi_feed_items
        $exists_in_feed = false;
        if ( '' !== $var_vid && null !== Shaghafi_DB::get_by_variant_id( $var_vid ) ) {
            $exists_in_feed = true;
        } elseif ( '' !== $var_sku && null !== Shaghafi_DB::get_by_sku( $var_sku ) ) {
            $exists_in_feed = true;
        } elseif ( '' !== $var_pid && '' !== $attr_norm ) {
            $p_vars = Shaghafi_DB::get_variants_by_product_id( $var_pid );
            foreach ( $p_vars as $pv ) {
                if ( $pv['attr_norm'] === $attr_norm ) {
                    $exists_in_feed = true;
                    break;
                }
            }
        }

        // Collect all variation IDs to purge (including Polylang translations and matching obsolete variations on translated parent posts)
        $target_vids      = array( $variation_id );
        $affected_parents = $parent_id > 0 ? array( $parent_id ) : array();

        if ( function_exists( 'pll_get_post_translations' ) ) {
            $pll_vids = pll_get_post_translations( $variation_id );
            if ( is_array( $pll_vids ) ) {
                foreach ( $pll_vids as $tv ) {
                    $tv = (int) $tv;
                    if ( $tv > 0 ) {
                        $target_vids[] = $tv;
                        $tp            = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $tv ) );
                        if ( $tp > 0 ) {
                            $affected_parents[] = $tp;
                        }
                    }
                }
            }
        }

        if ( ! $exists_in_feed ) {
            if ( '' !== $var_vid ) {
                $same_vid_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT p.ID, p.post_parent
                         FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_shaghafi_variant_id'
                         WHERE p.post_type = 'product_variation' AND LOWER(TRIM(pm.meta_value)) = %s",
                        $var_vid
                    )
                );
                if ( $same_vid_rows ) {
                    foreach ( $same_vid_rows as $svr ) {
                        $target_vids[] = (int) $svr->ID;
                        if ( (int) $svr->post_parent > 0 ) {
                            $affected_parents[] = (int) $svr->post_parent;
                        }
                    }
                }
            }
            if ( '' !== $var_sku ) {
                $same_sku_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT p.ID, p.post_parent
                         FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
                         WHERE p.post_type = 'product_variation' AND LOWER(TRIM(pm.meta_value)) = %s",
                        strtolower( $var_sku )
                    )
                );
                if ( $same_sku_rows ) {
                    foreach ( $same_sku_rows as $ssr ) {
                        $target_vids[] = (int) $ssr->ID;
                        if ( (int) $ssr->post_parent > 0 ) {
                            $affected_parents[] = (int) $ssr->post_parent;
                        }
                    }
                }
            }
        }

        $target_vids      = array_values( array_unique( array_filter( array_map( 'intval', $target_vids ) ) ) );
        $affected_parents = array_values( array_unique( array_filter( array_map( 'intval', $affected_parents ) ) ) );

        // Persist in shaghafi_permanently_deleted_variations so it can never be resurrected on rescan
        $reg = get_option( 'shaghafi_permanently_deleted_variations', array() );
        if ( ! is_array( $reg ) ) {
            $reg = array();
        }
        foreach ( $target_vids as $tv ) {
            $reg[ 'vid_id:' . $tv ] = 1;
        }
        if ( ! $exists_in_feed ) {
            if ( '' !== $var_vid ) {
                $reg[ 'feed_vid:' . $var_vid ] = 1;
            }
            if ( '' !== $var_sku ) {
                $reg[ 'sku:' . strtolower( $var_sku ) ] = 1;
            }
            if ( '' !== $attr_norm ) {
                foreach ( $affected_parents as $ap ) {
                    $reg[ 'p_attr:' . $ap . '|' . $attr_norm ] = 1;
                }
                if ( '' !== $var_pid ) {
                    $reg[ 'pid_attr:' . $var_pid . '|' . $attr_norm ] = 1;
                }
            }
        }
        if ( count( $reg ) > 8000 ) {
            $reg = array_slice( $reg, -6000, null, true );
        }
        update_option( 'shaghafi_permanently_deleted_variations', $reg, false );

        // Permanently delete all target variation posts first (before syncing parent products so Polylang never copies a variation back)
        foreach ( $target_vids as $tv ) {
            self::force_purge_variation_post( $tv );
        }

        // Clean parent attributes, swatches, and WooCommerce transients on all affected parent products
        foreach ( $affected_parents as $ap ) {
            self::sync_clean_parent_attributes( $ap );
            if ( class_exists( 'WC_Product_Variable' ) ) {
                WC_Product_Variable::sync( $ap );
            }
            wc_delete_product_transients( $ap );
            clean_post_cache( $ap );
        }

        delete_transient( Shaghafi_Scanner::OBSOLETE_CACHE_KEY );

        return array(
            'success'          => true,
            'variation_id'     => $variation_id,
            'deleted_vids'     => $target_vids,
            'parent_id'        => $parent_id,
            'affected_parents' => $affected_parents,
            'mode'             => 'deleted_permanently',
        );
    }

    /**
     * Complete, 100% Autonomous Live Check + Full Product Repair & Synchronization:
     * - Performs Live Check on Shaghafi.de + 3 Retail Price Sites (AkzentShop, MeinAkzent, Raptor-Watches)
     * - Purges any 404/discontinued variants from wp_shaghafi_feed_items
     * - Converts Simple -> Variable if Shaghafi has multiple variants (e.g. 5050833 with 9 variants)
     * - Permanently deletes disabled/removed variations (DISABLE_MISSING)
     * - Creates all missing variations (CREATE_NEW_VARIATION) with 700x800 uncropped images, stock, SKU, attributes, and retail/fallback price
     * - Repairs all existing variations (SKU, meta IDs, ascending attributes, stock, retail price, 700x800 uncropped images / size-variation cleanup, WCBoost Swatches)
     */
    public static function sync_and_repair_entire_product( $parent_id, $options = array() ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return new WP_Error( 'invalid_parent', 'شناسه محصول مادر نامعتبر است.' );
        }

        $identity = Shaghafi_Scanner::resolve_parent_shaghafi_identity( $parent_id );
        if ( ! $identity || empty( $identity['variants'] ) ) {
            return new WP_Error( 'not_in_feed', 'این محصول در فید مرجع شقفی یافت نشد.' );
        }

        $feed_pid      = $identity['product_id'];
        $feed_variants = $identity['variants'];
        $first_fv      = $feed_variants[0];

        // Preserve existing price on parent, child variations, or sibling/translated catalog posts before any conversion
        $fallback_price = self::resolve_existing_catalog_fallback_price( $parent_id, $first_fv );
        if ( '' !== $fallback_price && (float) $fallback_price > 0 ) {
            update_post_meta( $parent_id, '_shaghafi_preserved_site_price', $fallback_price );
        }

        // 1. Perform Live Check on Shaghafi.de + 3 Retail Sites (unless explicitly skipped)
        $live = null;
        if ( empty( $options['skip_live'] ) ) {
            $live = Shaghafi_Live_Scraper::fetch_product_live(
                $feed_pid,
                $first_fv['variant_id'],
                $first_fv['artikelnummer'],
                true
            );
            if ( ! is_wp_error( $live ) ) {
                if ( ! empty( $live['product_id'] ) ) {
                    update_post_meta( $parent_id, '_shaghafi_product_id', $live['product_id'] );
                }
                if ( ! empty( $live['retail_links'] ) ) {
                    update_post_meta( $parent_id, '_shaghafi_retail_links_json', wp_json_encode( $live['retail_links'] ) );
                }
                if ( ! empty( $live['retail_url'] ) ) {
                    update_post_meta( $parent_id, '_shaghafi_retail_url', $live['retail_url'] );
                    update_post_meta( $parent_id, '_shaghafi_retail_source', $live['retail_source'] );
                }
                if ( null !== $live['price'] && $live['price'] > 0 ) {
                    update_post_meta( $parent_id, '_shaghafi_retail_price', (string) $live['price'] );
                }
            }
            // Refresh feed variants after live matrix sync (which purges any 404/discontinued variants)
            $refreshed = Shaghafi_DB::get_variants_by_product_id( $feed_pid );
            if ( ! empty( $refreshed ) ) {
                $feed_variants = $refreshed;
                $first_fv      = $feed_variants[0];
            }
        }

        // Read selective checkboxes (defaulting to true)
        $settings       = get_option( 'shaghafi_sync_settings', array() );
        $do_sku_meta    = isset( $options['sync_sku_meta'] ) ? ! empty( $options['sync_sku_meta'] ) : ( ! isset( $settings['sync_sku_meta'] ) || ! empty( $settings['sync_sku_meta'] ) );
        $do_attrs       = isset( $options['sync_attributes_swatches'] ) ? ! empty( $options['sync_attributes_swatches'] ) : ( ! isset( $settings['sync_attributes_swatches'] ) || ! empty( $settings['sync_attributes_swatches'] ) );
        $do_stock       = isset( $options['sync_stock'] ) ? ! empty( $options['sync_stock'] ) : ( ! isset( $settings['sync_stock'] ) || ! empty( $settings['sync_stock'] ) );
        $do_prices      = isset( $options['sync_retail_prices'] ) ? ! empty( $options['sync_retail_prices'] ) : ( ! isset( $settings['sync_retail_prices'] ) || ! empty( $settings['sync_retail_prices'] ) );
        $do_images      = isset( $options['sync_images'] ) ? ! empty( $options['sync_images'] ) : ( ! isset( $settings['sync_images'] ) || ! empty( $settings['sync_images'] ) );
        $do_create_vars = isset( $options['sync_create_missing_variations'] ) ? ! empty( $options['sync_create_missing_variations'] ) : ( ! isset( $settings['sync_create_missing_variations'] ) || ! empty( $settings['sync_create_missing_variations'] ) );
        $do_delete_vars = isset( $options['sync_delete_obsolete_variations'] ) ? ! empty( $options['sync_delete_obsolete_variations'] ) : ( ! isset( $settings['sync_delete_obsolete_variations'] ) || ! empty( $settings['sync_delete_obsolete_variations'] ) );

        $item_opts = array(
            '_inside_full_sync'        => true,
            'fetch_live'               => false,
            'skip_live'                => true,
            'force_image'              => $do_images,
            'ensure_price'             => $do_prices,
            'sync_sku_meta'            => $do_sku_meta ? 1 : 0,
            'sync_attributes_swatches' => $do_attrs ? 1 : 0,
            'sync_stock'               => $do_stock ? 1 : 0,
            'sync_retail_prices'       => $do_prices ? 1 : 0,
            'sync_images'              => $do_images ? 1 : 0,
        );
        if ( '' !== $fallback_price ) {
            $item_opts['fallback_price'] = $fallback_price;
        }

        $repaired = 0;
        $created  = 0;
        $deleted  = 0;

        // Heal all product gallery, parent featured image, and variation attachments (especially after a database backup restore!)
        if ( $do_images ) {
            self::heal_product_gallery_and_variation_images( $parent_id, $feed_variants );
        }

        // Case A: True 1-row Simple Product in Shaghafi
        if ( 1 === count( $feed_variants ) ) {
            $res = self::repair_single_item( $parent_id, $parent_id, $first_fv['variant_id'], $item_opts );
            if ( ! is_wp_error( $res ) ) {
                $repaired++;
            }
            wc_delete_product_transients( $parent_id );
            clean_post_cache( $parent_id );
            return array(
                'parent_id' => $parent_id,
                'repaired'  => $repaired,
                'created'   => 0,
                'deleted'   => 0,
                'live'      => $live,
            );
        }

        // Case B: Multi-Variant Product in Shaghafi (count > 1, e.g. 5050833 with 9 variants)
        // If currently saved as Simple Product in WooCommerce, convert to Variable Product first!
        $wc_prod = wc_get_product( $parent_id );
        if ( $wc_prod && ! $wc_prod->is_type( 'variable' ) ) {
            wp_set_object_terms( $parent_id, 'variable', 'product_type', false );
        }
        update_post_meta( $parent_id, '_shaghafi_product_id', $feed_pid );
        delete_post_meta( $parent_id, '_shaghafi_variant_id' );
        if ( ! empty( $first_fv['base_sku'] ) ) {
            $cur_p_sku = trim( (string) get_post_meta( $parent_id, '_sku', true ) );
            if ( '' === $cur_p_sku || 0 !== strcasecmp( $cur_p_sku, $first_fv['base_sku'] ) ) {
                self::set_clean_sku( $parent_id, $first_fv['base_sku'] );
            }
        }
        clean_post_cache( $parent_id );
        wc_delete_product_transients( $parent_id );

        // Scan the product to match existing child variations, find DISABLE_MISSING, and find CREATE_NEW_VARIATION
        $rows = Shaghafi_Scanner::scan_single_product( $parent_id );

        // 1) Permanently delete obsolete/disabled variations not in Shaghafi
        if ( $do_delete_vars ) {
            foreach ( $rows as $r ) {
                if ( 'DISABLE_MISSING' === $r['action'] && (int) $r['variation_id'] > 0 && (int) $r['variation_id'] !== $parent_id ) {
                    $d_res = self::disable_missing_variation( (int) $r['variation_id'] );
                    if ( ! is_wp_error( $d_res ) ) {
                        $deleted++;
                    }
                }
            }
        }

        // 2) Repair all existing matched child variations
        foreach ( $rows as $r ) {
            $vid  = (int) $r['variation_id'];
            $fvid = isset( $r['feed_variant_id'] ) ? (string) $r['feed_variant_id'] : '';
            if ( $vid > 0 && $vid !== $parent_id && '' !== $fvid && '—' !== $fvid && in_array( $r['action'], array( 'REPAIR_AND_LINK', 'KEEP_AND_SYNC' ), true ) ) {
                $r_opts = $item_opts;
                if ( empty( $r['live_price'] ) && '' !== $fallback_price ) {
                    $r_opts['custom_price'] = $fallback_price;
                }
                $r_res = self::repair_single_item( $parent_id, $vid, $fvid, $r_opts );
                if ( ! is_wp_error( $r_res ) ) {
                    $repaired++;
                }
            }
        }

        // 3) Create all missing variations (including all variants of a former Simple Product that was just converted to Variable!)
        if ( $do_create_vars ) {
            foreach ( $rows as $r ) {
                $vid  = (int) $r['variation_id'];
                $fvid = isset( $r['feed_variant_id'] ) ? (string) $r['feed_variant_id'] : '';
                $is_former_simple_row = ( ( $vid <= 0 || $vid === $parent_id ) && ! empty( $r['is_simple'] ) );
                if ( ( 'CREATE_NEW_VARIATION' === $r['action'] || $is_former_simple_row ) && '' !== $fvid && '—' !== $fvid ) {
                    $c_opts = $item_opts;
                    if ( empty( $r['live_price'] ) && '' !== $fallback_price ) {
                        $c_opts['custom_price'] = $fallback_price;
                    }
                    $c_res = self::create_missing_variation( $parent_id, $fvid, $c_opts );
                    if ( ! is_wp_error( $c_res ) ) {
                        $created++;
                    }
                }
            }
        }

        // 4) Final clean sync of parent attributes, ascending option & variation order (1, 2, 3, ...), and WCBoost Variation Swatches
        self::sync_clean_parent_attributes( $parent_id );
        if ( class_exists( 'WC_Product_Variable' ) ) {
            WC_Product_Variable::sync( $parent_id );
        }
        wc_delete_product_transients( $parent_id );
        clean_post_cache( $parent_id );

        return array(
            'parent_id' => $parent_id,
            'repaired'  => $repaired,
            'created'   => $created,
            'deleted'   => $deleted,
            'live'      => $live,
        );
    }

    /**
     * Handle removal / deactivation of an Obsolete Product or Variation that is no longer used in Shaghafi
     * Modes:
     * - 'draft_zero': Set status to draft/private and stock to 0
     * - 'trash': Move product (or variation) to Trash
     * - 'delete': Permanently delete post
     */
    public static function handle_obsolete_item( $parent_id, $variation_id = 0, $mode = 'trash' ) {
        global $wpdb;
        $parent_id    = (int) $parent_id;
        $variation_id = (int) $variation_id;
        $target_id    = $variation_id > 0 ? $variation_id : $parent_id;

        if ( $target_id <= 0 ) {
            return new WP_Error( 'invalid_target', 'شناسه محصول یا وریشن نامعتبر است.' );
        }

        // For any obsolete variation ($variation_id > 0), ALWAYS permanently delete it so it never returns on rescan!
        if ( $variation_id > 0 ) {
            $del_res = self::disable_missing_variation( $variation_id, $parent_id );
            if ( is_wp_error( $del_res ) ) {
                return $del_res;
            }
            if ( $parent_id > 0 ) {
                $updated_rows = Shaghafi_Scanner::scan_single_product( $parent_id );
                Shaghafi_Scanner::update_product_in_scan_cache( $parent_id, $updated_rows );
            }
            delete_transient( Shaghafi_Scanner::OBSOLETE_CACHE_KEY );
            return array(
                'success'      => true,
                'target_id'    => $target_id,
                'parent_id'    => $parent_id,
                'variation_id' => $variation_id,
                'mode'         => 'deleted_permanently',
            );
        }

        // Collect $parent_id and any Polylang translated sibling parent products if applicable
        $target_parents = array( $parent_id );
        if ( function_exists( 'pll_get_post_translations' ) ) {
            $pll_pids = pll_get_post_translations( $parent_id );
            if ( is_array( $pll_pids ) ) {
                foreach ( $pll_pids as $tp ) {
                    if ( (int) $tp > 0 ) {
                        $target_parents[] = (int) $tp;
                    }
                }
            }
        }
        $target_parents = array_values( array_unique( array_filter( array_map( 'intval', $target_parents ) ) ) );

        if ( 'draft_zero' === $mode ) {
            foreach ( $target_parents as $pid ) {
                update_post_meta( $pid, '_shaghafi_obsolete_handled', 'draft_zero' );
                update_post_meta( $pid, '_manage_stock', 'yes' );
                wc_update_product_stock( $pid, 0, 'set' );
                wc_update_product_stock_status( $pid, 'outofstock' );
                wp_update_post(
                    array(
                        'ID'          => $pid,
                        'post_status' => 'draft',
                    )
                );
                $wpdb->update(
                    $wpdb->posts,
                    array( 'post_status' => 'draft' ),
                    array( 'ID' => $pid ),
                    array( '%s' ),
                    array( '%d' )
                );

                $children = array_map(
                    'intval',
                    $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts}
                             WHERE post_parent = %d AND post_type = 'product_variation'",
                            $pid
                        )
                    )
                );
                foreach ( $children as $cid ) {
                    update_post_meta( $cid, '_manage_stock', 'yes' );
                    wc_update_product_stock( $cid, 0, 'set' );
                    wc_update_product_stock_status( $cid, 'outofstock' );
                    clean_post_cache( $cid );
                }
                wc_delete_product_transients( $pid );
                clean_post_cache( $pid );
            }
        } elseif ( 'delete' === $mode ) {
            foreach ( $target_parents as $pid ) {
                $children = array_map(
                    'intval',
                    $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts}
                             WHERE post_parent = %d AND post_type = 'product_variation'",
                            $pid
                        )
                    )
                );
                foreach ( $children as $cid ) {
                    self::force_purge_variation_post( (int) $cid );
                }
                wp_delete_post( $pid, true );
                $wpdb->delete( $wpdb->posts, array( 'ID' => $pid ), array( '%d' ) );
                $wpdb->delete( $wpdb->postmeta, array( 'post_id' => $pid ), array( '%d' ) );
                clean_post_cache( $pid );
            }
        } else {
            // Default: 'trash'
            foreach ( $target_parents as $pid ) {
                update_post_meta( $pid, '_shaghafi_obsolete_handled', 'trash' );
                wp_trash_post( $pid );
                $wpdb->update(
                    $wpdb->posts,
                    array( 'post_status' => 'trash' ),
                    array( 'ID' => $pid ),
                    array( '%s' ),
                    array( '%d' )
                );
                wc_delete_product_transients( $pid );
                clean_post_cache( $pid );
            }
        }

        Shaghafi_Scanner::clear_scan_caches();

        return array(
            'success'      => true,
            'target_id'    => $target_id,
            'parent_id'    => $parent_id,
            'variation_id' => $variation_id,
            'mode'         => $mode,
        );
    }

    /**
     * Create a complete WooCommerce Product (Parent + All Variations + 700x800 Images + Prices + Stocks) from Shaghafi SKU or product_id
     */
    public static function create_complete_product_from_shaghafi( $query_sku_or_id ) {
        global $wpdb;
        $query = trim( (string) $query_sku_or_id );
        if ( '' === $query ) {
            return new WP_Error( 'empty_query', 'لطفاً SKU یا product_id شقفی را وارد کنید.' );
        }

        // Ensure the latest downloaded local likadeo.csv is imported into wp_shaghafi_feed_items
        Shaghafi_DB::detect_and_import_latest_local_csv();

        $variants = Shaghafi_DB::get_variants_by_product_id( $query );
        if ( empty( $variants ) ) {
            $variants = Shaghafi_DB::get_variants_by_base_sku( $query );
        }
        if ( empty( $variants ) ) {
            $exact = Shaghafi_DB::get_by_sku( $query );
            if ( $exact && ! empty( $exact['product_id'] ) ) {
                $variants = Shaghafi_DB::get_variants_by_product_id( $exact['product_id'] );
            }
        }
        if ( empty( $variants ) ) {
            $row = Shaghafi_DB::find_by_fuzzy_sku( $query );
            if ( $row && ! empty( $row['product_id'] ) ) {
                $variants = Shaghafi_DB::get_variants_by_product_id( $row['product_id'] );
            }
        }
        if ( empty( $variants ) ) {
            // Try live check on shaghafi.de in case the SKU was just added and not in DB yet
            $live_try = Shaghafi_Live_Scraper::fetch_product_live( '', '', $query, true );
            if ( ! is_wp_error( $live_try ) ) {
                $try_base = Shaghafi_DB::extract_base_sku( $query );
                $variants = Shaghafi_DB::get_variants_by_base_sku( $try_base );
            }
        }
        if ( empty( $variants ) ) {
            return new WP_Error( 'not_in_feed', 'محصولی با کد «' . esc_html( $query ) . '» در فید مرجع likadeo.csv و سایت شقفی یافت نشد.' );
        }

        $feed_pid  = $variants[0]['product_id'];
        $base_sku  = $variants[0]['base_sku'];

        // Live-scrape shaghafi.de + the 3 retail sites (akzentshop.de, meinakzent.de, raptor-watches.com)
        $live     = Shaghafi_Live_Scraper::fetch_product_live( $feed_pid, $variants[0]['variant_id'], $variants[0]['artikelnummer'], true );
        $variants = Shaghafi_DB::get_variants_by_product_id( $feed_pid );
        $is_simple = ( 1 === count( $variants ) );

        $raw_title = ( ! is_wp_error( $live ) && ! empty( $live['title'] ) ) ? (string) $live['title'] : '';
        if ( ! $is_simple && '' !== $raw_title && ! empty( $variants[0]['variantenauspraegung'] ) ) {
            // Strip first variant's option suffix from parent title if present (e.g. "Buchstabenanhänger A, " -> "Buchstabenanhänger, ")
            $v0_opt = preg_quote( trim( (string) $variants[0]['variantenauspraegung'] ), '/' );
            $raw_title = preg_replace( '/(\bBuchstabenanhänger|\bVariante|\bModell|\bGröße|\bLänge)\s+' . $v0_opt . '\b/iu', '$1', $raw_title );
        }

        // The product NAME must never contain SKUs/article numbers — strip every known SKU and generic SKU pattern.
        $all_variant_skus = array_filter( array_map( 'trim', wp_list_pluck( $variants, 'artikelnummer' ) ) );
        $title            = self::sanitize_product_title_without_sku( $raw_title, $base_sku, $all_variant_skus );
        if ( '' === $title ) {
            $brand_name = ( ! is_wp_error( $live ) && ! empty( $live['brand'] ) ) ? trim( (string) $live['brand'] ) : '';
            // Human-readable fallback without leaking any SKU into the product name.
            $title = '' !== $brand_name ? ( $brand_name . ' — محصول جدید' ) : 'محصول جدید شقفی';
        }

        $desc       = ( ! is_wp_error( $live ) && ! empty( $live['description'] ) ) ? $live['description'] : '';
        $short_desc = ( ! is_wp_error( $live ) && ! empty( $live['short_description'] ) ) ? $live['short_description'] : '';
        $props      = ( ! is_wp_error( $live ) && ! empty( $live['properties'] ) && is_array( $live['properties'] ) ) ? $live['properties'] : array();
        $weight     = ( ! is_wp_error( $live ) && ! empty( $live['weight'] ) ) ? (string) $live['weight'] : '';
        $gtin13     = ( ! is_wp_error( $live ) && ! empty( $live['gtin13'] ) ) ? (string) $live['gtin13'] : '';
        $brand      = ( ! is_wp_error( $live ) && ! empty( $live['brand'] ) ) ? (string) $live['brand'] : '';

        // Check if a product for this _shaghafi_product_id or base_sku already exists on the site
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_shaghafi_product_id'
                 WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private','pending')
                   AND pm.meta_value = %s
                 LIMIT 1",
                $feed_pid
            )
        );
        if ( $existing_id <= 0 && '' !== $base_sku ) {
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
                     WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private','pending')
                       AND (pm.meta_value = %s OR pm.meta_value = %s)
                     LIMIT 1",
                    $base_sku,
                    $variants[0]['artikelnummer']
                )
            );
        }

        if ( $existing_id > 0 ) {
            $parent_id = $existing_id;
            $upd_post  = array( 'ID' => $parent_id );
            if ( '' !== $desc ) {
                $upd_post['post_content'] = $desc;
            }
            if ( '' !== $short_desc ) {
                $upd_post['post_excerpt'] = $short_desc;
            }
            // Repair legacy names that still contain a SKU/article number (created by older plugin versions).
            $current_name = get_the_title( $parent_id );
            $clean_name   = self::sanitize_product_title_without_sku( $current_name, $base_sku, $all_variant_skus );
            if ( '' !== $clean_name && trim( $clean_name ) !== trim( $current_name ) ) {
                $upd_post['post_title'] = $clean_name;
            }
            if ( count( $upd_post ) > 1 ) {
                wp_update_post( $upd_post );
            }
            self::apply_scraped_product_details_meta( $parent_id, $live, $props, $weight, $gtin13, $brand );
            $sync_res = self::sync_and_repair_entire_product(
                $parent_id,
                array(
                    'force_image'               => true,
                    'live_check_shaghafi'       => false,
                    'sync_retail_prices'        => true,
                    'sync_stocks'               => true,
                    'create_missing_variations' => true,
                    'delete_removed_variations' => true,
                )
            );
            // Only fill categories when the product has none; existing manual categories are never touched.
            $category_res = self::auto_assign_product_categories( $parent_id, $title, $brand, $props, $variants );
            self::ensure_featured_image_fallback( $parent_id, $live, $base_sku, $title, $variants );
            Shaghafi_Scanner::clear_scan_caches();
            return array(
                'parent_id'       => $parent_id,
                'sku'             => $base_sku,
                'title'           => get_the_title( $parent_id ) ?: $title,
                'variations_made' => count( $variants ),
                'updated_existing'=> true,
                'price'           => get_post_meta( $parent_id, '_price', true ),
                'categories'      => $category_res,
                'edit_url'        => get_edit_post_link( $parent_id, 'raw' ),
                'view_url'        => get_permalink( $parent_id ),
            );
        }

        if ( $is_simple ) {
            $prod = new WC_Product_Simple();
            $prod->set_name( $title );
            $prod->set_description( $desc );
            if ( '' !== $short_desc ) {
                $prod->set_short_description( $short_desc );
            }
            $prod->set_status( 'publish' );
            $parent_id = $prod->save();

            self::apply_scraped_product_details_meta( $parent_id, $live, $props, $weight, $gtin13, $brand );
            self::repair_single_item( $parent_id, $parent_id, $variants[0]['variant_id'], array( 'force_image' => true ) );
            self::sideload_product_gallery_images( $parent_id, $live, $base_sku, $title );
            self::ensure_featured_image_fallback( $parent_id, $live, $base_sku, $title, $variants );
            $category_res = self::auto_assign_product_categories( $parent_id, $title, $brand, $props, $variants );
            Shaghafi_Scanner::clear_scan_caches();
            return array(
                'parent_id'       => $parent_id,
                'sku'             => $variants[0]['artikelnummer'],
                'title'           => $title,
                'variations_made' => 1,
                'updated_existing'=> false,
                'price'           => get_post_meta( $parent_id, '_price', true ),
                'categories'      => $category_res,
                'edit_url'        => get_edit_post_link( $parent_id, 'raw' ),
                'view_url'        => get_permalink( $parent_id ),
            );
        }

        $prod = new WC_Product_Variable();
        $prod->set_name( $title );
        $prod->set_description( $desc );
        if ( '' !== $short_desc ) {
            $prod->set_short_description( $short_desc );
        }
        $prod->set_status( 'publish' );
        $parent_id = $prod->save();

        update_post_meta( $parent_id, '_shaghafi_product_id', $feed_pid );
        self::set_clean_sku( $parent_id, $base_sku );
        self::apply_scraped_product_details_meta( $parent_id, $live, $props, $weight, $gtin13, $brand );

        if ( ! is_wp_error( $live ) && ! empty( $live['image'] ) ) {
            $att_id = self::sideload_shaghafi_image( $live['image'], $parent_id, $base_sku, '', $title );
            if ( ! is_wp_error( $att_id ) && $att_id > 0 ) {
                set_post_thumbnail( $parent_id, $att_id );
            }
        }
        self::sideload_product_gallery_images( $parent_id, $live, $base_sku, $title );

        $created_count = 0;
        foreach ( $variants as $fv ) {
            $res = self::create_missing_variation( $parent_id, $fv['variant_id'], array( 'force_image' => true ) );
            if ( ! is_wp_error( $res ) ) {
                $created_count++;
            }
        }

        // Re-apply non-variation technical specification attributes alongside the variation attribute
        self::merge_technical_properties_into_product_attributes( $parent_id, $props );

        // Guarantee the product always ends up with a featured image + a complete gallery (page gallery and
        // per-variant galleries from Shaghafi), and verify every non-size variation really got its own image.
        self::ensure_featured_image_fallback( $parent_id, $live, $base_sku, $title, $variants );
        self::enrich_gallery_from_variant_images( $parent_id, $live, $base_sku, $title, $variants );
        self::verify_variation_images( $parent_id, $variants );

        // Smart category detection against the shop's OWN product_cat tree (mother category + subcategories).
        $category_res = self::auto_assign_product_categories( $parent_id, $title, $brand, $props, $variants );

        WC_Product_Variable::sync( $parent_id );
        wc_delete_product_transients( $parent_id );
        Shaghafi_Scanner::clear_scan_caches();

        return array(
            'parent_id'       => $parent_id,
            'sku'             => $base_sku,
            'title'           => $title,
            'variations_made' => $created_count,
            'updated_existing'=> false,
            'price'           => get_post_meta( $parent_id, '_price', true ),
            'categories'      => $category_res,
            'edit_url'        => get_edit_post_link( $parent_id, 'raw' ),
            'view_url'        => get_permalink( $parent_id ),
        );
    }

    /**
     * Run the smart category matcher with title/brand/properties/variant options as context.
     */
    private static function auto_assign_product_categories( $parent_id, $title, $brand, array $props, array $variants ) {
        if ( ! class_exists( 'Shaghafi_Category_Matcher' ) ) {
            return array();
        }
        $options = array();
        foreach ( $variants as $fv ) {
            if ( ! empty( $fv['variantenauspraegung'] ) ) {
                $options[] = $fv['variantenauspraegung'];
            }
        }
        $res = Shaghafi_Category_Matcher::assign_for_product(
            (int) $parent_id,
            array(
                'title'      => $title,
                'brand'      => $brand,
                'properties' => $props,
                'options'    => $options,
                'sku'        => ! empty( $variants[0]['base_sku'] ) ? $variants[0]['base_sku'] : '',
            )
        );
        if ( is_wp_error( $res ) ) {
            return array( 'note' => $res->get_error_message() );
        }
        return $res;
    }

    /**
     * Make sure the product has a featured image even when the primary scrape returned nothing:
     * first gallery image → first variation image.
     */
    private static function ensure_featured_image_fallback( $parent_id, $live, $base_sku, $title, array $variants ) {
        if ( (int) get_post_thumbnail_id( $parent_id ) > 0 ) {
            return;
        }

        // 1. Promote the first valid gallery image to featured image.
        $gallery_raw = (string) get_post_meta( $parent_id, '_product_image_gallery', true );
        foreach ( array_filter( array_map( 'intval', explode( ',', $gallery_raw ) ) ) as $gid ) {
            if ( Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $gid, false ) ) {
                set_post_thumbnail( $parent_id, $gid );
                return;
            }
        }

        // 2. Use the main scraped image (live structure may have arrived after the first attempt).
        if ( ! is_wp_error( $live ) && ! empty( $live['image'] ) ) {
            $att_id = self::sideload_shaghafi_image( $live['image'], $parent_id, $base_sku, '', $title );
            if ( ! is_wp_error( $att_id ) && $att_id > 0 ) {
                set_post_thumbnail( $parent_id, $att_id );
                return;
            }
        }

        // 3. Fall back to the first variation's image.
        foreach ( $variants as $fv ) {
            $img_url = ! empty( $fv['live_image_url'] ) ? $fv['live_image_url'] : '';
            if ( '' === $img_url && ! empty( $fv['variant_id'] ) ) {
                $gal = Shaghafi_Live_Scraper::fetch_variant_gallery_live( $fv['variant_id'] );
                if ( ! empty( $gal[0] ) ) {
                    $img_url = $gal[0];
                }
            }
            if ( '' !== $img_url ) {
                $att_id = self::sideload_shaghafi_image( $img_url, $parent_id, $base_sku, '', $title );
                if ( ! is_wp_error( $att_id ) && $att_id > 0 ) {
                    set_post_thumbnail( $parent_id, $att_id );
                    return;
                }
            }
        }
    }

    /**
     * Merge per-variant Shaghafi galleries into the parent product gallery (deduped, no featured duplicate).
     */
    private static function enrich_gallery_from_variant_images( $parent_id, $live, $base_sku, $title, array $variants ) {
        $extra_urls = array();
        foreach ( $variants as $fv ) {
            // Size-only variations share the parent images; their galleries add nothing new.
            if ( Shaghafi_Scanner::is_size_based_product( $parent_id, array( $fv ), $fv['variantenauspraegung'] ) ) {
                continue;
            }
            if ( ! empty( $fv['variant_id'] ) ) {
                $gal = Shaghafi_Live_Scraper::fetch_variant_gallery_live( $fv['variant_id'] );
                if ( ! empty( $gal ) && is_array( $gal ) ) {
                    foreach ( $gal as $g_url ) {
                        $extra_urls[ $g_url ] = true;
                    }
                }
            }
            if ( ! empty( $fv['live_image_url'] ) ) {
                $extra_urls[ $fv['live_image_url'] ] = true;
            }
        }
        if ( empty( $extra_urls ) ) {
            return;
        }
        self::sideload_product_gallery_images( $parent_id, $live, $base_sku, $title, array_keys( $extra_urls ) );
    }

    /**
     * Verify every non-size variation has a thumbnail; download its Shaghafi image if it is still missing.
     */
    private static function verify_variation_images( $parent_id, array $variants ) {
        $children = array();
        foreach ( get_posts(
            array(
                'post_parent' => $parent_id,
                'post_type'   => 'product_variation',
                'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
                'numberposts' => -1,
                'fields'      => 'ids',
            )
        ) as $cid ) {
            $vid = trim( (string) get_post_meta( $cid, '_shaghafi_variant_id', true ) );
            if ( '' !== $vid ) {
                $children[ $vid ] = (int) $cid;
            }
        }

        foreach ( $variants as $fv ) {
            if ( empty( $fv['variant_id'] ) || ! isset( $children[ $fv['variant_id'] ] ) ) {
                continue;
            }
            $variation_id = $children[ $fv['variant_id'] ];
            if ( Shaghafi_Scanner::is_size_based_product( $parent_id, array( $fv ), $fv['variantenauspraegung'] ) ) {
                continue; // Size variations intentionally share the parent image.
            }
            if ( (int) get_post_meta( $variation_id, '_thumbnail_id', true ) > 0 ) {
                continue;
            }
            self::assign_smart_variation_image( $parent_id, $variation_id, $fv, true );
        }
    }

    /**
     * Save weight, GTIN/EAN, brand, retail links, and technical specifications onto a product
     */
    private static function apply_scraped_product_details_meta( $parent_id, $live, $props, $weight, $gtin13, $brand ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return;
        }
        if ( '' !== $weight && (float) $weight > 0 ) {
            update_post_meta( $parent_id, '_weight', $weight );
        }
        if ( '' !== $gtin13 ) {
            update_post_meta( $parent_id, '_global_unique_id', $gtin13 );
            update_post_meta( $parent_id, '_gtin', $gtin13 );
            update_post_meta( $parent_id, '_ean', $gtin13 );
        }
        if ( '' !== $brand ) {
            update_post_meta( $parent_id, '_brand', $brand );
        }
        if ( ! is_wp_error( $live ) && is_array( $live ) ) {
            if ( ! empty( $live['retail_source'] ) ) {
                update_post_meta( $parent_id, '_shaghafi_retail_source', sanitize_text_field( $live['retail_source'] ) );
            }
            if ( ! empty( $live['retail_url'] ) ) {
                update_post_meta( $parent_id, '_shaghafi_retail_url', esc_url_raw( $live['retail_url'] ) );
            }
            if ( ! empty( $live['retail_links'] ) && is_array( $live['retail_links'] ) ) {
                update_post_meta( $parent_id, '_shaghafi_retail_links_json', wp_json_encode( $live['retail_links'] ) );
            }
            if ( isset( $live['price'] ) && null !== $live['price'] && (float) $live['price'] > 0 ) {
                $target_p = Shaghafi_Scanner::calculate_target_price( (float) $live['price'] );
                if ( null !== $target_p && $target_p > 0 ) {
                    update_post_meta( $parent_id, '_shaghafi_retail_price', (string) (float) $live['price'] );
                    update_post_meta( $parent_id, '_shaghafi_preserved_site_price', (string) $target_p );
                    update_post_meta( $parent_id, '_regular_price', (string) $target_p );
                    update_post_meta( $parent_id, '_price', (string) $target_p );
                }
            }
        }
        self::merge_technical_properties_into_product_attributes( $parent_id, $props );
    }

    /**
     * Merge scraped Shaghafi technical properties (Marke, Material, Farbe, Geschlecht, Verschluss, Maße, etc.)
     * into _product_attributes as visible informational attributes without disturbing variation attributes.
     */
    private static function merge_technical_properties_into_product_attributes( $parent_id, $props ) {
        if ( empty( $props ) || ! is_array( $props ) ) {
            return;
        }
        $existing = get_post_meta( $parent_id, '_product_attributes', true );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }
        $pos = count( $existing );
        foreach ( $props as $label => $val ) {
            $label = trim( (string) $label );
            $val   = trim( (string) $val );
            if ( '' === $label || '' === $val ) {
                continue;
            }
            $slug = sanitize_title( $label );
            if ( '' === $slug || isset( $existing[ $slug ] ) || isset( $existing[ 'pa_' . $slug ] ) ) {
                continue;
            }
            // Do not overwrite any variation attribute
            $conflict = false;
            foreach ( $existing as $ek => $ev ) {
                if ( ! empty( $ev['is_variation'] ) && ( $ek === $slug || 0 === strcasecmp( isset( $ev['name'] ) ? $ev['name'] : '', $label ) ) ) {
                    $conflict = true;
                    break;
                }
            }
            if ( $conflict ) {
                continue;
            }
            $existing[ $slug ] = array(
                'name'         => $label,
                'value'        => $val,
                'position'     => $pos++,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 0,
            );
        }
        update_post_meta( $parent_id, '_product_attributes', $existing );
    }

    /**
     * Sideload gallery images from Shaghafi (700x800 WebP uncropped) into _product_image_gallery.
     *
     * @param array $extra_urls Optional additional image URLs (e.g. per-variant galleries) to merge in.
     */
    private static function sideload_product_gallery_images( $parent_id, $live, $base_sku, $title, array $extra_urls = array() ) {
        $gallery_urls = array();
        if ( ! is_wp_error( $live ) && ! empty( $live['gallery_images'] ) && is_array( $live['gallery_images'] ) ) {
            foreach ( $live['gallery_images'] as $g_url ) {
                $gallery_urls[ $g_url ] = true;
            }
        }
        foreach ( $extra_urls as $g_url ) {
            $gallery_urls[ $g_url ] = true;
        }

        if ( empty( $gallery_urls ) ) {
            return;
        }

        $main_thumb  = (int) get_post_thumbnail_id( $parent_id );
        $gallery_ids = array();
        $existing_gal = (string) get_post_meta( $parent_id, '_product_image_gallery', true );
        if ( '' !== $existing_gal ) {
            foreach ( explode( ',', $existing_gal ) as $gid ) {
                $gid = (int) trim( $gid );
                if ( $gid > 0 && Shaghafi_Scanner::is_attachment_currently_valid_on_disk( $gid, false ) ) {
                    $gallery_ids[] = $gid;
                }
            }
        }

        // Up to 12 gallery pictures; the featured image is never duplicated inside the gallery.
        $count = 0;
        foreach ( array_keys( $gallery_urls ) as $g_url ) {
            if ( $count >= 12 ) {
                break;
            }
            $att_id = self::sideload_shaghafi_image( $g_url, $parent_id, $base_sku, '', $title );
            if ( ! is_wp_error( $att_id ) && $att_id > 0 && (int) $att_id !== $main_thumb && ! in_array( (int) $att_id, $gallery_ids, true ) ) {
                $gallery_ids[] = (int) $att_id;
                $count++;
            }
        }
        if ( ! empty( $gallery_ids ) ) {
            update_post_meta( $parent_id, '_product_image_gallery', implode( ',', array_unique( array_map( 'intval', $gallery_ids ) ) ) );
        }
    }

    /**
     * Fast bulk stock sync for all already-linked variations (_shaghafi_variant_id) from DB feed
     */
    public static function sync_all_linked_stocks_from_feed() {
        global $wpdb;
        $table = Shaghafi_DB::table_name();

        $linked = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value AS variant_id, f.bestand, f.live_stock
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$table} f ON f.variant_id = pm.meta_value
             WHERE pm.meta_key = '_shaghafi_variant_id'",
            ARRAY_A
        );

        $updated = 0;
        $parents = array();
        foreach ( $linked as $row ) {
            $post_id = (int) $row['post_id'];
            $stock   = ( null !== $row['live_stock'] && '' !== (string) $row['live_stock'] )
                ? (int) $row['live_stock']
                : (int) $row['bestand'];

            update_post_meta( $post_id, '_manage_stock', 'yes' );
            wc_update_product_stock( $post_id, $stock, 'set' );
            wc_update_product_stock_status( $post_id, $stock > 0 ? 'instock' : 'outofstock' );
            $pid = (int) wp_get_post_parent_id( $post_id );
            if ( $pid > 0 ) {
                $parents[ $pid ] = true;
            }
            $updated++;
        }

        if ( class_exists( 'WC_Product_Variable' ) ) {
            foreach ( array_keys( $parents ) as $pid ) {
                WC_Product_Variable::sync( $pid );
                wc_delete_product_transients( $pid );
            }
        }

        Shaghafi_Scanner::clear_scan_caches();
        return $updated;
    }
}
