<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Smart WooCommerce Category Matcher (تشخیص هوشمند دسته‌بندی محصول جدید)
 *
 * Reads the product's German title, brand, technical properties and variant options from
 * Shaghafi and maps them onto the EXISTING product_cat tree of the shop (Persian / English /
 * German names are all supported). The winning leaf category is assigned together with its full
 * parent chain, so mother category + subcategories are always stored correctly.
 */
class Shaghafi_Category_Matcher {

    /**
     * Concept dictionary: every concept maps to multilingual keywords (German source site,
     * English slugs/names, and common Persian category words).
     *
     * @return array
     */
    private static function concept_dictionary() {
        return array(
            'watch'        => array( 'uhr', 'uhren', 'watch', 'watches', 'raptor', 'ساعت' ),
            'watch_box'    => array( 'uhrenbox', 'uhrenkasten', 'uhrenstaender', 'watch box', 'watch case', 'watch stand', 'جعبه ساعت', 'جای ساعت' ),
            'ring'         => array( 'ring', 'ringe', 'rings', 'fingerring', 'انگشتر', 'رینگ' ),
            'necklace'     => array( 'kette', 'halskette', 'collier', 'necklace', 'chain', 'choker', 'گردنبند', 'زنجیر', 'زنجير' ),
            'pendant'      => array( 'anhänger', 'anhanger', 'pendant', 'آویز', 'آويز', 'پلاک' ),
            'bracelet'     => array( 'armband', 'armbänder', 'armbander', 'bracelet', 'bracelets', 'armreif', 'bangles', 'bangle', 'دستبند', 'النگو', 'النگو' ),
            'earring'      => array( 'ohrring', 'ohrrings', 'ohrstecker', 'ohrhänger', 'ohrhangar', 'earring', 'earrings', 'گوشواره', 'گوش واره' ),
            'anklet'       => array( 'fußkettchen', 'fusskettchen', 'anklet', 'anklets', 'پابند' ),
            'piercing'     => array( 'piercing', 'پیرسینگ' ),
            'brooch'       => array( 'brosche', 'brooch', 'سنجاق' ),
            'hair'         => array( 'haarspange', 'haarclip', 'haargummi', 'haarreif', 'haarband', 'hair clip', 'hair band', 'hairband', 'گیره مو', 'گيره مو', 'تل مو' ),
            'keyring'      => array( 'schlüsselanhänger', 'schlusselanhanger', 'schlüsselband', 'schlusselband', 'keyring', 'keychain', 'lanyard', 'جاکلیدی', 'جا کلیدی', 'جاسوئیچی' ),
            'cufflink'     => array( 'manschettenknopf', 'manschettenknöpfe', 'cufflink', 'cufflinks', 'دکمه سرآستین', 'دكمه سرآستين' ),
            'tie_clip'     => array( 'krawattenklammer', 'krawattennadel', 'tie clip', 'tiepin', 'گیره کراوات', 'گيره كراوات' ),
            'money_clip'   => array( 'geldklammer', 'money clip', 'گیره پول', 'گيره پول' ),
            'wallet'       => array( 'geldbörse', 'geldborse', 'geldbeutel', 'brieftasche', 'wallet', 'purse', 'کیف پول', 'كيف پول' ),
            'belt'         => array( 'gürtel', 'gurtel', 'belt', 'belts', 'کمربند' ),
            'bag'          => array( 'tasche', 'handtasche', 'bag', 'bags', 'کیف', 'كيف', 'کوله' ),
            'glasses'      => array( 'brille', 'sonnenbrille', 'glasses', 'sunglasses', 'عینک', 'عينك' ),
            'scarf'        => array( 'schal', 'tuch', 'scarf', 'شال', 'روسری' ),
            'hat'          => array( 'mütze', 'mutze', 'cap', 'hut', 'hat', 'کلاه' ),
            'jewelry_box'  => array( 'schmuckbox', 'schatulle', 'jewelry box', 'jewellery box', 'جعبه جواهرات', 'جعبه زيورآلات' ),
            'jewelry'      => array( 'schmuck', 'jewelry', 'jewellery', 'jewel', 'جواهرات', 'زيورآلات', 'زیورآلات', 'زیور', 'زرین', 'نقره‌جات' ),
            'charm'        => array( 'charm', 'charms', 'charm-anhänger', 'بیس', 'بيس', 'چارم' ),
            'letter'       => array( 'buchstabe', 'buchstabenanhänger', 'letter', 'letters', 'alphabet', 'حرف', 'حروف' ),
            'pearl'        => array( 'perle', 'perlen', 'pearl', 'pearls', 'مروارید' ),
            'crystal'      => array( 'zirkonia', 'zirconia', 'kristall', 'crystal', 'strass', 'نگین', 'نگين', 'کریستال' ),
            'women'        => array( 'damen', 'dame', 'frau', 'frauen', 'women', 'woman', 'female', 'ladies', 'lady', 'زنانه', 'بانوان', 'دخترانه' ),
            'men'          => array( 'herren', 'herr', 'mann', 'männer', 'manner', 'men', 'man', 'male', 'مردانه', 'مردان' ),
            'kids'         => array( 'kinder', 'kind', 'kids', 'kid', 'children', 'child', 'بچگانه', 'کودک', 'كودك', 'بچه' ),
            'unisex'       => array( 'unisex', 'یونیسکس' ),
            'silver'       => array( 'silber', 'silver', '925', 'نقره', 'نقره‌اي' ),
            'gold'         => array( 'gold', 'golden', 'طلا', 'طلایی', 'طلائي' ),
            'stainless'    => array( 'edelstahl', 'stainless', 'steel', 'استیل', 'فولاد' ),
            'leather'      => array( 'leder', 'leather', 'چرم' ),
        );
    }

    /**
     * Assign the best-matching category chain to a product.
     *
     * @param int   $parent_id Product post ID.
     * @param array $context   Optional keys: title, brand, properties (assoc), options (array), sku, force.
     * @return array|WP_Error  Result with assigned term IDs/names or an explanatory error.
     */
    public static function assign_for_product( $parent_id, array $context = array() ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return new WP_Error( 'invalid_product', 'شناسه محصول برای دسته‌بندی نامعتبر است.' );
        }

        $force = ! empty( $context['force'] );

        // Never overwrite categories the shop manager already chose, unless forced.
        $current = wp_get_post_terms( $parent_id, 'product_cat', array( 'fields' => 'ids' ) );
        if ( ! $force && ! empty( $current ) && ! self::is_only_uncategorized( $current ) ) {
            return array(
                'skipped'   => true,
                'reason'    => 'محصول از قبل دسته‌بندی دارد؛ دسته‌های موجود حفظ شد.',
                'term_ids'  => $current,
            );
        }

        $terms = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'none',
            )
        );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return new WP_Error( 'no_categories', 'هیچ دسته‌بندی‌ای در ووکامرس تعریف نشده است.' );
        }

        $haystack = self::build_haystack( $context );
        if ( '' === trim( $haystack ) ) {
            return new WP_Error( 'empty_context', 'متنی برای تشخیص دسته‌بندی در دسترس نیست.' );
        }

        $dictionary = self::concept_dictionary();
        $concepts   = self::detect_concepts( $haystack, $dictionary );

        if ( empty( $concepts ) ) {
            return new WP_Error( 'no_match', 'هیچ دسته‌بندی هماهنگی برای این محصول در فروشگاه پیدا نشد؛ محصول بدون دسته باقی ماند.' );
        }

        // Index terms and remember which concepts each term responds to.
        $term_by_id = array();
        $parent_of  = array();
        foreach ( $terms as $term ) {
            if ( 'uncategorized' === $term->slug ) {
                continue;
            }
            $term_by_id[ $term->term_id ] = $term;
            $parent_of[ $term->term_id ]  = (int) $term->parent;
        }

        if ( empty( $term_by_id ) ) {
            return new WP_Error( 'no_categories', 'تنها دسته «Uncategorized» وجود دارد.' );
        }

        $scored = array();
        foreach ( $term_by_id as $tid => $term ) {
            $score = self::score_term( $term, $haystack, $concepts, $dictionary );
            if ( $score > 0 ) {
                $scored[ $tid ] = $score;
            }
        }

        if ( empty( $scored ) ) {
            return new WP_Error( 'no_match', 'هیچ دسته‌بندی هماهنگی برای این محصول در فروشگاه پیدا نشد؛ محصول بدون دسته باقی ماند.' );
        }

        arsort( $scored );
        $best_tid = key( $scored );
        $best_score = current( $scored );

        // Safety threshold: avoid pinning products onto weak/accidental matches.
        if ( $best_score < 3 ) {
            return new WP_Error(
                'weak_match',
                'اطمینان کافی برای انتخاب دسته وجود نداشت (بالاترین امتیاز ' . $best_score . '). محصول بدون دسته باقی ماند.'
            );
        }

        // Collect the full ancestor chain (mother category + subcategories).
        $chain_ids   = array();
        $chain_names = array();
        $cursor      = $best_tid;
        $guard       = 0;
        while ( $cursor > 0 && isset( $term_by_id[ $cursor ] ) && $guard < 20 ) {
            array_unshift( $chain_ids, $cursor );
            array_unshift( $chain_names, $term_by_id[ $cursor ]->name );
            $cursor = isset( $parent_of[ $cursor ] ) ? $parent_of[ $cursor ] : 0;
            $guard++;
        }

        wp_set_object_terms( $parent_id, $chain_ids, 'product_cat', false );

        $result = array(
            'term_ids'    => $chain_ids,
            'term_names'  => $chain_names,
            'leaf_id'     => $best_tid,
            'score'       => $best_score,
            'concepts'    => array_keys( $concepts ),
        );
        update_post_meta( $parent_id, '_shaghafi_auto_category_log', wp_json_encode( $result ) );

        return $result;
    }

    /**
     * Lowercased, transliteration-friendly text blob of every product signal.
     */
    private static function build_haystack( array $context ) {
        $parts = array();
        if ( ! empty( $context['title'] ) ) {
            $parts[] = (string) $context['title'];
        }
        if ( ! empty( $context['brand'] ) ) {
            $parts[] = (string) $context['brand'];
        }
        if ( ! empty( $context['properties'] ) && is_array( $context['properties'] ) ) {
            foreach ( $context['properties'] as $pk => $pv ) {
                $parts[] = $pk . ' ' . $pv;
            }
        }
        if ( ! empty( $context['options'] ) && is_array( $context['options'] ) ) {
            foreach ( $context['options'] as $opt ) {
                $parts[] = (string) $opt;
            }
        }
        if ( ! empty( $context['sku'] ) ) {
            $parts[] = (string) $context['sku'];
        }

        $text = implode( ' ', $parts );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = wp_strip_all_tags( ' ' . $text . ' ' );
        // Normalize German umlauts so "Armbänder" == "armbander" matches.
        $text = strtr(
            mb_strtolower( $text, 'UTF-8' ),
            array(
                'ä' => 'a',
                'ö' => 'o',
                'ü' => 'u',
                'ß' => 'ss',
            )
        );
        return ' ' . trim( preg_replace( '/\s+/u', ' ', $text ) ) . ' ';
    }

    /**
     * Detect which concepts are present in the product haystack, with hit counts for weighting.
     */
    private static function detect_concepts( $haystack, array $dictionary ) {
        $hits = array();
        foreach ( $dictionary as $concept => $keywords ) {
            $count = 0;
            foreach ( $keywords as $kw ) {
                $kw = mb_strtolower( (string) $kw, 'UTF-8' );
                if ( '' === $kw ) {
                    continue;
                }
                // Latin keywords use word boundaries; Persian/CJK-ish strings use plain substring matching.
                if ( preg_match( '/^[a-z0-9 .\-]+$/u', $kw ) ) {
                    if ( preg_match( '/(?<![a-z0-9])' . preg_quote( $kw, '/' ) . '(?![a-z0-9])/u', $haystack ) ) {
                        $count++;
                    }
                } elseif ( false !== mb_strpos( $haystack, $kw ) ) {
                    $count++;
                }
            }
            if ( $count > 0 ) {
                $hits[ $concept ] = $count;
            }
        }
        return $hits;
    }

    /**
     * Score a single category term against the detected concepts and the raw haystack.
     */
    private static function score_term( $term, $haystack, array $concepts, array $dictionary ) {
        $name = mb_strtolower( html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'UTF-8' );
        $slug = mb_strtolower( (string) $term->slug, 'UTF-8' );
        $desc = mb_strtolower( html_entity_decode( wp_strip_all_tags( (string) $term->description ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'UTF-8' );
        $name = strtr( $name, array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss' ) );
        $desc = strtr( $desc, array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss' ) );

        $term_text = ' ' . trim( $name . ' ' . str_replace( array( '-', '_' ), ' ', $slug ) . ' ' . $desc ) . ' ';
        $score     = 0.0;

        // 1. Concept-based matching (cross-lingual: German product data vs. Persian/English category names).
        foreach ( $concepts as $concept => $hit_count ) {
            if ( empty( $dictionary[ $concept ] ) ) {
                continue;
            }
            foreach ( $dictionary[ $concept ] as $kw ) {
                $kw = mb_strtolower( (string) $kw, 'UTF-8' );
                if ( '' === $kw ) {
                    continue;
                }
                $in_term = self::term_text_contains( $term_text, $kw );
                if ( ! $in_term ) {
                    continue;
                }
                // Product-type concepts weigh more than material/gender qualifiers.
                $weight = self::concept_weight( $concept );
                $score += $weight * min( 2, $hit_count );

                // Exact full-name match (category name IS the keyword) is the strongest possible signal.
                if ( trim( $name ) === $kw || trim( str_replace( array( '-', '_' ), ' ', $slug ) ) === $kw ) {
                    $score += 4;
                } elseif ( false !== mb_strpos( $name, $kw ) ) {
                    $score += 1.5;
                }
            }
        }

        // 2. Direct token overlap between the category name and the product text (same language).
        $tokens = preg_split( '/[\s\-_,،.()\/]+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
        if ( is_array( $tokens ) ) {
            foreach ( $tokens as $token ) {
                $token = trim( $token );
                if ( mb_strlen( $token, 'UTF-8' ) < 3 ) {
                    continue;
                }
                if ( self::term_text_contains( $haystack, $token ) ) {
                    $score += 2.0;
                }
            }
        }

        // 3. Specificity bonus: deeper categories are more precise than top-level containers.
        $depth = (int) $term->parent > 0 ? 1 : 0;
        if ( $depth ) {
            $score += 1.5;
        }

        // 4. Gender agreement bonus/penalty so Damen products prefer the women's branch when it exists.
        foreach ( array( 'women', 'men', 'kids', 'unisex' ) as $gender_concept ) {
            if ( isset( $concepts[ $gender_concept ] ) && self::term_text_contains( $term_text, self::first_keyword( $dictionary, $gender_concept ) ) ) {
                $score += 2.5;
            }
        }

        return $score;
    }

    private static function term_text_contains( $text, $keyword ) {
        $keyword = trim( (string) $keyword );
        if ( '' === $keyword ) {
            return false;
        }
        if ( preg_match( '/^[a-z0-9 .\-]+$/u', $keyword ) ) {
            return (bool) preg_match( '/(?<![a-z0-9])' . preg_quote( $keyword, '/' ) . '(?![a-z0-9])/u', $text );
        }
        return false !== mb_strpos( $text, $keyword );
    }

    private static function concept_weight( $concept ) {
        $product_types = array(
            'watch', 'watch_box', 'ring', 'necklace', 'pendant', 'bracelet', 'earring', 'anklet',
            'piercing', 'brooch', 'hair', 'keyring', 'cufflink', 'tie_clip', 'money_clip',
            'wallet', 'belt', 'bag', 'glasses', 'scarf', 'hat', 'jewelry_box', 'jewelry',
            'charm',
        );
        if ( in_array( $concept, $product_types, true ) ) {
            return 3.0;
        }
        if ( in_array( $concept, array( 'women', 'men', 'kids', 'unisex' ), true ) ) {
            return 1.5;
        }
        // Materials / stones / styles.
        return 1.0;
    }

    private static function first_keyword( array $dictionary, $concept ) {
        return isset( $dictionary[ $concept ][0] ) ? $dictionary[ $concept ][0] : '';
    }

    private static function is_only_uncategorized( array $term_ids ) {
        if ( empty( $term_ids ) ) {
            return true;
        }
        foreach ( $term_ids as $tid ) {
            $term = get_term( (int) $tid, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) && 'uncategorized' !== $term->slug ) {
                return false;
            }
        }
        return true;
    }
}
