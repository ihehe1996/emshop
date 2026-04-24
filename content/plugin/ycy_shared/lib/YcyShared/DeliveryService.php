<?php

declare(strict_types=1);

namespace YcyShared;

/**
 * 发货服务（占位骨架 · 下一阶段填充）。
 *
 * 计划：
 *   - handle($orderGoods) 找到对应 ycy_goods 映射 → 代付上游 → 写 ycy_trade 流水 → 回写卡密到 order_goods.delivery_content
 */
use Database;
use GoodsModel;
use PermanentDeliveryException;
use RuntimeException;

final class DeliveryService
{
    /**
     * 代付上游 → 写流水 → 回写卡密到 order_goods.delivery_content。
     *
     * 抛异常时，Swoole 队列会按 max_attempts / 退避策略重试；
     * 本方法幂等性由 ycy_trade.upstream_trade_no 保证（同一 order_goods_id 重试时复用）。
     */
    public static function handle(int $orderId, int $orderGoodsId, string $payloadJson): void
    {
        $prefix = Database::prefix();

        $og = Database::fetchOne(
            "SELECT `id`, `goods_id`, `spec_id`, `quantity`, `delivery_content`
               FROM `{$prefix}order_goods` WHERE `id` = ? LIMIT 1",
            [$orderGoodsId]
        );
        if (!$og) {
            throw new RuntimeException('[ycy_shared] 订单商品不存在 #' . $orderGoodsId);
        }
        // 幂等：已写过 delivery_content 直接返回
        if (!empty($og['delivery_content'])) {
            return;
        }

        $goodsId = (int) $og['goods_id'];
        $specId  = (int) $og['spec_id'];
        $qty     = (int) $og['quantity'];
        if ($goodsId <= 0 || $specId <= 0 || $qty <= 0) {
            throw new RuntimeException('[ycy_shared] 订单行字段异常');
        }

        // 找上游映射
        $mapping = Database::fetchOne(
            "SELECT * FROM `{$prefix}ycy_goods` WHERE `goods_id` = ? LIMIT 1",
            [$goodsId]
        );
        if (!$mapping) {
            throw new RuntimeException('[ycy_shared] 找不到上游映射 goods_id=' . $goodsId);
        }
        $site = SiteModel::find((int) $mapping['site_id']);
        if ($site === null) {
            throw new RuntimeException('[ycy_shared] 站点已删除 site_id=' . $mapping['site_id']);
        }
        if ((int) $site['enabled'] !== 1) {
            throw new RuntimeException('[ycy_shared] 站点已停用：' . ($site['name'] ?? ''));
        }

        $skuMap = json_decode((string) ($mapping['sku_map'] ?? '[]'), true) ?: [];
        $hitSku = null;
        foreach ($skuMap as $s) {
            if ((int) ($s['local_spec_id'] ?? 0) === $specId) { $hitSku = $s; break; }
        }
        if ($hitSku === null) {
            throw new RuntimeException('[ycy_shared] SKU 映射缺失，spec_id=' . $specId);
        }

        // 预取现有流水（幂等复用同一条 pending 记录）
        $trade = Database::fetchOne(
            "SELECT * FROM `{$prefix}ycy_trade` WHERE `order_goods_id` = ? ORDER BY `id` DESC LIMIT 1",
            [$orderGoodsId]
        );

        $client = Client::make($site);
        $upstreamRef = (string) $mapping['upstream_ref'];

        // 重复下单防护：如果之前已经有 upstream_trade_no（重试场景），先查上游是否已成单
        // V3 有独立查询接口；V4 客户端 trade_no 天然幂等，queryOrder 返回 found=false 走重下
        if ($trade && !empty($trade['upstream_trade_no'])) {
            try {
                $q = $client->queryOrder((string) $trade['upstream_trade_no']);
                if (!empty($q['found']) && !empty($q['contents'])) {
                    // 上次其实已经成功，直接复用不再下单
                    self::upsertTrade(
                        $orderGoodsId, $site['id'], $upstreamRef, $qty, $hitSku,
                        (string) $trade['upstream_trade_no'], (string) $q['contents'], 'success', '', $q
                    );
                    Database::execute(
                        "UPDATE `{$prefix}order_goods` SET `delivery_content` = ?, `delivery_at` = NOW() WHERE `id` = ?",
                        [(string) $q['contents'], $orderGoodsId]
                    );
                    Database::execute(
                        "UPDATE `{$prefix}goods_spec` SET `stock` = GREATEST(`stock` - ?, 0) WHERE `id` = ?",
                        [$qty, $specId]
                    );
                    GoodsModel::incrementSoldCount($specId, $qty);
                    GoodsModel::updatePriceStockCache($goodsId);
                    return;
                }
            } catch (\Throwable $e) {
                // 查询失败不阻塞，继续走重下流程
                error_log('[ycy_shared] queryOrder fallback: ' . $e->getMessage());
            }
        }

        // 代付上游
        try {
            $extra = [
                'sku_id'   => (int) ($hitSku['upstream_sku_id'] ?? 0),
                'trade_no' => $trade ? (string) $trade['upstream_trade_no'] : '',
                'contact'  => 'emshop-order-' . $orderId,
                'pay_id'   => 1, // v3 默认余额支付
            ];
            $resp = $client->placeOrder($upstreamRef, $qty, $extra);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $isPermanent = self::isPermanentError($msg);
            self::upsertTrade(
                $orderGoodsId, $site['id'], $upstreamRef, $qty, $hitSku,
                '', '', 'failed', ($isPermanent ? '[永久] ' : '') . $msg, null
            );
            // 业务错误（余额不足/商品下架等）抛 Permanent，队列不再重试
            if ($isPermanent) {
                throw new PermanentDeliveryException('[ycy_shared] 代付永久失败：' . $msg);
            }
            throw $e;
        }

        $contents = (string) ($resp['contents'] ?? '');
        $tradeNo  = (string) ($resp['trade_no'] ?? '');
        if ($contents === '') {
            self::upsertTrade($orderGoodsId, $site['id'], $upstreamRef, $qty, $hitSku, $tradeNo, '', 'failed', '上游未返回发货内容', $resp);
            throw new RuntimeException('[ycy_shared] 上游未返回发货内容');
        }

        // 成功：写流水 + 回写 delivery_content + 扣本地库存
        self::upsertTrade($orderGoodsId, $site['id'], $upstreamRef, $qty, $hitSku, $tradeNo, $contents, 'success', '', $resp);

        Database::execute(
            "UPDATE `{$prefix}order_goods` SET `delivery_content` = ?, `delivery_at` = NOW() WHERE `id` = ?",
            [$contents, $orderGoodsId]
        );
        // 扣本地规格库存 + 累加销量
        Database::execute(
            "UPDATE `{$prefix}goods_spec` SET `stock` = GREATEST(`stock` - ?, 0) WHERE `id` = ?",
            [$qty, $specId]
        );
        GoodsModel::incrementSoldCount($specId, $qty);
        GoodsModel::updatePriceStockCache($goodsId);
    }

    /**
     * 识别上游返回的错误文案是否属于"业务终止性错误"。
     * 命中后发货队列不再重试，节省无谓的网络/失败告警。
     *
     * 按异次元 V3 / MCY V4 常见文案总结；匹配模糊包含关键词。
     */
    private static function isPermanentError(string $msg): bool
    {
        $keywords = [
            '余额不足', '账户余额', '下架', '不存在', '已停售', '售罄', '库存不足',
            '签名错误', '签名失败', '签名非法', '验签', '权限不足', '封禁', '禁用',
            'SKU 不存在', 'sku不存在', '规格不存在', '金额不匹配', '商品已售罄',
        ];
        foreach ($keywords as $kw) {
            if (mb_stripos($msg, $kw) !== false) return true;
        }
        return false;
    }

    /**
     * 插入/更新 ycy_trade 流水（按 order_goods_id 反查，保持幂等）。
     *
     * @param array $sku sku_map 里命中的条目
     * @param array|null $raw 上游返回原始结构
     */
    private static function upsertTrade(
        int $orderGoodsId, int $siteId, string $upstreamRef, int $quantity,
        array $sku, string $upstreamTradeNo, string $response, string $status, string $err, $raw
    ): void {
        $prefix = Database::prefix();

        // 上游实付金额：用 sku_map 里的 upstream_price × 数量，保存为 ×1000000 的整数
        $costRaw = (int) round(((float) ($sku['upstream_price'] ?? 0)) * $quantity * 1000000);

        $existing = Database::fetchOne(
            "SELECT `id` FROM `{$prefix}ycy_trade` WHERE `order_goods_id` = ? ORDER BY `id` DESC LIMIT 1",
            [$orderGoodsId]
        );
        $responseText = $raw !== null ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $response;

        if ($existing) {
            Database::update('ycy_trade', [
                'upstream_trade_no' => $upstreamTradeNo,
                'status'            => $status,
                'response'          => $responseText,
                'error_message'     => $err,
                'cost_amount_raw'   => $costRaw,
            ], (int) $existing['id']);
        } else {
            Database::insert('ycy_trade', [
                'order_goods_id'    => $orderGoodsId,
                'site_id'           => $siteId,
                'upstream_ref'      => $upstreamRef,
                'upstream_trade_no' => $upstreamTradeNo,
                'quantity'          => $quantity,
                'cost_amount_raw'   => $costRaw,
                'status'            => $status,
                'response'          => $responseText,
                'error_message'     => $err,
            ]);
        }
    }
}
