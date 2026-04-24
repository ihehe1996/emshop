<?php
defined('EM_ROOT') || exit('access denied!');

/**
 * 默认模板设置页。
 *
 * 这里先放几个演示配置项，后续你可以再替换成真实模板配置。
 */
function template_setting_view() {
    $storage = TemplateStorage::getInstance('default');
    $bannerTitle = (string) $storage->getValue('banner_title');
    $bannerSubtitle = (string) $storage->getValue('banner_subtitle');
    $themeAccent = (string) $storage->getValue('theme_accent');
    $showAnnouncement = (string) $storage->getValue('show_announcement');
    if ($themeAccent === '') {
        $themeAccent = '#4f46e5';
    }
?>

<div class="popup-inner">
<form class="layui-form" id="defaultTemplateForm" lay-filter="defaultTemplateForm">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) Csrf::token(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="popup-section">
        <blockquote class="layui-elem-quote">
            默认模板演示配置。这里只是示例字段，方便你后续继续扩展真实模板设置。
        </blockquote>

        <div class="layui-form-item" style="margin-top: 16px;">
            <label class="layui-form-label">横幅标题</label>
            <div class="layui-input-block">
                <input type="text" class="layui-input" name="banner_title" value="<?php echo htmlspecialchars($bannerTitle, ENT_QUOTES, 'UTF-8'); ?>" placeholder="例如：欢迎来到我的网站">
            </div>
        </div>

        <div class="layui-form-item">
            <label class="layui-form-label">横幅副标题</label>
            <div class="layui-input-block">
                <input type="text" class="layui-input" name="banner_subtitle" value="<?php echo htmlspecialchars($bannerSubtitle, ENT_QUOTES, 'UTF-8'); ?>" placeholder="例如：分享技术与生活记录">
            </div>
        </div>

        <div class="layui-form-item">
            <label class="layui-form-label">主题色</label>
            <div class="layui-input-block">
                <input type="text" class="layui-input" name="theme_accent" value="<?php echo htmlspecialchars($themeAccent, ENT_QUOTES, 'UTF-8'); ?>" placeholder="#4f46e5">
            </div>
            <div class="layui-form-mid layui-word-aux">演示用颜色值，可填写十六进制颜色。</div>
        </div>

        <div class="layui-form-item">
            <label class="layui-form-label">公告开关</label>
            <div class="layui-input-block">
                <input type="checkbox" name="show_announcement" value="1" lay-skin="switch" lay-text="开启|关闭"<?php echo ($showAnnouncement === '1') ? ' checked' : ''; ?>>
            </div>
        </div>
    </div>
</form>
</div>

<div class="popup-footer">
    <button type="button" class="popup-btn popup-btn--default" id="defaultTemplateCancelBtn">取消</button>
    <button type="button" class="popup-btn popup-btn--primary" id="defaultTemplateSubmitBtn"><i class="layui-icon layui-icon-ok"></i> 保存配置</button>
</div>

<script>
(function(){
    layui.use(['layer', 'form'], function(){
        var $ = layui.$;
        var form = layui.form;
        form.render();

        $('#defaultTemplateCancelBtn').on('click', function(){
            var index = parent.layer.getFrameIndex(window.name);
            parent.layer.close(index);
        });

        $('#defaultTemplateSubmitBtn').on('click', function(){
            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="layui-icon layui-icon-loading layui-icon"></i> 保存中...');

            // URL 由 popup header 注入到 iframe 自身 window（主站默认 /admin/template.php，商户覆盖为 /user/merchant/theme.php）
            var __saveUrl = window.TEMPLATE_SAVE_URL || '/admin/template.php';
            $.ajax({
                type: 'POST',
                url: __saveUrl,
                data: $('#defaultTemplateForm').serialize() + '&_action=save_config&name=default',
                dataType: 'json',
                success: function(res){
                    if (res.code === 0 || res.code === 200) {
                        if (res.data && res.data.csrf_token) {
                            $('#defaultTemplateForm input[name=csrf_token]').val(res.data.csrf_token);
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

/**
 * 保存默认模板演示配置。
 */
function template_setting() {
    $csrf = (string) Input::postStrVar('csrf_token');
    if (!Csrf::validate($csrf)) {
        Output::fail('请求已失效，请刷新页面后重试');
    }

    $storage = TemplateStorage::getInstance('default');
    $storage->setValue('banner_title', (string) Input::postStrVar('banner_title'));
    $storage->setValue('banner_subtitle', (string) Input::postStrVar('banner_subtitle'));
    $storage->setValue('theme_accent', (string) Input::postStrVar('theme_accent'));
    $storage->setValue('show_announcement', Input::postStrVar('show_announcement') === '1' ? '1' : '0');

    Output::ok('配置已保存', ['csrf_token' => Csrf::refresh()]);
}
