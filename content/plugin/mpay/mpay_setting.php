<?php
defined('EM_ROOT') || exit('Access Denied');

function mpay_setting_value(Storage $storage, array $keys, string $default = ''): string
{
    foreach ($keys as $k) {
        $v = trim((string) ($storage->getValue($k) ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return $default;
}

function mpay_setting_enabled(Storage $storage, string $code, bool $default = true): bool
{
    $raw = $storage->getValue($code . '_enabled');
    if ($raw === null || (string) $raw === '') {
        return $default;
    }
    return (string) $raw === '1';
}

function mpay_setting_mode(Storage $storage, string $code): string
{
    $mode = strtolower(mpay_setting_value($storage, [$code . '_create_mode', 'create_mode'], 'submit'));
    return in_array($mode, ['submit', 'mapi'], true) ? $mode : 'submit';
}

function plugin_setting_view(): void
{
    $storage = Storage::getInstance('mpay');
    $csrfToken = Csrf::token();

    $channels = [
        'mpay_alipay' => ['label' => '支付宝', 'default_name' => '支付宝', 'image' => '/content/plugin/mpay/alipay.png'],
        'mpay_wxpay'  => ['label' => '微信支付', 'default_name' => '微信支付', 'image' => '/content/plugin/mpay/wxpay.png'],
        'mpay_qqpay'  => ['label' => 'QQ钱包', 'default_name' => 'QQ钱包', 'image' => '/content/plugin/mpay/qqpay.png'],
    ];
    ?>
    <style>
    .mpay-tab{margin:0;}
    .mpay-tab>.layui-tab-title{padding:0 10px;background:#fafafa;border-bottom:1px solid #e6e6e6;}
    .mpay-tab>.layui-tab-title li{font-size:13px;padding:0 15px;display:inline-flex;align-items:center;gap:6px;}
    .mpay-tab>.layui-tab-title li img{width:16px;height:16px;object-fit:contain;}
    .mpay-tab>.layui-tab-content>.layui-tab-item{padding:0;}
    </style>
    <div class="popup-inner">
        <form class="layui-form" id="mpayForm" lay-filter="mpayForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="popup-section">
                <div class="layui-form-item" style="margin-bottom:0;">
                    <label class="layui-form-label">官网注册地址</label>
                    <div class="layui-input-block" style="padding-top:9px;">
                        <a href="https://m.ynile.cn/" target="_blank" rel="noopener noreferrer">https://m.ynile.cn/</a>
                    </div>
                </div>
            </div>

            <div class="layui-tab layui-tab-brief mpay-tab" lay-filter="mpayTab">
                <ul class="layui-tab-title">
                    <?php $first = true; foreach ($channels as $code => $ch): ?>
                    <li class="<?= $first ? 'layui-this' : '' ?>">
                        <img src="<?= htmlspecialchars($ch['image'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <?= htmlspecialchars($ch['label'], ENT_QUOTES, 'UTF-8') ?>
                    </li>
                    <?php $first = false; endforeach; ?>
                </ul>
                <div class="layui-tab-content">
                    <?php $first = true; foreach ($channels as $code => $ch):
                        $enabled = mpay_setting_enabled($storage, $code, true);
                        $mode = mpay_setting_mode($storage, $code);
                        $displayName = mpay_setting_value($storage, [$code . '_name'], $ch['default_name']);
                        $merchantId = mpay_setting_value($storage, [$code . '_merchant_id', 'merchant_id']);
                        $secretKey = mpay_setting_value($storage, [$code . '_secret_key', 'secret_key']);
                        $submitUrl = 'https://m.ynile.cn/submit.php';
                        $mapiUrl = 'https://m.ynile.cn/mapi.php';
                    ?>
                    <div class="layui-tab-item <?= $first ? 'layui-show' : '' ?>">
                        <div class="popup-section">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="<?= $code ?>_enabled" value="1" lay-skin="switch" lay-text="开启|关闭" <?= $enabled ? 'checked' : '' ?>>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">下单模式</label>
                                <div class="layui-input-block">
                                    <input type="radio" name="<?= $code ?>_create_mode" value="submit" title="页面跳转（submit.php）" <?= $mode === 'submit' ? 'checked' : '' ?>>
                                    <input type="radio" name="<?= $code ?>_create_mode" value="mapi" title="后端接口（mapi.php）" <?= $mode === 'mapi' ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>

                        <div class="popup-section">
                            <div class="layui-form-item">
                                <label class="layui-form-label">商户ID</label>
                                <div class="layui-input-block">
                                    <input type="text" class="layui-input" name="<?= $code ?>_merchant_id" value="<?= htmlspecialchars($merchantId, ENT_QUOTES, 'UTF-8') ?>" placeholder="例如：1001" autocomplete="off">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">商户密钥</label>
                                <div class="layui-input-block">
                                    <input type="text" class="layui-input" name="<?= $code ?>_secret_key" value="<?= htmlspecialchars($secretKey, ENT_QUOTES, 'UTF-8') ?>" placeholder="请输入商户密钥" autocomplete="off">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">跳转接口</label>
                                <div class="layui-input-block">
                                    <input type="text" class="layui-input" value="<?= htmlspecialchars($submitUrl, ENT_QUOTES, 'UTF-8') ?>" readonly disabled>
                                </div>
                                <div class="layui-form-mid layui-word-aux">固定地址（硬编码）：不可修改</div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">mapi 接口</label>
                                <div class="layui-input-block">
                                    <input type="text" class="layui-input" value="<?= htmlspecialchars($mapiUrl, ENT_QUOTES, 'UTF-8') ?>" readonly disabled>
                                </div>
                                <div class="layui-form-mid layui-word-aux">固定地址（硬编码）：不可修改</div>
                            </div>
                        </div>

                        <div class="popup-section">
                            <div class="layui-form-item">
                                <label class="layui-form-label">前台名称</label>
                                <div class="layui-input-block">
                                    <input type="text" class="layui-input" name="<?= $code ?>_name" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($ch['default_name'], ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="layui-form-mid layui-word-aux">留空则显示默认名称“<?= htmlspecialchars($ch['default_name'], ENT_QUOTES, 'UTF-8') ?>”</div>
                            </div>
                        </div>
                    </div>
                    <?php $first = false; endforeach; ?>
                </div>
            </div>
        </form>
    </div>
    <div class="popup-footer">
        <button type="button" class="popup-btn popup-btn--default" id="mpayCancelBtn">取消</button>
        <button type="button" class="popup-btn popup-btn--primary" id="mpaySaveBtn"><i class="layui-icon layui-icon-ok"></i> 保存配置</button>
    </div>
    <script>
    (function(){
        layui.use(['layer', 'form', 'element'], function(){
            var $ = layui.$;
            layui.form.render();
            layui.element.render('tab');

            $('#mpayCancelBtn').on('click', function(){
                parent.layer.close(parent.layer.getFrameIndex(window.name));
            });

            $('#mpaySaveBtn').on('click', function(){
                var $btn = $(this);
                $btn.prop('disabled', true).html('<i class="layui-icon layui-icon-loading"></i> 保存中...');
                $.ajax({
                    type:'POST',
                    url: window.PLUGIN_SAVE_URL || '/admin/plugin.php',
                    data: $('#mpayForm').serialize() + '&_action=save_config&name=mpay',
                    dataType:'json',
                    success: function(res){
                        if(res.code===0 || res.code===200){
                            if(res.data && res.data.csrf_token){
                                $('#mpayForm input[name=csrf_token]').val(res.data.csrf_token);
                            }
                            parent.layer.msg('配置已保存');
                            parent.layer.close(parent.layer.getFrameIndex(window.name));
                            return;
                        }
                        layui.layer.msg(res.msg || '保存失败');
                        $btn.prop('disabled', false).html('<i class="layui-icon layui-icon-ok"></i> 保存配置');
                    },
                    error: function(){
                        layui.layer.msg('网络异常');
                        $btn.prop('disabled', false).html('<i class="layui-icon layui-icon-ok"></i> 保存配置');
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

    $storage = Storage::getInstance('mpay');
    $channels = ['mpay_alipay', 'mpay_wxpay', 'mpay_qqpay'];

    foreach ($channels as $code) {
        $enabled = Input::post($code . '_enabled', '') === '1' ? '1' : '0';
        $mode = strtolower(trim((string) Input::post($code . '_create_mode', 'submit')));
        if (!in_array($mode, ['submit', 'mapi'], true)) {
            $mode = 'submit';
        }

        $storage->setValue($code . '_enabled', $enabled);
        $storage->setValue($code . '_create_mode', $mode);
        $storage->setValue($code . '_name', trim((string) Input::post($code . '_name', '')));
        $storage->setValue($code . '_merchant_id', trim((string) Input::post($code . '_merchant_id', '')));
        $storage->setValue($code . '_secret_key', trim((string) Input::post($code . '_secret_key', '')));
        // 网关地址已硬编码，不接受任何保存修改。
    }

    Response::success('配置已保存', ['csrf_token' => Csrf::refresh()]);
}


