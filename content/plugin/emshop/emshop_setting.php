<?php
/**
 * EMSHOP共享店铺 - 设置页（对接站点 CRUD）。
 *
 * 渲染：plugin_setting_view() — admin/plugin.php 弹窗
 * 保存：plugin_setting()     — _action=save_config，_sub_action 分发
 */

defined('EM_ROOT') || exit('Access Denied');

require_once __DIR__ . '/emshop.php';
emshop_plugin_ensure_schema();

use EmshopPlugin\RemoteApiClient;
use EmshopPlugin\RemoteSiteModel;

/**
 * @param array<string, mixed> $data
 */
function emshop_validate_site_payload(array $data, bool $isNew): array
{
    $baseUrl = trim((string) ($data['base_url'] ?? ''));
    $baseUrl = rtrim($baseUrl, '/');
    if ($baseUrl === '') {
        Response::error('请填写接口地址');
    }
    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
        Response::error('接口地址须为 http:// 或 https:// 开头的完整 URL');
    }
    $baseUrl .= '/';
    $appid = preg_replace('/\D/', '', (string) ($data['appid'] ?? '')) ?? '';
    if ($appid === '') {
        Response::error('请填写对方 API 的 appid（数字）');
    }
    $secret = trim((string) ($data['secret'] ?? ''));
    if ($isNew && $secret === '') {
        Response::error('请填写对方 API 的 SECRET');
    }
    $enabled = ((int) ($data['enabled'] ?? 1)) === 1 ? 1 : 0;
    $remark = trim((string) ($data['remark'] ?? ''));

    return [
        'base_url' => $baseUrl,
        'appid'    => $appid,
        'secret'   => $secret,
        'enabled'  => $enabled,
        'remark'   => $remark,
    ];
}

function plugin_setting_view(): void
{
    $csrfToken = Csrf::token();
    ?>
    <style>
    .ems-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-bottom: 1px solid #f0f0f0; background: #fff; }
    .ems-toolbar__left { font-size: 12px; color: #9ca3af; max-width: 70%; line-height: 1.5; }
    </style>
    <div class="popup-inner">
        <div class="ems-toolbar">
            <span class="ems-toolbar__left">配置与本商城对接的其他 EMSHOP 站点（对方需在用户中心生成 API SECRET）。保存时将请求对方 <code>base_info</code> 接口并自动写入站点名。后续同步/下单能力将逐步接入。</span>
            <button type="button" class="popup-btn popup-btn--primary" id="emsAddSiteBtn"><i class="fa fa-plus"></i> 新增站点</button>
        </div>
        <table id="emsSiteTable" lay-filter="emsSiteTable"></table>
    </div>

    <script type="text/html" id="emsSiteRowActionTpl">
        <a class="layui-btn layui-btn-xs layui-btn-normal" lay-event="edit"><i class="fa fa-pencil"></i> 编辑</a>
        <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete"><i class="fa fa-trash"></i> 删除</a>
    </script>
    <script type="text/html" id="emsSiteEnabledTpl">
        <input type="checkbox" lay-skin="switch" lay-text="启用|停用" lay-filter="emsSiteEnabled" value="{{ d.id }}" {{ d.enabled == 1 ? 'checked' : '' }}>
    </script>

    <script>
    var EMS_CSRF = <?= json_encode($csrfToken) ?>;

    layui.use(['layer', 'form', 'table'], function(){
        var $ = layui.$, layer = layui.layer, table = layui.table, form = layui.form;

        // 新增/编辑表单在顶层窗口打开，避免嵌在「插件设置」iframe 里过挤；表单 DOM 在 winShell 内，序列化须用 $top
        var winShell = (function () {
            try {
                if (typeof top !== 'undefined' && top !== window && top.layui && top.layui.layer) {
                    return top;
                }
            } catch (e) {}
            return window;
        })();
        var layerShell = winShell.layui.layer;
        var $top = winShell.layui.$;
        var formShell = winShell.layui.form;

        function call(subAction, payload, done) {
            payload = payload || {};
            payload._action = 'save_config';
            payload.name = 'emshop';
            payload._sub_action = subAction;
            payload.csrf_token = EMS_CSRF;
            $.ajax({
                url: window.PLUGIN_SAVE_URL || '/admin/plugin.php',
                type: 'POST',
                dataType: 'json',
                data: payload
            }).done(function(res){
                if (res && (res.code === 0 || res.code === 200)) {
                    if (res.data && res.data.csrf_token) EMS_CSRF = res.data.csrf_token;
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

        function renderSiteTable(rows) {
            table.render({
                elem: '#emsSiteTable',
                id: 'emsSiteTable',
                data: rows,
                page: false,
                cellMinWidth: 80,
                cols: [[
                    { field: 'id', title: 'ID', width: 60, align: 'center' },
                    { field: 'name', title: '站点名', minWidth: 120 },
                    { field: 'base_url', title: '接口地址', minWidth: 200 },
                    { field: 'appid', title: 'appid', width: 100, align: 'center' },
                    { field: 'secret_masked', title: 'SECRET', width: 120, align: 'center' },
                    { field: 'enabled', title: '启用', width: 100, templet: '#emsSiteEnabledTpl', align: 'center', unresize: true },
                    { field: 'remark', title: '备注', minWidth: 100 },
                    { title: '操作', width: 160, align: 'center', toolbar: '#emsSiteRowActionTpl' }
                ]]
            });
            form.render('checkbox');
        }

        function loadSites() {
            call('site_list', {}, function(err, data){
                if (err) { layer.msg(err.message); return; }
                renderSiteTable(data.list || []);
            });
        }
        loadSites();

        function escHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }
        function serializeFormRoot($root) {
            var out = {};
            $root.find('input,textarea,select').each(function(){
                var $el = $(this), name = $el.attr('name');
                if (!name) return;
                if ($el.attr('type') === 'radio') { if ($el.prop('checked')) out[name] = $el.val(); }
                else if ($el.attr('type') === 'checkbox') { out[name] = $el.prop('checked') ? 1 : 0; }
                else { out[name] = $el.val(); }
            });
            return out;
        }

        function openSiteForm(row) {
            var isEdit = !!(row && row.id);
            var r = row || { enabled: 1 };
            var formId = 'emsSiteForm_' + Date.now();
            var html =
              '<div class="popup-inner" style="padding:14px 20px;">' +
                '<form class="layui-form" lay-filter="' + formId + '" id="' + formId + '">' +
                  '<input type="hidden" name="id" value="' + (r.id || '') + '">' +
                  '<div class="layui-form-item"><label class="layui-form-label">接口地址</label>' +
                    '<div class="layui-input-block"><input class="layui-input" name="base_url" required lay-verify="required" value="' + escHtml(r.base_url || '') + '" placeholder="https://对方域名/"></div>' +
                    '<div class="layui-form-mid layui-word-aux" style="margin-left:0;">对方对外 API 的根 URL；若末尾未带 /，保存时会自动补上</div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">appid</label>' +
                    '<div class="layui-input-block"><input class="layui-input" name="appid" required lay-verify="required" value="' + escHtml(r.appid || '') + '" placeholder="对方用户中心 API 的 APPID"></div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">SECRET</label>' +
                    '<div class="layui-input-block"><input type="password" class="layui-input" name="secret" value="' + escHtml(r.secret || '') + '" placeholder="' + (isEdit ? '留空表示不修改' : '必填') + '" autocomplete="new-password"></div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">启用</label>' +
                    '<div class="layui-input-block"><input type="checkbox" name="enabled" lay-skin="switch" lay-text="启用|停用"' + ((r.enabled == 1 || r.enabled === undefined) ? ' checked' : '') + '></div></div>' +
                  '<div class="layui-form-item"><label class="layui-form-label">备注</label>' +
                    '<div class="layui-input-block"><textarea class="layui-textarea" name="remark" rows="2">' + escHtml(r.remark || '') + '</textarea></div></div>' +
                '</form>' +
              '</div>';

            layerShell.open({
                type: 1,
                title: (isEdit ? '编辑' : '新增') + '对接站点',
                skin: 'admin-modal',
                area: ['560px', 'auto'],
                shadeClose: false,
                content: html,
                btn: ['保存', '取消'],
                success: function () {
                    formShell.render(null, formId);
                },
                yes: function (index) {
                    var $form = $top('#' + formId);
                    if (!$form.length) {
                        layerShell.msg('表单异常，请关闭后重试');
                        return;
                    }
                    var data = serializeFormRoot($form);
                    call('site_save', data, function(err){
                        if (err) { layerShell.msg(err.message); return; }
                        layerShell.close(index);
                        layerShell.msg('已保存');
                        loadSites();
                    });
                },
                btn2: function (index) {
                    layerShell.close(index);
                }
            });
        }

        $('#emsAddSiteBtn').on('click', function() { openSiteForm(null); });

        table.on('tool(emsSiteTable)', function(obj){
            if (obj.event === 'edit') {
                var loading = layerShell.load(2, { shade: 0.15 });
                call('site_get', { id: obj.data.id }, function(err, data){
                    layerShell.close(loading);
                    if (err) { layer.msg(err.message); return; }
                    openSiteForm(data.row || {});
                });
            } else if (obj.event === 'delete') {
                layerShell.confirm('确定删除该对接站点？', function(i){
                    layerShell.close(i);
                    call('site_delete', { id: obj.data.id }, function(err){
                        if (err) { layerShell.msg(err.message); return; }
                        layerShell.msg('已删除');
                        loadSites();
                    });
                });
            }
        });

        form.on('switch(emsSiteEnabled)', function(obj){
            var id = parseInt(obj.value, 10);
            var enabled = obj.elem.checked ? 1 : 0;
            call('site_toggle', { id: id, enabled: enabled }, function(err){
                if (err) {
                    layer.msg(err.message);
                    obj.elem.checked = !obj.elem.checked;
                    form.render('checkbox');
                    return;
                }
                layer.msg('已更新');
            });
        });
    });
    </script>
    <?php
}

function plugin_setting(): void
{
    $sub = (string) Input::post('_sub_action', '');

    switch ($sub) {
        case 'site_list': {
            $rows = RemoteSiteModel::all();
            $list = [];
            foreach ($rows as $row) {
                $list[] = RemoteSiteModel::rowForList($row);
            }
            Response::success('', ['list' => $list, 'csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'site_get': {
            $id = (int) Input::post('id', 0);
            if ($id <= 0) {
                Response::error('参数错误');
            }
            $row = RemoteSiteModel::find($id);
            if ($row === null) {
                Response::error('记录不存在');
            }
            Response::success('', ['row' => $row, 'csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'site_save': {
            $id = (int) Input::post('id', 0);
            $raw = [
                'base_url' => (string) Input::post('base_url', ''),
                'appid'    => (string) Input::post('appid', ''),
                'secret'   => (string) Input::post('secret', ''),
                'enabled'  => Input::post('enabled', 1),
                'remark'   => (string) Input::post('remark', ''),
            ];
            $validated = emshop_validate_site_payload($raw, $id <= 0);

            $secretForApi = trim((string) ($validated['secret'] ?? ''));
            if ($id > 0 && $secretForApi === '') {
                $old = RemoteSiteModel::find($id);
                if ($old === null) {
                    Response::error('记录不存在');
                }
                $secretForApi = trim((string) ($old['secret'] ?? ''));
            }
            if ($secretForApi === '') {
                Response::error('请填写对方 API 的 SECRET');
            }

            try {
                $info = RemoteApiClient::fetchBaseInfo(
                    $validated['base_url'],
                    (string) $validated['appid'],
                    $secretForApi
                );
            } catch (Throwable $e) {
                Response::error('无法拉取对方站点信息（请核对接口地址、appid、SECRET）：' . $e->getMessage());
            }

            $name = mb_substr(trim((string) ($info['site_name'] ?? '')), 0, 100, 'UTF-8');
            if ($name === '') {
                $name = mb_substr(trim((string) ($info['account'] ?? '')), 0, 100, 'UTF-8');
            }
            if ($name === '') {
                $name = 'EMSHOP-' . $validated['appid'];
            }
            $validated['name'] = $name;

            if ($id > 0) {
                $hasNewSecret = trim((string) Input::post('secret', '')) !== '';
                if (!$hasNewSecret) {
                    unset($validated['secret']);
                }
                RemoteSiteModel::update($id, $validated, !$hasNewSecret);
            } else {
                RemoteSiteModel::create($validated);
            }
            Response::success('已保存', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'site_delete': {
            $id = (int) Input::post('id', 0);
            if ($id <= 0) {
                Response::error('参数错误');
            }
            RemoteSiteModel::delete($id);
            Response::success('已删除', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'site_toggle': {
            $id = (int) Input::post('id', 0);
            $enabled = ((int) Input::post('enabled', 0)) === 1 ? 1 : 0;
            if ($id <= 0) {
                Response::error('参数错误');
            }
            RemoteSiteModel::update($id, ['enabled' => $enabled], true);
            Response::success('', ['csrf_token' => Csrf::refresh()]);
            break;
        }

        default:
            Response::error('未知操作');
    }
}
