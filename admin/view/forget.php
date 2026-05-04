<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>忘记密码 - <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <link rel="stylesheet" href="/admin/static/css/reset-layui.css">
    <link rel="stylesheet" href="/admin/static/css/sign.css">
</head>
<body>
<div class="sign-page">
    <div class="sign-cover">
        <div class="sign-cover__inner">
            <p class="sign-cover__tag"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> ADMIN</p>
            <h1>找回后台登录密码</h1>
            <p>通过管理员邮箱接收验证码，即可快速重置后台登录密码。</p>
        </div>
    </div>
    <div class="sign-panel">
        <div class="sign-card layui-anim layui-anim-upbit">
            <div class="sign-brand"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></div>
            <h2>忘记密码</h2>
            <p class="sign-subtitle">请填写管理员邮箱，通过验证码重置密码</p>
            <form class="layui-form" id="forgetForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="layui-form-item">
                    <label class="sign-label" for="forgetEmail">管理员邮箱</label>
                    <input type="email" id="forgetEmail" name="email" class="layui-input" placeholder="请输入管理员邮箱">
                </div>
                <div class="layui-form-item">
                    <label class="sign-label" for="forgetCode">邮箱验证码</label>
                    <div class="sign-code-row">
                        <input type="text" id="forgetCode" name="code" class="layui-input" placeholder="请输入 6 位验证码" maxlength="6" inputmode="numeric">
                        <button type="button" class="sign-code-btn" id="sendCodeBtn">发送验证码</button>
                    </div>
                </div>
                <div class="layui-form-item">
                    <label class="sign-label" for="newPassword">新密码</label>
                    <input type="password" id="newPassword" name="new_password" class="layui-input" placeholder="至少 6 位">
                </div>
                <div class="layui-form-item">
                    <label class="sign-label" for="confirmPassword">确认新密码</label>
                    <input type="password" id="confirmPassword" name="confirm_password" class="layui-input" placeholder="再次输入新密码">
                </div>
                <button type="submit" class="layui-btn layui-btn-fluid sign-submit">重置密码</button>
            </form>
            <?php
            // 安全要点：只从当前 URL 的 ?s= 读，绝不从 Config 读 admin_entry_key，
            // 保证 key 永远不会在 HTML 回显给未通过守卫的访客。
            $urlS = (string) Input::get('s', '');
            $signBackUrl = '/admin/sign.php' . ($urlS !== '' ? '?s=' . urlencode($urlS) : '');
            ?>
            <div class="sign-options" style="margin-top: 18px; justify-content: flex-end;">
                <a href="<?php echo htmlspecialchars($signBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="sign-link">返回登录</a>
            </div>
        </div>
    </div>
</div>
<script src="/content/static/lib/jquery.min.3.5.1.js"></script>
<script>
if (typeof jQuery !== 'undefined' && typeof jQuery.trim !== 'function') {
    jQuery.trim = function (value) {
        return value == null ? '' : String(value).trim();
    };
}
</script>
<script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
<script src="/admin/static/js/forget.js"></script>
</body>
</html>
