<?php
/**
Plugin Name: EMSHOP共享店铺
Version: 0.1.0
Plugin URL:
Description: 与同系统（EMSHOP）其他站点对接：管理对接站点凭证，后续可同步商品、代下单等。
Author: EMSHOP
Author URL:
Category: 对接插件
*/

defined('EM_ROOT') || exit('Access Denied');

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'EmshopPlugin\\') !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', $class);
    $file = __DIR__ . '/lib/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * 保证插件数据表存在（幂等）。后台设置弹窗在未走前台 init 时也可调用。
 */
function emshop_plugin_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $cb = __DIR__ . '/emshop_callback.php';
    if (is_file($cb)) {
        require_once $cb;
    }
    if (function_exists('callback_init')) {
        callback_init();
    }
}

// ============================================================
// 商品类型：对接导入商品（不与 virtual_card / physical 共用发货链）
// Swoole processQueue 调 goods_type_{type}_order_paid，必须用独立 type 避免误触发卡密/实物逻辑
// ============================================================

addAction('goods_type_register', function (&$types): void {
    $types['emshop_remote'] = [
        'name'            => 'EMSHOP 对接商品',
        'description'     => '从其它 EMSHOP 站点导入的商品；发货由本店人工处理或后续扩展上游对接，不走卡密自动发货。',
        'icon'            => '',
        'default'         => false,
        'delivery_type'   => 'manual',
        'needs_address'   => true,
    ];
});

addFilter('goods_type_label', function ($label, $og) {
    if (($og['goods_type'] ?? '') === 'emshop_remote') {
        return '对接商品';
    }
    return $label;
});

// 发货类型展示：按导入映射里的 fulfillment_mode 动态显示（auto/manual）
addFilter('goods_delivery_type', function ($deliveryType, $goods) {
    if (($goods['goods_type'] ?? '') !== 'emshop_remote') {
        return $deliveryType;
    }
    $cfg = $goods['configs'] ?? null;
    if (is_string($cfg)) {
        $decoded = json_decode($cfg, true);
        if (is_array($decoded)) {
            $cfg = $decoded;
        }
    }
    if (!is_array($cfg)) {
        return $deliveryType !== '' ? $deliveryType : 'manual';
    }
    $imp = $cfg['emshop_import'] ?? null;
    if (!is_array($imp)) {
        return $deliveryType !== '' ? $deliveryType : 'manual';
    }
    $mode = strtolower(trim((string) ($imp['fulfillment_mode'] ?? '')));
    if ($mode === 'upstream_auto') {
        return 'auto';
    }
    if ($mode === 'manual') {
        return 'manual';
    }
    return $deliveryType !== '' ? $deliveryType : 'manual';
});

// 是否需要收货地址：emshop_remote 且 upstream_auto 时不需要地址，manual 仍需要。
addFilter('goods_needs_address', function ($needsAddress, $goods) {
    if (($goods['goods_type'] ?? '') !== 'emshop_remote') {
        return $needsAddress;
    }
    $cfg = $goods['configs'] ?? null;
    if (is_string($cfg)) {
        $decoded = json_decode($cfg, true);
        if (is_array($decoded)) {
            $cfg = $decoded;
        }
    }
    if (!is_array($cfg)) {
        return $needsAddress;
    }
    $imp = $cfg['emshop_import'] ?? null;
    if (!is_array($imp)) {
        $dt = applyFilter('goods_delivery_type', 'manual', $goods);
        if ($dt === 'auto') return false;
        if ($dt === 'manual') return true;
        return $needsAddress;
    }
    $mode = strtolower(trim((string) ($imp['fulfillment_mode'] ?? '')));
    if ($mode === 'upstream_auto') {
        return false;
    }
    if ($mode === 'manual') {
        return true;
    }
    $dt = applyFilter('goods_delivery_type', 'manual', $goods);
    if ($dt === 'auto') return false;
    if ($dt === 'manual') return true;
    return $needsAddress;
});

addAction('goods_type_emshop_remote_save', function ($goodsId, $postData): void {
    $goodsId = (int) $goodsId;
    if ($goodsId <= 0) {
        return;
    }
    $pd = $postData['plugin_data'] ?? [];
    if (!is_array($pd)) {
        $pd = [];
    }
    Database::update('goods', [
        'plugin_data' => json_encode($pd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], $goodsId);
});

addAction('goods_type_emshop_remote_order_paid', function ($orderId, $orderGoodsId, $pluginData): void {
    \EmshopPlugin\DeliveryService::handle((int) $orderId, (int) $orderGoodsId, (string) $pluginData);
});

addFilter('goods_type_emshop_remote_manual_delivery_form', function ($html, $og) {
    ob_start();
    $ogId = (int) ($og['id'] ?? 0);
    ?>
    <div class="ship-field-row" style="margin-bottom:10px;">
        <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;">发货说明（展示给买家）</label>
        <textarea name="delivery_note" maxlength="4000" rows="6" class="ship-input" required
                  placeholder="可填写卡密、网盘、说明文字等"
                  style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;line-height:1.6;"></textarea>
    </div>
    <?php
    return (string) ob_get_clean();
});

addFilter('goods_type_emshop_remote_manual_delivery_submit', function ($_, $args) {
    $post = $args['post'] ?? [];
    $note = trim((string) ($post['delivery_note'] ?? ''));
    if ($note === '') {
        return '请填写发货说明';
    }
    if (mb_strlen($note) > 4000) {
        return '发货说明过长';
    }
    return [
        'delivery_content' => $note,
        'plugin_data'      => ['delivery_note' => $note],
    ];
});

// 下单前校验（OrderModel::create 会统一触发）：
// emshop_remote 不论自动/人工，统一先调上游 simulate_order，失败就拦截本地下单。
addFilter('goods_type_emshop_remote_order_submit', function ($currentError, $ctx) {
    if (is_string($currentError) && $currentError !== '') {
        return $currentError;
    }
    $goods = $ctx['goods'] ?? [];
    $spec = $ctx['spec'] ?? [];
    $qty = max(1, (int) ($ctx['quantity'] ?? 1));
    if (!is_array($goods) || !is_array($spec)) {
        return $currentError;
    }
    if (($goods['goods_type'] ?? '') !== 'emshop_remote') {
        return $currentError;
    }

    $goodsCfg = [];
    if (!empty($goods['configs'])) {
        $decoded = is_array($goods['configs']) ? $goods['configs'] : json_decode((string) $goods['configs'], true);
        if (is_array($decoded)) {
            $goodsCfg = $decoded;
        }
    }
    $imp = $goodsCfg['emshop_import'] ?? [];
    if (!is_array($imp)) {
        return $currentError;
    }

    $siteId = (int) ($imp['remote_site_id'] ?? 0);
    $remoteGoodsId = (int) ($imp['remote_goods_id'] ?? 0);
    if ($siteId <= 0 || $remoteGoodsId <= 0) {
        return '上游映射缺失：请重新导入该商品';
    }
    $site = \EmshopPlugin\RemoteSiteModel::find($siteId);
    if ($site === null || (int) ($site['enabled'] ?? 0) !== 1) {
        return '上游站点不可用：请检查对接站点状态';
    }

    $remoteSpecId = 0;
    $specCfg = [];
    if (!empty($spec['configs'])) {
        $decodedSpec = is_array($spec['configs']) ? $spec['configs'] : json_decode((string) $spec['configs'], true);
        if (is_array($decodedSpec)) {
            $specCfg = $decodedSpec;
        }
    }
    if (!empty($specCfg['emshop_import']) && is_array($specCfg['emshop_import'])) {
        $remoteSpecId = (int) ($specCfg['emshop_import']['upstream_spec_id'] ?? 0);
    }

    $payload = [
        'goods_id'        => $remoteGoodsId,
        'quantity'        => $qty,
        // 上游启用了游客查单必填时，至少给非空值避免被此类配置拦住库存/余额校验
        'contact'         => 'emshop-precheck',
        'order_password'  => 'emshop-precheck',
    ];
    if ($remoteSpecId > 0) {
        $payload['spec_id'] = $remoteSpecId;
    }

    $cacheKey = 'emshop_sim_' . md5(json_encode([
        'site' => $siteId,
        'g'    => $remoteGoodsId,
        's'    => $remoteSpecId,
        'q'    => $qty,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $resp = class_exists('Cache') ? Cache::get($cacheKey) : null;
    if (!is_array($resp)) {
        try {
            $resp = \EmshopPlugin\RemoteApiClient::apiPost(
                (string) ($site['base_url'] ?? ''),
                'simulate_order',
                $payload,
                (string) ($site['appid'] ?? ''),
                (string) ($site['secret'] ?? '')
            );
            if (!is_array($resp)) {
                $resp = ['can_order' => false, 'reason' => '上游模拟下单返回异常'];
            }
        } catch (\Throwable $e) {
            $resp = [
                'can_order' => false,
                'reason'    => '上游模拟下单校验失败：' . $e->getMessage(),
            ];
        }
        if (class_exists('Cache')) {
            Cache::set($cacheKey, $resp, 20, 'emshop_simulate_order');
        }
    }

    $canOrder = (bool) ($resp['can_order'] ?? false);
    if (!$canOrder) {
        $reason = trim((string) ($resp['reason'] ?? '上游模拟下单未通过'));
        return $reason !== '' ? $reason : '上游模拟下单未通过';
    }

    return $currentError;
});
