(function ($) {
    'use strict';

    var currentQueueRows = [];
    var currentQueuePage = 1;
    var currentObsPage = 1;
    var obsLoadedOnce = false;

    function showToast(msg, isError) {
        $('.shaghafi-toast').remove();
        var $t = $('<div class="shaghafi-toast"></div>')
            .text(msg)
            .css('background', isError ? '#b91c1c' : '#0f172a');
        $('body').append($t);
        setTimeout(function () {
            $t.fadeOut(300, function () {
                $(this).remove();
            });
        }, 4500);
    }

    // Global session guard: when the PHP login gate rejects an AJAX call, ask the user to log in again.
    function handleReloginFlag(data, textStatus, jqXHR) {
        var payload = data;
        if (!payload && jqXHR && jqXHR.responseJSON) {
            payload = jqXHR.responseJSON;
        }
        if (payload && payload.data && payload.data.relogin) {
            if (window.confirm('نشست ورود به پنل شقفی منقضی شده است. برای ورود مجدد صفحه بارگذاری می‌شود.')) {
                window.location.reload();
            }
            return true;
        }
        return false;
    }
    $(document).ajaxError(function (event, jqXHR) {
        if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.relogin) {
            handleReloginFlag(jqXHR.responseJSON, null, jqXHR);
        }
    });
    $(document).ajaxSuccess(function (event, jqXHR, settings, data) {
        handleReloginFlag(data, null, jqXHR);
    });

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function updateProgress(title, current, total) {
        var $box = $('#shaghafi-progress-box');
        if (total <= 0) {
            $box.hide();
            return;
        }
        $box.show();
        $('#shaghafi-progress-title').text(title);
        $('#shaghafi-progress-counter').text(current + ' / ' + total);
        var pct = Math.min(100, Math.round((current / total) * 100));
        $('#shaghafi-progress-bar').css('width', pct + '%');
    }

    function updateKpiCounts(counts) {
        if (!counts) return;
        $('#kpi-total-items').text(counts.total_items || 0);
        $('#kpi-repair-link').text(counts.REPAIR_AND_LINK || 0);
        $('#kpi-keep-sync').text(counts.KEEP_AND_SYNC || 0);
        $('#kpi-create-new').text(counts.CREATE_NEW_VARIATION || 0);
        $('#kpi-disable-missing').text(counts.DISABLE_MISSING || 0);
        $('#kpi-manual-review').text(counts.MANUAL_REVIEW || 0);
        $('#kpi-healthy').text(counts.HEALTHY || 0);
    }

    function renderPaginationBar(containerSelector, pagination, btnClass) {
        var $bar = $(containerSelector);
        $bar.empty();
        if (!pagination || pagination.total_rows <= 0) {
            $bar.hide();
            return;
        }
        $bar.show();

        var cur = parseInt(pagination.current_page, 10) || 1;
        var totalPages = parseInt(pagination.total_pages, 10) || 1;
        var infoHtml = '<div class="shaghafi-page-info">' +
            'نمایش ردیف <strong>' + pagination.from_item + '</strong> تا <strong>' + pagination.to_item + '</strong> از مجموع <strong>' + pagination.total_rows + '</strong> مورد (صفحه ' + cur + ' از ' + totalPages + ')' +
            '</div>';

        var btnsHtml = '<div class="shaghafi-page-buttons">';
        btnsHtml += '<button type="button" class="shaghafi-page-btn ' + btnClass + '" data-page="1" ' + (cur <= 1 ? 'disabled' : '') + '>« اول</button>';
        btnsHtml += '<button type="button" class="shaghafi-page-btn ' + btnClass + '" data-page="' + (cur - 1) + '" ' + (cur <= 1 ? 'disabled' : '') + '>‹ قبلی</button>';

        var startP = Math.max(1, cur - 3);
        var endP = Math.min(totalPages, cur + 3);
        for (var p = startP; p <= endP; p++) {
            btnsHtml += '<button type="button" class="shaghafi-page-btn ' + btnClass + (p === cur ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
        }

        btnsHtml += '<button type="button" class="shaghafi-page-btn ' + btnClass + '" data-page="' + (cur + 1) + '" ' + (cur >= totalPages ? 'disabled' : '') + '>بعدی ›</button>';
        btnsHtml += '<button type="button" class="shaghafi-page-btn ' + btnClass + '" data-page="' + totalPages + '" ' + (cur >= totalPages ? 'disabled' : '') + '>آخر »</button>';
        btnsHtml += '</div>';

        $bar.html(infoHtml + btnsHtml);
    }

    function renderQueueTable(rows) {
        currentQueueRows = rows || [];
        var $tbody = $('#shaghafi-queue-tbody');
        $tbody.empty();
        $('#shaghafi-select-all').prop('checked', false);

        if (!rows || rows.length === 0) {
            $tbody.html('<tr><td colspan="11" style="text-align:center;padding:28px;">هیچ موردی با فیلتر انتخاب‌شده یافت نشد.</td></tr>');
            return;
        }

        var targetW = ShaghafiSyncData.target_img_width || 700;
        var targetH = ShaghafiSyncData.target_img_height || 800;

        rows.forEach(function (r) {
            var badgeClass = 'shaghafi-badge-' + String(r.action || '').toLowerCase();
            var shaghafiLink = (r.feed_product_id && r.feed_product_id !== '—')
                ? '<a href="https://shaghafi.de/detail/' + escHtml(r.feed_variant_id !== '—' ? r.feed_variant_id : r.feed_product_id) + '" target="_blank" style="font-size:11px;display:inline-block;margin-right:6px;">Shaghafi.de ↗</a>'
                : '';

            // Image cell (Uncropped 700x800 preview + dimension badge, or Size Variation badge)
            var imgHtml = '';
            if (r.is_size_variation) {
                imgHtml = '<span style="display:inline-block;padding:4px 8px;background:#e0f2fe;color:#0369a1;font-size:11px;font-weight:600;border-radius:4px;border:1px solid #bae6fd;">📏 سایز (بدون نیاز به عکس مجزا)</span>';
            } else {
                imgHtml = '<div style="display:flex;align-items:center;gap:6px;">';
                if (r.woo_image_url) {
                    var dimsBadge = '';
                    if (r.woo_image_dims) {
                        dimsBadge = '<span class="shaghafi-dims-badge ' + (r.woo_image_dims_ok ? 'shaghafi-dims-ok' : 'shaghafi-dims-bad') + '">' +
                            escHtml(r.woo_image_dims) + (r.woo_image_dims_ok ? ' ✓' : ' ⚠') +
                            '</span>';
                    }
                    imgHtml += '<div style="text-align:center;">' +
                        '<a href="' + escHtml(r.woo_image_url) + '" target="_blank">' +
                        '<img src="' + escHtml(r.woo_image_url) + '" class="shaghafi-img-preview-box" title="عکس فعلی در سایت (بدون برش)" />' +
                        '</a>' + dimsBadge + '</div>';
                } else {
                    imgHtml += '<span style="display:inline-block;width:49px;height:56px;background:#fee2e2;color:#991b1b;font-size:10px;line-height:18px;padding-top:10px;box-sizing:border-box;text-align:center;border-radius:5px;border:1px solid #fecaca;">بدون عکس<br/>(حذف شده)</span>';
                }
                if (!r.woo_image_url && r.local_gallery_match && r.local_gallery_match.url) {
                    imgHtml += '<span>←</span><div style="text-align:center;"><a href="' + escHtml(r.local_gallery_match.url) + '" target="_blank">' +
                        '<img src="' + escHtml(r.local_gallery_match.url) + '" class="shaghafi-img-preview-box" style="border:2px solid #2563eb;" title="موجود در گالری محصول شما (آماده بازیابی خودکار)" />' +
                        '</a><span class="shaghafi-dims-badge" style="background:#eff6ff;color:#1d4ed8;">گالری محصول</span></div>';
                } else if (r.live_image_url) {
                    imgHtml += '<span>←</span><div style="text-align:center;"><a href="' + escHtml(r.live_image_url) + '" target="_blank">' +
                        '<img src="' + escHtml(r.live_image_url) + '" class="shaghafi-img-preview-box" style="border:2px solid #16a34a;" title="عکس زنده در Shaghafi.de (آماده تبدیل بدون برش به ' + targetW + '×' + targetH + ')" />' +
                        '</a><span class="shaghafi-dims-badge" style="background:#f0fdf4;color:#15803d;">Shaghafi</span></div>';
                } else if (r.local_gallery_match && r.local_gallery_match.url) {
                    imgHtml += '<span>←</span><div style="text-align:center;"><a href="' + escHtml(r.local_gallery_match.url) + '" target="_blank">' +
                        '<img src="' + escHtml(r.local_gallery_match.url) + '" class="shaghafi-img-preview-box" style="border:2px solid #2563eb;" title="موجود در گالری وردپرس شما" />' +
                        '</a><span class="shaghafi-dims-badge" style="background:#eff6ff;color:#1d4ed8;">گالری</span></div>';
                }
                imgHtml += '</div>';
            }

            // SKU cell
            var skuDiff = (r.woo_sku !== r.feed_sku && r.feed_sku !== '—');
            var skuHtml = '<div style="direction:ltr;text-align:left;font-family:monospace;">' +
                '<div>فعلی: <span class="' + (skuDiff ? 'shaghafi-diff-bad' : '') + '">' + escHtml(r.woo_sku || '—') + '</span></div>' +
                '<div>مرجع: <span class="shaghafi-diff-good">' + escHtml(r.feed_sku || '—') + '</span></div>' +
                '</div>';

            // Attribute cell
            var attrDiff = (String(r.woo_attr || '').toLowerCase() !== String(r.feed_attr || '').toLowerCase() && r.feed_attr !== '—');
            var attrHtml = '<div>فعلی: <span class="' + (attrDiff ? 'shaghafi-diff-bad' : '') + '">' + escHtml(r.woo_attr || '—') + '</span></div>' +
                '<div>مرجع: <span class="shaghafi-diff-good">' + escHtml(r.feed_attr || '—') + '</span></div>';

            // Stock cell
            var refStock = (r.live_stock !== null && r.live_stock !== undefined) ? r.live_stock : r.feed_stock;
            var stockDiff = (r.woo_stock !== refStock && refStock !== null);
            var stockHtml = '<div>فعلی: <strong class="' + (stockDiff ? 'shaghafi-diff-bad' : '') + '">' + (r.woo_stock !== null ? r.woo_stock : '—') + '</strong></div>' +
                '<div>فید CSV: <strong>' + (r.feed_stock !== null ? r.feed_stock : '—') + '</strong>' +
                (r.live_stock !== null && r.live_stock !== undefined ? ' | <span style="color:#15803d;font-weight:700;">زنده: ' + r.live_stock + '</span>' : '') +
                '</div>';

            // Price & Retail Links cell (ONLY from AkzentShop.de / MeinAkzent.de / Raptor-Watches.com — NEVER Shaghafi wholesale!)
            var priceHtml = '<div>فعلی سایت: <strong>' + escHtml(r.woo_price !== '' && r.woo_price !== null ? r.woo_price : '—') + '</strong></div>';
            if (r.live_price !== null && r.live_price !== undefined && r.live_price !== '') {
                var srcLabel = r.retail_source ? (' (' + r.retail_source + ')') : '';
                var priceMatched = (r.target_price !== null && Math.abs(parseFloat(r.target_price) - parseFloat(r.woo_price || 0)) < 0.01);
                priceHtml += '<div style="color:' + (priceMatched ? '#15803d' : '#7c3aed') + ';font-weight:700;">مرجع' + escHtml(srcLabel) + ': ' + escHtml(r.live_price) + ' € ' + (priceMatched ? '✓' : '') + '</div>';
                if (r.target_price !== null && !priceMatched) {
                    priceHtml += '<div style="font-size:11px;color:#dc2626;font-weight:600;">قیمت هدف: ' + escHtml(r.target_price) + ' €</div>';
                }
            } else if (r.live_checked) {
                priceHtml += '<div style="font-size:11px;color:#15803d;font-weight:600;">✓ بررسی زنده انجام شد (قیمت فعلی سایت حفظ شد)</div>';
            } else {
                priceHtml += '<div style="font-size:11px;color:#d97706;font-weight:600;">⏳ در صف بررسی زنده خودکار قیمت از ۳ سایت مرجع</div>';
            }

            if (r.retail_links && r.retail_links.length > 0) {
                priceHtml += '<div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:4px;">';
                r.retail_links.forEach(function (lnk) {
                    var badgeBg = lnk.is_search ? '#f1f5f9' : '#ede9fe';
                    var badgeColor = lnk.is_search ? '#334155' : '#5b21b6';
                    var pTxt = (lnk.price !== null && lnk.price !== undefined) ? (' (' + lnk.price + '€)') : '';
                    priceHtml += '<a href="' + escHtml(lnk.url) + '" target="_blank" style="display:inline-block;padding:2px 6px;border-radius:4px;font-size:10.5px;text-decoration:none;background:' + badgeBg + ';color:' + badgeColor + ';border:1px solid #cbd5e1;" title="مشاهده محصول در ' + escHtml(lnk.label) + '">🔗 ' + escHtml(lnk.label + pTxt) + ' ↗</a>';
                });
                priceHtml += '</div>';
            }

            // IDs cell
            var pidShort = r.feed_product_id && r.feed_product_id !== '—' ? r.feed_product_id.substring(0, 10) + '…' : '—';
            var vidShort = r.feed_variant_id && r.feed_variant_id !== '—' ? r.feed_variant_id.substring(0, 10) + '…' : '—';
            var idMatch = (String(r.woo_product_id_meta || '').toLowerCase() === String(r.feed_product_id || '').toLowerCase() &&
                           String(r.woo_variant_id_meta || '').toLowerCase() === String(r.feed_variant_id || '').toLowerCase());
            var idsHtml = '<div style="direction:ltr;text-align:left;font-family:monospace;font-size:11px;">' +
                '<div title="' + escHtml(r.feed_product_id) + '">PID: ' + escHtml(pidShort) + '</div>' +
                '<div title="' + escHtml(r.feed_variant_id) + '">VID: ' + escHtml(vidShort) + '</div>' +
                '<span style="color:' + (idMatch ? '#16a34a' : '#dc2626') + ';">' + (idMatch ? '✓ متا ذخیره شده' : '⚠ متا ناقص/مغایر') + '</span>' +
                '</div>';

            // Issues cell
            var issuesHtml = '';
            if (!r.issues || r.issues.length === 0) {
                issuesHtml = '<span style="color:#059669;font-weight:600;">✓ کاملاً منطبق با مرجع</span>';
            } else {
                issuesHtml = '<ul style="margin:0;padding-right:15px;color:#b91c1c;font-size:11.5px;">';
                r.issues.forEach(function (iss) {
                    issuesHtml += '<li>' + escHtml(iss.text) + '</li>';
                });
                issuesHtml += '</ul>';
            }

            // Actions cell
            var actBtns = '<div class="shaghafi-action-stack">';
            if (r.action === 'CREATE_NEW_VARIATION') {
                actBtns += '<button type="button" class="button button-primary button-small shaghafi-btn-create-var" data-parent-id="' + r.parent_id + '" data-feed-vid="' + escHtml(r.feed_variant_id) + '">➕ اضافه کردن وریشن</button>';
            } else if (r.action === 'DISABLE_MISSING') {
                actBtns += '<button type="button" class="button button-small shaghafi-btn-disable-var" style="background:#dc2626;color:#fff;border-color:#b91c1c;" data-parent-id="' + r.parent_id + '" data-variation-id="' + r.variation_id + '">🗑️ حذف کامل و دائمی</button>';
            } else if (r.action === 'MANUAL_REVIEW' && r.candidate_variants && r.candidate_variants.length > 0) {
                actBtns += '<select class="shaghafi-manual-select" style="font-size:11px;"><option value="">انتخاب وریشن شقفی...</option>';
                r.candidate_variants.forEach(function (c) {
                    actBtns += '<option value="' + escHtml(c.variant_id) + '">' + escHtml(c.artikelnummer + ' (' + c.variantenauspraegung + ')') + '</option>';
                });
                actBtns += '</select>';
                actBtns += '<button type="button" class="button button-primary button-small shaghafi-btn-manual-link" data-parent-id="' + r.parent_id + '" data-variation-id="' + r.variation_id + '">🔗 اتصال و اصلاح</button>';
            } else if (r.feed_variant_id && r.feed_variant_id !== '—') {
                actBtns += '<button type="button" class="button button-primary button-small shaghafi-btn-repair-row" data-parent-id="' + r.parent_id + '" data-variation-id="' + r.variation_id + '" data-feed-vid="' + escHtml(r.feed_variant_id) + '">🔧 اصلاح طبق فایل مرجع</button>';
                if (!r.is_size_variation) {
                    actBtns += '<button type="button" class="button button-small shaghafi-btn-force-image" data-parent-id="' + r.parent_id + '" data-variation-id="' + r.variation_id + '" data-feed-vid="' + escHtml(r.feed_variant_id) + '">🖼 عکس ' + targetW + '×' + targetH + ' (بدون برش)</button>';
                }
            }
            actBtns += '<button type="button" class="button button-small shaghafi-btn-live-product" data-parent-id="' + r.parent_id + '" data-product-id="' + escHtml(r.feed_product_id !== '—' ? r.feed_product_id : '') + '" data-sku="' + escHtml(r.feed_sku !== '—' ? r.feed_sku : r.woo_sku) + '">🔍 بررسی زنده شقفی</button>';
            actBtns += '</div>';

            var tr = '<tr data-row-id="' + escHtml(r.row_id) + '" data-parent-id="' + r.parent_id + '" data-variation-id="' + r.variation_id + '" data-action="' + escHtml(r.action) + '" data-feed-vid="' + escHtml(r.feed_variant_id) + '" data-feed-pid="' + escHtml(r.feed_product_id) + '">' +
                '<td><input type="checkbox" class="shaghafi-row-check" value="' + escHtml(r.row_id) + '" /></td>' +
                '<td>' +
                '<strong><a href="' + escHtml(r.edit_url || '#') + '" target="_blank">' + escHtml(r.product_title) + '</a></strong>' +
                '<div style="font-size:11px;color:#64748b;margin-top:4px;"><span dir="ltr">Parent #' + r.parent_id + (r.variation_id ? ' | Variation #' + r.variation_id : '') + '</span> ' + shaghafiLink + '</div>' +
                '</td>' +
                '<td><span class="shaghafi-badge ' + badgeClass + '">' + escHtml(r.action) + '</span><div style="font-size:11px;margin-top:4px;color:#475569;">' + escHtml(r.status_label) + '</div></td>' +
                '<td>' + imgHtml + '</td>' +
                '<td>' + skuHtml + '</td>' +
                '<td>' + attrHtml + '</td>' +
                '<td>' + stockHtml + '</td>' +
                '<td>' + priceHtml + '</td>' +
                '<td>' + idsHtml + '</td>' +
                '<td>' + issuesHtml + '</td>' +
                '<td>' + actBtns + '</td>' +
                '</tr>';

            $tbody.append(tr);
        });
    }

    function loadCatalogPage(page, forceRescan) {
        currentQueuePage = page || 1;
        var $btn = $('#shaghafi-btn-scan');
        $btn.prop('disabled', true).text('⏳ در حال بارگذاری...');

        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_scan_catalog',
            nonce: ShaghafiSyncData.nonce,
            search: $('#shaghafi-scan-search').val(),
            action_filter: $('#shaghafi-action-filter').val(),
            page: currentQueuePage,
            per_page: $('#shaghafi-per-page').val() || 50,
            force_rescan: forceRescan ? 1 : 0
        }, function (res) {
            $btn.prop('disabled', false).text('🔍 اعمال فیلتر / نمایش');
            if (res && res.success) {
                updateKpiCounts(res.data.counts);
                renderQueueTable(res.data.rows);
                renderPaginationBar('#shaghafi-pagination-top', res.data.pagination, 'js-queue-page-btn');
                renderPaginationBar('#shaghafi-pagination-bottom', res.data.pagination, 'js-queue-page-btn');
            } else {
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در اسکن محصولات.', true);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('🔍 اعمال فیلتر / نمایش');
            showToast('خطا در ارتباط با سرور وردپرس.', true);
        });
    }

    function replaceParentRowsInQueue(parentId, updatedRows) {
        if (!updatedRows) return;
        var activeFilter = $('#shaghafi-action-filter').val() || 'ALL';
        var filteredUpdates = [];
        updatedRows.forEach(function (ur) {
            if (activeFilter === 'ALL') {
                filteredUpdates.push(ur);
            } else if (activeFilter === 'ISSUES_ONLY') {
                if (ur.action !== 'HEALTHY') {
                    filteredUpdates.push(ur);
                }
            } else if (ur.action === activeFilter) {
                filteredUpdates.push(ur);
            }
        });

        var newQueue = [];
        var inserted = false;
        currentQueueRows.forEach(function (r) {
            if (parseInt(r.parent_id, 10) === parseInt(parentId, 10)) {
                if (!inserted) {
                    filteredUpdates.forEach(function (ur) {
                        newQueue.push(ur);
                    });
                    inserted = true;
                }
            } else {
                newQueue.push(r);
            }
        });
        if (!inserted && filteredUpdates.length) {
            filteredUpdates.forEach(function (ur) {
                newQueue.push(ur);
            });
        }
        renderQueueTable(newQueue);
    }

    // =========================================================================
    // OBSOLETE / REMOVED FROM SHAGHAFI PRODUCTS TAB
    // =========================================================================
    function renderObsoleteTable(items) {
        var $tbody = $('#shaghafi-obsolete-tbody');
        $tbody.empty();
        $('#shaghafi-obs-select-all').prop('checked', false);

        if (!items || items.length === 0) {
            $tbody.html('<tr><td colspan="9" style="text-align:center;padding:28px;">هیچ محصول یا وریشن منقضی/حذف‌شده‌ای یافت نشد.</td></tr>');
            return;
        }

        items.forEach(function (it) {
            var badgeClass = 'shaghafi-badge-' + String(it.obsolete_type || '').toLowerCase();
            var imgHtml = it.image_url
                ? '<a href="' + escHtml(it.image_url) + '" target="_blank"><img src="' + escHtml(it.image_url) + '" class="shaghafi-img-preview-box" /></a>'
                : '<span style="display:inline-block;width:49px;height:56px;background:#f1f5f9;color:#64748b;font-size:10px;line-height:56px;text-align:center;border-radius:5px;">بدون عکس</span>';

            var tr = '<tr data-parent-id="' + it.parent_id + '" data-variation-id="' + it.variation_id + '">' +
                '<td><input type="checkbox" class="shaghafi-obs-check" data-parent-id="' + it.parent_id + '" data-variation-id="' + it.variation_id + '" /></td>' +
                '<td>' + imgHtml + '</td>' +
                '<td>' +
                '<strong><a href="' + escHtml(it.edit_url || '#') + '" target="_blank">' + escHtml(it.product_title) + '</a></strong>' +
                '<div style="font-size:11px;color:#64748b;margin-top:4px;"><span dir="ltr">Parent #' + it.parent_id + (it.variation_id ? ' | Variation #' + it.variation_id : ' (' + it.variations_cnt + ' وریشن)') + '</span> | <a href="' + escHtml(it.view_url || '#') + '" target="_blank">نمایش در سایت ↗</a></div>' +
                '</td>' +
                '<td><span class="shaghafi-badge ' + badgeClass + '">' + escHtml(it.type_label) + '</span></td>' +
                '<td style="direction:ltr;text-align:left;font-family:monospace;font-weight:700;">' + escHtml(it.woo_sku) + '</td>' +
                '<td><code>' + escHtml(it.post_status) + '</code></td>' +
                '<td>سایت: <strong>' + it.woo_stock + '</strong> | شقفی: <strong style="color:#dc2626;">' + it.feed_stock + '</strong></td>' +
                '<td style="color:#b91c1c;font-size:12px;">' + escHtml(it.reason) + '</td>' +
                '<td>' +
                '<div class="shaghafi-action-stack">' +
                '<button type="button" class="button button-primary button-small shaghafi-btn-obs-action" style="background:#dc2626;border-color:#b91c1c;" data-parent-id="' + it.parent_id + '" data-variation-id="' + it.variation_id + '" data-mode="trash">🗑️ انتقال به زباله‌دان (حذف از سایت)</button>' +
                '<button type="button" class="button button-small shaghafi-btn-obs-action" data-parent-id="' + it.parent_id + '" data-variation-id="' + it.variation_id + '" data-mode="draft_zero">📦 پیش‌نویس + موجودی صفر</button>' +
                '</div>' +
                '</td>' +
                '</tr>';

            $tbody.append(tr);
        });
    }

    function loadObsoletePage(page, forceRescan) {
        currentObsPage = page || 1;
        var $btn = $('#shaghafi-btn-scan-obsolete');
        $btn.prop('disabled', true).text('⏳ در حال بررسی محصولات منقضی...');

        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_scan_obsolete',
            nonce: ShaghafiSyncData.nonce,
            search: $('#shaghafi-obs-search').val(),
            type_filter: $('#shaghafi-obs-type-filter').val(),
            page: currentObsPage,
            per_page: $('#shaghafi-obs-per-page').val() || 50,
            force_rescan: forceRescan ? 1 : 0
        }, function (res) {
            $btn.prop('disabled', false).text('🔍 جستجو و فیلتر لیست منقضی‌ها');
            if (res && res.success) {
                obsLoadedOnce = true;
                var c = res.data.counts || {};
                $('#obs-kpi-total').text(c.total || 0);
                $('#obs-kpi-removed').text(c.REMOVED_FROM_FEED || 0);
                $('#obs-kpi-zerostock').text(c.ALL_ZERO_STOCK_IN_FEED || 0);
                $('#obs-kpi-orphan').text(c.ORPHAN_VARIATION || 0);
                renderObsoleteTable(res.data.items);
                renderPaginationBar('#shaghafi-obs-pagination-top', res.data.pagination, 'js-obs-page-btn');
                renderPaginationBar('#shaghafi-obs-pagination-bottom', res.data.pagination, 'js-obs-page-btn');
            } else {
                showToast('خطا در بررسی محصولات منقضی.', true);
            }
        });
    }

    // Tab Navigation
    $(document).on('click', '.shaghafi-tab-btn', function () {
        var target = $(this).data('tab');
        $('.shaghafi-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.shaghafi-tab-panel').hide().removeClass('active');
        $('#' + target).show().addClass('active');

        if (target === 'tab-obsolete' && !obsLoadedOnce) {
            loadObsoletePage(1, false);
        }
    });

    // Queue Pagination Clicks
    $(document).on('click', '.js-queue-page-btn', function () {
        var p = parseInt($(this).data('page'), 10);
        if (p > 0) {
            loadCatalogPage(p, false);
        }
    });

    // Obsolete Pagination Clicks
    $(document).on('click', '.js-obs-page-btn', function () {
        var p = parseInt($(this).data('page'), 10);
        if (p > 0) {
            loadObsoletePage(p, false);
        }
    });

    // KPI Card Click Filter (Queue)
    $(document).on('click', '.shaghafi-kpi-card:not(.obs-kpi-card)', function () {
        var filterVal = $(this).data('filter');
        if (filterVal) {
            $('#shaghafi-action-filter').val(filterVal);
            loadCatalogPage(1, false);
        }
    });

    // KPI Card Click Filter (Obsolete)
    $(document).on('click', '.obs-kpi-card', function () {
        var filterVal = $(this).data('obs-filter');
        if (filterVal) {
            $('#shaghafi-obs-type-filter').val(filterVal);
            loadObsoletePage(1, false);
        }
    });

    // Select All Checkboxes
    $(document).on('change', '#shaghafi-select-all', function () {
        $('.shaghafi-row-check').prop('checked', $(this).is(':checked'));
    });

    $(document).on('change', '#shaghafi-obs-select-all', function () {
        $('.shaghafi-obs-check').prop('checked', $(this).is(':checked'));
    });

    // Filter / Scan Buttons
    $(document).on('click', '#shaghafi-btn-scan', function () {
        loadCatalogPage(1, false);
    });

    $(document).on('click', '#shaghafi-btn-force-rescan', function () {
        loadCatalogPage(1, true);
    });

    $(document).on('change', '#shaghafi-action-filter, #shaghafi-per-page', function () {
        loadCatalogPage(1, false);
    });

    $(document).on('click', '#shaghafi-btn-scan-obsolete', function () {
        loadObsoletePage(1, false);
    });

    $(document).on('click', '#shaghafi-btn-rescan-obsolete', function () {
        loadObsoletePage(1, true);
    });

    $(document).on('change', '#shaghafi-obs-type-filter, #shaghafi-obs-per-page', function () {
        loadObsoletePage(1, false);
    });

    // Single Obsolete Item Action (Trash / Draft)
    $(document).on('click', '.shaghafi-btn-obs-action', function () {
        var $btn = $(this);
        var parentId = $btn.data('parent-id');
        var variationId = $btn.data('variation-id') || 0;
        var mode = $btn.data('mode') || 'trash';

        $btn.prop('disabled', true).text('⏳ در حال انجام...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_handle_obsolete_item',
            nonce: ShaghafiSyncData.nonce,
            parent_id: parentId,
            variation_id: variationId,
            mode: mode
        }, function (res) {
            if (res && res.success) {
                showToast(res.data.message);
                $btn.closest('tr').fadeOut(250, function () {
                    $(this).remove();
                });
            } else {
                $btn.prop('disabled', false).text('تلاش مجدد');
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در عملیات.', true);
            }
        });
    });

    // Bulk Obsolete Actions (Trash / Draft)
    function runBulkObsolete(mode) {
        var tasks = [];
        $('.shaghafi-obs-check:checked').each(function () {
            tasks.push({
                parent_id: $(this).data('parent-id'),
                variation_id: $(this).data('variation-id') || 0
            });
        });

        if (tasks.length === 0) {
            showToast('لطفاً ابتدا موارد مورد نظر را تیک بزنید.', true);
            return;
        }

        var idx = 0;
        function nextObs() {
            if (idx >= tasks.length) {
                showToast('عملیات دسته‌جمعی روی ' + tasks.length + ' مورد انجام شد.');
                loadObsoletePage(currentObsPage, true);
                return;
            }
            var t = tasks[idx];
            $.post(ShaghafiSyncData.ajax_url, {
                action: 'shaghafi_handle_obsolete_item',
                nonce: ShaghafiSyncData.nonce,
                parent_id: t.parent_id,
                variation_id: t.variation_id,
                mode: mode
            }).always(function () {
                idx++;
                nextObs();
            });
        }
        nextObs();
    }

    $(document).on('click', '#shaghafi-btn-bulk-obs-trash', function () {
        runBulkObsolete('trash');
    });

    $(document).on('click', '#shaghafi-btn-bulk-obs-draft', function () {
        runBulkObsolete('draft_zero');
    });

    // Single Product Live Check
    $(document).on('click', '.shaghafi-btn-live-product', function () {
        var $btn = $(this);
        var origText = $btn.text();
        var parentId = $btn.data('parent-id') || 0;
        var productId = $btn.data('product-id') || '';
        var sku = $btn.data('sku') || '';

        $btn.prop('disabled', true).text('⏳ بررسی زنده...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_live_check_product',
            nonce: ShaghafiSyncData.nonce,
            parent_id: parentId,
            product_id: productId,
            sku: sku
        }, function (res) {
            $btn.prop('disabled', false).text(origText);
            if (res && res.success) {
                showToast(res.data.message);
                if (res.data.updated_rows && $('#shaghafi-queue-tbody').length) {
                    replaceParentRowsInQueue(parentId, res.data.updated_rows);
                }
                if (res.data.metabox_html && $('.shaghafi-metabox-results').length) {
                    $('.shaghafi-metabox-results').html(res.data.metabox_html);
                }
                if (res.data.swatches_ui) {
                    syncWcBoostSwatchesPanel(res.data.swatches_ui);
                }
                if (res.data.featured_image_ui) {
                    syncFeaturedImagePanel(res.data.featured_image_ui);
                }
                if (res.data.variations_attr_ui) {
                    syncVariationsAndAttributesPanel(res.data.variations_attr_ui);
                }
            } else {
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در بررسی زنده از شقفی.', true);
            }
        });
    });

    // Live-sync the WordPress Featured Image (#postimagediv) box on post.php when repaired from Product Gallery
    function syncFeaturedImagePanel(featuredImageUi) {
        if (!featuredImageUi || typeof featuredImageUi !== 'object') {
            return;
        }
        var $inside = $('#postimagediv .inside');
        if ($inside.length && featuredImageUi.html) {
            $inside.html(featuredImageUi.html);
        } else if (featuredImageUi.url && $('#set-post-thumbnail img').length) {
            $('#set-post-thumbnail img').attr('src', featuredImageUi.url).removeAttr('srcset');
            if (featuredImageUi.attachment_id) {
                $('#_thumbnail_id').val(featuredImageUi.attachment_id);
            }
        }
    }

    // Live-sync WooCommerce #product_attributes (ویژگی‌ها) and #variable_product_options (متغیرها) tabs on post.php
    // so variation dropdowns immediately show 1, 2, 3, 4... instead of "هر Variante:" and saving post.php never overwrites them!
    function syncVariationsAndAttributesPanel(attrUi) {
        if (!attrUi || typeof attrUi !== 'object') {
            return;
        }

        // 1. Update #product_attributes (ویژگی‌ها tab) for custom attributes
        var $attrContainer = $('#product_attributes');
        if ($attrContainer.length && !attrUi.is_taxonomy) {
            $attrContainer.find('.woocommerce_attribute').each(function () {
                var $box = $(this);
                var $varChk = $box.find('input[name^="attribute_variation"]');
                if ($varChk.length && $varChk.is(':checked')) {
                    if (attrUi.attr_name) {
                        $box.find('input[name^="attribute_names"]').val(attrUi.attr_name);
                        $box.find('strong.attribute_name').text(attrUi.attr_name);
                    }
                    if (attrUi.pipe_values) {
                        $box.find('textarea[name^="attribute_values"]').val(attrUi.pipe_values);
                    }
                }
            });
        }

        // 2. Update #variable_product_options (متغیرها tab) dropdowns
        var $varOptions = $('#variable_product_options');
        if (!$varOptions.length) {
            return;
        }

        var optionsList = Array.isArray(attrUi.options) ? attrUi.options : [];
        var variationsMap = (attrUi.variations && typeof attrUi.variations === 'object') ? attrUi.variations : {};
        var detailsMap = (attrUi.variation_details && typeof attrUi.variation_details === 'object') ? attrUi.variation_details : {};

        // Ensure all options exist in default_attribute_* and attribute_* selects
        $varOptions.find('select[name^="default_attribute_"], select[name^="attribute_"]').each(function () {
            var $sel = $(this);
            var existingVals = {};
            $sel.find('option').each(function () {
                existingVals[String($(this).attr('value') || '')] = true;
            });
            optionsList.forEach(function (opt) {
                if (opt && opt.value !== undefined && !existingVals[String(opt.value)]) {
                    $('<option></option>')
                        .attr('value', String(opt.value))
                        .text(String(opt.label || opt.value))
                        .appendTo($sel);
                    existingVals[String(opt.value)] = true;
                }
            });
        });

        // Set each variation's selected attribute value, price, SKU, stock, and thumbnail image in the DOM
        $varOptions.find('.woocommerce_variation').each(function () {
            var $vBox = $(this);
            var vid = parseInt($vBox.find('input.variable_post_id, input[name^="variable_post_id"]').val(), 10);
            if (!vid) {
                var hText = $vBox.find('h3 strong').text() || '';
                var m = hText.match(/#(\d+)/);
                if (m && m[1]) {
                    vid = parseInt(m[1], 10);
                }
            }
            if (vid && variationsMap[vid] !== undefined && variationsMap[vid] !== '') {
                var targetVal = String(variationsMap[vid]);
                var $sel = $vBox.find('select[name^="attribute_"]').first();
                if ($sel.length) {
                    if (!$sel.find('option[value="' + targetVal.replace(/"/g, '\\"') + '"]').length) {
                        $('<option></option>').attr('value', targetVal).text(targetVal).appendTo($sel);
                    }
                    $sel.val(targetVal);
                    // Update name attribute to canonical lowercase key if it had trailing colons or uppercase
                    if (attrUi.attr_key) {
                        var curName = String($sel.attr('name') || '');
                        var idxMatch = curName.match(/\[(\d+)\]/);
                        if (idxMatch && idxMatch[1] !== undefined) {
                            $sel.attr('name', 'attribute_' + attrUi.attr_key + '[' + idxMatch[1] + ']');
                        }
                    }
                }
            }
            if (vid && detailsMap[vid] && typeof detailsMap[vid] === 'object') {
                var det = detailsMap[vid];
                if (det.regular_price !== undefined && det.regular_price !== '') {
                    $vBox.find('input[name^="variable_regular_price"]').val(String(det.regular_price));
                }
                if (det.sku !== undefined && det.sku !== '') {
                    $vBox.find('input[name^="variable_sku"]').val(String(det.sku));
                }
                if (det.stock !== undefined && det.stock !== '') {
                    $vBox.find('input[name^="variable_stock"]').val(String(det.stock));
                }
                if (det.thumb_id && parseInt(det.thumb_id, 10) > 0) {
                    $vBox.find('input.upload_image_id, input[name^="upload_image_id"]').val(String(det.thumb_id));
                    if (det.thumb_url) {
                        var $imgBtn = $vBox.find('.upload_image_button');
                        $imgBtn.addClass('remove');
                        $imgBtn.find('img').attr('src', String(det.thumb_url)).removeAttr('srcset');
                    }
                }
            }
        });
    }

    // Live-sync the WCBoost Variation Swatches panel on post.php so Type=Image and swatch images appear immediately without reload
    function syncWcBoostSwatchesPanel(swatchesUi) {
        if (!swatchesUi || typeof swatchesUi !== 'object') {
            return;
        }
        var $panel = $('#wcboost_variation_swatches_data');
        if (!$panel.length) {
            return;
        }
        $.each(swatchesUi, function (attrSlug, cfg) {
            if (!cfg) return;
            var $typeSel = $panel.find('select[name="wcboost_variation_swatches[' + attrSlug + '][type]"]');
            if ($typeSel.length && cfg.type) {
                $typeSel.val(cfg.type).trigger('change');
            }
            if (cfg.swatches && typeof cfg.swatches === 'object') {
                $.each(cfg.swatches, function (optKey, sw) {
                    if (!sw || !sw.image_id) return;
                    var $inp = $panel.find('input[name="wcboost_variation_swatches[' + attrSlug + '][swatches][' + optKey + '][image]"]');
                    if ($inp.length) {
                        $inp.val(sw.image_id);
                        var $field = $inp.closest('.wcboost-variation-swatches__field-image');
                        $field.removeClass('is-empty');
                        if (sw.image_url) {
                            $field.find('img').attr('src', sw.image_url);
                        }
                        $field.find('.button-remove-image').removeClass('hidden');
                    }
                });
            }
        });
    }

    // Single Row Repair
    $(document).on('click', '.shaghafi-btn-repair-row', function () {
        var $btn = $(this);
        var origText = $btn.text();
        var parentId = $btn.data('parent-id');
        var variationId = $btn.data('variation-id');
        var feedVid = $btn.data('feed-vid');

        $btn.prop('disabled', true).text('⏳ در حال اصلاح...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_repair_single',
            nonce: ShaghafiSyncData.nonce,
            parent_id: parentId,
            variation_id: variationId,
            feed_vid: feedVid
        }, function (res) {
            $btn.prop('disabled', false).text(origText);
            if (res && res.success) {
                showToast(res.data.message);
                if (res.data.updated_rows && $('#shaghafi-queue-tbody').length) {
                    replaceParentRowsInQueue(parentId, res.data.updated_rows);
                }
                if (res.data.metabox_html && $('.shaghafi-metabox-results').length) {
                    $('.shaghafi-metabox-results').html(res.data.metabox_html);
                }
                if (res.data.swatches_ui) {
                    syncWcBoostSwatchesPanel(res.data.swatches_ui);
                }
                if (res.data.featured_image_ui) {
                    syncFeaturedImagePanel(res.data.featured_image_ui);
                }
                if (res.data.variations_attr_ui) {
                    syncVariationsAndAttributesPanel(res.data.variations_attr_ui);
                }
            } else {
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در تعمیر وریشن.', true);
            }
        });
    });

    // Force 700x800 Uncropped Image Sideload / Resize
    $(document).on('click', '.shaghafi-btn-force-image', function () {
        var $btn = $(this);
        var origText = $btn.text();
        var parentId = $btn.data('parent-id');
        var variationId = $btn.data('variation-id');
        var feedVid = $btn.data('feed-vid');

        $btn.prop('disabled', true).text('⏳ تنظیم عکس 700×800 بدون برش...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_repair_single',
            nonce: ShaghafiSyncData.nonce,
            parent_id: parentId,
            variation_id: variationId,
            feed_vid: feedVid,
            force_image: 1,
            fetch_live: 1
        }, function (res) {
            $btn.prop('disabled', false).text(origText);
            if (res && res.success) {
                showToast('عکس وریشن با ابعاد 700×800 بدون برش تنظیم شد.');
                if (res.data.updated_rows && $('#shaghafi-queue-tbody').length) {
                    replaceParentRowsInQueue(parentId, res.data.updated_rows);
                }
                if (res.data.metabox_html && $('.shaghafi-metabox-results').length) {
                    $('.shaghafi-metabox-results').html(res.data.metabox_html);
                }
                if (res.data.swatches_ui) {
                    syncWcBoostSwatchesPanel(res.data.swatches_ui);
                }
                if (res.data.featured_image_ui) {
                    syncFeaturedImagePanel(res.data.featured_image_ui);
                }
                if (res.data.variations_attr_ui) {
                    syncVariationsAndAttributesPanel(res.data.variations_attr_ui);
                }
            } else {
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در دریافت عکس.', true);
            }
        });
    });

    // Manual Review Link & Repair
    $(document).on('click', '.shaghafi-btn-manual-link', function () {
        var $btn = $(this);
        var $select = $btn.siblings('.shaghafi-manual-select');
        var feedVid = $select.val();
        if (!feedVid) {
            showToast('لطفاً ابتدا وریشن مرجع شقفی را از لیست کشویی انتخاب کنید.', true);
            return;
        }
        var parentId = $btn.data('parent-id');
        var variationId = $btn.data('variation-id');

        $btn.prop('disabled', true).text('⏳ در حال اتصال...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_repair_single',
            nonce: ShaghafiSyncData.nonce,
            parent_id: parentId,
            variation_id: variationId,
            feed_vid: feedVid
        }, function (res) {
            $btn.prop('disabled', false).text('🔗 اتصال و اصلاح');
            if (res && res.success) {
                showToast(res.data.message);
                if (res.data.updated_rows && $('#shaghafi-queue-tbody').length) {
                    replaceParentRowsInQueue(parentId, res.data.updated_rows);
                }
                if (res.data.metabox_html && $('.shaghafi-metabox-results').length) {
                    $('.shaghafi-metabox-results').html(res.data.metabox_html);
                }
                if (res.data.swatches_ui) {
                    syncWcBoostSwatchesPanel(res.data.swatches_ui);
                }
                if (res.data.featured_image_ui) {
                    syncFeaturedImagePanel(res.data.featured_image_ui);
                }
                if (res.data.variations_attr_ui) {
                    syncVariationsAndAttributesPanel(res.data.variations_attr_ui);
                }
            } else {
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در اتصال وریشن.', true);
            }
        });
    });

    // Create Missing Variation
    $(document).on('click', '.shaghafi-btn-create-var', function () {
        var $btn = $(this);
        var origText = $btn.text();
        var parentId = $btn.data('parent-id');
        var feedVid = $btn.data('feed-vid');

        $btn.prop('disabled', true).text('⏳ ساخت وریشن...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_create_variation',
            nonce: ShaghafiSyncData.nonce,
            parent_id: parentId,
            feed_vid: feedVid
        }, function (res) {
            $btn.prop('disabled', false).text(origText);
            if (res && res.success) {
                showToast(res.data.message);
                if (res.data.updated_rows && $('#shaghafi-queue-tbody').length) {
                    replaceParentRowsInQueue(parentId, res.data.updated_rows);
                }
                if (res.data.metabox_html && $('.shaghafi-metabox-results').length) {
                    $('.shaghafi-metabox-results').html(res.data.metabox_html);
                }
                if (res.data.swatches_ui) {
                    syncWcBoostSwatchesPanel(res.data.swatches_ui);
                }
                if (res.data.featured_image_ui) {
                    syncFeaturedImagePanel(res.data.featured_image_ui);
                }
                if (res.data.variations_attr_ui) {
                    syncVariationsAndAttributesPanel(res.data.variations_attr_ui);
                }
            } else {
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در افزودن وریشن.', true);
            }
        });
    });

    // Permanently Delete Missing / Removed Variation
    $(document).on('click', '.shaghafi-btn-disable-var', function () {
        var $btn = $(this);
        var variationId = parseInt($btn.data('variation-id'), 10) || 0;
        var parentId = parseInt($btn.data('parent-id') || $btn.closest('tr').data('parent-id'), 10) || 0;
        $btn.prop('disabled', true).text('⏳ در حال حذف کامل...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_disable_variation',
            nonce: ShaghafiSyncData.nonce,
            variation_id: variationId,
            parent_id: parentId
        }, function (res) {
            $btn.prop('disabled', false).text('🗑️ حذف کامل و دائمی');
            if (res && res.success) {
                showToast(res.data.message);
                var deletedVids = (res.data.result && res.data.result.deleted_vids && res.data.result.deleted_vids.length)
                    ? res.data.result.deleted_vids
                    : [variationId];

                // Remove deleted row(s) from currentQueueRows and fade out smoothly without destroying in-flight clicks
                currentQueueRows = currentQueueRows.filter(function (r) {
                    return deletedVids.indexOf(parseInt(r.variation_id, 10)) === -1;
                });
                $btn.closest('tr').fadeOut(200, function () {
                    $(this).remove();
                });
                deletedVids.forEach(function (dv) {
                    $('#shaghafi-queue-tbody tr[data-variation-id="' + dv + '"]').fadeOut(200, function () {
                        $(this).remove();
                    });
                });

                var curDisableCnt = parseInt($('#kpi-disable-count').text(), 10);
                if (!isNaN(curDisableCnt) && curDisableCnt > 0) {
                    $('#kpi-disable-count').text(Math.max(0, curDisableCnt - 1));
                }

                if (res.data.metabox_html && $('.shaghafi-metabox-results').length) {
                    $('.shaghafi-metabox-results').html(res.data.metabox_html);
                }
                if (res.data.swatches_ui) {
                    syncProductEditSwatchesDOM(res.data.swatches_ui);
                }
                if (res.data.variations_attr_ui) {
                    syncProductEditVariationAttributesDOM(res.data.variations_attr_ui);
                }
            } else {
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در حذف وریشن.', true);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('🗑️ حذف کامل و دائمی');
            showToast('خطای ارتباط با سرور در حذف وریشن.', true);
        });
    });

    // Helper: Read Master Sync Checkboxes & Schedule
    function getMasterSyncPrefs() {
        return {
            sync_download_feed: $('#ms-download-feed').is(':checked') ? 1 : 0,
            sync_stock: $('#ms-stock').is(':checked') ? 1 : 0,
            sync_sku_meta: $('#ms-sku-meta').is(':checked') ? 1 : 0,
            sync_attributes_swatches: $('#ms-attrs-swatches').is(':checked') ? 1 : 0,
            sync_create_missing_variations: $('#ms-create-vars').is(':checked') ? 1 : 0,
            sync_delete_obsolete_variations: $('#ms-delete-vars').is(':checked') ? 1 : 0,
            sync_images: $('#ms-images').is(':checked') ? 1 : 0,
            sync_retail_prices: $('#ms-prices').is(':checked') ? 1 : 0,
            sync_remove_obsolete_products: $('#ms-obsolete-prods').is(':checked') ? 1 : 0,
            cron_schedule_interval: $('#ms-cron-interval').val() || 'daily'
        };
    }

    // Save Master Sync Checkboxes & Schedule Preferences
    $(document).on('click', '#shaghafi-btn-save-master-prefs', function () {
        var $btn = $(this);
        var origText = $btn.text();
        var prefs = getMasterSyncPrefs();
        prefs.action = 'shaghafi_save_master_sync_prefs';
        prefs.nonce = ShaghafiSyncData.nonce;

        $btn.prop('disabled', true).text('⏳ ذخیره...');
        $.post(ShaghafiSyncData.ajax_url, prefs, function (res) {
            $btn.prop('disabled', false).text(origText);
            if (res && res.success) {
                showToast(res.data.message);
                if (res.data.next_run_text) {
                    $('#shaghafi-master-next-cron').text(res.data.next_run_text);
                }
            } else {
                showToast('خطا در ذخیره تنظیمات تیک‌ها و زمان‌بندی.', true);
            }
        });
    });

    // Run Server Cron Immediately
    $(document).on('click', '#shaghafi-btn-run-cron-now', function () {
        var $btn = $(this);
        var origText = $btn.text();
        var prefs = getMasterSyncPrefs();
        prefs.action = 'shaghafi_save_master_sync_prefs';
        prefs.nonce = ShaghafiSyncData.nonce;

        $btn.prop('disabled', true).text('⏳ در حال اجرای سینک پس‌زمینه...');
        $.post(ShaghafiSyncData.ajax_url, prefs).always(function () {
            $.post(ShaghafiSyncData.ajax_url, {
                action: 'shaghafi_run_cron_now',
                nonce: ShaghafiSyncData.nonce
            }, function (res) {
                $btn.prop('disabled', false).text(origText);
                if (res && res.success) {
                    showToast(res.data.message || 'سینک خودکار با موفقیت انجام شد.');
                    loadCatalogPage(1, true);
                } else {
                    showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در اجرای سینک پس‌زمینه.', true);
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(origText);
                showToast('خطا در ارتباط با سرور.', true);
            });
        });
    });

    // 1-CLICK MASTER SYNC & REPAIR ALL PRODUCTS (ACCORDING TO CHECKED BOXES)
    $(document).on('click', '#shaghafi-btn-master-sync-all', function () {
        var $btn = $(this);
        var origText = $btn.text();
        var prefs = getMasterSyncPrefs();

        $btn.prop('disabled', true).text('⏳ در حال اجرای مرحله‌به‌مرحله تعمیر کل محصولات...');
        updateProgress('💾 مرحله ۱: ذخیره تیک‌های انتخابی و تنظیمات زمان‌بندی...', 1, 10);

        var savePayload = $.extend({}, prefs, {
            action: 'shaghafi_save_master_sync_prefs',
            nonce: ShaghafiSyncData.nonce
        });

        $.post(ShaghafiSyncData.ajax_url, savePayload).always(function (saveRes) {
            if (saveRes && saveRes.success && saveRes.data && saveRes.data.next_run_text) {
                $('#shaghafi-master-next-cron').text(saveRes.data.next_run_text);
            }

            function stepDownloadFeed(nextStep) {
                if (!prefs.sync_download_feed) {
                    nextStep();
                    return;
                }
                updateProgress('📥 مرحله ۲: دریافت و تشخیص آخرین فایل مرجع likadeo.csv از شقفی و حذف کدهای منسوخ‌شده...', 2, 10);
                $.post(ShaghafiSyncData.ajax_url, {
                    action: 'shaghafi_fetch_remote_feed',
                    nonce: ShaghafiSyncData.nonce,
                    feed_url: $('#shaghafi-remote-feed-url').val() || ShaghafiSyncData.feed_url
                }, function (feedRes) {
                    if (feedRes && feedRes.success && feedRes.data && feedRes.data.stats) {
                        $('#shaghafi-stat-feed-rows').text(feedRes.data.stats.total_rows);
                        $('#shaghafi-stat-feed-date').text(feedRes.data.stats.imported_at);
                    }
                }).always(function () {
                    nextStep();
                });
            }

            function stepSyncAllStocks(nextStep) {
                if (!prefs.sync_stock) {
                    nextStep();
                    return;
                }
                updateProgress('📦 مرحله ۳: بروزرسانی سریع موجودی (bestand) تمام وریشن‌ها و محصولات متصل...', 3, 10);
                $.post(ShaghafiSyncData.ajax_url, {
                    action: 'shaghafi_sync_all_linked_stocks',
                    nonce: ShaghafiSyncData.nonce
                }).always(function () {
                    nextStep();
                });
            }

            function stepScanAndProcessCatalog(nextStep) {
                var wantAnyAction = (
                    prefs.sync_sku_meta ||
                    prefs.sync_attributes_swatches ||
                    prefs.sync_stock ||
                    prefs.sync_retail_prices ||
                    prefs.sync_images ||
                    prefs.sync_create_missing_variations ||
                    prefs.sync_delete_obsolete_variations
                );
                if (!wantAnyAction) {
                    nextStep();
                    return;
                }

                updateProgress('🔍 مرحله ۴: اسکن کامل محصولات سایت و استخراج صف کامل محصولات نیازمند بررسی زنده و تعمیر...', 4, 10);
                $.post(ShaghafiSyncData.ajax_url, {
                    action: 'shaghafi_get_master_sync_queue',
                    nonce: ShaghafiSyncData.nonce
                }, function (qRes) {
                    if (!qRes || !qRes.success || !qRes.data || !qRes.data.queue || qRes.data.queue.length === 0) {
                        nextStep();
                        return;
                    }
                    var queue = qRes.data.queue;
                    var idx = 0;

                    function runNextParentProduct() {
                        if (idx >= queue.length) {
                            nextStep();
                            return;
                        }
                        var pItem = queue[idx];
                        var labelTitle = pItem.product_title || ('#' + pItem.parent_id);

                        updateProgress(
                            '🚀 بررسی زنده خودکار + تعمیر، قیمت‌گذاری، عکس و همگام‌سازی کامل محصول (' + (idx + 1) + ' از ' + queue.length + '): ' + labelTitle,
                            idx + 1,
                            queue.length
                        );

                        $.post(ShaghafiSyncData.ajax_url, {
                            action: 'shaghafi_repair_product_all',
                            nonce: ShaghafiSyncData.nonce,
                            parent_id: pItem.parent_id,
                            sync_sku_meta: prefs.sync_sku_meta,
                            sync_attributes_swatches: prefs.sync_attributes_swatches,
                            sync_stock: prefs.sync_stock,
                            sync_retail_prices: prefs.sync_retail_prices,
                            sync_images: prefs.sync_images,
                            sync_create_missing_variations: prefs.sync_create_missing_variations,
                            sync_delete_obsolete_variations: prefs.sync_delete_obsolete_variations
                        }).always(function () {
                            idx++;
                            runNextParentProduct();
                        });
                    }
                    runNextParentProduct();
                }).fail(function () {
                    nextStep();
                });
            }

            function stepRemoveObsoleteProducts(nextStep) {
                if (!prefs.sync_remove_obsolete_products) {
                    nextStep();
                    return;
                }
                updateProgress('🚫 مرحله ۵: بررسی و انتقال محصولات کاملاً حذف‌شده از شقفی به زباله‌دان...', 9, 10);
                $.post(ShaghafiSyncData.ajax_url, {
                    action: 'shaghafi_scan_obsolete',
                    nonce: ShaghafiSyncData.nonce,
                    search: '',
                    type_filter: 'REMOVED_FROM_FEED',
                    page: 1,
                    per_page: 500,
                    force_rescan: 1
                }, function (obsRes) {
                    if (!obsRes || !obsRes.success || !obsRes.data || !obsRes.data.items || obsRes.data.items.length === 0) {
                        nextStep();
                        return;
                    }
                    var obsItems = obsRes.data.items;
                    var oIdx = 0;
                    function nextObsProd() {
                        if (oIdx >= obsItems.length) {
                            nextStep();
                            return;
                        }
                        var it = obsItems[oIdx];
                        updateProgress('🗑️ حذف محصول منسوخ (' + (oIdx + 1) + ' از ' + obsItems.length + '): ' + (it.product_title || ''), oIdx + 1, obsItems.length);
                        $.post(ShaghafiSyncData.ajax_url, {
                            action: 'shaghafi_handle_obsolete_item',
                            nonce: ShaghafiSyncData.nonce,
                            parent_id: it.parent_id,
                            variation_id: it.variation_id || 0,
                            mode: 'trash'
                        }).always(function () {
                            oIdx++;
                            nextObsProd();
                        });
                    }
                    nextObsProd();
                }).fail(function () {
                    nextStep();
                });
            }

            stepDownloadFeed(function () {
                stepSyncAllStocks(function () {
                    stepScanAndProcessCatalog(function () {
                        stepRemoveObsoleteProducts(function () {
                            updateProgress('', 0, 0);
                            $btn.prop('disabled', false).text(origText);
                            showToast('✅ بروزرسانی و تعمیر یک‌کلیکی کل محصولات طبق تیک‌های انتخابی با موفقیت کامل انجام شد!');
                            loadCatalogPage(1, true);
                        });
                    });
                });
            });
        });
    });

    // Repair All Variations of a Single Product (Metabox button)
    $(document).on('click', '.shaghafi-btn-repair-product-all', function () {
        var $btn = $(this);
        var origText = $btn.text();
        var parentId = $btn.data('parent-id');

        $btn.prop('disabled', true).text('⏳ در حال بررسی زنده و تعمیر تمام وریشن‌ها...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_repair_product_all',
            nonce: ShaghafiSyncData.nonce,
            parent_id: parentId
        }, function (res) {
            $btn.prop('disabled', false).text(origText);
            if (res && res.success) {
                showToast(res.data.message);
                if (res.data.metabox_html && $('.shaghafi-metabox-results').length) {
                    $('.shaghafi-metabox-results').html(res.data.metabox_html);
                }
                if (res.data.swatches_ui) {
                    syncWcBoostSwatchesPanel(res.data.swatches_ui);
                }
                if (res.data.featured_image_ui) {
                    syncFeaturedImagePanel(res.data.featured_image_ui);
                }
                if (res.data.variations_attr_ui) {
                    syncVariationsAndAttributesPanel(res.data.variations_attr_ui);
                }
            } else {
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در تعمیر کامل محصول.', true);
            }
        });
    });

    // Bulk Live Check
    $(document).on('click', '#shaghafi-btn-bulk-live', function () {
        var selectedParents = [];
        var seen = {};
        var $checked = $('.shaghafi-row-check:checked');
        var $targets = $checked.length > 0 ? $checked.closest('tr') : $('#shaghafi-queue-tbody tr[data-parent-id]');

        $targets.each(function () {
            var pid = $(this).data('parent-id');
            var shPid = $(this).data('feed-pid');
            if (pid && !seen[pid]) {
                seen[pid] = true;
                selectedParents.push({ parent_id: pid, product_id: shPid !== '—' ? shPid : '' });
            }
        });

        if (selectedParents.length === 0) {
            showToast('ابتدا لیست محصولات را بارگذاری کنید.', true);
            return;
        }

        var idx = 0;
        function nextLive() {
            if (idx >= selectedParents.length) {
                updateProgress('', 0, 0);
                showToast('بررسی زنده از Shaghafi.de برای ' + selectedParents.length + ' محصول کامل شد!');
                loadCatalogPage(currentQueuePage, false);
                return;
            }
            var item = selectedParents[idx];
            updateProgress('🌐 در حال بررسی زنده محصول #' + item.parent_id + ' از Shaghafi.de...', idx + 1, selectedParents.length);
            $.post(ShaghafiSyncData.ajax_url, {
                action: 'shaghafi_live_check_product',
                nonce: ShaghafiSyncData.nonce,
                parent_id: item.parent_id,
                product_id: item.product_id
            }).always(function () {
                idx++;
                nextLive();
            });
        }
        nextLive();
    });

    // Bulk Repair Selected Rows (including DISABLE_MISSING when selected)
    $(document).on('click', '#shaghafi-btn-bulk-repair', function () {
        var tasks = [];
        var $checked = $('.shaghafi-row-check:checked');
        var $rows = $checked.length > 0 ? $checked.closest('tr') : $('#shaghafi-queue-tbody tr[data-parent-id]');

        $rows.each(function () {
            var $tr = $(this);
            var act = $tr.data('action');
            var pid = $tr.data('parent-id');
            var vid = $tr.data('variation-id');
            var fvid = $tr.data('feed-vid');
            if ((act === 'REPAIR_AND_LINK' || act === 'KEEP_AND_SYNC') && fvid && fvid !== '—') {
                tasks.push({ type: 'repair', parent_id: pid, variation_id: vid, feed_vid: fvid });
            } else if (act === 'DISABLE_MISSING' && vid && parseInt(vid, 10) > 0) {
                tasks.push({ type: 'disable', parent_id: pid, variation_id: vid });
            }
        });

        if (tasks.length === 0) {
            showToast('هیچ ردیف نیازمند تعمیر یا حذفی در لیست انتخابی یافت نشد.', true);
            return;
        }

        var idx = 0;
        function nextRepair() {
            if (idx >= tasks.length) {
                updateProgress('', 0, 0);
                showToast('عملیات دسته‌جمعی روی ' + tasks.length + ' مورد با موفقیت به پایان رسید!');
                loadCatalogPage(currentQueuePage, false);
                return;
            }
            var t = tasks[idx];
            if (t.type === 'disable') {
                updateProgress('🗑️ در حال حذف دائمی وریشن منقضی (' + (idx + 1) + ' از ' + tasks.length + ')...', idx + 1, tasks.length);
                $.post(ShaghafiSyncData.ajax_url, {
                    action: 'shaghafi_disable_variation',
                    nonce: ShaghafiSyncData.nonce,
                    parent_id: t.parent_id,
                    variation_id: t.variation_id
                }).always(function () {
                    idx++;
                    nextRepair();
                });
            } else {
                updateProgress('🛠 در حال تعمیر و همگام‌سازی وریشن (' + (idx + 1) + ' از ' + tasks.length + ')...', idx + 1, tasks.length);
                $.post(ShaghafiSyncData.ajax_url, {
                    action: 'shaghafi_repair_single',
                    nonce: ShaghafiSyncData.nonce,
                    parent_id: t.parent_id,
                    variation_id: t.variation_id,
                    feed_vid: t.feed_vid
                }).always(function () {
                    idx++;
                    nextRepair();
                });
            }
        }
        nextRepair();
    });

    // Bulk Permanently Delete Missing / Expired Variations (DISABLE_MISSING)
    $(document).on('click', '#shaghafi-btn-bulk-disable', function () {
        var tasks = [];
        var $checked = $('.shaghafi-row-check:checked');
        var $rows = $checked.length > 0 ? $checked.closest('tr') : $('#shaghafi-queue-tbody tr[data-parent-id]');

        $rows.each(function () {
            var $tr = $(this);
            var act = $tr.data('action');
            var pid = $tr.data('parent-id');
            var vid = parseInt($tr.data('variation-id'), 10) || 0;
            if (act === 'DISABLE_MISSING' && vid > 0) {
                tasks.push({ parent_id: pid, variation_id: vid });
            }
        });

        if (tasks.length === 0) {
            showToast('هیچ وریشن حذف‌شده‌ای (DISABLE_MISSING) در انتخاب فعلی وجود ندارد.', true);
            return;
        }

        var idx = 0;
        function nextDisable() {
            if (idx >= tasks.length) {
                updateProgress('', 0, 0);
                showToast(tasks.length + ' وریشن منقضی به طور کامل و دائمی حذف شد!');
                loadCatalogPage(currentQueuePage, false);
                return;
            }
            var t = tasks[idx];
            updateProgress('🗑️ در حال حذف کامل و دائمی وریشن (' + (idx + 1) + ' از ' + tasks.length + ')...', idx + 1, tasks.length);
            $.post(ShaghafiSyncData.ajax_url, {
                action: 'shaghafi_disable_variation',
                nonce: ShaghafiSyncData.nonce,
                parent_id: t.parent_id,
                variation_id: t.variation_id
            }).always(function () {
                idx++;
                nextDisable();
            });
        }
        nextDisable();
    });

    // Bulk Create Missing Variations
    $(document).on('click', '#shaghafi-btn-bulk-create', function () {
        var tasks = [];
        var $checked = $('.shaghafi-row-check:checked');
        var $rows = $checked.length > 0 ? $checked.closest('tr') : $('#shaghafi-queue-tbody tr[data-parent-id]');

        $rows.each(function () {
            var $tr = $(this);
            if ($tr.data('action') === 'CREATE_NEW_VARIATION' && $tr.data('feed-vid')) {
                tasks.push({
                    parent_id: $tr.data('parent-id'),
                    feed_vid: $tr.data('feed-vid')
                });
            }
        });

        if (tasks.length === 0) {
            showToast('هیچ وریشن جاافتاده‌ای (CREATE_NEW_VARIATION) در انتخاب فعلی وجود ندارد.', true);
            return;
        }

        var idx = 0;
        function nextCreate() {
            if (idx >= tasks.length) {
                updateProgress('', 0, 0);
                showToast(tasks.length + ' وریشن جدید با موفقیت اضافه شد!');
                loadCatalogPage(currentQueuePage, false);
                return;
            }
            var t = tasks[idx];
            updateProgress('➕ در حال افزودن وریشن جدید (' + (idx + 1) + ' از ' + tasks.length + ')...', idx + 1, tasks.length);
            $.post(ShaghafiSyncData.ajax_url, {
                action: 'shaghafi_create_variation',
                nonce: ShaghafiSyncData.nonce,
                parent_id: t.parent_id,
                feed_vid: t.feed_vid
            }).always(function () {
                idx++;
                nextCreate();
            });
        }
        nextCreate();
    });

    // Fetch Remote Feed Directly
    $(document).on('click', '#shaghafi-btn-fetch-remote-feed', function () {
        var $btn = $(this);
        var origText = $btn.text();
        $btn.prop('disabled', true).text('⏳ در حال دانلود likadeo.csv از سرور شقفی و ایندکس دیتابیس...');

        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_fetch_remote_feed',
            nonce: ShaghafiSyncData.nonce,
            feed_url: $('#shaghafi-remote-feed-url').val()
        }, function (res) {
            $btn.prop('disabled', false).text(origText);
            if (res && res.success) {
                showToast(res.data.message);
                if (res.data.stats) {
                    $('#shaghafi-stat-feed-rows').text(res.data.stats.total_rows);
                    $('#shaghafi-stat-feed-date').text(res.data.stats.imported_at);
                }
            } else {
                showToast((res && res.data && res.data.message) ? res.data.message : 'خطا در دریافت فید روزانه.', true);
            }
        });
    });

    // Reload Bundled likadeo.csv
    $(document).on('click', '#shaghafi-btn-reload-bundled', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('⏳ در حال بارگذاری...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_import_bundled_feed',
            nonce: ShaghafiSyncData.nonce
        }, function (res) {
            $btn.prop('disabled', false).text('♻️ بارگذاری مجدد فایل مرجع داخلی پلاگین (21,759 ردیف)');
            if (res && res.success) {
                showToast(res.data.message);
                if (res.data.stats) {
                    $('#shaghafi-stat-feed-rows').text(res.data.stats.total_rows);
                    $('#shaghafi-stat-feed-date').text(res.data.stats.imported_at);
                }
            }
        });
    });

    // Sync All Linked Stocks
    $(document).on('click', '#shaghafi-btn-sync-linked-stocks', function () {
        var $btn = $(this);
        var origText = $btn.text();
        $btn.prop('disabled', true).text('⏳ در حال سینک موجودی...');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_sync_all_linked_stocks',
            nonce: ShaghafiSyncData.nonce
        }, function (res) {
            $btn.prop('disabled', false).text(origText);
            if (res && res.success) {
                showToast(res.data.message);
            }
        });
    });

    // Create Complete Product(s) from Shaghafi SKU / Product ID (Supports Single or Multi-SKU Step-by-Step Batch)
    $(document).on('click', '#shaghafi-btn-create-full-product', function () {
        var $btn = $(this);
        var rawInput = ($('#shaghafi-create-prod-input').val() || '').trim();
        if (!rawInput) {
            showToast('لطفاً یک یا چند SKU یا product_id شقفی را وارد کنید.', true);
            return;
        }

        // Split by newline, comma, Persian comma, semicolon, or whitespace and deduplicate by base SKU
        var rawTokens = rawInput.split(/[\r\n,،;\s]+/);
        var queries = [];
        var seenBase = {};
        rawTokens.forEach(function (tok) {
            var clean = tok.trim();
            if (!clean) {
                return;
            }
            var baseKey = clean.toUpperCase();
            if (!/^[A-F0-9]{32}$/i.test(clean) && clean.indexOf('-') !== -1) {
                baseKey = clean.split('-')[0].toUpperCase();
            }
            if (!seenBase[baseKey]) {
                seenBase[baseKey] = true;
                queries.push(clean);
            }
        });

        if (queries.length === 0) {
            showToast('هیچ کد معتبری یافت نشد.', true);
            return;
        }

        var origText = $btn.text();
        var refreshFeedFirst = $('#shaghafi-create-refresh-feed').is(':checked');
        var $resBox = $('#shaghafi-create-prod-result');
        $btn.prop('disabled', true);

        $resBox.html(
            '<div class="shaghafi-batch-create-log" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:12px;">' +
            '<div id="shaghafi-batch-create-header" style="font-weight:700;margin-bottom:8px;color:#1e293b;">⏳ آماده ساخت مرحله‌به‌مرحله ' + queries.length + ' محصول کامل...</div>' +
            '<ul id="shaghafi-batch-create-list" style="margin:0;padding-right:18px;line-height:1.9;"></ul>' +
            '</div>'
        );

        var idx = 0;
        var successCount = 0;
        var failCount = 0;

        function runNextProductCreate() {
            if (idx >= queries.length) {
                $btn.prop('disabled', false).text(origText);
                updateProgress('', 0, 0);
                $('#shaghafi-batch-create-header').html(
                    '✅ عملیات مرحله‌به‌مرحله به پایان رسید: <span style="color:#15803d;">' + successCount + ' محصول با موفقیت ساخته/تکمیل شد</span>' +
                    (failCount > 0 ? ' — <span style="color:#b91c1c;">' + failCount + ' مورد ناموفق</span>' : '')
                );
                showToast(successCount + ' محصول کامل با تمام جزئیات، قیمت، موجودی، توضیحات بلند و عکس 700×800 WebP ساخته شد!');
                return;
            }

            var currentQ = queries[idx];
            var stepNum = idx + 1;
            var statusMsg = '✨ مرحله ' + stepNum + ' از ' + queries.length + ': در حال بررسی زنده و ساخت کامل محصول «' + currentQ + '»...';
            $btn.text('⏳ مرحله ' + stepNum + ' از ' + queries.length + ' (' + currentQ + ')...');
            $('#shaghafi-batch-create-header').text(statusMsg);
            updateProgress(statusMsg, stepNum, queries.length);

            var $li = $('<li style="color:#475569;">⏳ <code>' + escHtml(currentQ) + '</code>: در حال دریافت نام، موجودی، قیمت خرده‌فروشی، توضیحات بلند و عکس‌های 700×800 WebP...</li>');
            $('#shaghafi-batch-create-list').append($li);

            $.post(ShaghafiSyncData.ajax_url, {
                action: 'shaghafi_create_complete_product',
                nonce: ShaghafiSyncData.nonce,
                query: currentQ
            }, function (res) {
                if (res && res.success && res.data && res.data.product) {
                    successCount++;
                    var p = res.data.product;
                    var badge = p.updated_existing
                        ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;font-size:11px;">بروزرسانی و تکمیل محصول موجود</span>'
                        : '<span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:4px;font-size:11px;">محصول جدید ساخته شد</span>';
                    var priceHtml = p.price ? ' — قیمت: <strong style="color:#7c3aed;">' + escHtml(p.price) + ' €</strong>' : '';
                    var catHtml = '';
                    if (p.categories && p.categories.term_names && p.categories.term_names.length) {
                        catHtml = ' — <span style="color:#0f766e;">📂 دسته: ' + escHtml(p.categories.term_names.join(' ← ')) + '</span>';
                    } else if (p.categories && p.categories.note) {
                        catHtml = ' — <span style="color:#b45309;">📂 ' + escHtml(p.categories.note) + '</span>';
                    }
                    $li.html(
                        '✅ <code>' + escHtml(currentQ) + '</code>: <strong>' + escHtml(p.title) + '</strong> ' + badge +
                        ' (' + escHtml(p.variations_made) + ' وریشن' + priceHtml + ')' + catHtml + ' — ' +
                        '<a href="' + escHtml(p.edit_url) + '" target="_blank" class="button button-small">ویرایش در ووکامرس ↗</a> ' +
                        (p.view_url ? '<a href="' + escHtml(p.view_url) + '" target="_blank" class="button button-small">مشاهده در سایت ↗</a>' : '')
                    );
                } else {
                    failCount++;
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : 'خطا در ساخت محصول.';
                    $li.html('❌ <code>' + escHtml(currentQ) + '</code>: <span style="color:#b91c1c;">' + escHtml(errMsg) + '</span>');
                }
            }).fail(function () {
                failCount++;
                $li.html('❌ <code>' + escHtml(currentQ) + '</code>: <span style="color:#b91c1c;">خطای ارتباط با سرور (Timeout).</span>');
            }).always(function () {
                idx++;
                runNextProductCreate();
            });
        }

        if (refreshFeedFirst) {
            $btn.text('⏳ در حال دانلود آخرین فید likadeo.csv از سرور شقفی...');
            updateProgress('⬇️ در حال دانلود آخرین فایل مرجع likadeo.csv از شقفی...', 1, queries.length + 1);
            $.post(ShaghafiSyncData.ajax_url, {
                action: 'shaghafi_fetch_remote_feed',
                nonce: ShaghafiSyncData.nonce,
                feed_url: $('#shaghafi-remote-feed-url').val() || ''
            }).always(function () {
                runNextProductCreate();
            });
        } else {
            runNextProductCreate();
        }
    });

    // 1-Click Create Product directly from Feed Search Row
    $(document).on('click', '.shaghafi-btn-create-from-row', function () {
        var sku = $(this).data('sku') || $(this).data('product-id') || '';
        if (!sku) {
            return;
        }
        $('#shaghafi-create-prod-input').val(sku);
        $('#shaghafi-btn-create-full-product').trigger('click');
        $('html, body').animate({ scrollTop: $('#shaghafi-create-prod-input').offset().top - 80 }, 300);
    });

    // Search Feed Explorer
    $(document).on('click', '#shaghafi-btn-search-feed', function () {
        var q = $('#shaghafi-feed-search-input').val();
        var $tbody = $('#shaghafi-feed-search-tbody');
        $tbody.html('<tr><td colspan="9" style="text-align:center;">⏳ در حال جستجو در فید...</td></tr>');
        $.post(ShaghafiSyncData.ajax_url, {
            action: 'shaghafi_search_feed',
            nonce: ShaghafiSyncData.nonce,
            query: q
        }, function (res) {
            $tbody.empty();
            if (!res || !res.success || !res.data.rows || res.data.rows.length === 0) {
                $tbody.html('<tr><td colspan="9" style="text-align:center;">موردی یافت نشد.</td></tr>');
                return;
            }
            res.data.rows.forEach(function (r) {
                var img = r.live_image_url
                    ? '<a href="' + escHtml(r.live_image_url) + '" target="_blank"><img src="' + escHtml(r.live_image_url) + '" style="width:42px;height:48px;object-fit:contain;background:#fff;border-radius:4px;border:1px solid #cbd5e1;" /></a>'
                    : '—';
                var retailBtns = '';
                if (r.live_product_url && r.live_product_url.indexOf('shaghafi.de') === -1) {
                    retailBtns += '<a href="' + escHtml(r.live_product_url) + '" target="_blank" class="button button-small" style="background:#ede9fe;color:#5b21b6;border-color:#c4b5fd;">🔗 لینک مستقیم مرجع ↗</a> ';
                }
                retailBtns += '<a href="https://akzentshop.de/search?sSearch=' + encodeURIComponent(r.artikelnummer) + '" target="_blank" class="button button-small" title="مشاهده در AkzentShop.de">AkzentShop ↗</a> ';
                retailBtns += '<a href="https://meinakzent.de/search?q=' + encodeURIComponent(r.artikelnummer) + '" target="_blank" class="button button-small" title="مشاهده در MeinAkzent.de">MeinAkzent ↗</a> ';
                retailBtns += '<a href="https://www.raptor-watches.com/detail/' + encodeURIComponent(r.variant_id) + '" target="_blank" class="button button-small" title="مشاهده در Raptor-Watches.com">Raptor ↗</a> ';

                var tr = '<tr>' +
                    '<td style="direction:ltr;font-family:monospace;font-weight:700;">' + escHtml(r.artikelnummer) + '</td>' +
                    '<td style="direction:ltr;font-family:monospace;">' + escHtml(r.base_sku) + '</td>' +
                    '<td>' + escHtml(r.variantenauspraegung || '—') + '</td>' +
                    '<td><strong>' + escHtml(r.bestand) + '</strong></td>' +
                    '<td>' + (r.live_price ? '<strong style="color:#7c3aed;">' + escHtml(r.live_price) + ' €</strong>' : '—') + '</td>' +
                    '<td>' + img + '</td>' +
                    '<td style="direction:ltr;font-family:monospace;font-size:11px;">' + escHtml(r.product_id) + '</td>' +
                    '<td style="direction:ltr;font-family:monospace;font-size:11px;">' + escHtml(r.variant_id) + '</td>' +
                    '<td>' +
                    '<button type="button" class="button button-small button-primary shaghafi-btn-create-from-row" data-sku="' + escHtml(r.base_sku || r.artikelnummer) + '" data-product-id="' + escHtml(r.product_id) + '">➕ ساخت کامل محصول</button> ' +
                    '<button type="button" class="button button-small shaghafi-btn-live-product" data-product-id="' + escHtml(r.product_id) + '" data-sku="' + escHtml(r.artikelnummer) + '">🔍 بررسی قیمت و موجودی زنده</button> ' +
                    retailBtns +
                    '<a href="https://shaghafi.de/detail/' + escHtml(r.variant_id) + '" target="_blank" class="button button-small">شقفی ↗</a>' +
                    '</td>' +
                    '</tr>';
                $tbody.append(tr);
            });
        });
    });

    // Auto-run initial paginated load
    $(function () {
        if ($('#shaghafi-queue-table').length) {
            loadCatalogPage(1, false);
        }
    });

})(jQuery);
