<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * Swoole 监控页面。
 */
adminRequireLogin();

$csrfToken = Csrf::token();
// Swoole API 地址（后台系统设置中配置 swoole_api_url，生产环境填 http://127.0.0.1:9601）
$swooleApiUrl = Config::get('swoole_api_url', 'http://127.0.0.1:9601');

// AJAX 请求：代理转发到 Swoole HTTP API
if (Request::isPost()) {
    $action = (string) Input::post('_action', '');
    $csrf = (string) Input::post('csrf_token', '');

    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }

    switch ($action) {
        case 'status':
            $result = swooleApiGet($swooleApiUrl . '/status');
            if ($result === null) {
                Response::success('', ['running' => false]);
            } else {
                Response::success('', $result['data'] ?? ['running' => false]);
            }
            break;

        case 'queue_recent':
            $result = swooleApiGet($swooleApiUrl . '/queue/recent');
            Response::success('', ['list' => $result['data'] ?? []]);
            break;

        case 'queue_retry':
            $id = (int) Input::post('id', 0);
            swooleApiPost($swooleApiUrl . '/queue/retry', ['id' => $id]);
            Response::success('已重置', ['csrf_token' => Csrf::refresh()]);
            break;

        default:
            Response::error('未知操作');
    }
}

// 页面渲染
if ((string) Input::get('_popup', '') !== '') {
    // 弹窗模式（iframe 嵌入）：只渲染监控内容 + 必要的 CSS/JS，不套后台框架（避免侧边栏重复出现）
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Swoole 监控</title>
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <link rel="stylesheet" href="/content/static/lib/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="/admin/static/css/reset-layui.css">
    <link rel="stylesheet" href="/admin/static/css/admin.css">
    <script src="/content/static/lib/jquery.min.3.5.1.js"></script>
    <script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
    <script src="/admin/static/js/admin.js"></script>
    <script>window.adminCsrfToken = <?php echo json_encode($csrfToken); ?>;</script>
    <style>
        /* 覆盖 admin.css 对 html/body 的"全局锁死滚动"策略（后台主框架依赖它做内滚动）
           在弹窗 iframe 模式下我们需要由 body 接管滚动，否则内容超高时会被裁掉 */
        html, body { height: auto; overflow: auto; }
        body { background: #f8fafc; margin: 0; }
        /* 弹窗里不需要 admin-content 的 flex:1 逻辑，解除它以便正常按内容流布局 */
        .swoole-popup-body { padding: 16px 20px 24px; }
    </style>
</head>
<body>
<div class="swoole-popup-body"><?php include __DIR__ . '/view/swoole.php'; ?></div>
</body>
</html>
    <?php
    exit;
}

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/swoole.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/swoole.php';
    require __DIR__ . '/index.php';
}

/**
 * 请求 Swoole HTTP API（GET）。
 */
function swooleApiGet(string $url): ?array
{
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    return json_decode($body, true);
}

/**
 * 请求 Swoole HTTP API（POST）。
 */
function swooleApiPost(string $url, array $data): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data),
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    return json_decode($body, true);
}
