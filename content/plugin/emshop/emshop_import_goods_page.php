<?php

declare(strict_types=1);

/**
 * 对接站点 · 导入商品（layer iframe）。
 */

$emRoot = dirname(__DIR__, 3);
require $emRoot . '/admin/global.php';
adminRequireLogin();

require_once __DIR__ . '/emshop.php';
emshop_plugin_ensure_schema();

use EmshopPlugin\RemoteSiteModel;

$siteId = (int) ($_GET['site_id'] ?? 0);
if ($siteId <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '参数错误';
    exit;
}
$site = RemoteSiteModel::find($siteId);
if ($site === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '对接站点不存在';
    exit;
}

$csrfToken = Csrf::token();
$siteName = (string) ($site['name'] ?? ('站点 #' . $siteId));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>导入商品</title>
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <link rel="stylesheet" href="/content/static/lib/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="/admin/static/css/popup.css">
    <link rel="stylesheet" href="/admin/static/css/reset-layui.css">
    <style>
    .eig-wrap { display: flex; flex-direction: column; height: 100vh; max-height: 100%; box-sizing: border-box; }
    .eig-head { flex-shrink: 0; padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e8ecf1; font-size: 13px; color: #64748b; }
    .eig-head strong { color: #1e293b; }
    .eig-main { flex: 1; display: flex; min-height: 0; }
    .eig-cats { width: 220px; flex-shrink: 0; border-right: 1px solid #e8ecf1; background: #fff; overflow-y: auto; }
    .eig-cat-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
    .eig-cat-item:hover { background: #f8fafc; }
    .eig-cat-item.is-active { background: #e6f7ff; color: #1e9fff; }
    .eig-cat-item--all { font-weight: 600; }
    .eig-cat-item .eig-cat-cb { flex-shrink: 0; }
    .eig-cat-name { flex: 1; min-width: 0; word-break: break-all; }
    .eig-cat-item--child .eig-cat-name { padding-left: 10px; color: #64748b; font-size: 12px; }
    .eig-right { flex: 1; display: flex; flex-direction: column; min-width: 0; background: #f4f6fa; }
    .eig-goods-toolbar { flex-shrink: 0; display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #fff; border-bottom: 1px solid #e8ecf1; font-size: 12px; color: #64748b; }
    .eig-goods-list { flex: 1; overflow-y: auto; padding: 10px 12px; }
    .eig-goods-row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; margin-bottom: 8px; background: #fff; border-radius: 8px; border: 1px solid #e8ecf1; }
    .eig-goods-row img { width: 52px; height: 52px; object-fit: cover; border-radius: 6px; flex-shrink: 0; background: #f1f5f9; }
    .eig-goods-meta { flex: 1; min-width: 0; }
    .eig-goods-title { font-size: 13px; color: #1e293b; font-weight: 500; line-height: 1.35; word-break: break-word; }
    .eig-goods-sub { font-size: 12px; color: #94a3b8; margin-top: 4px; }
    .eig-form-panel { flex-shrink: 0; background: #fff; border-top: 1px solid #e8ecf1; padding: 12px 14px 14px; max-height: 42vh; overflow-y: auto; }
    .eig-form-panel .popup-section { margin-bottom: 10px; }
    .eig-form-panel .popup-section:last-child { margin-bottom: 0; }
    .eig-footer { flex-shrink: 0; display: flex; justify-content: flex-end; gap: 10px; padding: 10px 14px; background: #fff; border-top: 1px solid #e8ecf1; }
    .eig-loading { text-align: center; padding: 40px; color: #94a3b8; }
    </style>
    <script src="/content/static/lib/jquery.min.3.5.1.js"></script>
    <script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
    <script>
    try {
        window.PLUGIN_SAVE_URL = (typeof parent !== 'undefined' && parent.PLUGIN_SAVE_URL)
            ? parent.PLUGIN_SAVE_URL : '/admin/plugin.php';
    } catch (e) {
        window.PLUGIN_SAVE_URL = '/admin/plugin.php';
    }
    var EMS_SITE_ID = <?= (int) $siteId ?>;
    var EMS_CSRF = <?= json_encode($csrfToken) ?>;
    var EMS_REMOTE_BASE = <?= json_encode(rtrim((string) ($site['base_url'] ?? ''), '/')) ?>;
    </script>
</head>
<body class="popup-body" style="height:100vh;margin:0;">
<div class="popup-wrap" style="height:100%;">
<div class="popup-content" style="height:100%;">
<div class="eig-wrap">
    <div class="eig-head">当前对接站点：<strong><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></strong> · 仅展示对方已开启 API 的商品</div>
    <div class="eig-main">
        <div class="eig-cats" id="eigCatList">
            <div class="eig-loading" id="eigCatLoading">加载分类中…</div>
        </div>
        <div class="eig-right">
            <div class="eig-goods-toolbar">
                <span id="eigGoodsHint">请选择左侧分类加载商品</span>
                <span style="flex:1"></span>
                <label style="margin:0;"><input type="checkbox" id="eigSelectVisible"> 全选当前列表</label>
            </div>
            <div class="eig-goods-list" id="eigGoodsList">
                <div class="eig-loading">请选择分类</div>
            </div>
        </div>
    </div>
    <form class="layui-form eig-form-panel" lay-filter="eigForm" id="eigForm" onsubmit="return false;">
        <div class="popup-section">
            <div class="layui-form-item">
                <label class="layui-form-label">本站分类</label>
                <div class="layui-input-block">
                    <select name="target_category_id" id="eigLocalCat" lay-verify="required" lay-filter="eigLocalCat">
                        <option value="">请选择</option>
                    </select>
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">加价模式</label>
                <div class="layui-input-block">
                    <input type="radio" name="markup_mode" value="percent" title="按比例加价" checked lay-filter="eigMarkupMode">
                    <input type="radio" name="markup_mode" value="amount" title="按金额加价" lay-filter="eigMarkupMode">
                </div>
            </div>
            <div class="layui-form-item" id="eigMarkupPercentWrap">
                <label class="layui-form-label">加价比例</label>
                <div class="layui-input-block">
                    <div class="layui-input-inline" style="width:120px;">
                        <input type="number" name="markup_percent" id="eigMarkupPercent" class="layui-input" step="0.1" min="0" value="10" placeholder="如 10 表示 +10%">
                    </div>
                    <div class="layui-form-mid layui-word-aux">在对方销售价基础上增加百分之几（0 表示不加价）</div>
                </div>
            </div>
            <div class="layui-form-item" id="eigMarkupAmountWrap" style="display:none;">
                <label class="layui-form-label">加价金额</label>
                <div class="layui-input-block">
                    <div class="layui-input-inline" style="width:120px;">
                        <input type="number" name="markup_amount" id="eigMarkupAmount" class="layui-input" step="0.01" min="0" value="0" placeholder="元">
                    </div>
                    <div class="layui-form-mid layui-word-aux">在对方销售价基础上每份加多少元</div>
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">图片资源</label>
                <div class="layui-input-block">
                    <input type="radio" name="image_mode" value="local" title="下载到本地">
                    <input type="radio" name="image_mode" value="remote" title="使用上游站点链接" checked>
                </div>
            </div>
        </div>
    </form>
    <div class="eig-footer">
        <button type="button" class="popup-btn" id="eigBtnCancel"><i class="fa fa-times"></i> 取消</button>
        <button type="button" class="popup-btn popup-btn--primary" id="eigBtnSync"><i class="fa fa-check mr-5"></i>立即同步</button>
    </div>
</div>
</div>
</div>
<script>
layui.use(['form', 'layer'], function () {
    var form = layui.form;
    var layLayer = layui.layer;
    var $ = layui.$;

    var remoteCats = [];
    var activeCatKey = null;
    var currentGoods = [];
    var selectedGoods = {};
    var catGoodsCache = {};

    function call(sub, payload, done) {
        payload = payload || {};
        payload._action = 'save_config';
        payload.name = 'emshop';
        payload._sub_action = sub;
        payload.csrf_token = EMS_CSRF;
        $.ajax({
            url: window.PLUGIN_SAVE_URL || '/admin/plugin.php',
            type: 'POST',
            dataType: 'json',
            data: payload
        }).done(function (res) {
            if (res && (res.code === 0 || res.code === 200)) {
                if (res.data && res.data.csrf_token) EMS_CSRF = res.data.csrf_token;
                done && done(null, res.data || {});
            } else {
                done && done(new Error((res && res.msg) || '请求失败'));
            }
        }).fail(function (xhr) {
            var msg = '网络异常';
            try { var j = JSON.parse(xhr.responseText || '{}'); if (j.msg) msg = j.msg; } catch (e) {}
            done && done(new Error(msg));
        });
    }

    function getLayerHostWin() {
        try {
            var host = window.__EMS_LAYER_HOST__;
            var lix = window.__EMS_LAYER_INDEX__;
            if (host && host.layui && host.layui.layer && lix !== undefined && lix !== null) {
                return { win: host, idx: lix };
            }
        } catch (e) {}
        return null;
    }

    function closeFrame() {
        var hit = getLayerHostWin();
        if (hit) {
            try { hit.win.layui.layer.close(hit.idx); } catch (e) {}
            return;
        }
        try {
            var idx = parent.layer.getFrameIndex(window.name);
            var n = typeof idx === 'number' ? idx : parseInt(idx, 10);
            if (!isNaN(n) && n >= 0) parent.layer.close(n);
        } catch (e2) {}
    }

    function layerMsgHost() {
        var hit = getLayerHostWin();
        return (hit && hit.win && hit.win.layui && hit.win.layui.layer)
            ? hit.win.layui.layer : layLayer;
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function imgSrc(u) {
        u = String(u || '').trim();
        if (!u) return '';
        if (/^https?:\/\//i.test(u)) return u;
        if (!EMS_REMOTE_BASE) return u;
        return EMS_REMOTE_BASE + '/' + u.replace(/^\/+/, '');
    }

    function collectDescendantCategoryIds(rootId) {
        var out = [rootId];
        function walk(pid) {
            remoteCats.forEach(function (c) {
                var p = parseInt(c.parent_id, 10) || 0;
                var cid = parseInt(c.category_id, 10) || 0;
                if (cid <= 0 || p !== pid) return;
                out.push(cid);
                walk(cid);
            });
        }
        walk(rootId);
        return out;
    }

    function categoryIdsForApi(catKey) {
        if (catKey === 0 || catKey === '0') return [];
        var id = parseInt(catKey, 10);
        if (!id) return [];
        return collectDescendantCategoryIds(id);
    }

    function cacheKey(catKey) {
        return String(catKey);
    }

    function loadLocalCategories() {
        call('import_local_categories', {}, function (err, data) {
            if (err) { layLayer.msg(err.message); return; }
            var list = data.list || [];
            var $sel = $('#eigLocalCat');
            list.forEach(function (row) {
                $sel.append('<option value="' + parseInt(row.id, 10) + '">' + esc(row.label) + '</option>');
            });
            form.render('select');
        });
    }

    function renderCategoryList() {
        var $box = $('#eigCatList').empty();
        var allRow = $('<div class="eig-cat-item eig-cat-item--all" data-cat="0">' +
            '<input type="checkbox" class="eig-cat-cb" data-cat-cb="0" title="">' +
            '<span class="eig-cat-name">全部商品</span></div>');
        $box.append(allRow);

        var tops = remoteCats.filter(function (c) { return (parseInt(c.parent_id, 10) || 0) === 0; });
        tops.forEach(function (top) {
            var tid = parseInt(top.category_id, 10);
            var tname = top.category_name || ('#' + tid);
            var $t = $('<div class="eig-cat-item" data-cat="' + tid + '">' +
                '<input type="checkbox" class="eig-cat-cb" data-cat-cb="' + tid + '" title="">' +
                '<span class="eig-cat-name">' + esc(tname) + '</span></div>');
            $box.append($t);
            remoteCats.forEach(function (c) {
                if ((parseInt(c.parent_id, 10) || 0) !== tid) return;
                var sid = parseInt(c.category_id, 10);
                var sname = c.category_name || ('#' + sid);
                var $s = $('<div class="eig-cat-item eig-cat-item--child" data-cat="' + sid + '">' +
                    '<input type="checkbox" class="eig-cat-cb" data-cat-cb="' + sid + '" title="">' +
                    '<span class="eig-cat-name">' + esc(sname) + '</span></div>');
                $box.append($s);
            });
        });
    }

    function fetchGoodsForCategory(catKey, done) {
        var ck = cacheKey(catKey);
        if (catGoodsCache[ck]) {
            done && done(null, catGoodsCache[ck]);
            return;
        }
        var ids = categoryIdsForApi(catKey);
        var payload = { site_id: EMS_SITE_ID };
        if (ids.length) payload.category_ids = JSON.stringify(ids);
        call('import_remote_goods', payload, function (err, data) {
            if (err) { done && done(err); return; }
            var list = data.list || [];
            catGoodsCache[ck] = list;
            done && done(null, list);
        });
    }

    function renderGoodsList() {
        var $list = $('#eigGoodsList').empty();
        if (!currentGoods.length) {
            $list.html('<div class="eig-loading">暂无商品</div>');
            $('#eigGoodsHint').text('当前分类下没有可导入的商品');
            $('#eigSelectVisible').prop('checked', false);
            return;
        }
        $('#eigGoodsHint').text('共 ' + currentGoods.length + ' 个商品 · 已选 ' + Object.keys(selectedGoods).length + ' 个');
        currentGoods.forEach(function (g) {
            var gid = parseInt(g.goods_id, 10);
            var chk = selectedGoods[gid] ? ' checked' : '';
            var img = imgSrc(g.cover_image || '');
            var imgEsc = esc(img);
            var title = esc(g.title || '');
            var price = esc(g.min_price != null ? g.min_price : '');
            var row = $('<div class="eig-goods-row" data-gid="' + gid + '">' +
                '<input type="checkbox" lay-skin="primary" class="eig-goods-cb" value="' + gid + '"' + chk + '>' +
                (img ? '<img src="' + imgEsc + '" alt="">' : '<div style="width:52px;height:52px;"></div>') +
                '<div class="eig-goods-meta"><div class="eig-goods-title">' + title + '</div>' +
                '<div class="eig-goods-sub">¥' + price + ' · ID ' + gid + '</div></div></div>');
            $list.append(row);
        });
        form.render('checkbox');
        syncVisibleSelectAll();
    }

    function syncVisibleSelectAll() {
        var total = currentGoods.length;
        var checked = 0;
        currentGoods.forEach(function (g) {
            if (selectedGoods[parseInt(g.goods_id, 10)]) checked++;
        });
        $('#eigSelectVisible').prop('checked', total > 0 && checked === total);
    }

    function setActiveCat(catKey) {
        activeCatKey = catKey;
        $('.eig-cat-item').removeClass('is-active');
        $('.eig-cat-item[data-cat="' + catKey + '"]').addClass('is-active');
        $('#eigGoodsList').html('<div class="eig-loading">加载中…</div>');
        fetchGoodsForCategory(catKey, function (err, list) {
            if (err) {
                $('#eigGoodsList').html('<div class="eig-loading">' + esc(err.message) + '</div>');
                return;
            }
            currentGoods = list;
            renderGoodsList();
        });
    }

    function mergeSelectionFromCategory(catKey, checked) {
        var ck = cacheKey(catKey);
        var apply = function (list) {
            list.forEach(function (g) {
                var gid = parseInt(g.goods_id, 10);
                if (!gid) return;
                if (checked) selectedGoods[gid] = true;
                else delete selectedGoods[gid];
            });
            if (String(activeCatKey) === String(catKey)) renderGoodsList();
            else $('#eigGoodsHint').text('已选 ' + Object.keys(selectedGoods).length + ' 个商品');
        };
        if (catGoodsCache[ck]) {
            apply(catGoodsCache[ck]);
            return;
        }
        var ids = categoryIdsForApi(catKey);
        var payload = { site_id: EMS_SITE_ID };
        if (ids.length) payload.category_ids = JSON.stringify(ids);
        call('import_remote_goods', payload, function (err, data) {
            if (err) { layLayer.msg(err.message); return; }
            var list = data.list || [];
            catGoodsCache[ck] = list;
            apply(list);
        });
    }

    $('#eigCatList').on('click', '.eig-cat-item', function (e) {
        if ($(e.target).is('input')) return;
        var cat = $(this).data('cat');
        setActiveCat(cat);
    });

    $('#eigCatList').on('change', '.eig-cat-cb', function (e) {
        e.stopPropagation();
        var cat = $(this).data('cat-cb');
        mergeSelectionFromCategory(cat, $(this).prop('checked'));
    });

    $('#eigGoodsList').on('change', '.eig-goods-cb', function () {
        var gid = parseInt($(this).val(), 10);
        if ($(this).prop('checked')) selectedGoods[gid] = true;
        else delete selectedGoods[gid];
        $('#eigGoodsHint').text('共 ' + currentGoods.length + ' 个商品 · 已选 ' + Object.keys(selectedGoods).length + ' 个');
        syncVisibleSelectAll();
    });

    $('#eigSelectVisible').on('change', function () {
        var on = $(this).prop('checked');
        currentGoods.forEach(function (g) {
            var gid = parseInt(g.goods_id, 10);
            if (!gid) return;
            if (on) selectedGoods[gid] = true;
            else delete selectedGoods[gid];
        });
        renderGoodsList();
    });

    form.on('radio(eigMarkupMode)', function (d) {
        if (d.value === 'amount') {
            $('#eigMarkupPercentWrap').hide();
            $('#eigMarkupAmountWrap').show();
        } else {
            $('#eigMarkupPercentWrap').show();
            $('#eigMarkupAmountWrap').hide();
        }
    });

    $('#eigBtnCancel').on('click', closeFrame);

    $('#eigBtnSync').on('click', function () {
        var $btn = $(this);
        var $icon = $btn.find('i');
        var catId = parseInt($('#eigLocalCat').val(), 10);
        if (!catId) {
            layLayer.msg('请选择本站分类');
            return;
        }
        var mode = $('input[name="markup_mode"]:checked').val() || 'percent';
        var mv = mode === 'amount'
            ? parseFloat($('#eigMarkupAmount').val() || '0')
            : parseFloat($('#eigMarkupPercent').val() || '0');
        if (isNaN(mv)) mv = 0;
        var imageMode = $('input[name="image_mode"]:checked').val() || 'remote';
        var ids = Object.keys(selectedGoods).map(function (k) { return parseInt(k, 10); }).filter(function (x) { return x > 0; });
        if (!ids.length) {
            layLayer.msg('请勾选要导入的商品或分类');
            return;
        }
        $icon.attr('class', 'fa fa-refresh admin-spin mr-5');
        $btn.prop('disabled', true);
        $.ajax({
            url: window.PLUGIN_SAVE_URL || '/admin/plugin.php',
            type: 'POST',
            dataType: 'json',
            data: {
                _action: 'save_config',
                name: 'emshop',
                _sub_action: 'import_goods_sync',
                csrf_token: EMS_CSRF,
                site_id: EMS_SITE_ID,
                target_category_id: catId,
                markup_mode: mode,
                markup_value: mv,
                image_mode: imageMode,
                goods_ids: JSON.stringify(ids)
            },
            success: function (res) {
                if (res && (res.code === 0 || res.code === 200)) {
                    try { window.top.dispatchEvent(new CustomEvent('emshop_site_list_reload')); } catch (e) {}
                    var msg = (res && res.msg) ? String(res.msg) : '同步完成';
                    try { layerMsgHost().msg(msg, { icon: 1, time: 2600 }); } catch (e2) { layLayer.msg(msg, { icon: 1 }); }
                    closeFrame();
                } else {
                    layLayer.msg((res && res.msg) || '同步失败', { icon: 2 });
                }
            },
            error: function (xhr) {
                var msg = '网络异常';
                try { var j = JSON.parse(xhr.responseText || '{}'); if (j.msg) msg = j.msg; } catch (e) {}
                layLayer.msg(msg, { icon: 2 });
            },
            complete: function () {
                $icon.attr('class', 'fa fa-check mr-5');
                $btn.prop('disabled', false);
            }
        });
    });

    loadLocalCategories();

    call('import_remote_categories', { site_id: EMS_SITE_ID }, function (err, data) {
        $('#eigCatLoading').remove();
        if (err) {
            $('#eigCatList').html('<div class="eig-loading">' + esc(err.message) + '</div>');
            return;
        }
        remoteCats = data.list || [];
        renderCategoryList();
        setActiveCat(0);
    });

    form.render();
});
</script>
</body>
</html>
