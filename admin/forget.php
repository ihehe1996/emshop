<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * 后台忘记密码入口。
 *
 * 支持两个动作：
 *   POST _action=send_code    —— 发送邮箱验证码（15 秒限流，5 分钟有效）
 *   POST _action=reset        —— 校验验证码并重置密码
 * GET 请求直接渲染页面。
 *
 * 状态用 session 管理，key 见 sessionKey() 内统一常量；不落库，避免为一个重置流程多建表。
 */

// 安全入口守卫：与 sign.php 一致，未带 ?s=xxx 的请求在这里被拦
adminEnforceEntryKey();

// session 必须启动才能用 $_SESSION；与 Csrf::token() / LoginThrottle 里用的是同一个 session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$siteName = Config::get('sitename', 'EMSHOP');

// 统一 session key（只在本文件里用；换表或换存储只需改这一处）
const ADMIN_FORGET_SESSION_KEY = 'admin_forget_state';

// 验证码有效期（秒）
const ADMIN_FORGET_CODE_TTL = 300;

// 发送验证码最小间隔（秒）—— 用户要求 15s 防刷
const ADMIN_FORGET_SEND_INTERVAL = 15;

if (Request::isPost()) {
    try {
        $action = (string) Input::post('_action', '');
        if ($action === 'send_code') {
            handleSendCode();
            return;
        }
        if ($action === 'reset') {
            handleResetPassword();
            return;
        }
        Response::error('未知操作');
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error('系统繁忙，请稍后再试');
    }
}

$csrfToken = Csrf::token();
$viewFile = __DIR__ . '/view/forget.php';
require $viewFile;

/**
 * 处理发送邮箱验证码请求。
 *
 * 流程：
 *   1. CSRF 校验
 *   2. 邮箱格式校验
 *   3. 15 秒限流（按 session 维度，防止同一浏览器反复刷）
 *   4. 查管理员记录（找不到也返回成功，避免通过响应差异枚举管理员邮箱）
 *   5. 生成 6 位验证码 + 落 session + 用 Mailer::send() 寄出
 */
function handleSendCode(): void
{
    $csrf = (string) Input::post('csrf_token', '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }

    $email = trim((string) Input::post('email', ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Response::error('请填写正确的邮箱地址');
    }

    // 15 秒限流（按 session）：防止脚本反复点击发码按钮
    $state = $_SESSION[ADMIN_FORGET_SESSION_KEY] ?? [];
    $lastSentAt = (int) ($state['sent_at'] ?? 0);
    $remaining = ADMIN_FORGET_SEND_INTERVAL - (time() - $lastSentAt);
    if ($lastSentAt > 0 && $remaining > 0) {
        Response::error('发送过于频繁，请 ' . $remaining . ' 秒后再试');
    }

    // 查管理员：即使找不到也假装成功（5 分钟后过期自然失效），避免邮箱枚举
    $userModel = new UserModel();
    $admin = $userModel->findAdminByEmail($email);

    if ($admin !== null) {
        // 邮件配置没填全的话 Mailer::send 会直接返回 false；这里统一提示让用户检查
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $subject = '[' . Config::get('sitename', 'EMSHOP') . '] 后台密码重置验证码';
        $html = buildResetEmailHtml($code);

        $ok = Mailer::send($email, $subject, $html);
        if (!$ok) {
            Response::error(Mailer::lastError() ?: '验证码发送失败，请稍后再试');
        }

        // 把验证码和邮箱绑定：reset 时必须提交同一邮箱才能校验通过
        $_SESSION[ADMIN_FORGET_SESSION_KEY] = [
            'code' => $code,
            'email' => $email,
            'admin_id' => (int) $admin['id'],
            'expires_at' => time() + ADMIN_FORGET_CODE_TTL,
            'sent_at' => time(),
        ];
    } else {
        // 找不到管理员：只更新 sent_at，不写 code；reset 时照常校验会失败
        $_SESSION[ADMIN_FORGET_SESSION_KEY] = [
            'code' => '',
            'email' => $email,
            'admin_id' => 0,
            'expires_at' => 0,
            'sent_at' => time(),
        ];
    }

    Response::success('验证码已发送，请查收邮件（5 分钟内有效）');
}

/**
 * 处理重置密码请求。
 *
 * 校验四项：邮箱格式 / 验证码未过期 / 验证码与邮箱绑定 / 新密码两次一致且 ≥6 位
 * 成功后清理 session 状态，避免验证码被二次使用。
 */
function handleResetPassword(): void
{
    $csrf = (string) Input::post('csrf_token', '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }

    $email = trim((string) Input::post('email', ''));
    $code = trim((string) Input::post('code', ''));
    $newPassword = (string) Input::post('new_password', '');
    $confirmPassword = (string) Input::post('confirm_password', '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Response::error('请填写正确的邮箱地址');
    }
    if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
        Response::error('请填写 6 位数字验证码');
    }
    if ($newPassword === '') {
        Response::error('请填写新密码');
    }
    if ($newPassword !== $confirmPassword) {
        Response::error('两次输入的新密码不一致');
    }
    if (mb_strlen($newPassword) < 6) {
        Response::error('新密码长度不能少于 6 位');
    }

    $state = $_SESSION[ADMIN_FORGET_SESSION_KEY] ?? [];
    $savedCode = (string) ($state['code'] ?? '');
    $savedEmail = (string) ($state['email'] ?? '');
    $savedAdminId = (int) ($state['admin_id'] ?? 0);
    $expiresAt = (int) ($state['expires_at'] ?? 0);

    // 统一提示"验证码错误或已过期"，避免让攻击者区分是哪种失败
    if ($savedCode === '' || $expiresAt <= time() || $savedAdminId <= 0) {
        Response::error('验证码错误或已过期，请重新获取');
    }
    if (!hash_equals(strtolower($savedEmail), strtolower($email))) {
        Response::error('验证码错误或已过期，请重新获取');
    }
    if (!hash_equals($savedCode, $code)) {
        Response::error('验证码错误或已过期，请重新获取');
    }

    // 再查一次管理员，防止用户状态发生变化（被禁用/删除）
    $userModel = new UserModel();
    $admin = $userModel->findById($savedAdminId);
    if ($admin === null || (int) $admin['status'] !== 1 || (string) $admin['role'] !== 'admin') {
        Response::error('账号不存在或已被禁用');
    }

    $hasher = new PasswordHash(8, true);
    $userModel->updatePassword($savedAdminId, $hasher->HashPassword($newPassword));

    // 清理状态：验证码一次性使用，避免被重放
    unset($_SESSION[ADMIN_FORGET_SESSION_KEY]);

    Response::success('密码已重置，请使用新密码登录', [
        'redirect' => adminSignUrl(),
    ]);
}

/**
 * 构造验证码邮件的 HTML 正文，风格与"发送测试"一致（简洁、居中、浅灰背景）。
 */
function buildResetEmailHtml(string $code): string
{
    $siteName = htmlspecialchars((string) Config::get('sitename', 'EMSHOP'), ENT_QUOTES, 'UTF-8');
    $ttlMinutes = (int) (ADMIN_FORGET_CODE_TTL / 60);
    $escCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<div style="font-family:'Microsoft YaHei',Arial,sans-serif;color:#374151;font-size:14px;line-height:1.8;">
    <p>你正在重置 <strong>{$siteName}</strong> 后台登录密码，验证码如下：</p>
    <p style="font-size:28px;font-weight:700;letter-spacing:6px;color:#4f46e5;margin:16px 0;">{$escCode}</p>
    <p style="color:#6b7280;">验证码 {$ttlMinutes} 分钟内有效，请勿泄露给他人。</p>
    <p style="color:#9ca3af;font-size:12px;">如果并非你本人操作，请忽略本邮件。</p>
</div>
HTML;
}
