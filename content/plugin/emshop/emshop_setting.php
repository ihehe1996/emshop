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

use EmshopPlugin\GoodsImportService;
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
    .ems-toolbar { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 12px 14px; border-bottom: 1px solid #eef0f4; background: #fff; flex-shrink: 0; }
    .ems-toolbar__left { font-size: 12px; color: #8b92a0; max-width: min(72%, 520px); line-height: 1.55; }
    .ems-site-list-area { flex: 1; min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; background: linear-gradient(180deg, #f4f6fa 0%, #eef1f7 100%); padding: 14px; }
    .ems-site-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(288px, 1fr)); gap: 14px; align-content: start; }
    .ems-site-card {
        position: relative;
        background: #fff;
        border-radius: 12px;
        border: 1px solid rgba(0, 0, 0, 0.06);
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .ems-site-card::before {
        content: '';
        display: block;
        height: 3px;
        background: linear-gradient(90deg, #cbd5e1, #94a3b8);
    }
    .ems-site-card--on::before {
        background: linear-gradient(90deg, #1e9fff, #36cfc9);
    }
    .ems-site-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.1), 0 2px 6px rgba(15, 23, 42, 0.06);
    }
    .ems-site-card__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        padding: 14px 14px 10px;
        border-bottom: 1px solid #f0f2f5;
    }
    .ems-site-card__title { font-size: 15px; font-weight: 600; color: #1e293b; line-height: 1.35; word-break: break-word; flex: 1; min-width: 0; }
    .ems-site-card__id { font-size: 11px; font-weight: 500; color: #94a3b8; margin-top: 4px; letter-spacing: 0.02em; }
    .ems-site-card__switch-wrap { flex-shrink: 0; padding-top: 2px; }
    .ems-site-card__body { padding: 12px 14px 10px; font-size: 12px; color: #64748b; }
    .ems-site-card__row { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 8px; line-height: 1.45; }
    .ems-site-card__row:last-child { margin-bottom: 0; }
    .ems-site-card__row > i.fa { width: 14px; text-align: center; color: #94a3b8; margin-top: 2px; flex-shrink: 0; }
    .ems-site-card__row-val { flex: 1; min-width: 0; word-break: break-all; color: #475569; }
    .ems-site-card__remark { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e8ecf1; font-size: 12px; color: #94a3b8; line-height: 1.45; word-break: break-word; }
    .ems-site-card__remark:empty { display: none; }
    .ems-site-card__footer {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        padding: 10px 14px 14px;
    }
    .ems-site-card__footer .layui-btn { border-radius: 6px; }
    .ems-site-empty {
        text-align: center;
        padding: 48px 20px 56px;
        color: #94a3b8;
    }
    .ems-site-empty__icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 16px;
        border-radius: 50%;
        background: #fff;
        border: 1px solid #e8ecf1;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        color: #cbd5e1;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
    }
    .ems-site-empty__title { font-size: 15px; color: #64748b; margin-bottom: 6px; }
    .ems-site-empty__hint { font-size: 12px; color: #adb5c9; }
    .popup-inner.ems-plugin-inner { display: flex; flex-direction: column; padding: 0; }
    </style>
    <div class="popup-inner ems-plugin-inner">
        <div class="ems-toolbar">
            <span class="ems-toolbar__left">配置与本商城对接的其他 EMSHOP 站点（对方需在用户中心生成 API SECRET）。保存时将请求对方 <code>base_info</code> 接口并自动写入站点名。后续同步/下单能力将逐步接入。</span>
            <button type="button" class="popup-btn popup-btn--primary" id="emsAddSiteBtn"><i class="fa fa-plus"></i> 新增站点</button>
        </div>
        <div class="layui-form ems-site-list-area">
            <div id="emsSiteEmpty" class="ems-site-empty">
                <div class="ems-site-empty__icon"><i class="fa fa-link"></i></div>
                <div class="ems-site-empty__title ems-site-empty__title-text">正在加载…</div>
                <div class="ems-site-empty__hint">点击上方「新增站点」开始配置</div>
            </div>
            <div id="emsSiteCardWrap" class="ems-site-cards" style="display: none;"></div>
        </div>
    </div>

    <script>
    var EMS_CSRF = <?= json_encode($csrfToken) ?>;

    layui.use(['layer', 'form'], function(){
        var $ = layui.$, layer = layui.layer, form = layui.form;

        // 弹层挂在顶层窗口；新增/编辑为 iframe 独立页，避免嵌在设置页里过挤
        var winShell = (function () {
            try {
                if (typeof top !== 'undefined' && top !== window && top.layui && top.layui.layer) {
                    return top;
                }
            } catch (e) {}
            return window;
        })();
        var layerShell = winShell.layui.layer;

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

        var siteListRows = [];

        function escHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function renderSiteCards(rows) {
            siteListRows = rows || [];
            var $wrap = $('#emsSiteCardWrap');
            var $empty = $('#emsSiteEmpty');
            if (!siteListRows.length) {
                $wrap.hide().empty();
                $empty.find('.ems-site-empty__title-text').text('暂无对接站点');
                $empty.find('.ems-site-empty__hint').text('点击上方「新增站点」开始配置').show();
                $empty.show();
                return;
            }
            $empty.hide();
            var parts = [];
            for (var i = 0; i < siteListRows.length; i++) {
                var d = siteListRows[i];
                var id = parseInt(d.id, 10) || 0;
                var on = parseInt(d.enabled, 10) === 1;
                var name = escHtml(d.name != null ? d.name : ('站点 #' + id));
                var baseUrl = escHtml(d.base_url != null ? d.base_url : '');
                var appid = escHtml(d.appid != null ? d.appid : '');
                var sec = escHtml(d.secret_masked != null ? d.secret_masked : '—');
                var remark = (d.remark != null && String(d.remark).trim() !== '')
                    ? escHtml(String(d.remark).trim())
                    : '';
                var remarkBlock = remark
                    ? '<div class="ems-site-card__remark">' + remark + '</div>'
                    : '';
                var chk = on ? ' checked' : '';
                parts.push(
                    '<div class="ems-site-card' + (on ? ' ems-site-card--on' : '') + '" data-site-id="' + id + '">' +
                    '<div class="ems-site-card__head">' +
                    '<div><div class="ems-site-card__title">' + name + '</div>' +
                    '<div class="ems-site-card__id">ID ' + id + '</div></div>' +
                    '<div class="ems-site-card__switch-wrap">' +
                    '<input type="checkbox" lay-skin="switch" lay-text="启用|停用" lay-filter="emsSiteEnabled" value="' + id + '"' + chk + '>' +
                    '</div></div>' +
                    '<div class="ems-site-card__body">' +
                    '<div class="ems-site-card__row"><i class="fa fa-link"></i><span class="ems-site-card__row-val" title="' + baseUrl + '">' + baseUrl + '</span></div>' +
                    '<div class="ems-site-card__row"><i class="fa fa-id-card-o"></i><span class="ems-site-card__row-val">appid ' + appid + '</span></div>' +
                    '<div class="ems-site-card__row"><i class="fa fa-lock"></i><span class="ems-site-card__row-val">' + sec + '</span></div>' +
                    '</div>' + remarkBlock +
                    '<div class="ems-site-card__footer">' +
                    '<button type="button" class="layui-btn layui-btn-xs layui-btn-normal ems-site-card__edit" data-site-id="' + id + '"><i class="fa fa-pencil"></i> 编辑</button>' +
                    '<button type="button" class="layui-btn layui-btn-xs ems-site-card__import" data-site-id="' + id + '"><i class="fa fa-cloud-download"></i> 导入商品</button>' +
                    '<button type="button" class="layui-btn layui-btn-xs layui-btn-danger ems-site-card__del" data-site-id="' + id + '"><i class="fa fa-trash"></i> 删除</button>' +
                    '</div></div>'
                );
            }
            $wrap.html(parts.join('')).show();
            form.render('checkbox');
        }

        function loadSites() {
            $('#emsSiteCardWrap').hide().empty();
            $('#emsSiteEmpty').show();
            $('#emsSiteEmpty').find('.ems-site-empty__title-text').text('正在加载…');
            $('#emsSiteEmpty').find('.ems-site-empty__hint').hide();
            call('site_list', {}, function (err, data) {
                if (err) {
                    $('#emsSiteEmpty').find('.ems-site-empty__title-text').text(err.message || '加载失败');
                    $('#emsSiteEmpty').find('.ems-site-empty__hint').text('请稍后重试或刷新页面').show();
                    return;
                }
                renderSiteCards(data.list || []);
            });
        }

        try {
            if (!winShell.__emsShopSiteReloadBound) {
                winShell.__emsShopSiteReloadBound = true;
                winShell.addEventListener('emshop_site_list_reload', function () {
                    loadSites();
                });
            }
        } catch (e) {}

        loadSites();

        function openSiteForm(row) {
            var id = (row && row.id) ? parseInt(row.id, 10) : 0;
            if (id < 0) id = 0;
            var url = '/content/plugin/emshop/emshop_site_form_page.php?id=' + id;
            if (id > 0) {
                try {
                    var sk = 'emshop_site_form_' + id + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
                    var pkg = {
                        id: row.id,
                        base_url: row.base_url ? String(row.base_url) : '',
                        appid: row.appid != null ? String(row.appid) : '',
                        enabled: parseInt(row.enabled, 10) === 1 ? 1 : 0,
                        remark: row.remark != null ? String(row.remark) : ''
                    };
                    winShell.sessionStorage.setItem(sk, JSON.stringify(pkg));
                    url += '&sk=' + encodeURIComponent(sk);
                } catch (e) {
                    layerShell.msg('无法写入浏览器会话（请禁用拦截后重试）');
                    return;
                }
            }
            layerShell.open({
                type: 2,
                title: (id ? '编辑' : '新增') + '对接站点',
                skin: 'admin-modal',
                area: ['640px', '560px'],
                shadeClose: false,
                content: url,
                success: function (layero, layerIndex) {
                    var iframe = layero.find('iframe')[0];
                    if (!iframe) return;
                    function stamp() {
                        try {
                            var cw = iframe.contentWindow;
                            if (!cw) return;
                            cw.__EMS_LAYER_INDEX__ = layerIndex;
                            cw.__EMS_LAYER_HOST__ = winShell;
                        } catch (e) {}
                    }
                    try {
                        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
                            stamp();
                        } else {
                            $(iframe).one('load', stamp);
                        }
                    } catch (e2) {
                        $(iframe).one('load', stamp);
                    }
                }
            });
        }

        $('#emsAddSiteBtn').on('click', function() { openSiteForm(null); });

        function findSiteRow(siteId) {
            var sid = parseInt(siteId, 10) || 0;
            for (var i = 0; i < siteListRows.length; i++) {
                if (parseInt(siteListRows[i].id, 10) === sid) {
                    return siteListRows[i];
                }
            }
            return null;
        }

        $('#emsSiteCardWrap').on('click', '.ems-site-card__edit', function () {
            var id = $(this).data('site-id');
            openSiteForm(findSiteRow(id) || { id: id });
        });

        function openImportGoods(siteId) {
            siteId = parseInt(siteId, 10) || 0;
            if (siteId <= 0) return;
            var url = '/content/plugin/emshop/emshop_import_goods_page.php?site_id=' + siteId;
            layerShell.open({
                type: 2,
                title: '导入商品',
                skin: 'admin-modal',
                area: ['960px', '90%'],
                shadeClose: false,
                content: url,
                success: function (layero, layerIndex) {
                    var iframe = layero.find('iframe')[0];
                    if (!iframe) return;
                    function stamp() {
                        try {
                            var cw = iframe.contentWindow;
                            if (!cw) return;
                            cw.__EMS_LAYER_INDEX__ = layerIndex;
                            cw.__EMS_LAYER_HOST__ = winShell;
                        } catch (e) {}
                    }
                    try {
                        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
                            stamp();
                        } else {
                            $(iframe).one('load', stamp);
                        }
                    } catch (e2) {
                        $(iframe).one('load', stamp);
                    }
                }
            });
        }

        $('#emsSiteCardWrap').on('click', '.ems-site-card__import', function () {
            openImportGoods($(this).data('site-id'));
        });

        $('#emsSiteCardWrap').on('click', '.ems-site-card__del', function () {
            var id = $(this).data('site-id');
            layerShell.confirm('确定删除该对接站点？', function (i) {
                layerShell.close(i);
                call('site_delete', { id: id }, function (err) {
                    if (err) { layerShell.msg(err.message); return; }
                    layerShell.msg('已删除');
                    loadSites();
                });
            });
        });

        form.on('switch(emsSiteEnabled)', function (obj) {
            var id = parseInt(obj.value, 10);
            var enabled = obj.elem.checked ? 1 : 0;
            var $card = $(obj.elem).closest('.ems-site-card');
            call('site_toggle', { id: id, enabled: enabled }, function (err) {
                if (err) {
                    layerShell.msg(err.message);
                    obj.elem.checked = !obj.elem.checked;
                    form.render('checkbox');
                    return;
                }
                if (enabled) {
                    $card.addClass('ems-site-card--on');
                } else {
                    $card.removeClass('ems-site-card--on');
                }
                for (var i = 0; i < siteListRows.length; i++) {
                    if (parseInt(siteListRows[i].id, 10) === id) {
                        siteListRows[i].enabled = enabled;
                        break;
                    }
                }
                layerShell.msg('已更新');
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

        case 'import_local_categories': {
            Response::success('', [
                'list'       => GoodsImportService::localCategoryOptions(),
                'csrf_token' => Csrf::refresh(),
            ]);
            break;
        }

        case 'import_remote_categories': {
            $sid = (int) Input::post('site_id', 0);
            $site = RemoteSiteModel::find($sid);
            if ($site === null) {
                Response::error('对接站点不存在');
            }
            if ((int) ($site['enabled'] ?? 0) !== 1) {
                Response::error('该对接站点已停用');
            }
            try {
                $list = RemoteApiClient::fetchGoodsCategory(
                    (string) ($site['base_url'] ?? ''),
                    (string) ($site['appid'] ?? ''),
                    (string) ($site['secret'] ?? '')
                );
            } catch (Throwable $e) {
                Response::error('拉取对方分类失败：' . $e->getMessage());
            }
            Response::success('', ['list' => $list, 'csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'import_remote_goods': {
            $sid = (int) Input::post('site_id', 0);
            $site = RemoteSiteModel::find($sid);
            if ($site === null) {
                Response::error('对接站点不存在');
            }
            if ((int) ($site['enabled'] ?? 0) !== 1) {
                Response::error('该对接站点已停用');
            }
            $rawIds = trim((string) Input::post('category_ids', ''));
            $query = [];
            if ($rawIds !== '') {
                $decoded = json_decode($rawIds, true);
                if (is_array($decoded)) {
                    $ids = [];
                    foreach ($decoded as $v) {
                        $id = (int) $v;
                        if ($id > 0) {
                            $ids[] = $id;
                        }
                    }
                    if ($ids !== []) {
                        $query['category_ids'] = implode(',', $ids);
                    }
                }
            }
            try {
                $list = RemoteApiClient::fetchGoodsList(
                    (string) ($site['base_url'] ?? ''),
                    (string) ($site['appid'] ?? ''),
                    (string) ($site['secret'] ?? ''),
                    $query
                );
            } catch (Throwable $e) {
                Response::error('拉取对方商品失败：' . $e->getMessage());
            }
            Response::success('', ['list' => $list, 'csrf_token' => Csrf::refresh()]);
            break;
        }

        case 'import_goods_sync': {
            $sid = (int) Input::post('site_id', 0);
            $site = RemoteSiteModel::find($sid);
            if ($site === null) {
                Response::error('对接站点不存在');
            }
            if ((int) ($site['enabled'] ?? 0) !== 1) {
                Response::error('该对接站点已停用');
            }
            $targetCat = (int) Input::post('target_category_id', 0);
            $markupMode = trim((string) Input::post('markup_mode', 'percent'));
            $markupValue = (float) Input::post('markup_value', 0);
            $imageMode = trim((string) Input::post('image_mode', 'remote'));
            $goodsRaw = Input::post('goods_ids', '');
            $goodsIds = [];
            if (is_string($goodsRaw) && $goodsRaw !== '') {
                $decoded = json_decode($goodsRaw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $v) {
                        $gid = (int) $v;
                        if ($gid > 0) {
                            $goodsIds[] = $gid;
                        }
                    }
                }
            }
            $goodsIds = array_values(array_unique($goodsIds));
            try {
                $result = GoodsImportService::syncGoods(
                    $site,
                    $targetCat,
                    $markupMode,
                    $markupValue,
                    $imageMode,
                    $goodsIds
                );
            } catch (Throwable $e) {
                Response::error($e->getMessage());
            }
            $msg = '完成：成功 ' . $result['ok'] . ' 条';
            if ($result['fail'] > 0) {
                $msg .= '，失败 ' . $result['fail'] . ' 条';
            }
            Response::success($msg, [
                'result'     => $result,
                'csrf_token' => Csrf::refresh(),
            ]);
            break;
        }

        default:
            Response::error('未知操作');
    }
}
