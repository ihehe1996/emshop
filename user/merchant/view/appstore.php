<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

/** @var string $csrfToken */

// 应用收费由中心服务端统一以人民币结算，这里固定用 ¥
// 分类 / 列表全部由 JS 走 /user/merchant/appstore.php?_action=categories / list 异步加载

// 封面 / 内容图统一基于 license_urls[0] 拼接——永远是第一个，不跟随线路切换
$__appstoreLines = LicenseClient::lines();
$appstoreAssetHost = $__appstoreLines ? rtrim($__appstoreLines[0]['url'], '/') : '';

// 站点是否已激活授权（主站 emkey 生效即算；商户自己不能单独激活）
$appstoreLicensed = LicenseService::isActivated();
?>
<style>
.mc-appstore { padding: 0; }

/* 封面小图 */
.appstore-cover {
    width: 40px; height: 40px;
    display: inline-block;
    border-radius: 6px;
    object-fit: cover;
    background: #f3f4f6;
    box-shadow: 0 1px 3px rgba(15,23,42,.08);
    vertical-align: middle;
}
.appstore-cover--empty {
    display: inline-flex; align-items: center; justify-content: center;
    color: #9ca3af; font-size: 18px;
}
.appstore-cover--zoom {
    cursor: zoom-in;
    transition: transform .15s ease, box-shadow .15s ease;
}
.appstore-cover--zoom:hover {
    transform: scale(1.06);
    box-shadow: 0 4px 12px rgba(15,23,42,.18);
}

/* 类型 tag：模板青 / 插件紫 */
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

/* 价格 chip */
.appstore-chip {
    min-width: 56px;
    padding: 4px 12px;
    font-size: 12px; font-weight: 600;
    border-radius: 5px;
    letter-spacing: .2px;
    line-height: 18px;
    border: 1px solid transparent;
    transition: transform .15s ease, box-shadow .15s ease;
}
.appstore-chip i { font-size: 10px; opacity: .85; }
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

/* 分类 tab（按商品列表风格） */
.appstore-tab-bar {
    position: relative;
    display: inline-flex; gap: 4px;
    padding: 4px;
    background: #f3f4f6;
    border-radius: 8px;
    margin-bottom: 14px;
}
.appstore-tab-slider {
    position: absolute; top: 4px; bottom: 4px;
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 2px rgba(15,23,42,.08);
    transition: left .2s ease, width .2s ease;
    pointer-events: none;
}
.appstore-tab {
    position: relative;
    padding: 6px 14px;
    font-size: 13px; color: #6b7280;
    cursor: pointer;
    border-radius: 6px;
    z-index: 1;
    user-select: none;
    transition: color .15s ease;
    white-space: nowrap;
}
.appstore-tab.active { color: #111827; font-weight: 600; }
.appstore-tab-count {
    font-size: 11px; color: #9ca3af;
    font-style: normal;
    margin-left: 3px;
}
.appstore-tab.active .appstore-tab-count { color: #6b7280; }

/* 搜索框 */
.appstore-toolbar {
    display: flex; align-items: center; justify-content: flex-end;
    padding: 0 12px 10px;
}
.appstore-search {
    position: relative;
    width: 260px;
}
.appstore-search input {
    width: 100%;
    height: 34px;
    padding: 0 12px 0 34px;
    font-size: 13px;
    background: #f9fafb;
    border: 1px solid #e5e7eb; border-radius: 6px;
    outline: none;
    transition: border-color .15s ease, background .15s ease;
}
.appstore-search input:focus { background: #fff; border-color: #6366f1; }
.appstore-search i.fa-search {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    color: #9ca3af; font-size: 13px;
}
</style>

<div class="mc-page mc-appstore">
    <div class="mc-page-header">
        <h2 class="mc-page-title">应用商店</h2>
        <p class="mc-page-desc">选购官方插件与模板，解锁本店更多能力。商户价格统一按 VIP 档结算；已购买的应用会自动出现在"插件管理"/"模板管理"里。</p>
    </div>

    <!-- 分类选项卡（由 JS 异步渲染） -->
    <div class="appstore-tab-bar" id="appstoreTabBar">
        <span class="appstore-tab-slider"></span>
        <span class="appstore-tab active" data-filter='{"type":"all","id":0}'>全部 <em class="appstore-tab-count">…</em></span>
    </div>

    <!-- 搜索 -->
    <div class="appstore-toolbar">
        <div class="appstore-search">
            <i class="fa fa-search"></i>
            <input type="text" id="appstoreSearch" placeholder="搜索应用名称 / 描述…">
        </div>
    </div>

    <table id="appstoreTable" lay-filter="appstoreTable"></table>
</div>

<!-- 表格单元格模板 -->
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
    {{ (Number(d.install_num) || 0).toLocaleString() }}
</script>

<!-- 商户价格：全部按 VIP 价结算，不展示至尊 / SVIP 分档 -->
<script type="text/html" id="appstorePriceTpl">
    {{# if(parseFloat(d.vip_price || 0) <= 0){ }}
        <span class="appstore-chip appstore-chip--free">免费</span>
    {{# } else { }}
        <span class="appstore-chip appstore-chip--paid">
            <span class="appstore-chip__cur">¥</span>{{ parseFloat(d.vip_price).toFixed(2) }}
        </span>
    {{# } }}
</script>

<!--
    商户侧按钮分支（依赖服务端 is_installed / installed_version 字段）：
    - 已装 · 同版本         灰色"已安装"（点击跳到商户的插件/模板管理页）
    - 已装 · 有新版         橙色"更新 v{远端}"
    - 未装 · 主站未激活授权  紫色"主站未激活"（商户自己不能激活，提示即可）
    - 未装 · 免费           蓝色"安装"
    - 未装 · 付费           红色"购买 ¥my_price"
-->
<script type="text/html" id="appstoreActionTpl">
    {{# if(d.is_installed == 1){ }}
        {{# if(d.installed_version && d.version && d.installed_version !== d.version){ }}
            <a class="layui-btn layui-btn-sm" style="background:#f59e0b;color:#fff;" lay-event="update"><i class="fa fa-cloud-download"></i> 更新 v{{ d.version }}</a>
        {{# } else { }}
            <a class="layui-btn layui-btn-sm layui-btn-primary" lay-event="goManage"><i class="fa fa-check"></i> 已安装</a>
        {{# } }}
    {{# } else if(d.is_free == 1){ }}
        <a class="layui-btn layui-btn-sm layui-btn-blue" lay-event="install"><i class="fa fa-download"></i> 安装</a>
    {{# } else if(!window.APPSTORE_LICENSED){ }}
        <a class="layui-btn layui-btn-sm" style="background:#6366f1;color:#fff;" lay-event="needLicense"><i class="fa fa-shield"></i> 主站未激活</a>
    {{# } else { }}
        <a class="layui-btn layui-btn-sm" style="background:#f43f5e;color:#fff;" lay-event="buy"><i class="fa fa-shopping-cart"></i> 购买 ¥{{ parseFloat(d.my_price || 0).toFixed(2) }}</a>
    {{# } }}
</script>

<script>
var APPSTORE_ASSET_HOST = <?= json_encode($appstoreAssetHost, JSON_UNESCAPED_SLASHES) ?>;
window.APPSTORE_LICENSED = <?= $appstoreLicensed ? 'true' : 'false' ?>;
var APPSTORE_CSRF = <?= json_encode($csrfToken ?? '') ?>;
function appstoreAbsUrl(url) {
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    return APPSTORE_ASSET_HOST + (url.charAt(0) === '/' ? '' : '/') + url;
}

$(function () {
    layui.use(['layer', 'table', 'util'], function () {
        var layer = layui.layer;
        var table = layui.table;

        // ---------- Tab 滑块 ----------
        var $tabBar = $('#appstoreTabBar');
        var $slider = $tabBar.find('.appstore-tab-slider');
        function moveSlider($tab) {
            if (!$tab.length) return;
            $slider.css({ left: $tab[0].offsetLeft + 'px', width: $tab.outerWidth() + 'px' });
        }
        moveSlider($tabBar.find('.appstore-tab.active'));
        $(window).on('resize.appstore', function () {
            moveSlider($tabBar.find('.appstore-tab.active'));
        });

        function currentFilter() {
            var raw = $tabBar.find('.appstore-tab.active').attr('data-filter');
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
            url: '/user/merchant/appstore.php?_action=list',
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
                { field: 'cover', title: '封面', width: 70, align: 'center', unresize: true,
                  templet: function (d) {
                      if (!d.cover) return '<span class="appstore-cover appstore-cover--empty"><i class="fa fa-cube"></i></span>';
                      var imgs = (Array.isArray(d.images) && d.images.length > 0 ? d.images : [d.cover]).map(appstoreAbsUrl);
                      return '<img class="appstore-cover appstore-cover--zoom" src="' + appstoreAbsUrl(d.cover) +
                             '" alt="" data-imgs="' + encodeURIComponent(JSON.stringify(imgs)) + '">';
                  } },
                { field: 'name_cn', title: '应用名称', minWidth: 240, templet: '#appstoreTitleTpl' },
                { field: 'install_num', title: '安装量', width: 100, templet: '#appstoreInstallTpl', align: 'center', sort: true },
                { field: 'vip_price', title: '价格', width: 120, templet: '#appstorePriceTpl', align: 'center' },
                { title: '操作', width: 160, align: 'center', toolbar: '#appstoreActionTpl' }
            ]]
        });

        function reloadTable() {
            table.reload('appstoreTableId', {
                where: buildWhere(),
                page: { curr: 1 }
            });
        }

        $tabBar.on('click', '.appstore-tab', function () {
            var $t = $(this);
            if ($t.hasClass('active')) return;
            $tabBar.find('.appstore-tab').removeClass('active');
            $t.addClass('active');
            moveSlider($t);
            reloadTable();
        });

        var searchTimer;
        $('#appstoreSearch').on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(reloadTable, 300);
        });

        // 封面点击放大（商户侧没有 Viewer.js，用 layer.photos 代替）
        $(document).off('click.appstoreCover').on('click.appstoreCover', '.appstore-cover--zoom', function () {
            var raw = $(this).attr('data-imgs');
            var imgs = [];
            try { imgs = JSON.parse(decodeURIComponent(raw || '')); } catch (e) {}
            if (!imgs.length) return;
            var photosData = imgs.map(function (src, idx) {
                return { alt: '预览', pid: idx, src: src };
            });
            layer.photos({ photos: { title: '', id: 0, start: 0, data: photosData }, anim: 5 });
        });

        // ---------- 安装 / 更新 ----------
        function installApp(d, action) {
            var displayName = d.name_cn || d.name_en || d.id;
            var typeLabel = d.type === 'template' ? '模板' : '插件';
            var actionLabel = action === 'update' ? '更新' : '安装';
            var loadingIdx = layer.load(2, { shade: [0.3, '#000'] });
            $.post('/user/merchant/appstore.php', {
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

        // ---------- 购买：打开独立 iframe 弹窗 ----------
        function openPurchaseModal(app) {
            var id = parseInt(app.id, 10) || 0;
            if (!id) { layer.msg('应用标识缺失，无法发起购买'); return; }
            layer.open({
                type: 2,
                title: '应用购买',
                skin: 'admin-modal',
                area: [window.innerWidth >= 640 ? '600px' : '94%', window.innerHeight >= 720 ? '520px' : '88%'],
                shadeClose: true,
                maxmin: false,
                content: '/user/merchant/appstore_buy.php?id=' + id
            });
        }

        // ---------- 行操作 ----------
        table.on('tool(appstoreTable)', function (obj) {
            var d = obj.data;
            if (obj.event === 'install')          installApp(d, 'install');
            else if (obj.event === 'update')      installApp(d, 'update');
            else if (obj.event === 'buy')         openPurchaseModal(d);
            else if (obj.event === 'goManage') {
                // 已装：跳到商户自己的插件/模板管理页
                var url = d.type === 'template' ? '/user/merchant/theme.php' : '/user/merchant/plugin.php';
                if ($.pjax) $.pjax({ url: url, container: '#merchantContent' });
                else location.href = url;
            }
            else if (obj.event === 'needLicense') {
                // 主站未激活：商户自己改变不了，给个说明
                layer.msg('付费应用需要主站管理员先激活正版授权', { time: 2500 });
            }
        });

        // ---------- 分类 Tab 异步渲染 ----------
        function escapeHtml(s) {
            return String(s || '').replace(/[&<>"']/g, function (c) {
                return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
            });
        }
        function renderTabs(list) {
            $tabBar.find('.appstore-tab').remove();
            var html = '';
            list.forEach(function (c, idx) {
                var filter = { type: c.type || '', id: c.id || 0 };
                var cls = idx === 0 ? 'appstore-tab active' : 'appstore-tab';
                html += '<span class="' + cls + '" data-filter="' + escapeHtml(JSON.stringify(filter)) + '">'
                     +      escapeHtml(c.name)
                     +      ' <em class="appstore-tab-count">' + (c.count != null ? c.count : 0) + '</em>'
                     + '</span>';
            });
            $tabBar.append(html);
            moveSlider($tabBar.find('.appstore-tab.active'));
        }
        var catLoadingIdx = layer.load(2, { shade: [0.3, '#000'] });
        $.ajax({
            url: '/user/merchant/appstore.php',
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
