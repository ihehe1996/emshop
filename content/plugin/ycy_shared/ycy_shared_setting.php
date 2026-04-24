<?php
/**
 * 异次元共享店铺 - 设置页。
 *
 * 渲染：plugin_setting_view()      由 admin/plugin.php 的 ?_action=settings 调用
 * 保存：plugin_setting()            由 admin/plugin.php 的 ?_action=save_config 调用
 *   内部根据 _sub_action 分发到不同操作：site_list / site_save / site_delete / site_test / site_toggle
 */

defined('EM_ROOT') || exit('Access Denied');

use YcyShared\Client;
use YcyShared\SiteModel;
use YcyShared\ImportService;
use YcyShared\SyncService;
use YcyShared\DeliveryService;

function plugin_setting_view(): void
{
    $csrfToken = Csrf::token();
    ?>
    <style>
    .ycy-tab { margin: 0; }
    .ycy-tab > .layui-tab-title { padding: 0 10px; background: #fafafa; border-bottom: 1px solid #e6e6e6; }
    .ycy-tab > .layui-tab-title li { font-size: 13px; padding: 0 15px; }
    .ycy-tab > .layui-tab-title li .fa { margin-right: 4px; }
    .ycy-tab > .layui-tab-content > .layui-tab-item { padding: 0; }
    .ycy-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-bottom: 1px solid #f0f0f0; background: #fff; }
    .ycy-toolbar__left { font-size: 12px; color: #9ca3af; }
    .ycy-site-badge--v3 { background: #eff6ff; color: #2563eb; }
    .ycy-site-badge--v4 { background: #f5f3ff; color: #7c3aed; }
    .ycy-site-badge { display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; }
    </style>
    <div class="popup-inner">
        <div class="layui-tab layui-tab-brief ycy-tab" lay-filter="ycyTab">
            <ul class="layui-tab-title">
                <li class="layui-this"><i class="fa fa-globe"></i> 站点管理</li>
                <li><i class="fa fa-cubes"></i> 商品同步</li>
                <li><i class="fa fa-list-alt"></i> 代付流水</li>
                <li><i class="fa fa-sliders"></i> 全局配置</li>
            </ul>
            <div class="layui-tab-content">
                <!-- 站点管理 -->
                <div class="layui-tab-item layui-show">
                    <div class="ycy-toolbar">
                        <span class="ycy-toolbar__left">上游 YCY / MCY 站点；同一站点下所有商品使用同一套 app_id / app_key 拉取</span>
                        <button type="button" class="popup-btn popup-btn--primary" id="ycyAddSiteBtn"><i class="fa fa-plus"></i> 新增站点</button>
                    </div>
                    <table id="ycySiteTable" lay-filter="ycySiteTable"></table>
                </div>

                <!-- 商品同步 -->
                <div class="layui-tab-item">
                    <div class="ycy-toolbar" style="flex-wrap:wrap;gap:10px;">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <label style="color:#4b5563;font-size:13px;">站点：</label>
                            <select id="ycyCatalogSite" style="height:32px;border:1px solid #e5e7eb;border-radius:4px;padding:0 10px;font-size:13px;">
                                <option value="">请选择</option>
                            </select>
                            <button type="button" class="popup-btn popup-btn--default" id="ycyCatalogFetch"><i class="fa fa-refresh"></i> 拉取目录</button>
                            <div style="position:relative;margin-left:8px;">
                                <i class="fa fa-search" style="position:absolute;left:10px;top:9px;color:#9ca3af;font-size:12px;"></i>
                                <input type="text" id="ycyCatalogSearch" placeholder="按名称 / Ref 搜索"
                                    style="height:32px;padding:0 10px 0 28px;border:1px solid #e5e7eb;border-radius:4px;font-size:12px;width:180px;">
                            </div>
                            <label style="font-size:12px;color:#4b5563;display:inline-flex;align-items:center;gap:4px;margin-left:6px;">
                                <select id="ycyCatalogFilter" style="height:28px;border:1px solid #e5e7eb;border-radius:4px;padding:0 8px;font-size:12px;">
                                    <option value="all">全部</option>
                                    <option value="imported">仅已导入</option>
                                    <option value="pending">仅未导入</option>
                                </select>
                            </label>
                        </div>
                        <div>
                            <span id="ycyCatalogSelCount" style="color:#9ca3af;font-size:12px;margin-right:10px;">已选 0 项</span>
                            <button type="button" class="popup-btn popup-btn--primary" id="ycyCatalogImport" disabled><i class="fa fa-download"></i> 导入选中</button>
                        </div>
                    </div>
                    <table id="ycyCatalogTable" lay-filter="ycyCatalogTable"></table>
                </div>

                <!-- 代付流水 -->
                <div class="layui-tab-item">
                    <div class="ycy-toolbar">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <label style="color:#4b5563;font-size:13px;">状态：</label>
                            <select id="ycyTradeStatus" style="height:32px;border:1px solid #e5e7eb;border-radius:4px;padding:0 10px;font-size:13px;">
                                <option value="">全部</option>
                                <option value="success">成功</option>
                                <option value="pending">进行中</option>
                                <option value="failed">失败</option>
                            </select>
                            <button type="button" class="popup-btn popup-btn--default" id="ycyTradeRefresh"><i class="fa fa-refresh"></i> 刷新</button>
                        </div>
                        <span style="color:#9ca3af;font-size:12px;">由订单支付后自动代付上游触发，失败会自动重试</span>
                    </div>
                    <table id="ycyTradeTable" lay-filter="ycyTradeTable"></table>
                </div>

                <!-- 全局配置 -->
                <div class="layui-tab-item">
                    <div style="padding:20px 22px;max-width:680px;">
                        <form class="layui-form" id="ycyConfigForm">
                            <div class="layui-form-item">
                                <label class="layui-form-label" style="width:160px;">库存 0 自动下架</label>
                                <div class="layui-input-block" style="margin-left:190px;">
                                    <input type="checkbox" id="ycyCfgAutoOff" name="auto_off_sale_on_empty" lay-skin="switch" lay-text="启用|停用">
                                </div>
                                <div class="layui-form-mid layui-word-aux" style="margin-left:190px;color:#9ca3af;font-size:12px;line-height:1.7;">
                                    启用后：每次库存同步（3 分钟一次）发现上游库存为 0，会自动把对应本地商品设为"已下架"；<br>
                                    库存恢复 ≥ 1 时再自动"上架"。<strong style="color:#f59e0b;">注意</strong>：此开关会覆盖手动上下架状态，需要手工控制时请保持停用。
                                </div>
                            </div>
                            <div class="layui-form-item" style="padding-top:8px;">
                                <div class="layui-input-block" style="margin-left:190px;">
                                    <button type="button" class="popup-btn popup-btn--primary" id="ycyConfigSave"><i class="fa fa-check"></i> 保存配置</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 站点行操作模板 -->
    <script type="text/html" id="ycySiteRowActionTpl">
        <a class="layui-btn layui-btn-xs" lay-event="test"><i class="fa fa-plug"></i> 测试</a>
        <a class="layui-btn layui-btn-xs layui-btn-normal" lay-event="edit"><i class="fa fa-pencil"></i> 编辑</a>
        <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete"><i class="fa fa-trash"></i> 删除</a>
    </script>

    <!-- 版本徽标模板 -->
    <script type="text/html" id="ycySiteVersionTpl">
        <span class="ycy-site-badge ycy-site-badge--{{ d.version }}">{{ (d.version||'v3').toUpperCase() }}</span>
    </script>

    <!-- 启用开关模板 -->
    <script type="text/html" id="ycySiteEnabledTpl">
        <input type="checkbox" lay-skin="switch" lay-text="启用|停用" lay-filter="ycySiteEnabled" value="{{ d.id }}" {{ d.enabled == 1 ? 'checked' : '' }}>
    </script>

    <!-- 加价系数模板 -->
    <script type="text/html" id="ycySiteMarkupTpl">
        × {{ parseFloat(d.markup_ratio).toFixed(3) }} <span style="color:#9ca3af;font-size:11px;">（下限 ×{{ parseFloat(d.min_markup).toFixed(3) }}）</span>
    </script>

    <!-- 商品目录 - 已导入状态 -->
    <script type="text/html" id="ycyCatalogImportedTpl">
        {{# if(d.imported){ }}
            <span style="color:#059669;font-weight:600;"><i class="fa fa-check-circle"></i> 已导入</span>
        {{# } else { }}
            <span style="color:#cbd5e1;">未导入</span>
        {{# } }}
    </script>

    <!-- 商品目录 - 价格（显示上游原价 + 加价后售价） -->
    <script type="text/html" id="ycyCatalogPriceTpl">
        <div style="line-height:1.35;">
            <div>¥{{ parseFloat(d.price).toFixed(2) }}</div>
            <div style="font-size:11px;color:#9ca3af;">原价</div>
        </div>
    </script>

    <!-- 商品目录 - 库存 -->
    <script type="text/html" id="ycyCatalogStockTpl">
        {{# if(d.stock <= 0){ }}
            <span style="color:#e11d48;font-weight:600;">{{ d.stock }}</span>
        {{# } else if(d.stock < 10){ }}
            <span style="color:#f59e0b;font-weight:600;">{{ d.stock }}</span>
        {{# } else { }}
            {{ d.stock }}
        {{# } }}
    </script>

    <!-- 代付流水 - 状态 -->
    <script type="text/html" id="ycyTradeStatusTpl">
        {{# if(d.status === 'success'){ }}
            <span style="color:#059669;font-weight:600;"><i class="fa fa-check-circle"></i> 成功</span>
        {{# } else if(d.status === 'failed'){ }}
            <span style="color:#e11d48;font-weight:600;" title="{{ d.error_message||'' }}"><i class="fa fa-times-circle"></i> 失败</span>
        {{# } else { }}
            <span style="color:#f59e0b;"><i class="fa fa-clock-o"></i> 进行中</span>
        {{# } }}
    </script>

    <!-- 代付流水 - 金额 -->
    <script type="text/html" id="ycyTradeAmountTpl">
        ¥{{ (d.cost_amount_raw / 1000000).toFixed(2) }}
    </script>

    <!-- 代付流水 - 发货内容截断 -->
    <script type="text/html" id="ycyTradeContentTpl">
        {{# if(d.status === 'success'){ }}
            <a href="javascript:;" class="ycy-trade-view" data-id="{{ d.id }}" style="color:#6366f1;">查看卡密</a>
        {{# } else if(d.error_message){ }}
            <span style="color:#9ca3af;font-size:11px;" title="{{ d.error_message }}">{{ d.error_message.length > 30 ? d.error_message.substring(0,30) + '...' : d.error_message }}</span>
        {{# } else { }}
            <span style="color:#cbd5e1;">—</span>
        {{# } }}
    </script>

    <!-- 代付流水 - 操作（失败记录可重试） -->
    <script type="text/html" id="ycyTradeActionTpl">
        {{# if(d.status === 'failed'){ }}
            <a class="layui-btn layui-btn-xs layui-btn-warm" lay-event="retry"><i class="fa fa-refresh"></i> 重试</a>
        {{# } else { }}
            <span style="color:#cbd5e1;">—</span>
        {{# } }}
    </script>

    <!-- 商品同步 - 行操作 -->
    <script type="text/html" id="ycyCatalogActionTpl">
        {{# if(d.imported){ }}
            <a class="layui-btn layui-btn-xs" lay-event="syncOne"><i class="fa fa-refresh"></i> 同步</a>
            <a class="layui-btn layui-btn-xs layui-btn-normal" lay-event="setMarkup"><i class="fa fa-percent"></i> 加价</a>
        {{# } else { }}
            <a class="layui-btn layui-btn-xs layui-btn-blue" lay-event="importOne"><i class="fa fa-download"></i> 导入</a>
        {{# } }}
    </script>

    <script>
    var YCY_CSRF = <?= json_encode($csrfToken) ?>;

    layui.use(['layer', 'form', 'table', 'element'], function(){
        var $ = layui.$, layer = layui.layer, table = layui.table, form = layui.form, element = layui.element;
        element.render('tab');

        // ---------- 调用保存接口的通用工具 ----------
        // admin/plugin.php 从 POST body 读 _action / name / csrf_token，全部放 body 里
        function call(subAction, payload, done) {
            payload = payload || {};
            payload._action     = 'save_config';
            payload.name        = 'ycy_shared';
            payload._sub_action = subAction;
            payload.csrf_token  = YCY_CSRF;
            $.ajax({
                url: window.PLUGIN_SAVE_URL || '/admin/plugin.php',
                type: 'POST',
                dataType: 'json',
                data: payload
            }).done(function(res){
                if (res && (res.code === 0 || res.code === 200)) {
                    if (res.data && res.data.csrf_token) YCY_CSRF = res.data.csrf_token;
                    done && done(null, res.data || {});
                } else {
                    done && done(new Error((res && res.msg) || '请求失败'));
                }
            }).fail(function(xhr){
                var msg = '网络异常';
                try { var j = JSON.parse(xhr.responseText||'{}'); if (j.msg) msg = j.msg; } catch(e){}
                done && done(new Error(msg));
            });
        }

        // ---------- 站点列表 ----------
        function renderSiteTable(rows) {
            table.render({
                elem: '#ycySiteTable',
                id: 'ycySiteTable',
                data: rows,
                page: false,
                cellMinWidth: 80,
                cols: [[
                    { field: 'id',           title: 'ID',       width: 60,  align: 'center' },
                    { field: 'name',         title: '站点名',   minWidth: 140 },
                    { field: 'version',      title: '版本',     width: 80,  templet: '#ycySiteVersionTpl', align: 'center' },
                    { field: 'host',         title: '地址',     minWidth: 220 },
                    { field: 'markup_ratio', title: '加价',     width: 180, templet: '#ycySiteMarkupTpl' },
                    { field: 'enabled',      title: '启用',     width: 100, templet: '#ycySiteEnabledTpl', align: 'center', unresize: true },
                    { field: 'last_synced_at', title: '最近同步', width: 160, align: 'center',
                      templet: function(d){ return d.last_synced_at || '<span style="color:#cbd5e1;">—</span>'; } },
                    { title: '操作', width: 220, align: 'center', toolbar: '#ycySiteRowActionTpl' }
                ]]
            });
        }

        function loadSites() {
            call('site_list', {}, function(err, data){
                if (err) { layer.msg(err.message); return; }
                renderSiteTable(data.list || []);
            });
        }
        loadSites();

        // ---------- 新增/编辑表单弹窗 ----------
        function openSiteForm(row) {
            var isEdit = !!(row && row.id);
            var r = row || { version: 'v3', enabled: 1, markup_ratio: 1.2, min_markup: 1.05 };
            var html =
              '<div class="popup-inner" style="padding:14px 20px;">' +
                '<form class="layui-form" id="ycySiteForm">' +
                  '<input type="hidden" name="id" value="' + (r.id||'') + '">' +
                  '<div class="layui-form-item"><label class="layui-form-label">站点名</label>' +
                    '<div class="layui-input-block"><input class="layui-input" name="name" required lay-verify="required" value="' + escHtml(r.name||'') + '" placeholder="如：异次元主站"></div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">版本</label>' +
                    '<div class="layui-input-block">' +
                      '<input type="radio" name="version" value="v3" title="V3（异次元）"' + (r.version==='v4'?'':' checked') + '>' +
                      '<input type="radio" name="version" value="v4" title="V4（萌次元）"' + (r.version==='v4'?' checked':'') + '>' +
                    '</div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">站点地址</label>' +
                    '<div class="layui-input-block"><input class="layui-input" name="host" required lay-verify="required" value="' + escHtml(r.host||'') + '" placeholder="https://ycy.xxx.com"></div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">App ID</label>' +
                    '<div class="layui-input-block"><input class="layui-input" name="app_id" required lay-verify="required" value="' + escHtml(r.app_id||'') + '"></div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">App Key</label>' +
                    '<div class="layui-input-block"><input class="layui-input" name="app_key" required lay-verify="required" value="' + escHtml(r.app_key||'') + '"></div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">加价系数</label>' +
                    '<div class="layui-input-inline"><input class="layui-input" name="markup_ratio" value="' + (r.markup_ratio||1.2) + '" placeholder="1.200"></div>' +
                    '<div class="layui-form-mid layui-word-aux">本地价 = 上游价 × 此系数</div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">最低系数</label>' +
                    '<div class="layui-input-inline"><input class="layui-input" name="min_markup" value="' + (r.min_markup||1.05) + '" placeholder="1.050"></div>' +
                    '<div class="layui-form-mid layui-word-aux">防亏本兜底，系数不能低于此值</div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">启用</label>' +
                    '<div class="layui-input-block"><input type="checkbox" name="enabled" lay-skin="switch" lay-text="启用|停用"' + ((r.enabled==1||r.enabled===undefined)?' checked':'') + '></div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">备注</label>' +
                    '<div class="layui-input-block"><textarea class="layui-textarea" name="remark" rows="2">' + escHtml(r.remark||'') + '</textarea></div></div>' +
                '</form>' +
              '</div>';

            var idx = layer.open({
                type: 1, title: (isEdit?'编辑':'新增') + '上游站点', skin: 'admin-modal',
                area: ['560px','auto'], shadeClose: false, content: html,
                btn: ['测试连接并保存', '仅保存', '取消'],
                success: function(){ form.render(); },
                yes: function(){
                    submitSite(true, idx);
                },
                btn2: function(){
                    submitSite(false, idx);
                    return false;
                }
            });
        }

        function submitSite(withTest, layerIdx) {
            var data = serializeForm('#ycySiteForm');
            if (withTest) {
                call('site_test', data, function(err, resp){
                    if (err) { layer.msg('测试失败：' + err.message); return; }
                    layer.msg('连接成功：' + (resp.username||'-') + ' · 余额 ' + (resp.balance||0));
                    doSave(data, layerIdx);
                });
            } else {
                doSave(data, layerIdx);
            }
        }
        function doSave(data, layerIdx) {
            call('site_save', data, function(err){
                if (err) { layer.msg('保存失败：' + err.message); return; }
                layer.close(layerIdx);
                layer.msg('已保存');
                loadSites();
            });
        }

        $('#ycyAddSiteBtn').on('click', function(){ openSiteForm(null); });

        // ============================================================
        // 商品同步 Tab：拉目录 + 勾选导入
        // ============================================================
        var catalogLoaded = false;
        var catalogAllRows = []; // 本地缓存全量目录，搜索/筛选在前端做

        function applyCatalogFilter() {
            var kw = ($('#ycyCatalogSearch').val() || '').trim().toLowerCase();
            var mode = $('#ycyCatalogFilter').val();
            return catalogAllRows.filter(function(r){
                if (mode === 'imported' && !r.imported) return false;
                if (mode === 'pending' && r.imported) return false;
                if (kw) {
                    var hay = ((r.name||'') + ' ' + (r.ref||'') + ' ' + (r.category||'')).toLowerCase();
                    if (hay.indexOf(kw) < 0) return false;
                }
                return true;
            });
        }

        function ensureSiteOptions(sites) {
            var $sel = $('#ycyCatalogSite');
            if ($sel.children('option').length > 1) return; // 已渲染
            (sites || []).forEach(function(s){
                if (!s.enabled) return; // 只列启用站点
                $sel.append('<option value="' + s.id + '">' + escHtml(s.name) + '（' + (s.version||'v3').toUpperCase() + '）</option>');
            });
        }

        function renderCatalog(rows) {
            table.render({
                elem: '#ycyCatalogTable',
                id: 'ycyCatalogTable',
                data: rows,
                page: true,
                limit: 20,
                limits: [20, 50, 100],
                cellMinWidth: 80,
                cols: [[
                    { type: 'checkbox', width: 50, fixed: 'left' },
                    { field: 'ref',       title: '上游 Ref', width: 180 },
                    { field: 'name',      title: '商品名称', minWidth: 200 },
                    { field: 'category',  title: '分类',     width: 120 },
                    { field: 'price',     title: '上游原价', width: 100, align: 'right', templet: '#ycyCatalogPriceTpl' },
                    { field: 'stock',     title: '库存',     width: 80,  align: 'center', templet: '#ycyCatalogStockTpl' },
                    { field: 'imported',  title: '本地状态', width: 100, align: 'center', templet: '#ycyCatalogImportedTpl' },
                    { title: '操作',      width: 200, align: 'center', toolbar: '#ycyCatalogActionTpl' }
                ]],
                done: function(){
                    $('#ycyCatalogSelCount').text('已选 0 项');
                    $('#ycyCatalogImport').prop('disabled', true);
                }
            });
        }

        function loadCatalog() {
            var siteId = $('#ycyCatalogSite').val();
            if (!siteId) { layer.msg('请先选择站点'); return; }
            var loading = layer.load(2, { shade: 0.2 });
            call('catalog_list', { site_id: siteId }, function(err, data){
                layer.close(loading);
                if (err) { layer.msg('拉取失败：' + err.message); return; }
                catalogAllRows = (data && data.list) || [];
                if (!catalogAllRows.length) { layer.msg('该站点暂无商品'); renderCatalog([]); return; }
                renderCatalog(applyCatalogFilter());
                catalogLoaded = true;
            });
        }

        $('#ycyCatalogFetch').on('click', loadCatalog);

        // 本地搜索 / 筛选：防抖 150ms
        var catalogSearchTimer;
        $('#ycyCatalogSearch').on('input', function(){
            clearTimeout(catalogSearchTimer);
            catalogSearchTimer = setTimeout(function(){ renderCatalog(applyCatalogFilter()); }, 150);
        });
        $('#ycyCatalogFilter').on('change', function(){ renderCatalog(applyCatalogFilter()); });

        // 勾选变化 → 更新计数和导入按钮可用态
        table.on('checkbox(ycyCatalogTable)', function(){
            var checked = table.checkStatus('ycyCatalogTable').data;
            $('#ycyCatalogSelCount').text('已选 ' + checked.length + ' 项');
            $('#ycyCatalogImport').prop('disabled', checked.length === 0);
        });

        // 行操作：单个导入 / 立即同步 / 改加价
        table.on('tool(ycyCatalogTable)', function(obj){
            var d = obj.data;
            var siteId = $('#ycyCatalogSite').val();
            if (obj.event === 'importOne') {
                var loading = layer.load(2, { shade: 0.2 });
                call('catalog_import', { site_id: siteId, refs: [d.ref] }, function(err, resp){
                    layer.close(loading);
                    if (err) { layer.msg(err.message); return; }
                    var s = resp.stats || {};
                    layer.msg('新增 ' + (s.ok||0) + ' · 已存在 ' + (s.duplicated||0) + ' · 失败 ' + (s.fail||0));
                    loadCatalog();
                });
            } else if (obj.event === 'syncOne') {
                if (!d.local_goods_id) { layer.msg('请先导入'); return; }
                var loading = layer.load(2, { shade: 0.2 });
                call('goods_sync_one', { goods_id: d.local_goods_id }, function(err){
                    layer.close(loading);
                    if (err) { layer.msg(err.message); return; }
                    layer.msg('已同步最新价格/库存');
                    loadCatalog();
                });
            } else if (obj.event === 'setMarkup') {
                if (!d.local_goods_id) { layer.msg('请先导入'); return; }
                layer.prompt({
                    title: '设置加价系数（商品级，留空恢复站点默认）',
                    value: '', formType: 0
                }, function(val, idx){
                    call('goods_markup_save', { goods_id: d.local_goods_id, markup_ratio: val }, function(err){
                        if (err) { layer.msg(err.message); return; }
                        layer.close(idx);
                        layer.msg('已保存');
                        loadCatalog();
                    });
                });
            }
        });

        // 批量导入
        $('#ycyCatalogImport').on('click', function(){
            var siteId = $('#ycyCatalogSite').val();
            var checked = table.checkStatus('ycyCatalogTable').data;
            if (!siteId || !checked.length) return;
            // 过滤掉已经导入的（防止无谓调用）
            var refs = checked.filter(function(r){ return !r.imported; }).map(function(r){ return r.ref; });
            if (!refs.length) { layer.msg('选中项全部已导入'); return; }

            layer.confirm('将导入 ' + refs.length + ' 个上游商品到本地，确认继续？', {
                icon: 3, title: '导入确认', skin: 'admin-modal'
            }, function(idx){
                layer.close(idx);
                var loading = layer.load(2, { shade: [0.3, '#000'] });
                call('catalog_import', { site_id: siteId, refs: refs }, function(err, data){
                    layer.close(loading);
                    if (err) { layer.msg('导入失败：' + err.message); return; }
                    var s = data.stats || {};
                    layer.msg('新增 ' + (s.ok||0) + ' · 已存在 ' + (s.duplicated||0) + ' · 失败 ' + (s.fail||0));
                    // 刷新目录：重新拉取以更新 imported 状态
                    loadCatalog();
                });
            });
        });

        // Tab 切换：按需加载对应数据
        element.on('tab(ycyTab)', function(data){
            if (data.index === 1) {
                call('site_list', {}, function(err, d){
                    if (!err) ensureSiteOptions(d.list || []);
                });
            } else if (data.index === 2) {
                loadTrades();
            } else if (data.index === 3) {
                loadConfig();
            }
        });
        // 首次也刷一下站点列表
        call('site_list', {}, function(err, d){
            if (!err) ensureSiteOptions(d.list || []);
        });

        // ============================================================
        // 代付流水 Tab
        // ============================================================
        function renderTradeTable(rows) {
            table.render({
                elem: '#ycyTradeTable',
                id: 'ycyTradeTable',
                data: rows,
                page: true,
                limit: 20,
                limits: [20, 50, 100],
                cellMinWidth: 80,
                cols: [[
                    { field: 'id',                title: 'ID',        width: 60,  align: 'center' },
                    { field: 'site_name',         title: '站点',      width: 120 },
                    { field: 'upstream_ref',      title: '上游 Ref',  width: 160 },
                    { field: 'order_goods_id',    title: '订单行',    width: 90,  align: 'center' },
                    { field: 'upstream_trade_no', title: '上游订单号', width: 200 },
                    { field: 'quantity',          title: '数量',      width: 70,  align: 'center' },
                    { field: 'cost_amount_raw',   title: '上游成本',  width: 100, align: 'right', templet: '#ycyTradeAmountTpl' },
                    { field: 'status',            title: '状态',      width: 100, templet: '#ycyTradeStatusTpl', align: 'center' },
                    { field: 'response',          title: '内容/错误', minWidth: 160, templet: '#ycyTradeContentTpl' },
                    { field: 'created_at',        title: '创建时间',  width: 160 },
                    { title: '操作',              width: 100, align: 'center', toolbar: '#ycyTradeActionTpl' }
                ]]
            });
        }

        function loadTrades() {
            var status = $('#ycyTradeStatus').val();
            var loading = layer.load(2, { shade: 0.2 });
            call('trade_list', { status: status }, function(err, d){
                layer.close(loading);
                if (err) { layer.msg('加载失败：' + err.message); return; }
                renderTradeTable(d.list || []);
            });
        }
        $('#ycyTradeRefresh').on('click', loadTrades);
        $('#ycyTradeStatus').on('change', loadTrades);

        // 代付流水行操作：重试失败记录
        table.on('tool(ycyTradeTable)', function(obj){
            if (obj.event !== 'retry') return;
            var id = obj.data.id;
            layer.confirm('确认重新代付此订单行？', { icon: 3, title: '重试', skin: 'admin-modal' }, function(idx){
                layer.close(idx);
                var loading = layer.load(2, { shade: 0.2 });
                call('trade_retry', { id: id }, function(err){
                    layer.close(loading);
                    if (err) { layer.msg('重试失败：' + err.message); return; }
                    layer.msg('重试成功');
                    loadTrades();
                });
            });
        });

        // ============================================================
        // 全局配置 Tab
        // ============================================================
        function loadConfig() {
            call('config_get', {}, function(err, d){
                if (err) { layer.msg(err.message); return; }
                var cfg = d.config || {};
                $('#ycyCfgAutoOff').prop('checked', cfg.auto_off_sale_on_empty == 1);
                form.render('checkbox');
            });
        }
        $('#ycyConfigSave').on('click', function(){
            var payload = {
                auto_off_sale_on_empty: $('#ycyCfgAutoOff').prop('checked') ? 1 : 0,
            };
            call('config_save', payload, function(err){
                if (err) { layer.msg(err.message); return; }
                layer.msg('已保存');
            });
        });

        // 查看卡密弹窗
        $(document).on('click', '.ycy-trade-view', function(){
            var id = $(this).data('id');
            call('trade_view', { id: id }, function(err, d){
                if (err) { layer.msg(err.message); return; }
                var r = d.record || {};
                var content = (r.delivery_content || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                layer.open({
                    type: 1, title: '卡密内容 · #' + id, skin: 'admin-modal',
                    area: ['520px', '400px'], shadeClose: true,
                    content: '<div style="padding:16px 20px;"><pre style="background:#f9fafb;padding:12px;border-radius:6px;max-height:320px;overflow:auto;font-size:12px;line-height:1.8;white-space:pre-wrap;word-break:break-all;">' + (content||'（空）') + '</pre></div>'
                });
            });
        });

        // ---------- 行操作 ----------
        table.on('tool(ycySiteTable)', function(obj){
            var d = obj.data;
            if (obj.event === 'edit') {
                openSiteForm(d);
            } else if (obj.event === 'delete') {
                layer.confirm('确认删除站点「' + d.name + '」？映射到本站点的商品将失去同步来源。', { icon: 3, title: '删除确认', skin: 'admin-modal' }, function(idx){
                    call('site_delete', { id: d.id }, function(err){
                        layer.close(idx);
                        if (err) { layer.msg('删除失败：' + err.message); return; }
                        layer.msg('已删除');
                        loadSites();
                    });
                });
            } else if (obj.event === 'test') {
                var loading = layer.load(2, { shade: 0.2 });
                call('site_test', { id: d.id }, function(err, resp){
                    layer.close(loading);
                    if (err) { layer.msg('连接失败：' + err.message); return; }
                    layer.msg('连接成功：' + (resp.username||'-') + ' · 余额 ' + (resp.balance||0));
                });
            }
        });

        // 启用开关
        form.on('switch(ycySiteEnabled)', function(obj){
            call('site_toggle', { id: obj.elem.value, enabled: obj.elem.checked ? 1 : 0 }, function(err){
                if (err) { layer.msg('更新失败：' + err.message); }
            });
        });

        // ---------- 工具 ----------
        function serializeForm(selector) {
            var out = {};
            $(selector).find('input,textarea,select').each(function(){
                var $el = $(this), name = $el.attr('name'); if (!name) return;
                if ($el.attr('type') === 'radio') { if ($el.prop('checked')) out[name] = $el.val(); }
                else if ($el.attr('type') === 'checkbox') { out[name] = $el.prop('checked') ? 1 : 0; }
                else { out[name] = $el.val(); }
            });
            return out;
        }
        function escHtml(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    });
    </script>
    <?php
}

/**
 * 保存入口。按 _sub_action 分发到具体 CRUD / 测试。
 * 统一返回 Response::success / Response::error 的 JSON。
 */
function plugin_setting(): void
{
    // CSRF 校验由 admin/plugin.php 入口已完成，此处不重复校验，避免双消耗 token
    $sub = (string) Input::post('_sub_action', '');

    switch ($sub) {
        case 'site_list':
            $rows = SiteModel::all();
            // 不回 app_key 明文也能用，这里保留回显方便用户核对，按需可 mask
            Response::success('', ['list' => $rows, 'csrf_token' => Csrf::refresh()]);
            break;

        case 'site_save': {
            $id = (int) Input::post('id', 0);
            $data = [
                'name'         => (string) Input::post('name', ''),
                'version'      => (string) Input::post('version', 'v3'),
                'host'         => (string) Input::post('host', ''),
                'app_id'       => (string) Input::post('app_id', ''),
                'app_key'      => (string) Input::post('app_key', ''),
                'markup_ratio' => (float)  Input::post('markup_ratio', 1.2),
                'min_markup'   => (float)  Input::post('min_markup', 1.05),
                'enabled'      => ((int) Input::post('enabled', 1)) === 1 ? 1 : 0,
                'remark'       => (string) Input::post('remark', ''),
            ];
            // 兜底：加价系数不低于最低系数
            if ($data['markup_ratio'] < $data['min_markup']) {
                Response::error('加价系数不能低于最低系数');
            }
            if ($id > 0) {
                SiteModel::update($id, $data);
            } else {
                $id = SiteModel::create($data);
            }
            Response::success('已保存', ['id' => $id, 'csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'site_delete': {
            $id = (int) Input::post('id', 0);
            if ($id <= 0) Response::error('参数错误');
            SiteModel::delete($id);
            Response::success('已删除', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'site_toggle': {
            $id = (int) Input::post('id', 0);
            $enabled = ((int) Input::post('enabled', 0)) === 1 ? 1 : 0;
            if ($id <= 0) Response::error('参数错误');
            SiteModel::update($id, ['enabled' => $enabled]);
            Response::success('', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'config_get': {
            $storage = Storage::getInstance('ycy_shared');
            Response::success('', [
                'config' => [
                    'auto_off_sale_on_empty' => (string) $storage->getValue('auto_off_sale_on_empty') === '1' ? 1 : 0,
                ],
                'csrf_token' => Csrf::refresh(),
            ]);
            break;
        }

        case 'config_save': {
            $storage = Storage::getInstance('ycy_shared');
            $val = ((int) Input::post('auto_off_sale_on_empty', 0)) === 1 ? '1' : '0';
            $storage->setValue('auto_off_sale_on_empty', $val);
            Response::success('已保存', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'trade_retry': {
            // 失败流水手动重试：复用 DeliveryService::handle
            $id = (int) Input::post('id', 0);
            if ($id <= 0) Response::error('参数错误');
            $t = Database::fetchOne(
                'SELECT * FROM `' . Database::prefix() . 'ycy_trade` WHERE `id` = ? LIMIT 1',
                [$id]
            );
            if (!$t) Response::error('流水不存在');
            $og = Database::fetchOne(
                'SELECT `id`, `order_id` FROM `' . Database::prefix() . 'order_goods` WHERE `id` = ? LIMIT 1',
                [(int) $t['order_goods_id']]
            );
            if (!$og) Response::error('对应订单行不存在');
            try {
                DeliveryService::handle((int) $og['order_id'], (int) $og['id'], '');
                Response::success('重试完成', ['csrf_token' => Csrf::refresh()]);
            } catch (Throwable $e) {
                Response::error('重试失败：' . $e->getMessage());
            }
            break;
        }

        case 'goods_sync_one': {
            // 立即同步单个映射：支持 mapping_id 或 goods_id 两种入参
            $mappingId = (int) Input::post('mapping_id', 0);
            if ($mappingId <= 0) {
                $goodsId = (int) Input::post('goods_id', 0);
                if ($goodsId > 0) {
                    $row = Database::fetchOne(
                        'SELECT `id` FROM `' . Database::prefix() . 'ycy_goods` WHERE `goods_id` = ? LIMIT 1',
                        [$goodsId]
                    );
                    if ($row) $mappingId = (int) $row['id'];
                }
            }
            if ($mappingId <= 0) Response::error('找不到对应映射');
            try {
                SyncService::syncByMappingId($mappingId);
                Response::success('同步完成', ['csrf_token' => Csrf::refresh()]);
            } catch (Throwable $e) {
                Response::error($e->getMessage());
            }
            break;
        }

        case 'goods_markup_save': {
            // 设置/清除单个商品的加价系数。入参：mapping_id 或 goods_id
            $mappingId = (int) Input::post('mapping_id', 0);
            if ($mappingId <= 0) {
                $goodsId = (int) Input::post('goods_id', 0);
                if ($goodsId > 0) {
                    $r = Database::fetchOne(
                        'SELECT `id` FROM `' . Database::prefix() . 'ycy_goods` WHERE `goods_id` = ? LIMIT 1',
                        [$goodsId]
                    );
                    if ($r) $mappingId = (int) $r['id'];
                }
            }
            $raw = Input::post('markup_ratio', '');
            if ($mappingId <= 0) Response::error('找不到对应映射');

            $row = Database::fetchOne(
                'SELECT g.*, s.`min_markup` FROM `' . Database::prefix() . 'ycy_goods` g
                   JOIN `' . Database::prefix() . 'ycy_site`  s ON s.`id` = g.`site_id`
                  WHERE g.`id` = ? LIMIT 1',
                [$mappingId]
            );
            if (!$row) Response::error('映射不存在');

            if ($raw === '' || $raw === null) {
                Database::update('ycy_goods', ['markup_ratio' => null], $mappingId);
                Response::success('已清除商品级系数，恢复使用站点默认', ['csrf_token' => Csrf::refresh()]);
            }
            $ratio = (float) $raw;
            $min   = (float) $row['min_markup'];
            if ($ratio < $min) Response::error('系数不能低于站点最低 ' . number_format($min, 3));
            Database::update('ycy_goods', ['markup_ratio' => $ratio], $mappingId);
            Response::success('已保存商品级加价系数', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'trade_list': {
            $status = (string) Input::post('status', '');
            $limit  = min(200, max(10, (int) Input::post('limit', 50)));
            $sql = 'SELECT t.*, s.`name` AS `site_name` FROM `' . Database::prefix() . 'ycy_trade` t
                    LEFT JOIN `' . Database::prefix() . 'ycy_site` s ON s.`id` = t.`site_id`';
            $args = [];
            if (in_array($status, ['success', 'pending', 'failed'], true)) {
                $sql .= ' WHERE t.`status` = ?';
                $args[] = $status;
            }
            $sql .= ' ORDER BY t.`id` DESC LIMIT ' . $limit;
            $rows = Database::query($sql, $args);
            Response::success('', ['list' => $rows, 'csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'trade_view': {
            $id = (int) Input::post('id', 0);
            if ($id <= 0) Response::error('参数错误');
            $row = Database::fetchOne(
                'SELECT t.*, og.`delivery_content` FROM `' . Database::prefix() . 'ycy_trade` t
                 LEFT JOIN `' . Database::prefix() . 'order_goods` og ON og.`id` = t.`order_goods_id`
                 WHERE t.`id` = ? LIMIT 1',
                [$id]
            );
            if (!$row) Response::error('记录不存在');
            Response::success('', ['record' => $row, 'csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'catalog_list': {
            $siteId = (int) Input::post('site_id', 0);
            $site = SiteModel::find($siteId);
            if ($site === null) Response::error('站点不存在');
            try {
                $items = ImportService::listUpstreamItems($site);
                Response::success('', ['list' => $items, 'csrf_token' => Csrf::refresh()]);
            } catch (Throwable $e) {
                Response::error('拉取目录失败：' . $e->getMessage());
            }
            break;
        }

        case 'catalog_import': {
            $siteId = (int) Input::post('site_id', 0);
            $site = SiteModel::find($siteId);
            if ($site === null) Response::error('站点不存在');
            $refsRaw = Input::post('refs', '');
            $refs = is_array($refsRaw) ? $refsRaw : array_values(array_filter(explode(',', (string) $refsRaw)));
            if ($refs === []) Response::error('未选择要导入的商品');
            try {
                $result = ImportService::importBatch($site, $refs);
                // 统计
                $ok = 0; $fail = 0; $dup = 0;
                foreach ($result as $r) {
                    if ($r['ok'] ?? false) {
                        if (!empty($r['already_imported'])) $dup++; else $ok++;
                    } else {
                        $fail++;
                    }
                }
                Response::success('导入完成：新增 ' . $ok . ' · 已存在 ' . $dup . ' · 失败 ' . $fail, [
                    'result' => $result,
                    'stats'  => ['ok' => $ok, 'fail' => $fail, 'duplicated' => $dup],
                    'csrf_token' => Csrf::refresh(),
                ]);
            } catch (Throwable $e) {
                Response::error('导入异常：' . $e->getMessage());
            }
            break;
        }

        case 'site_test': {
            // 两种入口：保存前预验（传完整参数） / 列表行测试（传 id）
            $id = (int) Input::post('id', 0);
            if ($id > 0) {
                $site = SiteModel::find($id);
                if ($site === null) Response::error('站点不存在');
            } else {
                $site = [
                    'version' => (string) Input::post('version', 'v3'),
                    'host'    => (string) Input::post('host', ''),
                    'app_id'  => (string) Input::post('app_id', ''),
                    'app_key' => (string) Input::post('app_key', ''),
                ];
            }
            try {
                $client = Client::make($site);
                $info = $client->connect();
                Response::success('连接成功', array_merge($info, ['csrf_token' => Csrf::refresh()]));
            } catch (Throwable $e) {
                Response::error($e->getMessage());
            }
            break;
        }

        default:
            Response::error('未知动作：' . $sub);
    }
}
