<?php
/**
Plugin Name: EMSHOP共享店铺
Version: 1.0.0
Plugin URL:
Description: 与同系统（EMSHOP）其他站点对接：管理对接站点凭证，可同步商品、代下单等。
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

// ============================================================
// 定时同步：每 1 分钟拉一次库存+价格
//   依赖核心 swoole_timer_tick（60s 一次）钩子。插件内部做节流(暂时不做节流)
// ============================================================
addAction('swoole_timer_tick', function (): void {
    try {
        $summary = emshop_sync_remote_price_stock();
        emshop_write_system_log(
            'info',
            'EMSHOP 定时同步完成',
            '已执行对接商品库存/价格同步',
            $summary
        );
    } catch (Throwable $e) {
        emshop_write_system_log(
            'error',
            'EMSHOP 定时同步失败',
            $e->getMessage(),
            ['trace' => mb_substr($e->getTraceAsString(), 0, 2000, 'UTF-8')]
        );
    }
});

/**
 * 同步对接商品库存/价格并自动上下架。
 *
 * 规则：
 * 1) 规格库存：用上游对应规格库存覆盖本地库存
 * 2) 成本价：用上游规格售价覆盖本地成本价
 * 3) 销售价：按上游规格售价 * 1.10（四舍五入保留两位）覆盖本地售价
 * 4) 上下架：总库存<=0 自动下架；库存恢复自动上架
 *
 * @return array<string, mixed>
 */
function emshop_sync_remote_price_stock(): array
{
    $prefix = Database::prefix();
    $goodsRows = Database::query(
        "SELECT `id`, `title`, `source_id`, `configs`, `is_on_sale`
         FROM `{$prefix}goods`
         WHERE `source_type` = ? AND `deleted_at` IS NULL",
        ['emshop_remote']
    );
    if ($goodsRows === []) {
        return ['total_goods' => 0, 'changed_specs' => 0, 'auto_up' => 0, 'auto_down' => 0];
    }

    $bySite = [];
    $goodsMeta = [];
    foreach ($goodsRows as $g) {
        $goodsId = (int) ($g['id'] ?? 0);
        if ($goodsId <= 0) {
            continue;
        }
        $sourceId = trim((string) ($g['source_id'] ?? ''));
        [$siteIdBySource, $remoteGoodsBySource] = emshop_parse_source_id($sourceId);

        $cfg = json_decode((string) ($g['configs'] ?? '{}'), true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $imp = $cfg['emshop_import'] ?? [];
        if (!is_array($imp)) {
            $imp = [];
        }
        $siteId = (int) ($imp['remote_site_id'] ?? $siteIdBySource);
        $remoteGoodsId = (int) ($imp['remote_goods_id'] ?? $remoteGoodsBySource);
        if ($siteId <= 0 || $remoteGoodsId <= 0) {
            continue;
        }

        $goodsMeta[$goodsId] = [
            'id'            => $goodsId,
            'title'         => (string) ($g['title'] ?? ''),
            'is_on_sale'    => (int) ($g['is_on_sale'] ?? 0),
            'site_id'       => $siteId,
            'remote_goods_id' => $remoteGoodsId,
        ];
        $bySite[$siteId][$remoteGoodsId] = $goodsId;
    }

    if ($bySite === []) {
        return ['total_goods' => 0, 'changed_specs' => 0, 'auto_up' => 0, 'auto_down' => 0];
    }

    $changedSpecs = 0;
    $autoUp = 0;
    $autoDown = 0;
    $warn = [];

    foreach ($bySite as $siteId => $map) {
        $site = \EmshopPlugin\RemoteSiteModel::find((int) $siteId);
        if ($site === null || (int) ($site['enabled'] ?? 0) !== 1) {
            $warn[] = "site#{$siteId} 不存在或已停用";
            continue;
        }
        $baseUrl = (string) ($site['base_url'] ?? '');
        $appid = (string) ($site['appid'] ?? '');
        $secret = (string) ($site['secret'] ?? '');
        if ($baseUrl === '' || $appid === '' || $secret === '') {
            $warn[] = "site#{$siteId} 凭证不完整";
            continue;
        }

        $remoteGoodsIds = array_keys($map);
        foreach (array_chunk($remoteGoodsIds, 40) as $chunk) {
            try {
                $list = \EmshopPlugin\RemoteApiClient::fetchGoodsList($baseUrl, $appid, $secret, [
                    'goods_ids' => implode(',', $chunk),
                ]);
            } catch (Throwable $e) {
                $warn[] = "site#{$siteId} 拉取失败：" . $e->getMessage();
                continue;
            }

            $remoteById = [];
            foreach ($list as $row) {
                $rid = (int) ($row['goods_id'] ?? 0);
                if ($rid > 0) {
                    $remoteById[$rid] = $row;
                }
            }

            foreach ($chunk as $remoteGoodsId) {
                $localGoodsId = (int) ($map[(int) $remoteGoodsId] ?? 0);
                if ($localGoodsId <= 0) {
                    continue;
                }
                $remote = $remoteById[(int) $remoteGoodsId] ?? null;
                if (!is_array($remote)) {
                    $warn[] = "goods#{$localGoodsId}/up#{$remoteGoodsId} 上游未返回";
                    continue;
                }

                $specRows = Database::query(
                    "SELECT `id`, `price`, `cost_price`, `stock`, `configs`
                     FROM `{$prefix}goods_spec`
                     WHERE `goods_id` = ? AND `status` = 1
                     ORDER BY `sort` ASC, `id` ASC",
                    [$localGoodsId]
                );
                if ($specRows === []) {
                    continue;
                }

                $remoteSpecsRaw = $remote['specs'] ?? [];
                $remoteSpecs = is_array($remoteSpecsRaw) ? $remoteSpecsRaw : [];
                $remoteSpecById = [];
                foreach ($remoteSpecs as $rs) {
                    if (!is_array($rs)) {
                        continue;
                    }
                    $upSpecId = (int) ($rs['upstream_spec_id'] ?? 0);
                    if ($upSpecId > 0) {
                        $remoteSpecById[$upSpecId] = $rs;
                    }
                }

                $sumStock = 0;
                $hasMappedSpec = false;
                foreach ($specRows as $idx => $sp) {
                    $specId = (int) ($sp['id'] ?? 0);
                    if ($specId <= 0) {
                        continue;
                    }
                    $cfg = json_decode((string) ($sp['configs'] ?? '{}'), true);
                    if (!is_array($cfg)) {
                        $cfg = [];
                    }
                    $imp = $cfg['emshop_import'] ?? [];
                    $upSpecId = is_array($imp) ? (int) ($imp['upstream_spec_id'] ?? 0) : 0;

                    $upSpec = null;
                    if ($upSpecId > 0 && isset($remoteSpecById[$upSpecId]) && is_array($remoteSpecById[$upSpecId])) {
                        $upSpec = $remoteSpecById[$upSpecId];
                    } elseif (count($specRows) === 1 && count($remoteSpecs) === 1 && is_array($remoteSpecs[0])) {
                        // 单规格兜底：上游/本地都只有一个规格时直接映射
                        $upSpec = $remoteSpecs[0];
                    }
                    if (!is_array($upSpec)) {
                        $sumStock += max(0, (int) ($sp['stock'] ?? 0));
                        continue;
                    }
                    $hasMappedSpec = true;

                    $upPrice = max(0.0, (float) ($upSpec['price'] ?? 0));
                    $newCost = GoodsModel::moneyToDb(number_format($upPrice, 2, '.', ''));
                    $newSale = GoodsModel::moneyToDb(number_format($upPrice * 1.10, 2, '.', ''));
                    $newStock = max(0, (int) ($upSpec['stock'] ?? 0));
                    $sumStock += $newStock;

                    $oldPrice = (int) ($sp['price'] ?? 0);
                    $oldCost = (int) ($sp['cost_price'] ?? 0);
                    $oldStock = (int) ($sp['stock'] ?? 0);
                    if ($oldPrice !== $newSale || $oldCost !== $newCost || $oldStock !== $newStock) {
                        Database::update('goods_spec', [
                            'price' => $newSale,
                            'cost_price' => $newCost,
                            'stock' => $newStock,
                        ], $specId);
                        $changedSpecs++;
                    }
                }

                if (!$hasMappedSpec) {
                    $sumStock = max(0, (int) ($remote['stock'] ?? 0));
                }

                $meta = $goodsMeta[$localGoodsId] ?? null;
                $oldOnSale = (int) ($meta['is_on_sale'] ?? 0);
                $newOnSale = $sumStock > 0 ? 1 : 0;
                if ($oldOnSale !== $newOnSale) {
                    Database::update('goods', ['is_on_sale' => $newOnSale], $localGoodsId);
                    if ($newOnSale === 1) {
                        $autoUp++;
                    } else {
                        $autoDown++;
                    }
                }
                GoodsModel::updatePriceStockCache($localGoodsId);
            }
        }
    }

    return [
        'total_goods' => count($goodsMeta),
        'changed_specs' => $changedSpecs,
        'auto_up' => $autoUp,
        'auto_down' => $autoDown,
        'warnings' => $warn,
    ];
}

/**
 * @return array{0:int,1:int}
 */
function emshop_parse_source_id(string $sourceId): array
{
    $parts = explode(':', $sourceId, 2);
    return [
        (int) ($parts[0] ?? 0),
        (int) ($parts[1] ?? 0),
    ];
}

/**
 * @param array<string,mixed> $detail
 */
function emshop_write_system_log(string $level, string $action, string $message, array $detail = []): void
{
    try {
        require_once EM_ROOT . '/include/model/SystemLogModel.php';
        if (!class_exists('SystemLogModel')) {
            return;
        }
        $m = new SystemLogModel();
        if ($level === 'error') {
            $m->error('system', $action, $message, $detail);
        } elseif ($level === 'warning' || $level === 'warn') {
            $m->warning('system', $action, $message, $detail);
        } else {
            $m->info('system', $action, $message, $detail);
        }
    } catch (Throwable $e) {
        // 日志写入失败不影响主流程
    }
}

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
