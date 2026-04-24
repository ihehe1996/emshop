<?php
defined('EM_ROOT') || exit('Access Denied');

function plugin_setting_view(): void
{
    $storage = Storage::getInstance('epay');
    $csrfToken = Csrf::token();

    // 三种渠道
    $channels = [
        'epay_alipay' => ['label' => '支付宝',  'default_name' => '支付宝',  'image' => '/content/plugin/epay/alipay.png'],
        'epay_wxpay'  => ['label' => '微信支付', 'default_name' => '微信支付', 'image' => '/content/plugin/epay/wxpay.png'],
        'epay_qqpay'  => ['label' => 'QQ钱包',  'default_name' => 'QQ钱包',  'image' => '/content/plugin/epay/qqpay.png'],
    ];
    ?>
    <style>
    /* 支付类型选项卡（样式参考后台商品编辑页） */
    .epay-tab { margin: 0; }
    .epay-tab > .layui-tab-title { padding: 0 10px; background: #fafafa; border-bottom: 1px solid #e6e6e6; }
    .epay-tab > .layui-tab-title li { font-size: 13px; padding: 0 15px; display: inline-flex; align-items: center; gap: 6px; }
    .epay-tab > .layui-tab-title li img { width: 16px; height: 16px; object-fit: contain; }
    .epay-tab > .layui-tab-content > .layui-tab-item { padding: 0; }
    </style>
    <div class="popup-inner">
        <form class="layui-form" id="epayForm" lay-filter="epayForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="layui-tab layui-tab-brief epay-tab" lay-filter="epayTab">
                <ul class="layui-tab-title">
                    <?php $first = true; foreach ($channels as $code => $ch): ?>
                    <li class="<?= $first ? 'layui-this' : '' ?>">
                        <img src="<?= $ch['image'] ?>" alt=""><?= $ch['label'] ?>
                    </li>
                    <?php $first = false; endforeach; ?>
                </ul>
                <div class="layui-tab-content">
                    <?php $first = true; foreach ($channels as $code => $ch):
                        $enabled = $storage->getValue($code . '_enabled');
                        // Storage::getValue 会把 "0" 转成 int 0，这里用宽松比较
                        $isOn = ((string) $enabled !== '0'); // 默认启用
                        $displayName = $storage->getValue($code . '_name') ?: '';
                        $merchantId  = $storage->getValue($code . '_merchant_id') ?: '';
                        $secretKey   = $storage->getValue($code . '_secret_key')  ?: '';
                        $submitUrl   = $storage->getValue($code . '_submit_url')  ?: '';
                        $mapiUrl     = $storage->getValue($code . '_mapi_url')    ?: '';
                    ?>
                    <div class="layui-tab-item <?= $first ? 'layui-show' : '' ?>">
                        <!-- 版块 1：启用开关 -->
                        <div class="popup-section">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="<?= $code ?>_enabled" value="1" lay-skin="switch" lay-text="开启|关闭" <?= $isOn ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>

                        <!-- 版块 2：商户配置 -->
                        <div class="popup-section">
                            <div class="layui-form-item">
                                <label class="layui-form-label">商户ID</label>
                                <div class="layui-input-block">
                                    <input type="text" class="layui-input" name="<?= $code ?>_merchant_id" value="<?= htmlspecialchars($merchantId, ENT_QUOTES, 'UTF-8') ?>" placeholder="请输入商户ID" autocomplete="off">
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
                                    <input type="text" class="layui-input" name="<?= $code ?>_submit_url" value="<?= htmlspecialchars($submitUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="例如：https://pay.xxx.com/submit.php">
                                </div>
                                <div class="layui-form-mid layui-word-aux">页面跳转支付提交地址</div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">mapi 接口</label>
                                <div class="layui-input-block">
                                    <input type="text" class="layui-input" name="<?= $code ?>_mapi_url" value="<?= htmlspecialchars($mapiUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="例如：https://pay.xxx.com/mapi.php">
                                </div>
                                <div class="layui-form-mid layui-word-aux">服务端对接调用地址</div>
                            </div>
                        </div>

                        <!-- 版块 3：外显名称 -->
                        <div class="popup-section">
                            <div class="layui-form-item">
                                <label class="layui-form-label">前台名称</label>
                                <div class="layui-input-block">
                                    <input type="text" class="layui-input" name="<?= $code ?>_name" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= $ch['default_name'] ?>">
                                </div>
                                <div class="layui-form-mid layui-word-aux">留空则显示默认名称"<?= $ch['default_name'] ?>"</div>
                            </div>
                        </div>
                    </div>
                    <?php $first = false; endforeach; ?>
                </div>
            </div>

        </form>
    </div>
    <div class="popup-footer">
        <button type="button" class="popup-btn popup-btn--default" id="epayCancelBtn">取消</button>
        <button type="button" class="popup-btn popup-btn--primary" id="epaySaveBtn"><i class="layui-icon layui-icon-ok"></i> 保存配置</button>
    </div>
    <script>
    (function(){
        layui.use(['layer', 'form', 'element'], function(){
            var $ = layui.$; layui.form.render(); layui.element.render('tab');
            $('#epayCancelBtn').on('click', function(){ parent.layer.close(parent.layer.getFrameIndex(window.name)); });
            $('#epaySaveBtn').on('click', function(){
                var $btn = $(this);
                $btn.prop('disabled', true).html('<i class="layui-icon layui-icon-loading"></i> 保存中...');
                $.ajax({ type:'POST', url: window.PLUGIN_SAVE_URL || '/admin/plugin.php', data: $('#epayForm').serialize()+'&_action=save_config&name=epay', dataType:'json',
                    success: function(res){
                        if(res.code===0||res.code===200){ if(res.data&&res.data.csrf_token) $('#epayForm input[name=csrf_token]').val(res.data.csrf_token); parent.layer.msg('配置已保存'); parent.layer.close(parent.layer.getFrameIndex(window.name)); }
                        else{ layui.layer.msg(res.msg||'保存失败'); $btn.prop('disabled',false).html('<i class="layui-icon layui-icon-ok"></i> 保存配置'); }
                    },
                    error: function(){ layui.layer.msg('网络异常'); $btn.prop('disabled',false).html('<i class="layui-icon layui-icon-ok"></i> 保存配置'); }
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

    $storage = Storage::getInstance('epay');
    $channels = ['epay_alipay', 'epay_wxpay', 'epay_qqpay'];

    foreach ($channels as $code) {
        // 开关：checkbox 未勾选时不提交，视为 '0'
        $enabled = Input::post($code . '_enabled', '') === '1' ? '1' : '0';
        $storage->setValue($code . '_enabled', $enabled);
        $storage->setValue($code . '_name',        (string) Input::post($code . '_name', ''));
        $storage->setValue($code . '_merchant_id', trim((string) Input::post($code . '_merchant_id', '')));
        $storage->setValue($code . '_secret_key',  trim((string) Input::post($code . '_secret_key', '')));
        $storage->setValue($code . '_submit_url',  trim((string) Input::post($code . '_submit_url', '')));
        $storage->setValue($code . '_mapi_url',    trim((string) Input::post($code . '_mapi_url', '')));
    }

    Response::success('配置已保存', ['csrf_token' => Csrf::refresh()]);
}
