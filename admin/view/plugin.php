<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
?>
<style>
/* 和控制台 / 模板管理一致：去掉 .admin-page 默认白底，卡片浮在灰底画布上 */
.admin-page-plugin { padding: 8px 4px 40px; background: unset; }

/* 顶部工具条：刷新 + 应用商店（参考模板管理）；不再有搜索 */
.plugin-filter-bar { display:flex; align-items:center; gap:8px; margin-bottom:16px; }
.plugin-appstore-btn { height:32px; padding:0 14px; border:1px solid rgba(99,102,241,.3); border-radius:8px; background:rgba(99,102,241,.06); font-size:13px; font-weight:500; color:#6366f1; cursor:pointer; display:flex; align-items:center; gap:6px; transition:all .15s; }
.plugin-appstore-btn:hover { background:rgba(99,102,241,.12); border-color:rgba(99,102,241,.5); }

/* 选项卡行：em-tabs 本身已有边框圆角，margin-bottom 已经在公共样式里 */

/* ===== 加载骨架屏 ===== */
.plugin-skeleton {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}
.plugin-skeleton-card {
    background: #fff;
    border: 1px solid #f0f0f0;
    border-radius: 14px;
    padding: 16px;
    animation: skeleton-pulse 1.5s ease-in-out infinite;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
}
.skeleton-header { display: flex; gap: 12px; margin-bottom: 12px; }
.skeleton-icon { width: 48px; height: 48px; border-radius: 10px; background: #f0f0f0; flex-shrink: 0; }
.skeleton-lines { flex: 1; }
.skeleton-line { height: 12px; background: #f0f0f0; border-radius: 6px; margin-bottom: 8px; }
.skeleton-line--short { width: 60%; }
.skeleton-line--medium { width: 80%; }
.skeleton-desc { height: 10px; background: #f0f0f0; border-radius: 5px; margin-bottom: 6px; }
@keyframes skeleton-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }

/* ===== 卡片网格 ===== */
.plugin-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }

/* ===== 插件卡片 ===== */
.plugin-card {
    background: #fff;
    border: 1px solid #e8e8ec;
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    position: relative;
    transition: border-color .2s, box-shadow .2s, transform .2s;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
}
.plugin-card:hover { border-color: #a5b4fc; box-shadow: 0 4px 20px rgba(99, 102, 241, 0.1); transform: translateY(-1px); }

/* 卡片头部 */
.plugin-card__header { display: flex; align-items: center; gap: 12px; padding: 16px 16px 12px; }
.plugin-card__icon-wrap { flex-shrink: 0; position: relative; cursor: pointer; }
.plugin-card__icon { width: 48px; height: 48px; border-radius: 10px; object-fit: cover; }
.plugin-card__icon--default { width: 48px; height: 48px; border-radius: 5px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.08)); display: flex; align-items: center; justify-content: center; color: #4f46e5; font-size: 22px; }
.plugin-card__info { flex: 1; min-width: 0; }
.plugin-card__title-row { display: flex; align-items: center; gap: 8px; }
.plugin-card__title { font-size: 15px; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.plugin-card__meta { display: flex; align-items: center; gap: 6px; margin-top: 4px; flex-wrap: wrap; }
.plugin-card__version { font-size: 12px; color: #9ca3af; }
.plugin-card__category { display: inline-flex; align-items: center; font-size: 12px; font-weight: 500; color: #9ca3af; background: rgba(0, 0, 0, 0.05); padding: 2px 8px; border-radius: 5px; flex-shrink: 0; }

/* 更多操作按钮 */
.plugin-card__more { position: relative; flex-shrink: 0; }
.plugin-card__more-btn { width: 28px; height: 28px; border: none; background: transparent; border-radius: 6px; cursor: pointer; color: #d1d5db; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: all .15s; }
.plugin-card__more-btn:hover { background: #f5f5f7; color: #6b7280; }
.plugin-card__more-dropdown { position: absolute; top: calc(100% + 4px); right: 0; background: #fff; border: 1px solid #e8e8ec; border-radius: 8px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); min-width: 120px; z-index: 100; overflow: hidden; display: none; }
.plugin-card__more-dropdown--open { display: block; }
.plugin-card__more-item { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 13px; color: #374151; cursor: pointer; border: none; background: transparent; width: 100%; text-align: left; transition: background .1s; border-radius: 0; }
.plugin-card__more-item:hover { background: #f5f5f7; }
.plugin-card__more-item--danger { color: #dc2626; }
.plugin-card__more-item--danger:hover { background: rgba(220, 38, 38, 0.04); }
.plugin-card__more-item i { width: 14px; text-align: center; font-size: 12px; }

/* 卡片内容 */
.plugin-card__body { padding: 0 16px 12px; flex: 1; }
.plugin-card__author { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: #9ca3af; }
.plugin-card__author a { color: #4f46e5; text-decoration: none; }
.plugin-card__author a:hover { text-decoration: underline; }
.plugin-card__meta-row { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.plugin-card__status { display: inline-flex; align-items: center; font-size: 12px; font-weight: 500; padding: 2px 8px; border-radius: 5px; flex-shrink: 0; }
.plugin-card__status--enabled { background: rgba(5, 150, 105, 0.9); color: #fff; animation: statusBreath 2s ease-in-out infinite; }
.plugin-card__status--disabled { background: rgba(0, 0, 0, 0.05); color: #9ca3af; }
@keyframes statusBreath {
    0%, 100% { background: rgba(5, 150, 105, 0.9); box-shadow: 0 0 0 0 rgba(5, 150, 105, 0); }
    50% { background: rgba(5, 150, 105, 0.98); box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15); }
}
.plugin-card__desc { font-size: 13px; color: #6b7280; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

/* 卡片底部操作栏 */
.plugin-card__footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 16px; border-top: 1px solid #f5f5f7; }

/* 开关 */
.plugin-toggle { position: relative; width: 40px; height: 22px; flex-shrink: 0; }
.plugin-toggle input { opacity: 0; width: 0; height: 0; }
.plugin-toggle__slider { position: absolute; inset: 0; background: #e5e7eb; border-radius: 22px; cursor: pointer; transition: background .2s; }
.plugin-toggle__slider::before { content: ''; position: absolute; width: 16px; height: 16px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: transform .2s; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15); }
.plugin-toggle input:checked + .plugin-toggle__slider { background: #4f46e5; }
.plugin-toggle input:checked + .plugin-toggle__slider::before { transform: translateX(18px); }
.plugin-toggle input:disabled + .plugin-toggle__slider { opacity: 0.5; cursor: not-allowed; }

/* 底部主操作按钮 */
.plugin-card__action { height: 32px; padding: 0 14px; border: none; border-radius: 7px; font-size: 12px; font-weight: 500; cursor: pointer; transition: all .15s; font-family: inherit; display: flex; align-items: center; gap: 5px; }
.plugin-card__action--install { background: rgba(99, 102, 241, 0.1); color: #4f46e5; }
.plugin-card__action--install:hover { background: rgba(99, 102, 241, 0.18); }
.plugin-card__action--settings { background: rgba(59, 130, 246, 0.08); color: #2563eb; }
.plugin-card__action--settings:hover { background: rgba(59, 130, 246, 0.15); }
.plugin-card__action--disabled { background: rgba(0, 0, 0, 0.04) !important; color: #c9cdd4 !important; cursor: not-allowed !important; }

/* ===== 空状态 ===== */
.plugin-empty { text-align: center; padding: 80px 20px; }
.plugin-empty__icon { width: 72px; height: 72px; border-radius: 20px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(129, 140, 248, 0.05)); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 32px; color: #a5b4fc; }
.plugin-empty__text { font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 8px; }
.plugin-empty__sub { font-size: 13px; color: #9ca3af; margin-bottom: 24px; }
.plugin-empty__sub code { background: #f5f5f7; padding: 2px 8px; border-radius: 5px; font-family: 'Courier New', monospace; font-size: 12px; color: #4f46e5; }
</style>

<div class="admin-page admin-page-plugin">
    <h1 class="admin-page__title">插件管理</h1>

    <!-- 工具条：刷新 + 应用商店 -->
    <div class="plugin-filter-bar">
        <a class="em-btn em-reset-btn" id="pluginRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <button class="plugin-appstore-btn" id="pluginAppstoreBtn" type="button"><i class="fa fa-shopping-basket"></i> 去逛逛应用商店</button>
    </div>

    <!-- 分类筛选（em-tabs + em-tabs__count，动态填数字） -->
    <div class="em-tabs" id="pluginTabs">
        <a class="em-tabs__item is-active" data-filter="all"><i class="fa fa-puzzle-piece"></i>我的插件<em class="em-tabs__count" id="countAll"></em></a>
        <a class="em-tabs__item" data-filter="enabled"><i class="fa fa-bolt"></i>已启用<em class="em-tabs__count" id="countEnabled"></em></a>
        <a class="em-tabs__item" data-filter="disabled"><i class="fa fa-ban"></i>已禁用<em class="em-tabs__count" id="countDisabled"></em></a>
    </div>

    <!-- 骨架屏 -->
    <div class="plugin-skeleton" id="pluginSkeleton">
        <?php for ($i = 0; $i < 3; $i++): ?>
        <div class="plugin-skeleton-card">
            <div class="skeleton-header">
                <div class="skeleton-icon"></div>
                <div class="skeleton-lines">
                    <div class="skeleton-line skeleton-line--short"></div>
                    <div class="skeleton-line skeleton-line--medium"></div>
                </div>
            </div>
            <div class="skeleton-desc" style="width:90%"></div>
            <div class="skeleton-desc" style="width:70%"></div>
        </div>
        <?php endfor; ?>
    </div>

    <!-- 插件卡片网格 -->
    <div class="plugin-grid" id="pluginGrid" style="display:none;"></div>

    <!-- 空状态 -->
    <div class="plugin-empty" id="pluginEmpty" style="display:none;">
        <div class="plugin-empty__icon"><i class="fa fa-puzzle-piece"></i></div>
        <div class="plugin-empty__text">暂未检测到任何插件</div>
        <div class="plugin-empty__sub">请将插件包解压后放入 <code>content/plugin/</code> 目录</div>
    </div>
</div>

<script>
$(function(){
    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var allPlugins = [];
    var currentFilter = 'all';
    var loading = false;

    function updateCsrf(token) { if (token) csrfToken = token; }
    window.updateCsrf = updateCsrf;

    // ===== 渲染统计（em-tabs__count 空值时自动隐藏，所以 0 时不填数字）=====
    function renderStats(plugins) {
        var total = plugins.length;
        var enabled = 0, disabled = 0;
        plugins.forEach(function(p) {
            if (p.is_enabled) enabled++;
            else disabled++;
        });
        $('#countAll').text(total || '');
        $('#countEnabled').text(enabled || '');
        $('#countDisabled').text(disabled || '');
    }

    // ===== 启停后只刷新本卡片状态 + 内存 + 计数(不重拉列表) =====
    function applyToggleState(name, isEnabled) {
        var $card = $('.plugin-card[data-name="' + name + '"]');
        $card.toggleClass('plugin-card--enabled', isEnabled);
        var $status = $card.find('.plugin-card__status');
        if ($status.length) {
            $status
                .removeClass('plugin-card__status--enabled plugin-card__status--disabled')
                .addClass(isEnabled ? 'plugin-card__status--enabled' : 'plugin-card__status--disabled')
                .text(isEnabled ? '生效中' : '未启用');
        }
        // 同步内存数组,保证切 filter / 后续重渲染时状态正确
        for (var i = 0; i < allPlugins.length; i++) {
            if (allPlugins[i].name === name) {
                allPlugins[i].is_enabled = isEnabled;
                break;
            }
        }
        renderStats(allPlugins);
    }

    // ===== 渲染插件卡片 =====
    function renderPlugins(plugins) {
        var $grid = $('#pluginGrid');
        var $empty = $('#pluginEmpty');
        var $skeleton = $('#pluginSkeleton');

        $grid.empty();
        $skeleton.hide();

        var filtered = filterPlugins(plugins);
        if (filtered.length === 0) {
            $grid.hide();
            $empty.show();
            return;
        }
        $empty.hide();
        $grid.show();

        filtered.forEach(function(p) {
            $grid.append(buildCard(p));
        });
    }

    function filterPlugins(plugins) {
        return plugins.filter(function(p) {
            return (currentFilter === 'all') ||
                (currentFilter === 'enabled' && p.is_enabled) ||
                (currentFilter === 'disabled' && !p.is_enabled);
        });
    }

    function buildCard(p) {
        var isInstalled = p.is_installed;
        var isEnabled = p.is_enabled;
        var hasSettings = !!p.setting_file;

        var cls = 'plugin-card';
        if (isInstalled && isEnabled) cls += ' plugin-card--enabled';

        var iconHtml;
        if (p.preview) {
            iconHtml = '<img src="' + escHtml(p.preview) + '" class="plugin-card__icon" alt="">';
        } else if (p.icon) {
            iconHtml = '<img src="' + escHtml(p.icon) + '" class="plugin-card__icon" alt="">';
        } else {
            iconHtml = '<div class="plugin-card__icon plugin-card__icon--default"><i class="fa fa-puzzle-piece"></i></div>';
        }

        var authorHtml = '';
        if (p.author) {
            if (p.author_url) {
                authorHtml = '<span class="plugin-card__author"><i class="fa fa-user-o"></i><a href="' + escHtml(p.author_url) + '" target="_blank">' + escHtml(p.author) + '</a></span>';
            } else {
                authorHtml = '<span class="plugin-card__author"><i class="fa fa-user-o"></i>' + escHtml(p.author) + '</span>';
            }
        }

        var statusHtml = '';
        if (isInstalled) {
            statusHtml = '<span class="plugin-card__status ' + (isEnabled ? 'plugin-card__status--enabled' : 'plugin-card__status--disabled') + '">' + (isEnabled ? '生效中' : '未启用') + '</span>';
        }

        var categoryHtml = '';
        if (p.category) {
            categoryHtml = '<span class="plugin-card__category">' + escHtml(p.category) + '</span>';
        }

        var actionHtml = '';
        if (!isInstalled) {
            actionHtml = '<button class="plugin-card__action plugin-card__action--install" data-action="install" data-name="' + escHtml(p.name) + '"><i class="fa fa-download"></i> 安装</button>';
        } else if (hasSettings) {
            actionHtml = '<button class="plugin-card__action plugin-card__action--settings" data-action="settings" data-name="' + escHtml(p.name) + '"><i class="fa fa-gear"></i> 配置</button>';
        } else {
            actionHtml = '<button class="plugin-card__action plugin-card__action--settings plugin-card__action--disabled" data-tip="该插件无需配置"><i class="fa fa-gear"></i> 配置</button>';
        }

        var toggleDisabled = !isInstalled ? ' disabled' : '';

        var card = $('<div>', {class: cls, 'data-name': p.name});
        card.html('<div class="plugin-card__header">' +
                '<div class="plugin-card__icon-wrap">' + iconHtml + '</div>' +
                '<div class="plugin-card__info">' +
                    '<div class="plugin-card__title-row">' +
                        '<div class="plugin-card__title">' + escHtml(p.title || p.name) + '</div>' +
                    '</div>' +
                    '<div class="plugin-card__meta">' +
                        '<span class="plugin-card__version">v' + escHtml(p.version || '1.0') + '</span>' +
                        categoryHtml +
                        statusHtml +
                    '</div>' +
                '</div>' +
                '<div class="plugin-card__more">' +
                    '<button class="plugin-card__more-btn"><i class="fa fa-ellipsis-h"></i></button>' +
                    '<div class="plugin-card__more-dropdown">' +
                        (isInstalled
                            ? '<button class="plugin-card__more-item" data-action="uninstall" data-name="' + escHtml(p.name) + '"><i class="fa fa-trash"></i> 卸载</button>'
                              + (p.has_update ? '<button class="plugin-card__more-item" data-action="upgrade" data-name="' + escHtml(p.name) + '"><i class="fa fa-arrow-up"></i> 升级</button>' : '')
                            : ''
                        ) +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="plugin-card__body">' +
                (authorHtml ? '<div class="plugin-card__meta-row">' + authorHtml + '</div>' : '') +
                '<div class="plugin-card__desc" data-tip="' + escHtml(p.description || '') + '">' + escHtml(p.description || '暂无描述') + '</div>' +
            '</div>' +
            '<div class="plugin-card__footer">' +
                '<div class="plugin-toggle">' +
                    '<input type="checkbox" id="toggle_' + escHtml(p.name) + '"' + toggleDisabled + (isEnabled ? ' checked' : '') + ' data-action="toggle" data-name="' + escHtml(p.name) + '">' +
                    '<label class="plugin-toggle__slider" for="toggle_' + escHtml(p.name) + '"' + (!isInstalled ? ' data-tip="插件未安装，该功能不可用"' : '') + '></label>' +
                '</div>' +
                actionHtml +
            '</div>'
        );
        return card;
    }

    function escHtml(str) {
        if (str == null) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ===== 加载插件列表 =====
    function loadPlugins() {
        if (loading) return;
        loading = true;
        $('#pluginSkeleton').show();
        $('#pluginGrid, #pluginEmpty').hide();

        $.ajax({
            url: '/admin/plugin.php?_action=list',
            type: 'GET',
            dataType: 'json',
            success: function(res) {
                if (res.code === 0 || res.code === 200) {
                    csrfToken = res.csrf_token || csrfToken;
                    allPlugins = res.data || [];
                    renderStats(allPlugins);
                    renderPlugins(allPlugins);
                }
            },
            error: function() {
                layui.use('layer', function(){ layui.layer.msg('加载插件列表失败'); });
                $('#pluginSkeleton').hide();
            },
            complete: function() { loading = false; }
        });
    }

    // ===== 操作处理 =====
    function handleAction(action, name, extraData) {
        if (action === 'toggle') {
            action = $('#toggle_' + name).prop('checked') ? 'enable' : 'disable';
        }
        if (action === 'settings') { openSettings(name); return; }

        if (action === 'uninstall') {
            var pluginTitle = name;
            for (var i = 0; i < allPlugins.length; i++) {
                if (allPlugins[i].name === name) { pluginTitle = allPlugins[i].title || name; break; }
            }
            layui.use('layer', function(){
                var layer = layui.layer;
                layer.confirm('卸载将清除插件「' + pluginTitle + '」的配置数据并删除磁盘文件，无法恢复。确认卸载？',
                    {icon: 3, title: '卸载确认', skin: 'admin-modal'},
                    function(idx){
                        doRequest(action, name, {clear_data: false}, function(){ layer.close(idx); });
                    });
            });
            return;
        }

        doRequest(action, name, extraData);
    }

    function doRequest(action, name, extraData, callback) {
        var postData = {csrf_token: csrfToken, _action: action, name: name};
        if (extraData) { for (var k in extraData) postData[k] = extraData[k]; }
        $.ajax({
            url: '/admin/plugin.php',
            type: 'POST',
            dataType: 'json',
            data: postData,
            success: function(res) {
                if (res.code === 0 || res.code === 200) {
                    csrfToken = (res.data && res.data.csrf_token) ? res.data.csrf_token : csrfToken;
                    layui.layer.msg(res.msg || '操作成功');
                    // 启停只局部刷新当前卡片 + 计数,不重拉列表(避免页面闪动)
                    // 其它操作(uninstall / delete / install / upgrade)涉及结构性变化,仍走全量刷新
                    if (action === 'enable' || action === 'disable') {
                        applyToggleState(name, action === 'enable');
                    } else {
                        loadPlugins();
                    }
                    if (callback) callback();
                } else {
                    layui.layer.msg(res.msg || '操作失败');
                    if (action === 'enable' || action === 'disable') {
                        $('#toggle_' + name).prop('checked', action === 'disable');
                    }
                }
            },
            error: function() {
                layui.layer.msg('网络异常');
                if (action === 'enable' || action === 'disable') {
                    $('#toggle_' + name).prop('checked', action === 'disable');
                }
                if (callback) callback();
            }
        });
    }

    function openSettings(name) {
        layui.use('layer', function(){
            var layer = layui.layer;
            layer.open({
                type: 2,
                title: '插件设置',
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 800 ? '600px' : '95%', window.innerHeight >= 600 ? '75%' : '90%'],
                shadeClose: true,
                content: '/admin/plugin.php?_popup=1&name=' + encodeURIComponent(name),
                id: 'plugin_settings_' + name
            });
        });
    }

    // ===== 事件绑定 =====

    // 刷新
    $('#pluginRefreshBtn').on('click', function(){ loadPlugins(); });

    // 应用商店
    $('#pluginAppstoreBtn').on('click', function(){
        $.pjax({ url: '/admin/appstore.php', container: '#adminContent' });
    });

    // em-tabs 分类筛选：同款切换（是否已激活跳过）
    $('#pluginTabs').on('click', '.em-tabs__item', function(){
        var $item = $(this);
        if ($item.hasClass('is-active')) return;
        $item.addClass('is-active').siblings().removeClass('is-active');
        currentFilter = $item.data('filter');
        renderPlugins(allPlugins);
    });

    // 解绑旧事件防止 PJAX 重复绑定
    $(document).off('.emPlugin');

    // 禁用按钮的 tips 提示
    layui.use('layer', function(){
        var layer = layui.layer;
        $(document).on('mouseenter.emPlugin', '[data-tip]', function(){
            layer.tips($(this).data('tip'), this, { tips: [1, '#555'], time: 0, closeBtn: true });
        }).on('mouseleave.emPlugin', '[data-tip]', function(){
            layer.closeAll('tips');
        });

        // 插件图标点击放大：Viewer.js（全局已加载）
        $(document).on('click.emPlugin', '.plugin-card__icon-wrap img', function(){
            var src = $(this).attr('src');
            if (!src) return;
            var $tmp = $('<div style="display:none;"><img src="' + src + '"></div>').appendTo('body');
            var viewer = new Viewer($tmp[0], {
                navbar: false, title: false, toolbar: true,
                hidden: function () { viewer.destroy(); $tmp.remove(); }
            });
            viewer.show();
        });
    });

    // 卡片内按钮点击
    $(document).on('click.emPlugin', '.plugin-card__action, .plugin-card__more-item', function(e){
        e.stopPropagation();
        var action = $(this).data('action');
        var name = $(this).data('name');
        handleAction(action, name);
    });

    // 开关切换
    $(document).on('change.emPlugin', '.plugin-toggle input', function(){
        var name = $(this).data('name');
        var action = $(this).prop('checked') ? 'enable' : 'disable';
        handleAction(action, name);
    });

    // 三点菜单
    $(document).on('click.emPlugin', '.plugin-card__more-btn', function(e){
        e.stopPropagation();
        var $dropdown = $(this).siblings('.plugin-card__more-dropdown');
        $('.plugin-card__more-dropdown').not($dropdown).removeClass('plugin-card__more-dropdown--open');
        $dropdown.toggleClass('plugin-card__more-dropdown--open');
    });

    // 点击其他地方关闭菜单
    $(document).on('click.emPlugin', function(){
        $('.plugin-card__more-dropdown').removeClass('plugin-card__more-dropdown--open');
    });

    // 初始加载
    loadPlugins();
});
</script>
