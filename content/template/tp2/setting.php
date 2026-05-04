<?php
defined('EM_ROOT') || exit('access denied!');

function template_setting_view() {
    $storage = TemplateStorage::getInstance('tp2');
    $themeColor = (string) $storage->getValue('theme_color');
    if ($themeColor === '') $themeColor = '#10b981';
    $bannerText = (string) $storage->getValue('banner_text');
?>

<div class="popup-inner">
<form class="layui-form" id="tp2Form" lay-filter="tp2Form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) Csrf::token(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="popup-section">
        <blockquote class="layui-elem-quote">
            演示模板 #2 —— 绿色主题。
        </blockquote>

        <div class="layui-form-item" style="margin-top: 16px;">
            <label class="layui-form-label">主题色</label>
            <div class="layui-input-block">
                <input type="text" class="layui-input" name="theme_color" value="<?php echo htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8'); ?>" placeholder="#10b981">
            </div>
        </div>

        <div class="layui-form-item">
            <label class="layui-form-label">横幅文案</label>
            <div class="layui-input-block">
                <input type="text" class="layui-input" name="banner_text" value="<?php echo htmlspecialchars($bannerText, ENT_QUOTES, 'UTF-8'); ?>" placeholder="留空使用默认">
            </div>
        </div>
    </div>
</form>
</div>

<div class="popup-footer">
    <button type="button" class="popup-btn popup-btn--default" id="tp2CancelBtn">取消</button>
    <button type="button" class="popup-btn popup-btn--primary" id="tp2SubmitBtn"><i class="layui-icon layui-icon-ok"></i> 保存配置</button>
</div>

<script>
(function(){
    layui.use(['layer','form'], function(){
        var $ = layui.$, form = layui.form;
        form.render();

        $('#tp2CancelBtn').on('click', function(){
            var index = parent.layer.getFrameIndex(window.name);
            parent.layer.close(index);
        });

        $('#tp2SubmitBtn').on('click', function(){
            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="layui-icon layui-icon-loading"></i> 保存中...');

            $.ajax({
                type: 'POST',
                url: window.TEMPLATE_SAVE_URL || '/admin/template.php',
                data: $('#tp2Form').serialize() + '&_action=save_config&name=tp2',
                dataType: 'json',
                success: function(res){
                    if (res.code === 0 || res.code === 200) {
                        if (res.data && res.data.csrf_token) {
                            $('#tp2Form input[name=csrf_token]').val(res.data.csrf_token);
                        }
                        parent.layer.msg('配置已保存', {icon: 1});
                        parent.layer.close(parent.layer.getFrameIndex(window.name));
                    } else {
                        layui.layer.msg(res.msg || '保存失败', {icon: 2});
                        $btn.prop('disabled', false).html('<i class="layui-icon layui-icon-ok"></i> 保存配置');
                    }
                },
                error: function(){
                    layui.layer.msg('网络异常', {icon: 2});
                    $btn.prop('disabled', false).html('<i class="layui-icon layui-icon-ok"></i> 保存配置');
                }
            });
        });
    });
})();
</script>

<?php }

function template_setting() {
    $csrf = (string) Input::postStrVar('csrf_token');
    if (!Csrf::validate($csrf)) {
        Output::fail('请求已失效，请刷新页面后重试');
    }
    $storage = TemplateStorage::getInstance('tp2');
    $storage->setValue('theme_color', (string) Input::postStrVar('theme_color'));
    $storage->setValue('banner_text', (string) Input::postStrVar('banner_text'));
    Output::ok('配置已保存', ['csrf_token' => Csrf::refresh()]);
}
