$(function () {
    // 保留当前查询串（含 ?s=xxx 安全入口），否则 POST 会落到无参 URL 被 adminEnforceEntryKey 拦住
    var postUrl = '/admin/forget.php' + window.location.search;

    var $form = $('#forgetForm');
    var $sendBtn = $('#sendCodeBtn');
    var cooldownTimer = null;

    // 开启发送验证码倒计时（参数以秒计）；倒计时期间禁用按钮并显示剩余秒数
    function startCooldown(seconds) {
        var remaining = seconds;
        $sendBtn.prop('disabled', true).addClass('is-disabled').text(remaining + 's 后重发');
        cooldownTimer = setInterval(function () {
            remaining -= 1;
            if (remaining <= 0) {
                clearInterval(cooldownTimer);
                cooldownTimer = null;
                $sendBtn.prop('disabled', false).removeClass('is-disabled').text('发送验证码');
                return;
            }
            $sendBtn.text(remaining + 's 后重发');
        }, 1000);
    }

    // 点击"发送验证码"
    $sendBtn.on('click', function () {
        var email = $.trim($('#forgetEmail').val() || '');
        if (email === '') {
            layui.layer.msg('请先填写管理员邮箱');
            $('#forgetEmail').focus();
            return;
        }

        $sendBtn.prop('disabled', true).text('发送中...');

        $.ajax({
            url: postUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                _action: 'send_code',
                csrf_token: $form.find('input[name="csrf_token"]').val(),
                email: email
            }
        }).done(function (res) {
            layui.layer.msg((res && res.msg) || (res && res.code === 200 ? '已发送' : '发送失败'));
            if (res && res.code === 200) {
                // 服务端限流是 15 秒，这里前端同步做一次 15 秒倒计时
                startCooldown(15);
            } else {
                $sendBtn.prop('disabled', false).text('发送验证码');
            }
        }).fail(function () {
            layui.layer.msg('网络异常，请稍后重试');
            $sendBtn.prop('disabled', false).text('发送验证码');
        });
    });

    // 提交表单：执行密码重置
    $form.on('submit', function (event) {
        event.preventDefault();

        var email = $.trim($('#forgetEmail').val() || '');
        var code = $.trim($('#forgetCode').val() || '');
        var newPassword = $('#newPassword').val() || '';
        var confirmPassword = $('#confirmPassword').val() || '';

        if (email === '') { layui.layer.msg('请填写管理员邮箱'); return; }
        if (code === '') { layui.layer.msg('请填写验证码'); return; }
        if (newPassword === '') { layui.layer.msg('请填写新密码'); return; }
        if (newPassword.length < 6) { layui.layer.msg('新密码长度不能少于 6 位'); return; }
        if (newPassword !== confirmPassword) { layui.layer.msg('两次输入的新密码不一致'); return; }

        var $submit = $form.find('button[type="submit"]');
        $submit.prop('disabled', true).addClass('layui-btn-disabled').text('提交中...');

        $.ajax({
            url: postUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                _action: 'reset',
                csrf_token: $form.find('input[name="csrf_token"]').val(),
                email: email,
                code: code,
                new_password: newPassword,
                confirm_password: confirmPassword
            }
        }).done(function (res) {
            layui.layer.msg((res && res.msg) || (res && res.code === 200 ? '重置成功' : '重置失败'));
            if (res && res.code === 200) {
                // 跳转到登录页（服务端会带上安全入口参数）
                setTimeout(function () {
                    window.location.href = (res.data && res.data.redirect) || '/admin/sign.php';
                }, 800);
                return;
            }
            $submit.prop('disabled', false).removeClass('layui-btn-disabled').text('重置密码');
        }).fail(function () {
            layui.layer.msg('网络异常，请稍后重试');
            $submit.prop('disabled', false).removeClass('layui-btn-disabled').text('重置密码');
        });
    });
});
