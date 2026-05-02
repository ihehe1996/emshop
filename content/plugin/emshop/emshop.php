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
    $prefix = Database::prefix();
    $og = Database::fetchOne(
        "SELECT spec_id, quantity FROM `{$prefix}order_goods` WHERE id = ? LIMIT 1",
        [(int) $orderGoodsId]
    );
    if ($og) {
        $specId = (int) ($og['spec_id'] ?? 0);
        $qty = (int) ($og['quantity'] ?? 0);
        if ($specId > 0 && $qty > 0) {
            GoodsModel::incrementSoldCount($specId, $qty);
        }
    }
    if (function_exists('log_message')) {
        log_message('info', "[emshop_remote] order_paid order={$orderId} order_goods={$orderGoodsId} (manual / upstream fulfillment)");
    }
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
