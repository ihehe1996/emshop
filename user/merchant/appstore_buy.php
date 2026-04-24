<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 商户后台 —— 应用商店购买弹窗（iframe 页面）。
 * 结构与主站 admin/appstore_buy.php 对齐；商户身份 + 商户侧 popup view。
 * 外部 URL：/user/merchant/appstore_buy.php?id={app_id}
 */
merchantRequireLogin();

$csrfToken = Csrf::token();
$appId = (int) Input::get('id', 0);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>应用购买</title>
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <link rel="stylesheet" href="/content/static/lib/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="/admin/static/css/reset-layui.css">
    <link rel="stylesheet" href="/admin/static/css/popup.css">
    <script src="/content/static/lib/jquery.min.3.5.1.js"></script>
    <script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
    <script>
        window.APPSTORE_BUY_ID = <?= (int) $appId ?>;
        <?php
            $__buyLines = LicenseClient::lines();
            $__buyAssetHost = $__buyLines ? rtrim($__buyLines[0]['url'], '/') : '';
        ?>
        window.APPSTORE_ASSET_HOST = <?= json_encode($__buyAssetHost, JSON_UNESCAPED_SLASHES) ?>;
        window.adminCsrfToken = <?= json_encode($csrfToken) ?>;
    </script>
</head>
<body class="popup-body">
<?php include __DIR__ . '/view/popup/appstore_buy.php'; ?>
</body>
</html>
