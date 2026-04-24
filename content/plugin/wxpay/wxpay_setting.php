<?php
defined('EM_ROOT') || exit('Access Denied');

function plugin_setting_view(): void
{
    $storage = Storage::getInstance('wxpay');
    $displayName = $storage->getValue('display_name') ?: '';
    $csrfToken = Csrf::token();
    ?>
    <div class="popup-inner">
        <form class="layui-form" id="wxpayForm" lay-filter="wxpayForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="popup-section">
                <div class="layui-form-item">
                    <label class="layui-form-label">外显名称</label>
                    <div class="layui-input-block">
                        <input type="text" class="layui-input" name="display_name" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" placeholder="微信支付">
                    </div>
                    <div class="layui-form-mid layui-word-aux">留空则显示默认名称"微信支付"</div>
                </div>
            </div>
        </form>
    </div>
    <div class="popup-footer">
        <button type="button" class="popup-btn popup-btn--default" id="wxpayCancelBtn">取消</button>
        <button type="button" class="popup-btn popup-btn--primary" id="wxpaySaveBtn"><i class="layui-icon layui-icon-ok"></i> 保存配置</button>
    </div>
    <script>
    (function(){
        layui.use(['layer', 'form'], function(){
            var $ = layui.$; layui.form.render();
            $('#wxpayCancelBtn').on('click', function(){ parent.layer.close(parent.layer.getFrameIndex(window.name)); });
            $('#wxpaySaveBtn').on('click', function(){
                var $btn = $(this);
                $btn.prop('disabled', true).html('<i class="layui-icon layui-icon-loading"></i> 保存中...');
                $.ajax({ type:'POST', url: window.PLUGIN_SAVE_URL || '/admin/plugin.php', data: $('#wxpayForm').serialize()+'&_action=save_config&name=wxpay', dataType:'json',
                    success: function(res){
                        if(res.code===0||res.code===200){ if(res.data&&res.data.csrf_token) $('#wxpayForm input[name=csrf_token]').val(res.data.csrf_token); parent.layer.msg('配置已保存'); parent.layer.close(parent.layer.getFrameIndex(window.name)); }
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
    if (!Csrf::validate($csrf)) { Response::error('请求已失效，请刷新页面后重试'); }
    $storage = Storage::getInstance('wxpay');
    $storage->setValue('display_name', (string) Input::post('display_name', ''));
    Response::success('配置已保存', ['csrf_token' => Csrf::refresh()]);
}
