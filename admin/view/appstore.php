<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

// 应用收费由中心服务端统一以人民币结算，这里固定用 ¥ 不读站点主货币
// 分类 / 列表全部由 JS 走 /admin/appstore.php?_action=categories / list 异步加载

// 应用图片（封面 / 内容图）统一基于 license_urls 第 0 个线路拼接 —— 永远是第一个，
// 不跟随用户切换的线路；以此保证资源 URL 在全站稳定、可被浏览器缓存
$__appstoreLines = LicenseClient::lines();
$appstoreAssetHost = $__appstoreLines ? rtrim($__appstoreLines[0]['url'], '/') : '';

// 当前站点是否已激活授权 —— 未激活时付费应用不允许安装/购买，引导到授权页
$appstoreLicensed = LicenseService::isActivated();
$csrfToken = $csrfToken ?? Csrf::token();
?>
<style>
/* 和控制台 / 模板管理 / 资源管理一致：去掉 .admin-page 默认白底，内容块浮在灰底画布 */
.admin-page-appstore { padding: 8px 4px 40px; background: unset; }

/* ===== 顶部工具条：只放一个快捷搜索，右对齐 ===== */
.appstore-toolbar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    margin-bottom: 12px;
}
.appstore-search {
    position: relative;
    width: 280px;
}
.appstore-search input {
    width: 100%;
    height: 34px;
    padding: 0 32px 0 34px;
    font-size: 13px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    outline: none;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.appstore-search input:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
.appstore-search i.fa-search {
    position: absolute;
    left: 12px; top: 50%; transform: translateY(-50%);
    color: #9ca3af; font-size: 12px;
    pointer-events: none;
}
.appstore-search__clear {
    position: absolute;
    right: 8px; top: 50%; transform: translateY(-50%);
    display: none;
    width: 20px; height: 20px;
    border: none;
    border-radius: 50%;
    background: #e5e7eb;
    color: #6b7280;
    cursor: pointer;
    font-size: 10px;
    align-items: center;
    justify-content: center;
    transition: background .15s;
}
.appstore-search__clear:hover { background: #ef4444; color: #fff; }
.appstore-search input:not(:placeholder-shown) ~ .appstore-search__clear { display: inline-flex; }

/* ===== 封面小图（表格第一列） ===== */
.appstore-cover {
    width: 44px; height: 44px;
    display: inline-block;
    border-radius: 8px;
    object-fit: cover;
    background: #f3f4f6;
    box-shadow: 0 1px 3px rgba(15,23,42,.08);
    vertical-align: middle;
}
.appstore-cover--empty {
    display: inline-flex; align-items: center; justify-content: center;
    color: #9ca3af; font-size: 18px;
}
.appstore-cover--zoom { cursor: zoom-in; transition: transform .15s ease, box-shadow .15s ease; }
.appstore-cover--zoom:hover {
    transform: scale(1.08);
    box-shadow: 0 4px 12px rgba(15,23,42,.18);
}

/* ===== 类型 tag（前置到名称行） ===== */
.appstore-type {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px;
    font-size: 11px; font-weight: 500;
    border-radius: 4px;
    line-height: 18px;
    flex-shrink: 0;
}
.appstore-type i { font-size: 10px; }
.appstore-type--template { background: #ecfeff; color: #0891b2; }
.appstore-type--plugin   { background: #f5f3ff; color: #7c3aed; }

/* ===== 名称行 ===== */
.appstore-title__row {
    display: flex; align-items: center; gap: 8px;
    min-width: 0;
}
.appstore-title__name {
    font-weight: 600; color: #0f172a;
    line-height: 1.35;
    flex: 1; min-width: 0;
    overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
}
.appstore-title__desc {
    font-size: 12px; color: #9ca3af;
    margin-top: 3px;
    font-weight: 400;
    line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 1; line-clamp: 1;
    -webkit-box-orient: vertical; overflow: hidden;
}

/* ===== 价格 chip（免费/付费/不可用） ===== */
.appstore-chip {
    display: inline-block;
    min-width: 56px;
    padding: 4px 12px;
    font-size: 12px; font-weight: 600;
    border-radius: 5px;
    letter-spacing: .2px;
    line-height: 18px;
    border: 1px solid transparent;
}
.appstore-chip__cur { font-size: 10px; opacity: .7; margin-right: 1px; }

.appstore-chip--free {
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    color: #047857;
    border-color: rgba(16,185,129,.28);
    box-shadow: 0 1px 2px rgba(16,185,129,.08);
}
.appstore-chip--paid {
    background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%);
    color: #be123c;
    border-color: rgba(225,29,72,.28);
    box-shadow: 0 1px 2px rgba(225,29,72,.08);
}
.appstore-chip--na {
    background: #f9fafb;
    color: #cbd5e1;
    border: 1px dashed #e5e7eb;
    font-weight: 400;
}
</style>

<div class="admin-page admin-page-appstore">
    <h1 class="admin-page__title">应用商店</h1>

    <!-- 分类选项卡（em-tabs 同款，内容由 JS 异步渲染，计数填到 em-tabs__count） -->
    <div class="em-tabs" id="appstoreTabs">
        <a class="em-tabs__item is-active" data-filter='{"type":"all","id":0}'>
            <i class="fa fa-th-large"></i>全部<em class="em-tabs__count">…</em>
        </a>
    </div>

    <!-- 工具条：右侧快捷搜索 -->
    <div class="appstore-toolbar">
        <div class="appstore-search">
            <i class="fa fa-search"></i>
            <input type="text" id="appstoreSearch" placeholder="搜索应用名称 / 描述…" autocomplete="off">
            <button type="button" class="appstore-search__clear" id="appstoreSearchClear" title="清空">
                <i class="fa fa-times"></i>
            </button>
        </div>
    </div>

    <table id="appstoreTable" lay-filter="appstoreTable"></table>
</div>

<!-- 名称 + 类型 tag + 描述 -->
<script type="text/html" id="appstoreTitleTpl">
    <div>
        <div class="appstore-title__row">
            <span class="appstore-type appstore-type--{{ d.type === 'template' ? 'template' : 'plugin' }}">
                <i class="fa {{ d.type === 'template' ? 'fa-paint-brush' : 'fa-puzzle-piece' }}"></i>
                {{ d.type === 'template' ? '模板' : '插件' }}
            </span>
            <span class="appstore-title__name">{{ d.name_cn || d.name_en || '-' }}</span>
        </div>
        <div class="appstore-title__desc" title="{{ (d.content || '').replace(/<[^>]+>/g, '').trim() || '该应用未配置描述信息' }}">
            {{ (d.content || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim() || '该应用未配置描述信息' }}
        </div>
    </div>
</script>

<script type="text/html" id="appstoreInstallTpl">
    <span style="font-family:Menlo,Consolas,monospace;color:#374151;">{{ (Number(d.install_num) || 0).toLocaleString() }}</span>
</script>

<!-- 至尊价格：统一免费 -->
<script type="text/html" id="appstorePriceSupremeTpl">
    <span class="appstore-chip appstore-chip--free" title="至尊会员全场免费">免费</span>
</script>

<!-- SVIP 价格 -->
<script type="text/html" id="appstorePriceSvipTpl">
    {{# if(parseFloat(d.svip_price || 0) <= 0){ }}
        <span class="appstore-chip appstore-chip--free">免费</span>
    {{# } else { }}
        <span class="appstore-chip appstore-chip--paid">
            <span class="appstore-chip__cur">¥</span>{{ parseFloat(d.svip_price).toFixed(2) }}
        </span>
    {{# } }}
</script>

<!-- VIP 价格 -->
<script type="text/html" id="appstorePriceVipTpl">
    {{# if(parseFloat(d.vip_price || 0) <= 0){ }}
        <span class="appstore-chip appstore-chip--free">免费</span>
    {{# } else { }}
        <span class="appstore-chip appstore-chip--paid">
            <span class="appstore-chip__cur">¥</span>{{ parseFloat(d.vip_price).toFixed(2) }}
        </span>
    {{# } }}
</script>

<!--
    操作按钮分支：
    - 已装 · 同版本       灰色"已安装"
    - 已装 · 有新版       橙色"更新 v{远端}"
    - 未装 · 未激活 · 付费   紫色"先激活授权"
    - 未装 · 免费          蓝色"安装"
    - 未装 · 付费          红色"购买 ¥my_price"
-->
<script type="text/html" id="appstoreActionTpl">
    {{# if(d.is_installed == 1){ }}
        {{# if(d.installed_version && d.version && d.installed_version !== d.version){ }}
            <a class="em-btn em-sm-btn" style="background:#f59e0b;color:#fff;" lay-event="update"><i class="fa fa-cloud-download"></i>更新 v{{ d.version }}</a>
        {{# } else { }}
            <a class="em-btn em-sm-btn em-reset-btn em-disabled-btn"><i class="fa fa-check"></i>已安装</a>
        {{# } }}
    {{# } else if(d.is_free == 1){ }}
        <a class="em-btn em-sm-btn em-save-btn" lay-event="install"><i class="fa fa-download"></i>安装</a>
    {{# } else if(!window.APPSTORE_LICENSED){ }}
        <a class="em-btn em-sm-btn em-purple-btn" lay-event="needLicense"><i class="fa fa-shield"></i>先激活授权</a>
    {{# } else { }}
        <a class="em-btn em-sm-btn em-red-btn" lay-event="buy"><i class="fa fa-shopping-cart"></i>购买 ¥{{ parseFloat(d.my_price || 0).toFixed(2) }}</a>
    {{# } }}
</script>

<script>
// 资源 host（由 PHP 注入）：始终取 license_urls[0].url，不跟随线路切换
var APPSTORE_ASSET_HOST = <?= json_encode($appstoreAssetHost, JSON_UNESCAPED_SLASHES) ?>;
// 当前站点是否已激活授权（templet 通过 window.APPSTORE_LICENSED 读取）
window.APPSTORE_LICENSED = <?= $appstoreLicensed ? 'true' : 'false' ?>;
// CSRF token（安装/更新 action 校验）
var APPSTORE_CSRF = <?= json_encode($csrfToken) ?>;

function appstoreAbsUrl(url) {
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    return APPSTORE_ASSET_HOST + (url.charAt(0) === '/' ? '' : '/') + url;
}

$(function () {
    layui.use(['layer', 'table', 'util'], function () {
        var layer = layui.layer;
        var table = layui.table;

        var $tabs = $('#appstoreTabs');

        // ---------- 当前 Tab 过滤参数 ----------
        function currentFilter() {
            var raw = $tabs.find('.em-tabs__item.is-active').attr('data-filter');
            try { return JSON.parse(raw || '{}'); } catch (e) { return { type: 'all', id: 0 }; }
        }
        function buildWhere() {
            var f = currentFilter();
            var where = { keyword: ($('#appstoreSearch').val() || '').trim() };
            if (f.type && f.type !== 'all') where.type = f.type;
            if (!f.type && f.id > 0)        where.category_id = f.id;
            return where;
        }

        // ---------- 服务端分页表格 ----------
        table.render({
            elem: '#appstoreTable',
            id: 'appstoreTableId',
            url: '/admin/appstore.php?_action=list',
            method: 'GET',
            where: buildWhere(),
            page: true,
            limit: 10,
            limits: [10, 20, 50],
            cellMinWidth: 80,
            lineStyle: 'height: 62px;',
            parseData: function (res) {
                var d = res && res.data ? res.data : {};
                return {
                    code: res.code === 200 ? 0 : (res.code || 500),
                    msg:  res.msg || '',
                    count: d.count || 0,
                    data:  d.list || []
                };
            },
            request: { pageName: 'page', limitName: 'limit' },
            cols: [[
                {
                    field: 'cover', title: '封面', width: 80, align: 'center', unresize: true,
                    templet: function (d) {
                        if (!d.cover) {
                            return '<span class="appstore-cover appstore-cover--empty"><i class="fa fa-cube"></i></span>';
                        }
                        var imgs = (Array.isArray(d.images) && d.images.length > 0 ? d.images : [d.cover]).map(appstoreAbsUrl);
                        return '<img class="appstore-cover appstore-cover--zoom" src="' + appstoreAbsUrl(d.cover) +
                               '" alt="" data-imgs="' + encodeURIComponent(JSON.stringify(imgs)) + '">';
                    }
                },
                { field: 'name_cn', title: '应用名称', minWidth: 240, templet: '#appstoreTitleTpl' },
                { field: 'install_num', title: '安装量', width: 100, templet: '#appstoreInstallTpl', align: 'center', sort: true },
                { title: '至尊授权', width: 120, templet: '#appstorePriceSupremeTpl', align: 'center' },
                { field: 'svip_price', title: 'SVIP 授权', width: 130, templet: '#appstorePriceSvipTpl', align: 'center' },
                { field: 'vip_price', title: 'VIP 授权', width: 130, templet: '#appstorePriceVipTpl', align: 'center' },
                { title: '操作', width: 200, align: 'center', toolbar: '#appstoreActionTpl' }
            ]]
        });

        function reloadTable() {
            table.reload('appstoreTableId', {
                where: buildWhere(),
                page: { curr: 1 }
            });
        }

        // ---------- em-tabs 切换 ----------
        $tabs.on('click', '.em-tabs__item', function () {
            var $item = $(this);
            if ($item.hasClass('is-active')) return;
            $item.addClass('is-active').siblings().removeClass('is-active');
            reloadTable();
        });

        // ---------- 搜索（输入即过滤，防抖 300ms） + 清空按钮 ----------
        var searchTimer;
        $('#appstoreSearch').on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(reloadTable, 300);
        });
        $('#appstoreSearchClear').on('click', function () {
            $('#appstoreSearch').val('').trigger('input').focus();
        });

        // ---------- 封面点击放大（Viewer.js，全局已加载） ----------
        $(document).off('click.appstoreCover').on('click.appstoreCover', '.appstore-cover--zoom', function () {
            var raw = $(this).attr('data-imgs');
            var imgs = [];
            try { imgs = JSON.parse(decodeURIComponent(raw || '')); } catch (e) {}
            if (!imgs.length) return;

            var $container = $('<div style="display:none;"></div>');
            imgs.forEach(function (url) { $container.append('<img src="' + url + '">'); });
            $('body').append($container);

            var viewer = new Viewer($container[0], {
                navbar: imgs.length > 1,
                title: false,
                toolbar: true,
                hidden: function () { viewer.destroy(); $container.remove(); }
            });
            viewer.show();
        });

        // ---------- 安装 / 更新 ----------
        function installApp(d, action) {
            var displayName = d.name_cn || d.name_en || d.id;
            var typeLabel = d.type === 'template' ? '模板' : '插件';
            var actionLabel = action === 'update' ? '更新' : '安装';
            var loadingIdx = layer.load(2, { shade: [0.3, '#000'] });
            $.post('/admin/appstore.php', {
                _action:    action,
                csrf_token: APPSTORE_CSRF,
                name:       d.name_en,
                type:       d.type === 'template' ? 'template' : 'plugin',
                file_path:  d.file_path || '',
                version:    d.version || ''
            }).done(function (res) {
                layer.close(loadingIdx);
                if (res && (res.code === 200 || res.code === 0)) {
                    if (res.data && res.data.csrf_token) APPSTORE_CSRF = res.data.csrf_token;
                    layer.msg(typeLabel + actionLabel + '成功：' + displayName);
                    reloadTable();
                } else {
                    layer.msg((res && res.msg) || (actionLabel + '失败'));
                }
            }).fail(function (xhr) {
                layer.close(loadingIdx);
                var msg = actionLabel + '请求失败';
                try {
                    var j = JSON.parse(xhr.responseText || '{}');
                    if (j && j.msg) msg = j.msg;
                } catch (e) {}
                layer.msg(msg);
            });
        }

        // ---------- 购买按钮：iframe 弹窗 ----------
        function openPurchaseModal(app) {
            var id = parseInt(app.id, 10) || 0;
            if (!id) { layer.msg('应用标识缺失，无法发起购买', { icon: 2 }); return; }
            layer.open({
                type: 2,
                title: '应用购买',
                skin: 'admin-modal',
                area: [window.innerWidth >= 640 ? '600px' : '94%', window.innerHeight >= 720 ? '520px' : '88%'],
                shadeClose: true,
                maxmin: false,
                content: '/admin/appstore_buy.php?id=' + id
            });
        }

        // ---------- 行操作 ----------
        table.on('tool(appstoreTable)', function (obj) {
            var d = obj.data;
            if (obj.event === 'install')          installApp(d, 'install');
            else if (obj.event === 'update')      installApp(d, 'update');
            else if (obj.event === 'buy')         openPurchaseModal(d);
            else if (obj.event === 'needLicense') {
                layer.confirm('付费应用需先激活正版授权。是否前往激活？', { icon: 3, title: '提示' }, function (idx) {
                    layer.close(idx);
                    if ($.pjax) $.pjax({ url: '/admin/license.php', container: '#adminContent' });
                    else location.href = '/admin/license.php';
                });
            }
        });

        // ---------- 异步拉取分类列表并渲染 em-tabs ----------
        function escapeHtml(s) {
            return String(s || '').replace(/[&<>"']/g, function (c) {
                return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
            });
        }
        function iconFor(cat) {
            // 主分类 + 明确类型的用不同图标
            if (cat.type === 'all')      return 'fa-th-large';
            if (cat.type === 'template') return 'fa-paint-brush';
            if (cat.type === 'plugin')   return 'fa-puzzle-piece';
            return 'fa-folder-o';
        }
        function renderTabs(list) {
            $tabs.empty();
            var html = '';
            list.forEach(function (c, idx) {
                var filter = { type: c.type || '', id: c.id || 0 };
                var cls = idx === 0 ? 'em-tabs__item is-active' : 'em-tabs__item';
                var icon = iconFor(c);
                html += '<a class="' + cls + '" data-filter="' + escapeHtml(JSON.stringify(filter)) + '">'
                     +      '<i class="fa ' + icon + '"></i>'
                     +      escapeHtml(c.name)
                     +      '<em class="em-tabs__count">' + (c.count != null ? c.count : '') + '</em>'
                     + '</a>';
            });
            $tabs.html(html);
        }

        // 拉取前遮一层 loading，避免分类未到位时用户看到空 Tab
        var catLoadingIdx = layer.load(2, { shade: [0.3, '#000'] });
        $.ajax({
            url: '/admin/appstore.php',
            method: 'GET',
            data: { _action: 'categories', _t: Date.now() },
            dataType: 'json',
            timeout: 15000
        }).done(function (resp) {
            if (resp && resp.code === 200 && resp.data && resp.data.list && resp.data.list.length > 0) {
                renderTabs(resp.data.list);
            }
        }).always(function () {
            layer.close(catLoadingIdx);
        });
    });
});
</script>
