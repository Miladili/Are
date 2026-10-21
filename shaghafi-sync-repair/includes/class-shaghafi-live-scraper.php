<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Live Checker & Scraper:
 * 1) Shaghafi.de (B2B): Used ONLY for live stock, variant matrix structure, attributes, and full-res product images (NEVER for prices!)
 * 2) Retail Price & Product Link Sources (B2C):
 *    - https://akzentshop.de/
 *    - https://meinakzent.de/
 *    - https://www.raptor-watches.com/
 */
class Shaghafi_Live_Scraper {

    const BASE_URL        = 'https://shaghafi.de';
    const AKZENTSHOP_URL  = 'https://akzentshop.de';
    const MEINAKZENT_URL  = 'https://meinakzent.de';
    const RAPTOR_URL      = 'https://www.raptor-watches.com';

    /**
     * Fetch live product & variant matrix data from shaghafi.de AND retail prices/links from
     * akzentshop.de, meinakzent.de, and raptor-watches.com.
     *
     * IMPORTANT: Shaghafi.de wholesale prices are NEVER exposed as target/retail prices.
     *
     * @param string $product_id_or_variant_id 32-char hex UUID from likadeo.csv
     * @param string $fallback_sku             Optional SKU if UUID redirect fails
     * @param bool   $force_refresh            Bypass transient cache
     * @return array|WP_Error
     */
    public static function fetch_product_live_data( $product_id_or_variant_id, $fallback_sku = '', $force_refresh = false ) {
        $id_clean  = strtolower( trim( (string) $product_id_or_variant_id ) );
        $sku_clean = trim( (string) $fallback_sku );

        $cache_key = 'shaghafi_live_v30_' . md5( $id_clean . '|' . $sku_clean );
        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $target_url = '';
        if ( preg_match( '/^[a-f0-9]{32}$/', $id_clean ) ) {
            $target_url = self::BASE_URL . '/detail/' . $id_clean;
        } elseif ( ! empty( $sku_clean ) ) {
            $target_url = self::find_product_url_by_sku( $sku_clean );
        }

        if ( empty( $target_url ) ) {
            return new WP_Error( 'no_target', 'شناسه یا کد محصول برای بررسی زنده در شقفی یافت نشد.' );
        }

        $response = wp_remote_get(
            $target_url,
            array(
                'timeout'     => 25,
                'redirection' => 5,
                'sslverify'   => false,
                'headers'     => array(
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            // If a specific variant_id returned 404 on shaghafi.de, it has been discontinued/removed from Shaghafi!
            if ( 404 === $code && preg_match( '/^[a-f0-9]{32}$/', $id_clean ) ) {
                $fv_check = Shaghafi_DB::get_by_variant_id( $id_clean );
                if ( $fv_check && ! empty( $fv_check['product_id'] ) && strtolower( $fv_check['product_id'] ) !== $id_clean ) {
                    Shaghafi_DB::delete_variant_from_feed( $id_clean );
                    $parent_detail_url = self::BASE_URL . '/detail/' . strtolower( $fv_check['product_id'] );
                    $response          = wp_remote_get(
                        $parent_detail_url,
                        array(
                            'timeout'     => 25,
                            'redirection' => 5,
                            'sslverify'   => false,
                        )
                    );
                    $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
                }
            }
            if ( 200 !== $code && ! empty( $sku_clean ) ) {
                $alt_url = self::find_product_url_by_sku( $sku_clean );
                if ( ! empty( $alt_url ) && $alt_url !== $target_url ) {
                    $response = wp_remote_get(
                        $alt_url,
                        array(
                            'timeout'     => 25,
                            'redirection' => 5,
                            'sslverify'   => false,
                        )
                    );
                    $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
                }
            }
        }

        if ( 200 !== $code ) {
            return new WP_Error( 'http_status', sprintf( 'صفحه محصول در شقفی کد %d برگرداند (احتمالاً محصول در شقفی غیرفعال شده است).', $code ) );
        }

        $html      = wp_remote_retrieve_body( $response );
        $final_url = self::extract_canonical_url( $html, $target_url );

        // Parse Shaghafi HTML for stock, images, attributes, title (NOT price!)
        $parsed = self::parse_product_html( $html, $final_url );

        // Now enrich with Retail Price & Direct Links from Raptor-Watches.com, AkzentShop.de, and MeinAkzent.de
        $primary_sku = ! empty( $sku_clean ) ? $sku_clean : ( ! empty( $parsed['base_sku_from_url'] ) ? $parsed['base_sku_from_url'] : '' );
        if ( empty( $primary_sku ) && ! empty( $parsed['variants'] ) ) {
            $first_var   = reset( $parsed['variants'] );
            $primary_sku = isset( $first_var['sku'] ) ? $first_var['sku'] : '';
        }

        $retail_data = self::fetch_retail_data_for_product(
            $primary_sku,
            $parsed['base_sku_from_url'],
            $id_clean,
            $parsed['variants'],
            $force_refresh
        );

        $parsed['price']         = $retail_data['price'];
        $parsed['regular_price'] = $retail_data['regular_price'];
        $parsed['retail_source'] = $retail_data['source'];
        $parsed['retail_url']    = $retail_data['url'];
        $parsed['retail_links']  = $retail_data['links'];

        // Enrich each variant in $parsed['variants'] with its retail price & direct retail URL
        if ( ! empty( $parsed['variants'] ) ) {
            foreach ( $parsed['variants'] as $vid_key => &$v_item ) {
                $v_sku = isset( $v_item['sku'] ) ? trim( (string) $v_item['sku'] ) : '';
                $v_sku_upper = strtoupper( $v_sku );
                $exact_vp    = ! empty( $v_sku_upper ) ? self::pick_exact_variant_price( $retail_data, $v_sku_upper ) : null;
                if ( null !== $exact_vp ) {
                    // Exact per-variant SKU hit — this variant's own retail price, never a sibling's price.
                    $v_item['price']         = $exact_vp['price'];
                    $v_item['price_exact']   = true;
                    $v_item['retail_url']    = ! empty( $exact_vp['url'] ) ? $exact_vp['url'] : self::build_variant_retail_url( $retail_data['url'], $retail_data['source'], $v_sku );
                    $v_item['retail_links']  = ! empty( $exact_vp['links'] ) ? $exact_vp['links'] : self::build_variant_retail_links_list( $retail_data['links'], $v_sku );
                    $v_item['retail_source'] = ! empty( $exact_vp['source'] ) ? $exact_vp['source'] : $retail_data['source'];
                } else {
                    // Generic product-level fallback (may be the first/cheapest variant) — flagged as non-exact.
                    $v_item['price']         = $retail_data['price'];
                    $v_item['price_exact']   = false;
                    $v_item['retail_url']    = self::build_variant_retail_url( $retail_data['url'], $retail_data['source'], $v_sku );
                    $v_item['retail_links']  = self::build_variant_retail_links_list( $retail_data['links'], $v_sku );
                    $v_item['retail_source'] = $retail_data['source'];
                }
            }
            unset( $v_item );
        }

        set_transient( $cache_key, $parsed, 30 * MINUTE_IN_SECONDS );
        return $parsed;
    }

    /**
     * Primary entrypoint used by AJAX, Repairer, and Scanner:
     * Fetches Shaghafi live structure/stock/images + Retail Price & Direct Links from
     * Raptor-Watches.com, AkzentShop.de, and MeinAkzent.de, and persists into wp_shaghafi_feed_items.
     */
    public static function fetch_product_live( $product_id = '', $variant_id = '', $fallback_sku = '', $save_to_db = true ) {
        $lookup_id = ! empty( $variant_id ) && preg_match( '/^[a-f0-9]{32}$/i', $variant_id )
            ? $variant_id
            : $product_id;

        $parsed = self::fetch_product_live_data( $lookup_id, $fallback_sku, true );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $resolved_pid = ! empty( $product_id ) ? strtolower( trim( $product_id ) ) : '';
        if ( empty( $resolved_pid ) && ! empty( $variant_id ) ) {
            $fv_row = Shaghafi_DB::get_by_variant_id( $variant_id );
            if ( $fv_row && ! empty( $fv_row['product_id'] ) ) {
                $resolved_pid = strtolower( trim( $fv_row['product_id'] ) );
            }
        }
        if ( empty( $resolved_pid ) && ! empty( $parsed['base_sku_from_url'] ) ) {
            $vars = Shaghafi_DB::get_variants_by_base_sku( $parsed['base_sku_from_url'] );
            if ( ! empty( $vars ) ) {
                $resolved_pid = strtolower( trim( $vars[0]['product_id'] ) );
            }
        }

        $parsed['product_id'] = $resolved_pid;
        $parsed['image']      = $parsed['main_image'];

        if ( $save_to_db ) {
            if ( ! empty( $parsed['variants'] ) ) {
                // Purge any discontinued variant rows in wp_shaghafi_feed_items for this product_id that are no longer on shaghafi.de
                if ( ! empty( $resolved_pid ) ) {
                    Shaghafi_DB::sync_product_variants_with_live_matrix( $resolved_pid, array_keys( $parsed['variants'] ) );
                }
                foreach ( $parsed['variants'] as $vid => $vdata ) {
                    Shaghafi_DB::save_live_variant_data(
                        $vid,
                        array(
                            'product_id'   => $resolved_pid,
                            'sku'          => $vdata['sku'],
                            'option'       => $vdata['option_value'],
                            'price'        => null !== $vdata['price'] ? (string) $vdata['price'] : null,
                            'price_exact'  => ! empty( $vdata['price_exact'] ),
                            'clear_price'  => null === $vdata['price'],
                            'stock'        => $vdata['stock'],
                            'image'        => ! empty( $vdata['image'] ) ? $vdata['image'] : $parsed['main_image'],
                            'name'         => ! empty( $vdata['title'] ) ? $vdata['title'] : $parsed['title'],
                            'attr_name'    => $parsed['attribute_group_name'],
                            'product_url'  => ! empty( $vdata['retail_url'] ) ? $vdata['retail_url'] : $parsed['retail_url'],
                        )
                    );
                }
            } elseif ( ! empty( $resolved_pid ) || ! empty( $lookup_id ) ) {
                $target_vids = array();
                if ( ! empty( $resolved_pid ) ) {
                    $pid_rows = Shaghafi_DB::get_variants_by_product_id( $resolved_pid );
                    foreach ( $pid_rows as $pr ) {
                        if ( ! empty( $pr['variant_id'] ) ) {
                            $target_vids[] = $pr['variant_id'];
                        }
                    }
                }
                if ( empty( $target_vids ) && ! empty( $lookup_id ) ) {
                    $target_vids[] = $lookup_id;
                }
                foreach ( array_unique( $target_vids ) as $tvid ) {
                    Shaghafi_DB::save_live_variant_data(
                        $tvid,
                        array(
                            'product_id'  => $resolved_pid ?: $lookup_id,
                            'sku'         => $fallback_sku ?: $parsed['base_sku_from_url'],
                            'price'       => null !== $parsed['price'] ? (string) $parsed['price'] : null,
                            'clear_price' => null === $parsed['price'],
                            'image'       => $parsed['main_image'],
                            'gallery'     => $parsed['gallery_images'],
                            'name'        => $parsed['title'],
                            'product_url' => ! empty( $parsed['retail_url'] ) ? $parsed['retail_url'] : '',
                        )
                    );
                }
            }
        }

        return $parsed;
    }

    /**
     * Alias for fetch_variant_gallery_images used by Repairer
     */
    public static function fetch_variant_gallery_live( $variant_id ) {
        return self::fetch_variant_gallery_images( $variant_id );
    }

    /**
     * Alias for normalize_image_url used by Repairer
     */
    public static function thumbnail_to_full_media_url( $url ) {
        return self::normalize_image_url( $url );
    }

    /**
     * Extract unique media hash or filename stem from a Shaghafi image URL
     */
    public static function extract_image_hash( $url ) {
        $url = self::normalize_image_url( $url );
        if ( empty( $url ) ) {
            return '';
        }
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! $path ) {
            return '';
        }
        $base = pathinfo( $path, PATHINFO_FILENAME );
        $base = preg_replace( '/_\d+x\d+$/', '', (string) $base );
        return strtolower( trim( $base ) );
    }

    /**
     * Fetch Retail Price & Direct Links from the 3 official B2C retail stores:
     * 1. https://www.raptor-watches.com/
     * 2. https://akzentshop.de/
     * 3. https://meinakzent.de/
     *
     * @param string $sku            Full variant SKU or parent SKU (e.g. 5000186-0400, RA10256-002)
     * @param string $base_sku       Parent base SKU (e.g. 5000186, RA10256)
     * @param string $uuid           32-char Shopware variant_id or product_id
     * @param array  $variants_map   Optional map of variants from Shaghafi matrix
     * @return array
     */
    public static function fetch_retail_data_for_product( $sku, $base_sku = '', $uuid = '', $variants_map = array(), $force_refresh = false ) {
        $sku      = trim( (string) $sku );
        $base_sku = trim( (string) $base_sku );
        if ( empty( $base_sku ) && ! empty( $sku ) ) {
            $base_sku = Shaghafi_DB::extract_base_sku( $sku );
        }

        $cache_key = 'shaghafi_retail_v30_' . md5( $sku . '|' . $base_sku . '|' . $uuid );
        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $result = array(
            'price'          => null,
            'regular_price'  => null,
            'source'         => '',
            'url'            => '',
            'links'          => array(),
            'variant_prices' => array(),
        );

        $is_raptor_sku = ( 0 === stripos( $sku, 'RA' ) || 0 === stripos( $base_sku, 'RA' ) );

        // If SKU starts with RA, query Raptor-Watches.com first; otherwise query AkzentShop.de & MeinAkzent.de first
        if ( $is_raptor_sku ) {
            $raptor = self::lookup_raptor_watches( $sku, $base_sku, $uuid, $variants_map );
            if ( $raptor ) {
                self::merge_retail_hit( $result, $raptor );
            }
        }

        // Check AkzentShop.de
        $akzentshop = self::lookup_akzentshop( $sku, $base_sku );
        if ( $akzentshop ) {
            self::merge_retail_hit( $result, $akzentshop );
        }

        // Check MeinAkzent.de (Shopify JSON API - returns all variant SKUs & prices at once)
        $meinakzent = self::lookup_meinakzent( $sku, $base_sku );
        if ( $meinakzent ) {
            self::merge_retail_hit( $result, $meinakzent );
        }

        // If not a RA-prefixed SKU and still no hit (or to check if also on Raptor-Watches.com)
        if ( ! $is_raptor_sku && empty( $result['links'] ) ) {
            $raptor = self::lookup_raptor_watches( $sku, $base_sku, $uuid, $variants_map );
            if ( $raptor ) {
                self::merge_retail_hit( $result, $raptor );
            }
        }

        set_transient( $cache_key, $result, 2 * HOUR_IN_SECONDS );
        return $result;
    }

    /**
     * Merge a single retail store lookup result into the aggregated retail result
     */
    private static function merge_retail_hit( &$result, $hit ) {
        if ( empty( $hit ) || empty( $hit['url'] ) ) {
            return;
        }

        $result['links'][] = array(
            'site'          => $hit['site'],
            'label'         => $hit['label'],
            'url'           => $hit['url'],
            'base_url'      => isset( $hit['base_url'] ) ? $hit['base_url'] : $hit['url'],
            'price'         => $hit['price'],
            'regular_price' => isset( $hit['regular_price'] ) ? $hit['regular_price'] : null,
        );

        if ( null === $result['price'] && null !== $hit['price'] && $hit['price'] > 0 ) {
            $result['price']         = $hit['price'];
            $result['regular_price'] = isset( $hit['regular_price'] ) ? $hit['regular_price'] : null;
            $result['source']        = $hit['site'];
            $result['url']           = $hit['url'];
        }

        if ( ! empty( $hit['variant_prices'] ) && is_array( $hit['variant_prices'] ) ) {
            foreach ( $hit['variant_prices'] as $vsku => $vp ) {
                if ( ! isset( $result['variant_prices'][ $vsku ] ) || empty( $result['variant_prices'][ $vsku ]['price'] ) ) {
                    $result['variant_prices'][ $vsku ] = $vp;
                }
            }
        }
    }

    /**
     * 1) Lookup product price & direct link on https://akzentshop.de/ (Shopware 5)
     */
    public static function lookup_akzentshop( $sku, $base_sku = '' ) {
        $queries = array();
        if ( ! empty( $sku ) ) {
            $queries[] = $sku;
        }
        if ( ! empty( $base_sku ) && $base_sku !== $sku ) {
            $queries[] = $base_sku;
        }

        foreach ( $queries as $q ) {
            $search_url = self::AKZENTSHOP_URL . '/search?sSearch=' . rawurlencode( $q );
            $resp       = wp_remote_get(
                $search_url,
                array(
                    'timeout'     => 12,
                    'redirection' => 5,
                    'sslverify'   => false,
                    'headers'     => array(
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    ),
                )
            );

            if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
                continue;
            }

            $html      = wp_remote_retrieve_body( $resp );
            $final_url = self::extract_akzentshop_url( $html, $search_url );

            // Check if it landed directly on a product detail page
            $parsed = self::parse_akzentshop_product_html( $html, $final_url, $sku );
            if ( $parsed ) {
                return $parsed;
            }

            // If it landed on a search listing page with multiple product boxes, find the matching card
            if ( preg_match_all( '/href=["\'](https:\/\/akzentshop\.de\/[^"\']+\?number=([^"\'&]+))["\']/i', $html, $matches, PREG_SET_ORDER ) ) {
                $chosen_url = '';
                foreach ( $matches as $m ) {
                    $card_url = $m[1];
                    $card_num = trim( $m[2] );
                    if ( 0 === strcasecmp( $card_num, $sku ) || ( ! empty( $base_sku ) && 0 === stripos( $card_num, $base_sku ) ) ) {
                        $chosen_url = $card_url;
                        break;
                    }
                }
                if ( ! empty( $chosen_url ) ) {
                    $resp2 = wp_remote_get(
                        $chosen_url,
                        array(
                            'timeout'     => 12,
                            'redirection' => 5,
                            'sslverify'   => false,
                        )
                    );
                    if ( ! is_wp_error( $resp2 ) && 200 === (int) wp_remote_retrieve_response_code( $resp2 ) ) {
                        $html2   = wp_remote_retrieve_body( $resp2 );
                        $parsed2 = self::parse_akzentshop_product_html( $html2, $chosen_url, $sku );
                        if ( $parsed2 ) {
                            return $parsed2;
                        }
                    }
                }
            }
        }

        return null;
    }

    private static function extract_akzentshop_url( $html, $fallback ) {
        if ( preg_match( '/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            return trim( $m[1] );
        }
        if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m ) ) {
            return trim( $m[1] );
        }
        return $fallback;
    }

    private static function parse_akzentshop_product_html( $html, $page_url, $sku = '' ) {
        $price = null;
        if ( preg_match( '/<meta[^>]+itemprop=["\']price["\'][^>]+content=["\']([0-9.,]+)["\']/i', $html, $m ) ) {
            $price = (float) str_replace( ',', '.', $m[1] );
        } elseif ( preg_match( '/<meta[^>]+property=["\']product:price["\'][^>]+content=["\']([0-9.,]+)["\']/i', $html, $m ) ) {
            $price = (float) str_replace( ',', '.', $m[1] );
        }

        // Extract the full per-variant price matrix (Shopware 5 embedded JSON / JSON-LD) so that
        // variants with deviating prices (e.g. the largest size) keep their own retail price.
        $variant_prices = self::extract_variant_price_map( $html );

        // Prefer the exact requested SKU price over the generic meta price (which often mirrors the cheapest variant).
        if ( ! empty( $sku ) && ! empty( $variant_prices ) ) {
            $exact = self::pick_exact_variant_price( array( 'variant_prices' => $variant_prices ), $sku );
            if ( $exact && ! empty( $exact['price'] ) ) {
                $price = (float) $exact['price'];
            }
        }

        if ( null === $price || $price <= 0 ) {
            return null;
        }

        // Verify SKU on page belongs to our requested SKU or base SKU
        if ( ! empty( $sku ) && preg_match( '/itemprop=["\']sku["\'][^>]*>\s*([^<\s]+)/i', $html, $sm ) ) {
            $page_sku = trim( $sm[1] );
            $base_req = Shaghafi_DB::extract_base_sku( $sku );
            $base_pg  = Shaghafi_DB::extract_base_sku( $page_sku );
            if ( ! empty( $base_req ) && ! empty( $base_pg ) && 0 !== strcasecmp( $base_req, $base_pg ) && 0 !== strcasecmp( $sku, $page_sku ) ) {
                return null;
            }
        }

        $regular_price = null;
        if ( preg_match( '/class=["\'][^"\']*price--line-through[^"\']*["\'][^>]*>\s*([0-9]+[,.][0-9]{2})/i', $html, $om ) ) {
            $regular_price = (float) str_replace( ',', '.', $om[1] );
        }

        // If the exact SKU carries a compare/list price, surface it as the regular price.
        if ( ! empty( $sku ) && ! empty( $variant_prices ) ) {
            $exact = self::pick_exact_variant_price( array( 'variant_prices' => $variant_prices ), $sku );
            if ( $exact && ! empty( $exact['regular_price'] ) ) {
                $regular_price = (float) $exact['regular_price'];
            }
        }

        $base_clean = strtok( $page_url, '?' );
        $direct_url = ! empty( $sku ) ? ( $base_clean . '?number=' . rawurlencode( $sku ) ) : $page_url;

        return array(
            'site'           => 'akzentshop.de',
            'label'          => 'AkzentShop.de',
            'url'            => $direct_url,
            'base_url'       => $base_clean,
            'price'          => $price,
            'regular_price'  => $regular_price,
            'variant_prices' => $variant_prices,
        );
    }

    /**
     * 2) Lookup product price & direct link on https://meinakzent.de/ (Shopify JSON API)
     */
    public static function lookup_meinakzent( $sku, $base_sku = '' ) {
        $queries = array();
        if ( ! empty( $base_sku ) ) {
            $queries[] = $base_sku;
        }
        if ( ! empty( $sku ) && $sku !== $base_sku ) {
            $queries[] = $sku;
        }

        foreach ( $queries as $q ) {
            $suggest_url = self::MEINAKZENT_URL . '/search/suggest.json?q=' . rawurlencode( $q ) . '&resources[type]=product&resources[options][fields]=title,product_type,variants.title,variants.sku';
            $resp        = wp_remote_get(
                $suggest_url,
                array(
                    'timeout'   => 12,
                    'sslverify' => false,
                    'headers'   => array(
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept'     => 'application/json',
                    ),
                )
            );

            if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
                continue;
            }

            $data     = json_decode( wp_remote_retrieve_body( $resp ), true );
            $products = isset( $data['resources']['results']['products'] ) ? $data['resources']['results']['products'] : array();
            if ( empty( $products ) ) {
                continue;
            }

            foreach ( $products as $prod_item ) {
                $handle = isset( $prod_item['handle'] ) ? trim( (string) $prod_item['handle'] ) : '';
                if ( empty( $handle ) ) {
                    continue;
                }

                $prod_json_url = self::MEINAKZENT_URL . '/products/' . rawurlencode( $handle ) . '.json';
                $resp_prod     = wp_remote_get(
                    $prod_json_url,
                    array(
                        'timeout'   => 12,
                        'sslverify' => false,
                        'headers'   => array( 'Accept' => 'application/json' ),
                    )
                );

                if ( is_wp_error( $resp_prod ) || 200 !== (int) wp_remote_retrieve_response_code( $resp_prod ) ) {
                    continue;
                }

                $pdata    = json_decode( wp_remote_retrieve_body( $resp_prod ), true );
                $variants = isset( $pdata['product']['variants'] ) ? $pdata['product']['variants'] : array();
                if ( empty( $variants ) ) {
                    continue;
                }

                $variant_prices = array();
                $matched_base   = false;
                foreach ( $variants as $v ) {
                    $v_sku = isset( $v['sku'] ) ? trim( (string) $v['sku'] ) : '';
                    if ( empty( $v_sku ) ) {
                        continue;
                    }
                    $v_base = Shaghafi_DB::extract_base_sku( $v_sku );
                    if ( 0 === strcasecmp( $v_sku, $sku ) || ( ! empty( $base_sku ) && 0 === strcasecmp( $v_base, $base_sku ) ) ) {
                        $matched_base = true;
                    }
                    $v_price = isset( $v['price'] ) ? (float) str_replace( ',', '.', (string) $v['price'] ) : 0.0;
                    $v_cmp   = ! empty( $v['compare_at_price'] ) ? (float) str_replace( ',', '.', (string) $v['compare_at_price'] ) : null;
                    $v_id    = isset( $v['id'] ) ? $v['id'] : '';
                    $v_url   = self::MEINAKZENT_URL . '/products/' . $handle . ( $v_id ? '?variant=' . $v_id : '' );

                    $variant_prices[ strtoupper( $v_sku ) ] = array(
                        'price'         => $v_price,
                        'regular_price' => $v_cmp,
                        'url'           => $v_url,
                        'source'        => 'meinakzent.de',
                    );
                }

                if ( ! $matched_base ) {
                    continue;
                }

                $sku_upper = strtoupper( $sku );
                if ( isset( $variant_prices[ $sku_upper ] ) ) {
                    $hit = $variant_prices[ $sku_upper ];
                    return array(
                        'site'           => 'meinakzent.de',
                        'label'          => 'MeinAkzent.de',
                        'url'            => $hit['url'],
                        'base_url'       => self::MEINAKZENT_URL . '/products/' . $handle,
                        'price'          => $hit['price'],
                        'regular_price'  => $hit['regular_price'],
                        'variant_prices' => $variant_prices,
                    );
                }

                $first = reset( $variant_prices );
                return array(
                    'site'           => 'meinakzent.de',
                    'label'          => 'MeinAkzent.de',
                    'url'            => self::MEINAKZENT_URL . '/products/' . $handle,
                    'base_url'       => self::MEINAKZENT_URL . '/products/' . $handle,
                    'price'          => $first['price'],
                    'regular_price'  => $first['regular_price'],
                    'variant_prices' => $variant_prices,
                );
            }
        }

        return null;
    }

    /**
     * 3) Lookup product price & direct link on https://www.raptor-watches.com/ (Shopware 6)
     */
    public static function lookup_raptor_watches( $sku, $base_sku = '', $uuid = '', $variants_map = array() ) {
        $candidate_urls = array();

        // Try direct Shopware 6 UUID first if available
        if ( ! empty( $uuid ) && preg_match( '/^[a-f0-9]{32}$/i', $uuid ) ) {
            $candidate_urls[] = self::RAPTOR_URL . '/detail/' . strtolower( $uuid );
        }

        // If we have variants in matrix, try first variant UUID
        if ( ! empty( $variants_map ) && is_array( $variants_map ) ) {
            foreach ( $variants_map as $vid => $vinfo ) {
                if ( preg_match( '/^[a-f0-9]{32}$/i', $vid ) ) {
                    $u = self::RAPTOR_URL . '/detail/' . strtolower( $vid );
                    if ( ! in_array( $u, $candidate_urls, true ) ) {
                        $candidate_urls[] = $u;
                    }
                    break;
                }
            }
        }

        // Also try Raptor /suggest?search={sku}
        $queries = array_filter( array_unique( array( $sku, $base_sku ) ) );
        foreach ( $queries as $q ) {
            $sug_url = self::RAPTOR_URL . '/suggest?search=' . rawurlencode( $q );
            $s_resp  = wp_remote_get(
                $sug_url,
                array(
                    'timeout'   => 10,
                    'sslverify' => false,
                    'headers'   => array( 'User-Agent' => 'Mozilla/5.0' ),
                )
            );
            if ( ! is_wp_error( $s_resp ) && 200 === (int) wp_remote_retrieve_response_code( $s_resp ) ) {
                $s_html = wp_remote_retrieve_body( $s_resp );
                if ( preg_match_all( '/href=["\'](https:\/\/www\.raptor-watches\.com\/[^"\']+)["\']/i', $s_html, $m ) ) {
                    foreach ( $m[1] as $lnk ) {
                        if ( strpos( $lnk, '/search' ) === false && strpos( $lnk, '/widgets/' ) === false ) {
                            $candidate_urls[] = $lnk;
                            break;
                        }
                    }
                }
            }
            if ( ! empty( $candidate_urls ) ) {
                break;
            }
        }

        foreach ( $candidate_urls as $target_url ) {
            $resp = wp_remote_get(
                $target_url,
                array(
                    'timeout'     => 12,
                    'redirection' => 5,
                    'sslverify'   => false,
                    'headers'     => array( 'User-Agent' => 'Mozilla/5.0' ),
                )
            );
            if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
                continue;
            }

            $html      = wp_remote_retrieve_body( $resp );
            $final_url = self::extract_canonical_url( $html, $target_url );

            $price = null;
            if ( preg_match( '/<meta[^>]+property=["\']product:price:amount["\'][^>]+content=["\']([0-9.,]+)["\']/i', $html, $pm ) ) {
                $price = (float) str_replace( ',', '.', $pm[1] );
            } elseif ( preg_match( '/<meta[^>]+itemprop=["\']price["\'][^>]+content=["\']([0-9.,]+)["\']/i', $html, $pm ) ) {
                $price = (float) str_replace( ',', '.', $pm[1] );
            }

            // Shopware 6 embeds every variant's calculated unit price in its page JSON — extract the full matrix.
            $variant_prices = self::extract_variant_price_map( $html );
            $exact_sku      = '';
            foreach ( array_filter( array( $sku, $base_sku ) ) as $candidate_sku ) {
                $exact = self::pick_exact_variant_price( array( 'variant_prices' => $variant_prices ), $candidate_sku );
                if ( $exact && ! empty( $exact['price'] ) ) {
                    $price     = (float) $exact['price'];
                    $exact_sku = $candidate_sku;
                    break;
                }
            }

            if ( null !== $price && $price > 0 ) {
                $regular_price = null;
                if ( preg_match( '/class=["\'][^"\']*list-price-price[^"\']*["\'][^>]*>\s*([0-9]+[,.][0-9]{2})/i', $html, $lm ) ) {
                    $regular_price = (float) str_replace( ',', '.', $lm[1] );
                }
                if ( '' !== $exact_sku && ! empty( $exact['regular_price'] ) ) {
                    $regular_price = (float) $exact['regular_price'];
                }
                return array(
                    'site'           => 'raptor-watches.com',
                    'label'          => 'Raptor-Watches.com',
                    'url'            => $final_url,
                    'base_url'       => $final_url,
                    'price'          => $price,
                    'regular_price'  => $regular_price,
                    'variant_prices' => $variant_prices,
                );
            }
        }

        return null;
    }

    /**
     * Build variant-specific retail link when base URL is known
     */
    public static function build_variant_retail_url( $base_url, $source, $variant_sku ) {
        if ( empty( $base_url ) ) {
            return '';
        }
        if ( 'akzentshop.de' === $source && ! empty( $variant_sku ) ) {
            $clean = strtok( $base_url, '?' );
            return $clean . '?number=' . rawurlencode( $variant_sku );
        }
        return $base_url;
    }

    /**
     * Build per-variant list of retail links
     */
    public static function build_variant_retail_links_list( $links, $variant_sku ) {
        if ( empty( $links ) || ! is_array( $links ) ) {
            return array();
        }
        $out = array();
        foreach ( $links as $lnk ) {
            $copy = $lnk;
            if ( 'akzentshop.de' === $lnk['site'] && ! empty( $variant_sku ) ) {
                $base        = ! empty( $lnk['base_url'] ) ? $lnk['base_url'] : strtok( $lnk['url'], '?' );
                $copy['url'] = $base . '?number=' . rawurlencode( $variant_sku );
            }
            $out[] = $copy;
        }
        return $out;
    }

    /**
     * Generate quick search links on all 3 retail domains for any SKU when live lookup hasn't been clicked yet
     */
    public static function get_fallback_retail_search_links( $sku, $base_sku = '', $variant_id = '' ) {
        $q = ! empty( $sku ) && '—' !== $sku ? $sku : $base_sku;
        if ( empty( $q ) || '—' === $q ) {
            return array();
        }
        $links = array();
        if ( 0 === stripos( $q, 'RA' ) ) {
            $raptor_url = ( ! empty( $variant_id ) && preg_match( '/^[a-f0-9]{32}$/i', $variant_id ) )
                ? ( self::RAPTOR_URL . '/detail/' . strtolower( $variant_id ) )
                : ( self::RAPTOR_URL . '/search?search=' . rawurlencode( $q ) );
            $links[] = array(
                'site'      => 'raptor-watches.com',
                'label'     => 'Raptor-Watches',
                'url'       => $raptor_url,
                'price'     => null,
                'is_search' => true,
            );
        }
        $links[] = array(
            'site'      => 'akzentshop.de',
            'label'     => 'AkzentShop',
            'url'       => self::AKZENTSHOP_URL . '/search?sSearch=' . rawurlencode( $q ),
            'price'     => null,
            'is_search' => true,
        );
        $links[] = array(
            'site'      => 'meinakzent.de',
            'label'     => 'MeinAkzent',
            'url'       => self::MEINAKZENT_URL . '/search?q=' . rawurlencode( $q ),
            'price'     => null,
            'is_search' => true,
        );
        if ( 0 !== stripos( $q, 'RA' ) ) {
            $links[] = array(
                'site'      => 'raptor-watches.com',
                'label'     => 'Raptor-Watches',
                'url'       => self::RAPTOR_URL . '/search?search=' . rawurlencode( $q ),
                'price'     => null,
                'is_search' => true,
            );
        }
        return $links;
    }

    /**
     * Search shaghafi.de for a SKU and return the first product detail URL
     */
    public static function find_product_url_by_sku( $sku ) {
        $search_url = self::BASE_URL . '/search?search=' . rawurlencode( $sku );
        $response   = wp_remote_get(
            $search_url,
            array(
                'timeout'     => 20,
                'redirection' => 5,
                'sslverify'   => false,
            )
        );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return '';
        }

        $html = wp_remote_retrieve_body( $response );

        if ( preg_match( '/<meta\s+property=["\']og:type["\']\s+content=["\']product["\']/i', $html ) ) {
            return self::extract_canonical_url( $html, $search_url );
        }

        if ( preg_match_all( '/<a[^>]+href=["\'](https:\/\/shaghafi\.de\/[^"\']+)["\'][^>]*class=["\'][^"\']*product-name[^"\']*["\']/i', $html, $matches ) ) {
            return $matches[1][0];
        }

        if ( preg_match_all( '/href=["\'](https:\/\/shaghafi\.de\/[A-Za-z0-9\-_%]+\/[A-Za-z0-9\-_.]+)["\']/i', $html, $matches ) ) {
            foreach ( $matches[1] as $u ) {
                if ( strpos( $u, '/widgets/' ) === false && strpos( $u, '/navigation/' ) === false && strpos( $u, '/account/' ) === false ) {
                    return $u;
                }
            }
        }

        return '';
    }

    /**
     * Extract canonical URL from HTML
     */
    private static function extract_canonical_url( $html, $fallback ) {
        if ( preg_match( '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']/i', $html, $m ) ) {
            return trim( $m[1] );
        }
        if ( preg_match( '/<meta\s+property=["\']og:url["\']\s+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            return trim( $m[1] );
        }
        return $fallback;
    }

    /**
     * Parse Shaghafi product detail HTML (ONLY for title, full long description, properties/specifications, images, stock, attributes — NEVER wholesale price!)
     */
    public static function parse_product_html( $html, $url ) {
        $data = array(
            'url'                 => $url,
            'base_sku_from_url'   => '',
            'title'               => '',
            'description'         => '',
            'short_description'   => '',
            'long_description'    => '',
            'properties'          => array(),
            'brand'               => '',
            'gtin13'              => '',
            'weight'              => '',
            'price'               => null, // Filled exclusively by Retail Price Engine (akzentshop / meinakzent / raptor)
            'regular_price'       => null,
            'retail_source'       => '',
            'retail_url'          => '',
            'retail_links'        => array(),
            'currency'            => 'EUR',
            'main_image'          => '',
            'gallery_images'      => array(),
            'is_variable_matrix'  => false,
            'attribute_group_name'=> 'Variante',
            'variants'            => array(),
        );

        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( $path ) {
            $segments = array_values( array_filter( explode( '/', $path ) ) );
            if ( ! empty( $segments ) ) {
                $data['base_sku_from_url'] = end( $segments );
            }
        }

        if ( preg_match( '/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $data['title'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
        } elseif ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
            $data['title'] = trim( wp_strip_all_tags( $m[1] ) );
        }

        if ( preg_match( '/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $data['short_description'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
            $data['description']       = $data['short_description'];
        }

        // Extract FULL Long Description HTML from .product-detail-description-text or [itemprop="description"]
        if ( preg_match( '/<div[^>]*class=["\'][^"\']*product-detail-description-text[^"\']*["\'][^>]*>(.*?)<\/div>\s*(?:<table|<div|<\/div>)/is', $html, $ld ) ) {
            $clean_long = trim( wp_kses_post( $ld[1] ) );
            if ( '' !== $clean_long ) {
                $data['long_description'] = $clean_long;
            }
        } elseif ( preg_match( '/<div[^>]*itemprop=["\']description["\'][^>]*>(.*?)<\/div>/is', $html, $ld2 ) ) {
            $clean_long = trim( wp_kses_post( $ld2[1] ) );
            if ( '' !== $clean_long ) {
                $data['long_description'] = $clean_long;
            }
        }

        // Extract complete technical specifications / properties table (.properties-row)
        if ( preg_match_all( '/<tr[^>]*class=["\'][^"\']*properties-row[^"\']*["\'][^>]*>.*?<th[^>]*>(.*?)<\/th>.*?<td[^>]*>(.*?)<\/td>.*?<\/tr>/is', $html, $prop_matches, PREG_SET_ORDER ) ) {
            foreach ( $prop_matches as $pm ) {
                $p_label = trim( rtrim( trim( html_entity_decode( wp_strip_all_tags( $pm[1] ), ENT_QUOTES, 'UTF-8' ) ), ':' ) );
                $p_val   = trim( html_entity_decode( wp_strip_all_tags( $pm[2] ), ENT_QUOTES, 'UTF-8' ) );
                if ( '' !== $p_label && '' !== $p_val ) {
                    $data['properties'][ $p_label ] = $p_val;
                    if ( '' === $data['brand'] && 0 === strcasecmp( $p_label, 'Marke' ) ) {
                        $data['brand'] = $p_val;
                    }
                    if ( '' === $data['weight'] && false !== stripos( $p_label, 'Artikelgewicht in g' ) ) {
                        $grams = (float) str_replace( ',', '.', $p_val );
                        if ( $grams > 0 ) {
                            $data['weight'] = (string) round( $grams / 1000, 4 );
                        }
                    }
                }
            }
        }

        // Build complete long description combining full paragraph description + specifications table
        $full_desc_parts = array();
        if ( '' !== $data['long_description'] ) {
            $full_desc_parts[] = $data['long_description'];
        } elseif ( '' !== $data['short_description'] ) {
            $full_desc_parts[] = '<p>' . esc_html( $data['short_description'] ) . '</p>';
        }
        if ( ! empty( $data['properties'] ) ) {
            $tbl = '<table class="shop_attributes product-properties-table"><tbody>';
            foreach ( $data['properties'] as $pk => $pv ) {
                $tbl .= '<tr><th>' . esc_html( $pk ) . '</th><td>' . esc_html( $pv ) . '</td></tr>';
            }
            $tbl .= '</tbody></table>';
            $full_desc_parts[] = $tbl;
        }
        if ( ! empty( $full_desc_parts ) ) {
            $data['description'] = implode( "\n\n", $full_desc_parts );
        }

        if ( preg_match( '/<meta\s+property=["\']product:brand["\']\s+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $data['brand'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
        }

        if ( preg_match( '/<meta\s+itemprop=["\']gtin13["\']\s+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $data['gtin13'] = trim( $m[1] );
        }

        if ( preg_match( '/<meta\s+itemprop=["\']weight["\']\s+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $data['weight'] = trim( str_replace( 'kg', '', $m[1] ) );
        }

        if ( preg_match( '/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $data['main_image'] = self::normalize_image_url( $m[1] );
        }

        if ( preg_match_all( '/<meta\s+itemprop=["\']image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches ) ) {
            foreach ( $matches[1] as $img ) {
                $full = self::normalize_image_url( $img );
                if ( ! in_array( $full, $data['gallery_images'], true ) ) {
                    $data['gallery_images'][] = $full;
                }
            }
        }

        if ( strpos( $html, 'biloba-variant-matrix' ) !== false ) {
            $data['is_variable_matrix'] = true;
            $matrix_variants            = self::parse_biloba_variant_matrix( $html );
            $data['variants']           = $matrix_variants;
        }

        return $data;
    }

    /**
     * Parse Shopware 6 Biloba Variant Order Matrix HTML (for SKU, option value, image, and live stock ONLY)
     * Supports both <label data-bit-variant-id="..."> (text options like 40 cm),
     * <img data-bit-variant-id="..."> (image swatches), and <input data-bit-variant-id="..."> (stock inputs).
     */
    private static function parse_biloba_variant_matrix( $html ) {
        $variants = array();

        if ( preg_match_all( '/<(?:img|label|input|div|a)[^>]+data-bit-variant-id=["\']([a-f0-9]{32})["\'][^>]*>/is', $html, $tag_matches, PREG_SET_ORDER ) ) {
            foreach ( $tag_matches as $tm ) {
                $tag = $tm[0];
                $vid = strtolower( trim( $tm[1] ) );

                if ( ! isset( $variants[ $vid ] ) ) {
                    $variants[ $vid ] = array(
                        'variant_id'   => $vid,
                        'sku'          => '',
                        'title'        => '',
                        'option_value' => '',
                        'image'        => '',
                        'stock'        => null,
                        'price'        => null, // Filled exclusively from Retail sites (akzentshop / meinakzent / raptor)
                    );
                }

                $vsku = self::extract_attr( $tag, 'data-attribute-variant-number' );
                if ( '' !== $vsku ) {
                    $variants[ $vid ]['sku'] = $vsku;
                }

                $vname = self::extract_attr( $tag, 'data-attribute-variant-name' );
                if ( '' !== $vname ) {
                    $variants[ $vid ]['title'] = html_entity_decode( $vname, ENT_QUOTES, 'UTF-8' );
                }

                $opt_title = self::extract_attr( $tag, 'title' );
                if ( '' === $opt_title ) {
                    $opt_title = self::extract_attr( $tag, 'alt' );
                }
                if ( '' !== $opt_title && '' === $variants[ $vid ]['option_value'] ) {
                    $variants[ $vid ]['option_value'] = html_entity_decode( $opt_title, ENT_QUOTES, 'UTF-8' );
                }

                $img_src = self::extract_attr( $tag, 'src' );
                if ( '' !== $img_src && '' === $variants[ $vid ]['image'] ) {
                    $variants[ $vid ]['image'] = self::normalize_image_url( $img_src );
                }

                $stock = self::extract_attr( $tag, 'data-attribute-max-stock' );
                if ( '' !== $stock ) {
                    $variants[ $vid ]['stock'] = (int) $stock;
                }
            }
        }

        return $variants;
    }

    /**
     * Fetch exact full-res gallery images for a specific variant_id via Shaghafi's AJAX endpoint
     */
    public static function fetch_variant_gallery_images( $variant_id ) {
        $vid = strtolower( trim( (string) $variant_id ) );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $vid ) ) {
            return array();
        }

        $cache_key = 'shaghafi_vimg_' . $vid;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $ajax_url = self::BASE_URL . '/biloba/article-variant-order-matrix/loadImageGallery/' . $vid;
        $response = wp_remote_get(
            $ajax_url,
            array(
                'timeout'   => 15,
                'sslverify' => false,
                'headers'   => array(
                    'X-Requested-With' => 'XMLHttpRequest',
                    'User-Agent'       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return array();
        }

        $html   = wp_remote_retrieve_body( $response );
        $images = array();

        if ( preg_match_all( '/https:\/\/shaghafi\.fsn1\.your-objectstorage\.com\/public\/(?:media|thumbnail)\/[^"\'\s>]+/i', $html, $matches ) ) {
            foreach ( $matches[0] as $raw_url ) {
                $full = self::normalize_image_url( $raw_url );
                if ( ! in_array( $full, $images, true ) ) {
                    $images[] = $full;
                }
            }
        }

        set_transient( $cache_key, $images, 6 * HOUR_IN_SECONDS );
        return $images;
    }

    /**
     * Convert Shaghafi thumbnail URL to full-resolution media URL
     */
    public static function normalize_image_url( $url ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
        $url = strtok( $url, '?' );
        if ( strpos( $url, '/public/thumbnail/' ) !== false ) {
            $url = str_replace( '/public/thumbnail/', '/public/media/', $url );
            $url = preg_replace( '/_\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url );
        }
        return $url;
    }

    private static function extract_attr( $tag, $attr_name ) {
        if ( preg_match( '/' . preg_quote( $attr_name, '/' ) . '=["\']([^"\']*)["\']/i', $tag, $m ) ) {
            return trim( $m[1] );
        }
        return '';
    }

    /**
     * Pick the exact per-variant price for a SKU from a merged retail result's variant_prices map.
     * Returns the variant entry array (price, regular_price, url, source) or null when no exact match.
     */
    public static function pick_exact_variant_price( array $retail, $sku ) {
        $sku = strtoupper( trim( (string) $sku ) );
        if ( '' === $sku || empty( $retail['variant_prices'] ) || ! is_array( $retail['variant_prices'] ) ) {
            return null;
        }
        if ( isset( $retail['variant_prices'][ $sku ] ) && ! empty( $retail['variant_prices'][ $sku ]['price'] ) && (float) $retail['variant_prices'][ $sku ]['price'] > 0 ) {
            return $retail['variant_prices'][ $sku ];
        }
        // Tolerate spacing/hyphen formatting differences between source systems.
        $norm = str_replace( array( ' ', '_' ), array( '', '-' ), $sku );
        foreach ( $retail['variant_prices'] as $vsku => $vp ) {
            $v_norm = str_replace( array( ' ', '_' ), array( '', '-' ), strtoupper( (string) $vsku ) );
            if ( $v_norm === $norm && ! empty( $vp['price'] ) && (float) $vp['price'] > 0 ) {
                return $vp;
            }
        }
        return null;
    }

    /**
     * Extract a full SKU => price map from a retail product page.
     *
     * Covers three real-world shapes used by the reference stores:
     *  - JSON-LD Offer/AggregateOffer blocks (all stores)
     *  - Shopware 5 embedded JSON/configurator ("ordernumber" near "price"/"priceNumeric") — AkzentShop
     *  - Shopware 6 embedded JSON ("sku"/"number" near calculatedPrice.unitPrice) — Raptor Watches
     *
     * This is what lets a variant whose price differs from its siblings (e.g. the largest size)
     * keep its OWN price instead of inheriting the product's generic first-variant price.
     */
    public static function extract_variant_price_map( $html ) {
        $map = array();
        if ( empty( $html ) ) {
            return $map;
        }

        // 1) JSON-LD structured data.
        if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks ) ) {
            foreach ( $blocks[1] as $raw_block ) {
                $decoded = json_decode( trim( $raw_block ), true );
                // Retry after stripping trailing commas, which some theme JSON-LD blocks contain.
                if ( ! is_array( $decoded ) ) {
                    $cleaned = preg_replace( '/,\s*([}\]])/', '$1', trim( $raw_block ) );
                    $decoded = json_decode( $cleaned, true );
                }
                if ( is_array( $decoded ) ) {
                    self::walk_jsonld_for_offers( $decoded, $map );
                }
            }
        }

        // 2) Windowed scan around every SKU-ish identifier in embedded JSON / data attributes.
        $sku_patterns = array(
            '/"ordernumber"\s*:\s*"([^"]+)"/i',
            '/"productNumber"\s*:\s*"([^"]+)"/i',
            '/"number"\s*:\s*"([A-Za-z0-9][A-Za-z0-9\-_]{2,})"/i',
            '/"sku"\s*:\s*"([^"]+)"/i',
            '/data-ordernumber=["\']([^"\']+)["\']/i',
            '/data-product-number=["\']([^"\']+)["\']/i',
        );

        foreach ( $sku_patterns as $pattern ) {
            if ( ! preg_match_all( $pattern, $html, $hits, PREG_OFFSET_CAPTURE ) ) {
                continue;
            }
            foreach ( $hits[1] as $hit ) {
                $sku = strtoupper( trim( $hit[0] ) );
                if ( '' === $sku || false !== strpos( $sku, ' ' ) || isset( $map[ $sku ] ) ) {
                    continue;
                }
                // A SKU looks like 5010034, 5010034-001, RA10256-002 — ignore random numeric JSON noise.
                if ( ! preg_match( '/^[A-Z]{0,3}[0-9]{4,}(?:-[A-Za-z0-9]{1,6})?$/', $sku ) ) {
                    continue;
                }
                $pos = $hit[1];
                // In Shopware 5/6 embedded JSON the price ALWAYS follows the SKU inside the same
                // object, so we only look FORWARD. Looking backward would risk inheriting the
                // previous variant's price (e.g. the last size silently keeping the first size's price).
                $forward_raw = substr( $html, $pos, 700 );
                // Cut at the next SKU marker so a neighbour's price can never leak into this variant.
                // The current marker's opening quote is already behind $pos, so an offset of 0 is safe here.
                if ( preg_match( '/"(?:ordernumber|productNumber|number|sku)"\s*:|data-(?:order|product-)number=/i', $forward_raw, $nm, PREG_OFFSET_CAPTURE ) ) {
                    $forward_raw = substr( $forward_raw, 0, $nm[0][1] );
                }
                $price   = self::extract_first_price_from_window( $forward_raw );
                $regular = self::extract_first_regular_price_from_window( $forward_raw );
                if ( null !== $price && $price > 0 ) {
                    $map[ $sku ] = array(
                        'price'         => $price,
                        'regular_price' => $regular,
                    );
                }
            }
        }

        return $map;
    }

    /**
     * Recursively walk a decoded JSON-LD structure collecting sku+price offers.
     */
    private static function walk_jsonld_for_offers( $node, array &$map ) {
        if ( ! is_array( $node ) ) {
            return;
        }

        if ( ! empty( $node['sku'] ) && isset( $node['price'] ) ) {
            $price = self::normalize_price_value( $node['price'] );
            if ( null !== $price && $price > 0 ) {
                $sku = strtoupper( trim( (string) $node['sku'] ) );
                if ( '' !== $sku && ! isset( $map[ $sku ] ) ) {
                    $regular = null;
                    if ( ! empty( $node['priceSpecification']['price'] ) ) {
                        $regular = self::normalize_price_value( $node['priceSpecification']['price'] );
                    }
                    $map[ $sku ] = array(
                        'price'         => $price,
                        'regular_price' => $regular,
                    );
                }
            }
        }

        // AggregateOffer / offer collections.
        if ( ! empty( $node['offers'] ) ) {
            $offers = $node['offers'];
            if ( isset( $offers['offers'] ) && is_array( $offers['offers'] ) ) {
                $offers = $offers['offers'];
            }
            if ( is_array( $offers ) ) {
                foreach ( $offers as $offer ) {
                    if ( is_array( $offer ) ) {
                        self::walk_jsonld_for_offers( $offer, $map );
                    }
                }
            }
        }

        foreach ( $node as $key => $value ) {
            if ( is_array( $value ) && 'offers' !== $key ) {
                self::walk_jsonld_for_offers( $value, $map );
            }
        }
    }

    /**
     * Find the first trustworthy price inside an embedded-JSON window.
     */
    private static function extract_first_price_from_window( $window ) {
        $patterns = array(
            '/"unitPrice"\s*:\s*"?([0-9]+(?:[.,][0-9]{1,2})?)"?/i',
            '/"priceNumeric"\s*:\s*"?([0-9]+(?:[.,][0-9]{1,2})?)"?/i',
            '/data-price=["\']([0-9]+(?:[.,][0-9]{1,2})?)["\']/i',
            '/"price"\s*:\s*"([0-9]+(?:[.,][0-9]{1,2})?)"/i',
            '/"price"\s*:\s*([0-9]+(?:\.[0-9]{1,2})?)/i',
        );
        foreach ( $patterns as $p ) {
            if ( preg_match( $p, $window, $m ) ) {
                $val = self::normalize_price_value( $m[1] );
                if ( null !== $val && $val > 0 ) {
                    return $val;
                }
            }
        }
        return null;
    }

    /**
     * Find a list/compare/regular price (strikethrough price) inside an embedded-JSON window.
     */
    private static function extract_first_regular_price_from_window( $window ) {
        $patterns = array(
            '/"listPrice"\s*:\s*"?([0-9]+(?:[.,][0-9]{1,2})?)"?/i',
            '/"referencePrice"\s*:\s*"?([0-9]+(?:[.,][0-9]{1,2})?)"?/i',
            '/"compareAtPrice"\s*:\s*"?([0-9]+(?:[.,][0-9]{1,2})?)"?/i',
            '/data-list-price=["\']([0-9]+(?:[.,][0-9]{1,2})?)["\']/i',
        );
        foreach ( $patterns as $p ) {
            if ( preg_match( $p, $window, $m ) ) {
                $val = self::normalize_price_value( $m[1] );
                if ( null !== $val && $val > 0 ) {
                    return $val;
                }
            }
        }
        return null;
    }

    /**
     * Normalize a scraped price scalar ("12,34" / "12.34" / 12.34) into a float.
     */
    private static function normalize_price_value( $value ) {
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }
        $value = trim( (string) $value );
        if ( '' === $value || ! preg_match( '/[0-9]/', $value ) ) {
            return null;
        }
        // German decimal comma ("12,34") vs. thousands separators ("1.234,56").
        if ( false !== strpos( $value, ',' ) ) {
            $value = str_replace( '.', '', $value );
            $value = str_replace( ',', '.', $value );
        }
        if ( ! is_numeric( $value ) ) {
            return null;
        }
        return (float) $value;
    }

    /**
     * Calculate final WooCommerce price from Retail price using configured multiplier + fixed addition
     */
    public static function calculate_target_price( $retail_eur_price ) {
        if ( null === $retail_eur_price || '' === $retail_eur_price || (float) $retail_eur_price <= 0 ) {
            return null;
        }
        $settings   = get_option( 'shaghafi_sync_settings', array() );
        $multiplier = isset( $settings['price_multiplier'] ) && (float) $settings['price_multiplier'] > 0
            ? (float) $settings['price_multiplier']
            : 1.0;
        $add_fixed  = isset( $settings['price_add_fixed'] ) ? (float) $settings['price_add_fixed'] : 0.0;

        return round( ( (float) $retail_eur_price * $multiplier ) + $add_fixed, 2 );
    }
}
