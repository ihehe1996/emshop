<?php

declare(strict_types=1);

/**
 * 支付异步通知入口。
 *
 * 流程：
 *   1. 根据 URL 参数 ?plugin=xxx 识别具体支付插件
 *   2. 分发到 doAction('payment_notify_' . $plugin, $data)，$data = 合并的 GET+POST
 *   3. 插件自行校验签名 / 更新订单状态 / echo 'success' 并 exit
 *
 * 约定：
 *   - 插件生成支付 URL 时，必须把本文件路径带上 ?plugin=<自身 slug> 作为 notify_url
 *   - 插件处理完成后必须输出约定字符串（如易支付要求 "success"）并 exit，否则本文件兜底输出 "fail"
 */
require_once __DIR__ . '/init.php';

$plugin = (string) Input::get('plugin', '');
if ($plugin === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $plugin)) {
    http_response_code(400);
    exit('fail: missing or invalid plugin');
}

// 合并 GET + POST 参数供插件使用（POST 优先覆盖 GET，符合表单+URL 混传场景）
$data = array_merge($_GET, $_POST);

// 分发给插件处理；插件内部应负责 echo 'success' / exit
// 走 PaymentService 包装：商户站子域名打过来的回调，必须切到主站 scope 让插件读到正确凭证
PaymentService::dispatchNotify($plugin, $data);

// 兜底：若插件未输出，视为未识别的回调
http_response_code(500);
echo 'fail: no plugin handler';
