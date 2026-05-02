<?php

declare(strict_types=1);

/**
 * 对接站点新增/编辑表单（layer iframe）。
 * - 新建：?id=0
 * - 编辑：列表行数据经 top.sessionStorage 传入（GET sk=会话键）；无 sk 时回落为服务端读取（书签/直链）。
 */

$emRoot = dirname(__DIR__, 3);
require $emRoot . '/admin/global.php';
adminRequireLogin();

require_once __DIR__ . '/emshop.php';
emshop_plugin_ensure_schema();

use EmshopPlugin\RemoteSiteModel;

$id = (int) ($_GET['id'] ?? 0);
if ($id < 0) {
    $id = 0;
}
$skParam = isset($_GET['sk']) ? trim((string) $_GET['sk']) : '';

$fallbackPrefillJs = 'null';

if ($id > 0) {
    $existRow = RemoteSiteModel::find($id);
    if ($existRow === null) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '记录不存在';
        exit;
    }
    // 直接打开表单页且无 sk（非列表入口）时使用库里的值预填，避免表单空白
    if ($skParam === '') {
        $fallbackPrefillJs = json_encode([
            'id'       => $id,
            'base_url' => (string) ($existRow['base_url'] ?? ''),
            'appid'    => (string) ($existRow['appid'] ?? ''),
            'enabled'  => (int) ($existRow['enabled'] ?? 1),
            'remark'   => (string) ($existRow['remark'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

$csrfToken = Csrf::token();
$pageTitle = $id > 0 ? '编辑对接站点' : '新增对接站点';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <link rel="stylesheet" href="/content/static/lib/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="/admin/static/css/popup.css">
    <link rel="stylesheet" href="/admin/static/css/reset-layui.css">
    <script src="/content/static/lib/jquery.min.3.5.1.js"></script>
    <script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
    <script>
    try {
        window.PLUGIN_SAVE_URL = (typeof parent !== 'undefined' && parent.PLUGIN_SAVE_URL)
            ? parent.PLUGIN_SAVE_URL : '/admin/plugin.php';
    } catch (e) {
        window.PLUGIN_SAVE_URL = '/admin/plugin.php';
    }
    var EMS_CSRF = <?= json_encode($csrfToken) ?>;
    var EMS_FORM_ID = <?= (int) $id ?>;
    window.__EMS_PREFILL_FALLBACK__ = <?= $fallbackPrefillJs ?>;
    </script>
</head>
<body class="popup-body">
<div class="popup-wrap">
    <div class="popup-content">
        <div class="popup-inner">
            <form class="layui-form" lay-filter="emsSiteIframeForm" id="emsSiteIframeForm">
                <input type="hidden" name="id" id="emsFieldId" value="<?= $id > 0 ? (int) $id : '' ?>">

                <!-- 接口地址、appid、SECRET -->
                <div class="popup-section">
                    <div class="layui-form-item">
                        <label class="layui-form-label">接口地址</label>
                        <div class="layui-input-block">
                            <input type="text" class="layui-input" id="emsFieldBaseUrl" name="base_url" required lay-verify="required"
                                   value="" placeholder="https://对方域名/">
                            <div class="layui-form-mid layui-word-aux">若末尾未带 /，保存时会自动补上</div>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">appid</label>
                        <div class="layui-input-block">
                            <input type="text" class="layui-input" id="emsFieldAppid" name="appid" required lay-verify="required"
                                   value="" placeholder="对方用户中心 API 的 APPID">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">SECRET</label>
                        <div class="layui-input-block">
                            <input type="password" class="layui-input" id="emsFieldSecret" name="secret" value=""
                                   placeholder="<?= $id > 0 ? '留空表示不修改' : '必填' ?>" autocomplete="new-password">
                        </div>
                        <div class="layui-form-mid layui-word-aux">与对方用户中心「API 管理」中生成的 SECRET 一致；编辑时留空则不修改</div>
                    </div>
                </div>

                <!-- 启用与备注 -->
                <div class="popup-section">
                    <div class="layui-form-item">
                        <label class="layui-form-label">启用</label>
                        <div class="layui-input-block">
                            <input type="checkbox" id="emsFieldEnabled" name="enabled" lay-skin="switch" lay-text="启用|停用">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">备注</label>
                        <div class="layui-input-block">
                            <textarea class="layui-textarea" id="emsFieldRemark" name="remark" rows="2" placeholder="可选，便于区分多个对接站点"></textarea>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="popup-footer">
            <button type="button" class="popup-btn" id="emsIframeCancel"><i class="fa fa-times"></i> 取消</button>
            <button type="button" class="popup-btn popup-btn--primary" id="emsIframeSave"><i class="fa fa-check mr-5"></i>确认保存</button>
        </div>
    </div>
</div>
<script>
layui.use(['form', 'layer'], function () {
    var form = layui.form;
    var layLayer = layui.layer;
    var $ = layui.$;

    function applyPrefill(o) {
        if (!o || typeof o !== 'object') return false;
        if (o.base_url != null) $('#emsFieldBaseUrl').val(String(o.base_url));
        if (o.appid != null) $('#emsFieldAppid').val(String(o.appid));
        if (o.remark != null) $('#emsFieldRemark').val(String(o.remark));
        if (o.enabled !== undefined && o.enabled !== null) {
            $('#emsFieldEnabled').prop('checked', parseInt(o.enabled, 10) === 1);
        }
        var fid = EMS_FORM_ID;
        if (o.id !== undefined && o.id !== null && fid > 0) {
            $('#emsFieldId').val(String(fid));
        }
        return true;
    }

    var qs = new URLSearchParams(location.search);
    var sk = qs.get('sk') || '';
    var prefilled = false;
    if (sk) {
        try {
            var raw = window.top.sessionStorage.getItem(sk);
            if (raw) {
                try {
                    prefilled = applyPrefill(JSON.parse(raw));
                } catch (err) {}
                window.top.sessionStorage.removeItem(sk);
            }
        } catch (e) {}
    }
    if (!prefilled && window.__EMS_PREFILL_FALLBACK__) {
        applyPrefill(window.__EMS_PREFILL_FALLBACK__);
    }

    if (EMS_FORM_ID <= 0) {
        $('#emsFieldEnabled').prop('checked', true);
    }

    form.render(null, 'emsSiteIframeForm');

    function serializeForm() {
        var out = {};
        $('#emsSiteIframeForm').find('input,textarea,select').each(function () {
            var $el = $(this), name = $el.attr('name');
            if (!name) return;
            if ($el.attr('type') === 'checkbox') {
                out[name] = $el.prop('checked') ? 1 : 0;
            } else {
                out[name] = $el.val();
            }
        });
        return out;
    }

    /**
     * layer iframe 由顶层 top.layer 打开时，必须用 top.layer 关闭；仅用 parent 在嵌套 iframe 下会取不到 index。
     */
    function getLayerHostWin() {
        var list = [];
        try {
            if (window.top && window.top !== window) list.push(window.top);
        } catch (e0) {}
        try {
            if (window.parent && window.parent !== window) list.push(window.parent);
        } catch (e1) {}
        var seen = {};
        for (var i = 0; i < list.length; i++) {
            var w = list[i];
            if (!w || seen[w]) continue;
            seen[w] = true;
            try {
                if (w.layui && w.layui.layer && typeof w.layui.layer.getFrameIndex === 'function') {
                    var idx = w.layui.layer.getFrameIndex(window.name);
                    if (typeof idx === 'number' && idx >= 0) {
                        return { win: w, idx: idx };
                    }
                }
            } catch (e2) {}
        }
        return null;
    }

    function closeFrame() {
        try {
            var host = window.__EMS_LAYER_HOST__;
            var lix = window.__EMS_LAYER_INDEX__;
            if (host && host.layui && host.layui.layer && lix !== undefined && lix !== null) {
                host.layui.layer.close(lix);
                return;
            }
        } catch (e0) {}
        var hit = getLayerHostWin();
        if (hit) {
            try {
                hit.win.layui.layer.close(hit.idx);
            } catch (e) {}
            return;
        }
        try {
            var idx = parent.layer.getFrameIndex(window.name);
            var n = typeof idx === 'number' ? idx : parseInt(idx, 10);
            if (!isNaN(n) && n >= 0) {
                parent.layer.close(n);
            }
        } catch (e3) {}
    }

    function layerMsgHost() {
        try {
            var host = window.__EMS_LAYER_HOST__;
            if (host && host.layui && host.layui.layer) {
                return host.layui.layer;
            }
        } catch (e) {}
        var hit = getLayerHostWin();
        return (hit && hit.win && hit.win.layui && hit.win.layui.layer) ? hit.win.layui.layer : layLayer;
    }

    $('#emsIframeCancel').on('click', function () {
        closeFrame();
    });

    $('#emsIframeSave').on('click', function () {
        var $btn = $(this);
        var $icon = $btn.find('i');
        var data = serializeForm();
        if (!data.base_url || !String(data.base_url).trim()) {
            layLayer.msg('请填写接口地址');
            return;
        }
        if (!data.appid || !String(data.appid).trim()) {
            layLayer.msg('请填写 appid');
            return;
        }
        if (!data.id && (!data.secret || !String(data.secret).trim())) {
            layLayer.msg('请填写 SECRET');
            return;
        }
        data._action = 'save_config';
        data.name = 'emshop';
        data._sub_action = 'site_save';
        data.csrf_token = EMS_CSRF;
        $icon.attr('class', 'fa fa-refresh admin-spin mr-5');
        $btn.prop('disabled', true);
        $.ajax({
            url: window.PLUGIN_SAVE_URL || '/admin/plugin.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (res) {
                if (res && (res.code === 0 || res.code === 200)) {
                    if (res.data && res.data.csrf_token) {
                        EMS_CSRF = res.data.csrf_token;
                    }
                    try {
                        window.top.dispatchEvent(new CustomEvent('emshop_site_list_reload'));
                    } catch (e) {}
                    var msg = (res && res.msg) ? String(res.msg) : '已保存';
                    try {
                        layerMsgHost().msg(msg, { icon: 1 });
                    } catch (e2) {
                        layLayer.msg(msg, { icon: 1 });
                    }
                    closeFrame();
                } else {
                    layLayer.msg((res && res.msg) || '保存失败', { icon: 2 });
                }
            },
            error: function (xhr) {
                var msg = '网络异常';
                try {
                    var j = JSON.parse(xhr.responseText || '{}');
                    if (j.msg) msg = j.msg;
                } catch (e) {}
                layLayer.msg(msg, { icon: 2 });
            },
            complete: function () {
                $icon.attr('class', 'fa fa-check mr-5');
                $btn.prop('disabled', false);
            }
        });
    });
});
</script>
</body>
</html>
