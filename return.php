<?php

declare(strict_types=1);

/**
 * 支付同步跳回入口。
 *
 * 流程：
 *   1. 根据 URL 参数 ?plugin=xxx 识别具体支付插件
 *   2. 分发到 doAction('payment_return_' . $plugin, $data)，$data = 合并的 GET+POST
 *   3. 插件一般会校验签名后 Response::redirect() 到订单详情页
 *
 * 约定：
 *   - 插件生成支付 URL 时，必须把本文件路径带上 ?plugin=<自身 slug> 作为 return_url
 *   - 插件处理完成后应 exit（通常通过 Response::redirect 自动 exit）
 *   - 未被插件处理时兜底跳到用户订单列表页
 */
require_once __DIR__ . '/init.php';

/**
 * 身份感知的回跳 URL 构造：
 *   - 登录用户 → /user/order_detail.php?order_no=xxx（个人中心壳）
 *   - 游客 → /user/find_order.php?order_no=xxx（独立查单壳，无侧边栏）
 * 单独放这里，供本文件兜底和 epay 等插件的 payment_return_* 钩子复用。
 */
function payment_return_redirect_url(string $orderNo = ''): string
{
    $isLogged = session_status() !== PHP_SESSION_NONE
        ? !empty($_SESSION['em_front_user']['id'])
        : false;
    // 兼容 cli/异常：session 未启动时尝试启动一次再判定
    if (!$isLogged) {
        if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') @session_start();
        $isLogged = !empty($_SESSION['em_front_user']['id']);
    }
    $base = $isLogged ? '/user/order_detail.php' : '/user/find_order.php';
    return $orderNo !== '' ? ($base . '?order_no=' . urlencode($orderNo)) : $base;
}

$plugin = (string) Input::get('plugin', '');
if ($plugin === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $plugin)) {
    Response::redirect(payment_return_redirect_url());
}

$data = array_merge($_GET, $_POST);
doAction('payment_return_' . $plugin, $data);

// 兜底：插件未跳转时按身份走
Response::redirect(payment_return_redirect_url());
