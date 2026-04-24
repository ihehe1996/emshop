<?php
/**
Plugin Name: 易支付
Version: 1.0.0
Plugin URL:
Description: 易支付聚合支付插件，支持支付宝、微信、QQ钱包三种支付渠道，可独立启停。
Author: EMSHOP
Author URL:
Category: 支付插件
*/

defined('EM_ROOT') || exit('Access Denied');

// 注册支付方式（根据配置启用的渠道）
addFilter('payment_methods_register', function (array $methods): array {
    $storage = Storage::getInstance('epay');

    // 三种渠道定义
    $channels = [
        'epay_alipay' => ['default_name' => '支付宝', 'channel' => 'alipay', 'image' => '/content/plugin/epay/alipay.png'],
        'epay_wxpay'  => ['default_name' => '微信支付', 'channel' => 'wxpay',  'image' => '/content/plugin/epay/wxpay.png'],
        'epay_qqpay'  => ['default_name' => 'QQ钱包',  'channel' => 'qqpay',  'image' => '/content/plugin/epay/qqpay.png'],
    ];

    foreach ($channels as $code => $ch) {
        // 检查该渠道是否启用（默认全部启用）
        // 注意：Storage::getValue 会对 "0" 做 JSON 解码 → int 0，这里用宽松判断
        $enabled = $storage->getValue($code . '_enabled');
        if ((string) $enabled === '0') {
            continue;
        }

        // 读取外显名称
        $displayName = $storage->getValue($code . '_name') ?: $ch['default_name'];

        $methods[] = [
            'code'         => $code,
            'name'         => $ch['default_name'],
            'display_name' => $displayName,
            'image'        => $ch['image'],
            'channel'      => $ch['channel'],
            'plugin'       => 'epay',
            'plugin_name'  => '易支付',
        ];
    }

    return $methods;
});

// ============================================================
// 签名工具：按易支付规范对参数做 MD5 签名
// ============================================================

/**
 * 生成易支付签名。
 *   1. 剔除 sign / sign_type 以及空值字段
 *   2. 按 key 升序排序
 *   3. 拼成 a=v&b=v 字符串（value 不做 urlencode，易支付协议规定如此）
 *   4. 末尾直接拼商户密钥后 md5
 */
function epay_sign(array $params, string $key): string
{
    unset($params['sign'], $params['sign_type']);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    return md5(implode('&', $parts) . $key);
}

/**
 * 读取支付方式对应的 epay 配置集。
 *
 * 返回：['pid', 'key', 'submit_url', 'mapi_url', 'type', 'enabled'] 或 null（未启用/未配）
 */
function epay_get_channel_config(string $code): ?array
{
    $channels = [
        'epay_alipay' => 'alipay',
        'epay_wxpay'  => 'wxpay',
        'epay_qqpay'  => 'qqpay',
    ];
    if (!isset($channels[$code])) return null;

    $storage = Storage::getInstance('epay');
    if ((string) $storage->getValue($code . '_enabled') === '0') return null;

    $pid        = (string) ($storage->getValue($code . '_merchant_id') ?: '');
    $key        = (string) ($storage->getValue($code . '_secret_key') ?: '');
    $submitUrl  = (string) ($storage->getValue($code . '_submit_url') ?: '');
    $mapiUrl    = (string) ($storage->getValue($code . '_mapi_url') ?: '');
    if ($pid === '' || $key === '' || $submitUrl === '') return null;

    return [
        'pid'        => $pid,
        'key'        => $key,
        'submit_url' => $submitUrl,
        'mapi_url'   => $mapiUrl,
        'type'       => $channels[$code],
    ];
}

/**
 * 取站点外网根地址（notify_url / return_url 要用完整 URL）。
 */
function epay_site_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    return $scheme . '://' . $host;
}

// ============================================================
// 支付创建：订单创建完成后，核心代码触发 payment_create 过滤器
// ============================================================

addFilter('payment_create', function (array $ctx): array {
    if (($ctx['payment']['plugin'] ?? '') !== 'epay') return $ctx;

    $conf = epay_get_channel_config((string) ($ctx['payment']['code'] ?? ''));
    if ($conf === null) return $ctx;

    $order = $ctx['order'];
    // 金额入参单位：数据库存 ×1000000 整数，易支付要求元且保留 2 位小数
    $money = number_format(((int) $order['pay_amount']) / 1000000, 2, '.', '');
    $site = epay_site_url();

    $params = [
        'pid'         => $conf['pid'],
        'type'        => $conf['type'],
        'out_trade_no'=> (string) $order['order_no'],
        'notify_url'  => $site . '/submit.php?plugin=epay',
        'return_url'  => $site . '/return.php?plugin=epay',
        'name'        => '订单 ' . $order['order_no'],
        'money'       => $money,
        'sitename'    => Config::get('sitename', 'EMSHOP'),
    ];
    $params['sign']      = epay_sign($params, $conf['key']);
    $params['sign_type'] = 'MD5';

    // 直接拼 GET URL 跳转（用户点"去支付" → 前端 location.href）
    // 配置中的 submit_url 已是完整地址，不再做任何拼接
    $ctx['pay_url'] = $conf['submit_url'] . (strpos($conf['submit_url'], '?') === false ? '?' : '&') . http_build_query($params);
    return $ctx;
});

// ============================================================
// 异步通知：/submit.php?plugin=epay
// ============================================================

addAction('payment_notify_epay', function (array $data): void {
    // 必要字段检查
    if (empty($data['out_trade_no']) || empty($data['sign']) || empty($data['pid']) || empty($data['trade_status'])) {
        echo 'fail: missing fields'; exit;
    }

    // 以 R 开头的单号走钱包充值分支；其余走商品订单分支
    $outTradeNo = (string) $data['out_trade_no'];
    $isRecharge = strncmp($outTradeNo, 'R', 1) === 0;

    if ($isRecharge) {
        $recharge = (new UserRechargeModel())->findByOrderNo($outTradeNo);
        if (!$recharge) { echo 'fail: recharge not found'; exit; }
        $tradePaymentCode = (string) $recharge['payment_code'];
        $tradeAmount      = (int) $recharge['amount'];
        $tradeStatusField = (string) $recharge['status'];
        $tradeAlreadyDone = ($tradeStatusField !== UserRechargeModel::STATUS_PENDING);
    } else {
        $order = OrderModel::getByOrderNo($outTradeNo);
        if (!$order) { echo 'fail: order not found'; exit; }
        $tradePaymentCode = (string) $order['payment_code'];
        $tradeAmount      = (int) $order['pay_amount'];
        $tradeStatusField = (string) $order['status'];
        $tradeAlreadyDone = ($tradeStatusField !== 'pending');
    }

    $conf = epay_get_channel_config($tradePaymentCode);
    if ($conf === null) { echo 'fail: payment channel disabled'; exit; }

    // pid 一致性校验
    if ((string) $data['pid'] !== $conf['pid']) { echo 'fail: pid mismatch'; exit; }

    // 签名校验：易支付服务端只对协议规定的字段签名，我们 notify_url 里塞的 plugin=epay
    // 等自定义 query 会混在 $_GET 里，直接参与签名会导致 MD5 不匹配。
    // 所以这里按白名单取字段。
    $signData = [];
    foreach (['pid', 'trade_no', 'out_trade_no', 'type', 'name', 'money', 'trade_status', 'param'] as $k) {
        if (isset($data[$k])) {
            $signData[$k] = $data[$k];
        }
    }
    $expect = epay_sign($signData, $conf['key']);
    if (!hash_equals($expect, (string) $data['sign'])) { echo 'fail: bad sign'; exit; }

    // 支付状态
    if ((string) $data['trade_status'] !== 'TRADE_SUCCESS') { echo 'fail: trade not success'; exit; }

    // 金额校验（元 vs 数据库 ×1000000）
    $expectMoney = number_format($tradeAmount / 1000000, 2, '.', '');
    if ((string) $data['money'] !== $expectMoney) { echo 'fail: amount mismatch'; exit; }

    // 幂等
    if ($tradeAlreadyDone) { echo 'success'; exit; }

    if ($isRecharge) {
        // 充值单：标记 paid + 给用户 money 加钱
        $ok = (new UserRechargeModel())->markPaid((int) $recharge['id'], (string) ($data['trade_no'] ?? ''));
        if (!$ok) { echo 'fail: recharge mark paid error'; exit; }
        echo 'success'; exit;
    }

    // —— 商品订单：原有逻辑
    try {
        Database::begin();
        OrderModel::changeStatus((int) $order['id'], 'paid');

        $sql = 'INSERT INTO `' . Database::prefix() . 'order_payment`
                (order_id, payment_code, payment_plugin, trade_no, amount, status, paid_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())';
        Database::execute($sql, [
            (int) $order['id'],
            (string) $order['payment_code'],
            'epay',
            (string) ($data['trade_no'] ?? ''),
            (int) $order['pay_amount'],
            'success',
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        echo 'fail: db error'; exit;
    }

    // 触发发货队列（与余额支付一致）
    try { OrderModel::triggerDelivery((int) $order['id']); } catch (Throwable $e) {}

    echo 'success'; exit;
});

// ============================================================
// 同步跳回：/return.php?plugin=epay
//   浏览器带回的 GET 参数一般也带签名，可选校验后直接跳订单详情
// ============================================================

addAction('payment_return_epay', function (array $data): void {
    $orderNo = (string) ($data['out_trade_no'] ?? '');
    // 充值单（R 开头）跳钱包页，商品订单走统一的身份感知跳转
    if (strncmp($orderNo, 'R', 1) === 0) {
        Response::redirect('/user/wallet.php');
    }
    // payment_return_redirect_url() 定义在 /return.php 入口里，因为 payment_return_epay
    // 只会被 /return.php 触发，所以此函数一定已经被定义。
    Response::redirect(payment_return_redirect_url($orderNo));
});
