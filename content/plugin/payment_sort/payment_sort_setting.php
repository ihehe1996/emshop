<?php
defined('EM_ROOT') || exit('Access Denied');

function plugin_setting_view(): void
{
    // 直接收集原始支付方式（不经过排序钩子，避免循环）
    $methods = applyFilter('payment_methods_register', []);
    if (Config::get('shop_balance_enabled', '1') === '1') {
        $methods[] = ['code' => 'balance', 'name' => '余额支付', 'image' => '/content/static/img/balance.png'];
    }

    // 读取已保存的排序
    $storage = Storage::getInstance('payment_sort');
    $sortOrder = $storage->getValue('sort_order') ?: [];
    if (!empty($sortOrder)) {
        $sortMap = array_flip($sortOrder);
        usort($methods, function ($a, $b) use ($sortMap) {
            return ($sortMap[$a['code']] ?? 999) - ($sortMap[$b['code']] ?? 999);
        });
    }

    $csrfToken = Csrf::token();
    ?>
    <style>
        .ps-list { list-style: none; padding: 0; margin: 0; }
        .ps-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px; margin-bottom: 8px;
            background: #fff; border: 1px solid #ebeef5; border-radius: 8px;
            cursor: grab; transition: box-shadow 0.2s;
            user-select: none; -webkit-user-select: none;
        }
        .ps-item:active { cursor: grabbing; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .ps-item-drag { color: #ccc; font-size: 16px; }
        .ps-item-img { width: 28px; height: 28px; object-fit: contain; }
        .ps-item-name { font-size: 14px; color: #333; font-weight: 500; }
        .ps-item-code { font-size: 12px; color: #adb5bd; margin-left: auto; }
        .ps-empty { text-align: center; padding: 40px; color: #ccc; font-size: 14px; }
    </style>

    <div class="popup-inner">
        <?php if (empty($methods)): ?>
        <div class="ps-empty">暂无可用的支付方式，请先启用支付插件</div>
        <?php else: ?>
        <p style="font-size: 13px; color: #999; margin-bottom: 16px;">拖动调整支付方式的显示顺序</p>
        <form id="paymentSortForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="sort_order" id="sortOrderInput" value="">
            <ul class="ps-list" id="paymentSortList">
                <?php foreach ($methods as $m): ?>
                <li class="ps-item" data-code="<?= htmlspecialchars($m['code']) ?>">
                    <span class="ps-item-drag"><i class="fa fa-bars"></i></span>
                    <img class="ps-item-img" src="<?= htmlspecialchars($m['image'] ?? '') ?>" alt="">
                    <span class="ps-item-name"><?= htmlspecialchars($m['name']) ?></span>
                    <span class="ps-item-code"><?= htmlspecialchars($m['code']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </form>
        <?php endif; ?>
    </div>

    <div class="popup-footer">
        <button type="button" class="popup-btn popup-btn--default" id="psCancelBtn">取消</button>
        <button type="button" class="popup-btn popup-btn--primary" id="psSaveBtn"><i class="layui-icon layui-icon-ok"></i> 保存排序</button>
    </div>

    <script src="/content/static/lib/Sortable.min.js"></script>
    <script>
    (function () {
        layui.use(['layer'], function () {
            var $ = layui.$;
            var listEl = document.getElementById('paymentSortList');

            if (listEl) {
                Sortable.create(listEl, { animation: 150, ghostClass: 'sortable-ghost' });
            }

            $('#psCancelBtn').on('click', function () {
                var index = parent.layer.getFrameIndex(window.name);
                parent.layer.close(index);
            });

            $('#psSaveBtn').on('click', function () {
                var $btn = $(this);

                // 收集排序
                var order = [];
                if (listEl) {
                    listEl.querySelectorAll('.ps-item').forEach(function (el) {
                        order.push(el.getAttribute('data-code'));
                    });
                }
                $('#sortOrderInput').val(JSON.stringify(order));

                $btn.prop('disabled', true).html('<i class="layui-icon layui-icon-loading"></i> 保存中...');

                $.ajax({
                    type: 'POST',
                    url: window.PLUGIN_SAVE_URL || '/admin/plugin.php',
                    data: $('#paymentSortForm').serialize() + '&_action=save_config&name=payment_sort',
                    dataType: 'json',
                    success: function (res) {
                        if (res.code === 0 || res.code === 200) {
                            if (res.data && res.data.csrf_token) {
                                $('#paymentSortForm input[name=csrf_token]').val(res.data.csrf_token);
                            }
                            parent.layer.msg('排序已保存');
                            var index = parent.layer.getFrameIndex(window.name);
                            parent.layer.close(index);
                        } else {
                            layui.layer.msg(res.msg || '保存失败');
                            $btn.prop('disabled', false).html('<i class="layui-icon layui-icon-ok"></i> 保存排序');
                        }
                    },
                    error: function () {
                        layui.layer.msg('网络异常');
                        $btn.prop('disabled', false).html('<i class="layui-icon layui-icon-ok"></i> 保存排序');
                    }
                });
            });
        });
    })();
    </script>
    <?php
}

function plugin_setting(): void
{
    $csrf = (string) Input::post('csrf_token', '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }

    $sortOrder = (string) Input::post('sort_order', '');
    $decoded = json_decode($sortOrder, true);
    if (!is_array($decoded)) {
        Response::error('排序数据无效');
    }

    $storage = Storage::getInstance('payment_sort');
    $storage->setValue('sort_order', $sortOrder);

    Response::success('排序已保存', ['csrf_token' => Csrf::refresh()]);
}
