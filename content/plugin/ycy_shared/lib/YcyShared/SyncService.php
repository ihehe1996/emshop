<?php

declare(strict_types=1);

namespace YcyShared;

use Database;
use GoodsModel;
use OrderModel;
use RuntimeException;
use Storage;
use Throwable;

/**
 * 定时同步服务。
 *
 * 每次 swoole_goods_sync_tick（60s）触发 tick()，按“到期时间”+“批次上限”+“站点限流”执行：
 *   - 库存：默认每 tick 最多 80 条，每站最多 20 条，成功后 30 分钟再同步
 *   - 价格：默认每 tick 最多 20 条，每站最多 8 条，成功后 30 分钟再同步
 *   - 失败走指数退避，防止异常站点把队列打满
 */
final class SyncService
{
    private const STOCK_DELAY_SECONDS = 1800;
    private const PRICE_DELAY_SECONDS = 1800;
    private const STOCK_BACKOFF_BASE = 30;
    private const PRICE_BACKOFF_BASE = 120;
    private const BACKOFF_MAX_SECONDS = 3600;
    private const DEFAULT_STOCK_BATCH = 80;
    private const DEFAULT_PRICE_BATCH = 20;
    private const DEFAULT_STOCK_SITE_LIMIT = 20;
    private const DEFAULT_PRICE_SITE_LIMIT = 8;
    private const DEFAULT_POLL_BATCH = 30;
    private const DEFAULT_POLL_SITE_LIMIT = 10;
    private const ORDER_POLL_INTERVAL_SECONDS = 60;
    private const LOCK_TTL_SECONDS = 120;

    public static function tick(): void
    {
        $stock = self::syncStockAll();
        $price = self::syncPriceAll();
        $processedTotal = (int) ($stock['processed'] ?? 0) + (int) ($price['processed'] ?? 0);
        if ($processedTotal > 0) {
            Logger::info('商品同步完成', '已执行异次元商品库存/价格同步', [
                'stock' => $stock,
                'price' => $price,
                'processed_total' => $processedTotal,
            ]);
        }
    }

    /**
     * 轮询待发货的上游订单（默认每分钟调用一次）。
     */
    public static function pollPendingTrades(): void
    {
        $limit = self::readSettingInt('order_poll_batch', self::DEFAULT_POLL_BATCH, 1, 300);
        $siteLimit = self::readSettingInt('order_poll_site_limit', self::DEFAULT_POLL_SITE_LIMIT, 1, 100);
        $rows = self::fetchDueTrades($limit);
        if ($rows === []) {
            return;
        }
        $siteCounter = [];
        $handled = 0;
        foreach ($rows as $row) {
            if ($handled >= $limit) {
                break;
            }
            $siteId = (int) ($row['site_id'] ?? 0);
            if ($siteId > 0 && (int) ($siteCounter[$siteId] ?? 0) >= $siteLimit) {
                continue;
            }
            $tradeId = (int) ($row['id'] ?? 0);
            if ($tradeId <= 0) {
                continue;
            }
            try {
                self::pollTradeOne($row);
            } catch (Throwable $e) {
                $attempts = (int) ($row['poll_attempts'] ?? 0) + 1;
                self::markTradePending($tradeId, $attempts, $e->getMessage(), self::ORDER_POLL_INTERVAL_SECONDS);
                Logger::warning('订单轮询失败', $e->getMessage(), [
                    'trade_id' => $tradeId,
                    'order_goods_id' => (int) ($row['order_goods_id'] ?? 0),
                    'site_id' => $siteId,
                ]);
            }
            $siteCounter[$siteId] = (int) ($siteCounter[$siteId] ?? 0) + 1;
            $handled++;
        }
    }

    /**
     * 遍历所有映射，拉最新库存写回 em_goods_spec + em_ycy_goods.last_stock。
     */
    public static function syncStockAll(): array
    {
        $limit = self::readSettingInt('sync_stock_batch', self::DEFAULT_STOCK_BATCH, 1, 500);
        $siteLimit = self::readSettingInt('sync_stock_site_limit', self::DEFAULT_STOCK_SITE_LIMIT, 1, 100);
        return self::processStockBatch($limit, $siteLimit);
    }

    /**
     * 遍历所有映射，重新拉一次 item 刷新本地规格价格（按站点加价系数）。
     */
    public static function syncPriceAll(): array
    {
        $limit = self::readSettingInt('sync_price_batch', self::DEFAULT_PRICE_BATCH, 1, 200);
        $siteLimit = self::readSettingInt('sync_price_site_limit', self::DEFAULT_PRICE_SITE_LIMIT, 1, 80);
        return self::processPriceBatch($limit, $siteLimit);
    }

    /**
     * 按映射 id 立即同步该商品的库存 + 价格（外部调用，设置页"立即同步"按钮用）。
     */
    public static function syncByMappingId(int $mappingId): void
    {
        $row = Database::fetchOne(
            'SELECT g.*, s.`enabled`, s.`version` AS `site_version`, s.`host`, s.`app_id`, s.`app_key`,
                    s.`markup_ratio`, s.`min_markup`
               FROM `' . Database::prefix() . 'ycy_goods` g
               JOIN `' . Database::prefix() . 'ycy_site`  s ON s.`id` = g.`site_id`
              WHERE g.`id` = ? LIMIT 1',
            [$mappingId]
        );
        if (!$row) {
            throw new \RuntimeException('映射不存在');
        }
        if ((int) $row['enabled'] !== 1) {
            throw new \RuntimeException('站点已停用');
        }
        $mappingId = (int) ($row['id'] ?? 0);
        if ($mappingId <= 0) {
            throw new \RuntimeException('映射不存在');
        }
        $token = self::makeLockToken();
        if (!self::acquireLock($mappingId, $token)) {
            throw new \RuntimeException('该映射正在同步中，请稍后再试');
        }
        try {
            self::syncStockOne($row);
            self::markStockSuccess($mappingId);
        } catch (Throwable $e) {
            self::markStockFailure($row, $e->getMessage());
            throw $e;
        } finally {
            self::releaseLock($mappingId, $token);
        }

        $row = Database::fetchOne(
            'SELECT g.*, s.`enabled`, s.`version` AS `site_version`, s.`host`, s.`app_id`, s.`app_key`,
                    s.`markup_ratio`, s.`min_markup`
               FROM `' . Database::prefix() . 'ycy_goods` g
               JOIN `' . Database::prefix() . 'ycy_site`  s ON s.`id` = g.`site_id`
              WHERE g.`id` = ? LIMIT 1',
            [$mappingId]
        );
        if (!$row || (int) $row['enabled'] !== 1) {
            return;
        }
        $token = self::makeLockToken();
        if (!self::acquireLock($mappingId, $token)) {
            throw new \RuntimeException('该映射正在同步中，请稍后再试');
        }
        try {
            self::syncPriceOne($row);
            self::markPriceSuccess($mappingId);
        } catch (Throwable $e) {
            self::markPriceFailure($row, $e->getMessage());
            throw $e;
        } finally {
            self::releaseLock($mappingId, $token);
        }
    }

    private static function processStockBatch(int $limit, int $siteLimit): array
    {
        $rows = self::fetchDueRows('next_stock_sync_at', max($limit * 3, $limit), true);
        if ($rows === []) {
            return [
                'due' => 0,
                'processed' => 0,
                'failed' => 0,
                'limit' => $limit,
                'site_limit' => $siteLimit,
            ];
        }
        $processed = 0;
        $failed = 0;
        $siteCounter = [];
        foreach ($rows as $row) {
            if ($processed >= $limit) {
                break;
            }
            $siteId = (int) ($row['site_id'] ?? 0);
            if ($siteId > 0 && (int) ($siteCounter[$siteId] ?? 0) >= $siteLimit) {
                continue;
            }
            $mappingId = (int) ($row['id'] ?? 0);
            if ($mappingId <= 0) {
                continue;
            }
            $token = self::makeLockToken();
            if (!self::acquireLock($mappingId, $token)) {
                continue;
            }
            try {
                self::syncStockOne($row);
                self::markStockSuccess($mappingId);
            } catch (Throwable $e) {
                self::markStockFailure($row, $e->getMessage());
                $failed++;
                Logger::warning('库存同步失败', $e->getMessage(), [
                    'mapping_id' => $mappingId,
                    'site_id' => $siteId,
                    'goods_id' => (int) ($row['goods_id'] ?? 0),
                ]);
            } finally {
                self::releaseLock($mappingId, $token);
            }
            $siteCounter[$siteId] = (int) ($siteCounter[$siteId] ?? 0) + 1;
            $processed++;
        }
        return [
            'due' => count($rows),
            'processed' => $processed,
            'failed' => $failed,
            'limit' => $limit,
            'site_limit' => $siteLimit,
        ];
    }

    private static function processPriceBatch(int $limit, int $siteLimit): array
    {
        $rows = self::fetchDueRows('next_price_sync_at', max($limit * 3, $limit), true);
        if ($rows === []) {
            return [
                'due' => 0,
                'processed' => 0,
                'failed' => 0,
                'limit' => $limit,
                'site_limit' => $siteLimit,
            ];
        }
        $processed = 0;
        $failed = 0;
        $siteCounter = [];
        foreach ($rows as $row) {
            if ($processed >= $limit) {
                break;
            }
            $siteId = (int) ($row['site_id'] ?? 0);
            if ($siteId > 0 && (int) ($siteCounter[$siteId] ?? 0) >= $siteLimit) {
                continue;
            }
            $mappingId = (int) ($row['id'] ?? 0);
            if ($mappingId <= 0) {
                continue;
            }
            $token = self::makeLockToken();
            if (!self::acquireLock($mappingId, $token)) {
                continue;
            }
            try {
                self::syncPriceOne($row);
                self::markPriceSuccess($mappingId);
            } catch (Throwable $e) {
                self::markPriceFailure($row, $e->getMessage());
                $failed++;
                Logger::warning('价格同步失败', $e->getMessage(), [
                    'mapping_id' => $mappingId,
                    'site_id' => $siteId,
                    'goods_id' => (int) ($row['goods_id'] ?? 0),
                ]);
            } finally {
                self::releaseLock($mappingId, $token);
            }
            $siteCounter[$siteId] = (int) ($siteCounter[$siteId] ?? 0) + 1;
            $processed++;
        }
        return [
            'due' => count($rows),
            'processed' => $processed,
            'failed' => $failed,
            'limit' => $limit,
            'site_limit' => $siteLimit,
        ];
    }

    /**
     * 单条：更新库存（按 sku_map 逐 SKU 查上游 → 写回 em_goods_spec.stock）。
     */
    private static function syncStockOne(array $row): void
    {
        $site = self::hydrateSite($row);
        $client = Client::make($site);
        $skuMap = json_decode((string) ($row['sku_map'] ?? '[]'), true) ?: [];
        $totalStock = 0;
        foreach ($skuMap as &$sku) {
            $specId = (int) ($sku['local_spec_id'] ?? 0);
            if ($specId <= 0) continue;
            $stockArg = $sku['upstream_sku_id'] ?? null;
            if ((int) ($stockArg ?? 0) <= 0 && !empty($sku['sku_fields']) && is_array($sku['sku_fields'])) {
                $stockArg = $sku['sku_fields'];
            }
            $stock = $client->fetchStock((string) $row['upstream_ref'], $stockArg);
            $sku['stock'] = $stock;
            $totalStock += max(0, $stock);
            Database::update('goods_spec', ['stock' => max(0, $stock)], $specId);
        }
        unset($sku);

        // 回写映射 + 商品缓存
        Database::update('ycy_goods', [
            'sku_map'              => json_encode($skuMap, JSON_UNESCAPED_UNICODE),
            'last_stock'           => $totalStock,
            'last_stock_synced_at' => date('Y-m-d H:i:s'),
        ], (int) $row['id']);
        GoodsModel::updatePriceStockCache((int) $row['goods_id']);

        // 开关启用时：库存 0 自动下架，库存恢复自动上架
        //   注意这会覆盖用户手动的上下架状态；如需手动控制请关闭此开关
        $autoOff = (string) Storage::getInstance('ycy_shared')->getValue('auto_off_sale_on_empty');
        if ($autoOff === '1') {
            $shouldOnSale = $totalStock > 0 ? 1 : 0;
            Database::update('goods', ['is_on_sale' => $shouldOnSale], (int) $row['goods_id']);
        }
    }

    /**
     * 单条：重新拉 item → 按站点加价系数（商品级覆盖优先）重算 em_goods_spec.price。
     */
    private static function syncPriceOne(array $row): void
    {
        $site = self::hydrateSite($row);
        $client = Client::make($site);
        $item = $client->fetchItem((string) $row['upstream_ref']);
        if (empty($item)) return;
        self::syncGoodsDeliveryWay((int) $row['goods_id'], (string) $row['upstream_ref'], (int) $row['site_id'], $item);

        $ratio = max(
            (float) ($row['markup_ratio'] ?? 0) > 0 ? (float) $row['markup_ratio'] : (float) $site['markup_ratio'],
            (float) $site['min_markup']
        );

        $skuMap = json_decode((string) ($row['sku_map'] ?? '[]'), true) ?: [];

        // 构 upstream sku_id → price 映射用于匹配
        $priceByUpId = [];
        foreach ($item['sku'] ?? [] as $s) {
            $priceByUpId[(int) ($s['sku_id'] ?? 0)] = (float) $s['price'];
        }
        $basePrice = (float) ($item['price'] ?? 0);

        foreach ($skuMap as &$sku) {
            $specId = (int) ($sku['local_spec_id'] ?? 0);
            if ($specId <= 0) continue;
            $upId = (int) ($sku['upstream_sku_id'] ?? 0);
            $upPrice = $upId > 0 && isset($priceByUpId[$upId]) ? $priceByUpId[$upId] : $basePrice;
            if ($upPrice <= 0) continue;

            $localPriceRaw = (int) round($upPrice * $ratio * 1000000);
            $sku['upstream_price']  = $upPrice;
            $sku['local_price_raw'] = $localPriceRaw;
            Database::update('goods_spec', ['price' => $localPriceRaw], $specId);
        }
        unset($sku);

        Database::update('ycy_goods', [
            'sku_map'        => json_encode($skuMap, JSON_UNESCAPED_UNICODE),
            'last_price_raw' => (int) round($basePrice * 1000000),
        ], (int) $row['id']);
        GoodsModel::updatePriceStockCache((int) $row['goods_id']);
    }

    /**
     * 从 JOIN 查询结果里重构出 site 结构（Client::make 要用）。
     */
    private static function hydrateSite(array $row): array
    {
        return [
            'id'          => (int) $row['site_id'],
            'version'     => (string) ($row['site_version'] ?? 'v3'),
            'host'        => (string) $row['host'],
            'app_id'      => (string) $row['app_id'],
            'app_key'     => (string) $row['app_key'],
            'markup_ratio'=> (float) ($row['markup_ratio'] ?? 1.2),
            'min_markup'  => (float) ($row['min_markup']   ?? 1.05),
        ];
    }

    /**
     * 把上游 delivery_way 回写到 goods.configs，供后台/前台列表展示发货类型。
     *
     * @param array<string,mixed> $item
     */
    private static function syncGoodsDeliveryWay(int $goodsId, string $upstreamRef, int $siteId, array $item): void
    {
        if ($goodsId <= 0 || !array_key_exists('delivery_way', $item)) {
            return;
        }
        $way = (int) ($item['delivery_way'] ?? 0);
        if ($way !== 1) {
            $way = 0;
        }
        $prefix = Database::prefix();
        $row = Database::fetchOne(
            "SELECT `configs` FROM `{$prefix}goods` WHERE `id` = ? LIMIT 1",
            [$goodsId]
        );
        if (!$row) {
            return;
        }
        $cfg = json_decode((string) ($row['configs'] ?? '{}'), true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $oldWay = null;
        if (!empty($cfg['ycy_shared']) && is_array($cfg['ycy_shared']) && array_key_exists('delivery_way', $cfg['ycy_shared'])) {
            $oldWay = (int) ($cfg['ycy_shared']['delivery_way'] ?? 0);
        }
        if ($oldWay === $way
            && (int) ($cfg['ycy_shared']['site_id'] ?? 0) === $siteId
            && (string) ($cfg['ycy_shared']['upstream_ref'] ?? '') === $upstreamRef
        ) {
            return;
        }
        $cfg['ycy_shared'] = [
            'site_id'      => $siteId,
            'upstream_ref' => $upstreamRef,
            'delivery_way' => $way,
        ];
        Database::update('goods', [
            'configs' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], $goodsId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fetchDueRows(string $dueColumn, int $limit, bool $withSiteFields): array
    {
        $prefix = Database::prefix();
        $siteFields = $withSiteFields
            ? ', s.`version` AS `site_version`, s.`host`, s.`app_id`, s.`app_key`, s.`markup_ratio`, s.`min_markup`'
            : '';
        return Database::query(
            "SELECT g.* {$siteFields}
               FROM `{$prefix}ycy_goods` g
               JOIN `{$prefix}ycy_site` s ON s.`id` = g.`site_id`
              WHERE s.`enabled` = 1
                AND (g.`{$dueColumn}` IS NULL OR g.`{$dueColumn}` <= NOW())
                AND (g.`sync_lock_until` IS NULL OR g.`sync_lock_until` < NOW())
              ORDER BY COALESCE(g.`{$dueColumn}`, '1970-01-01 00:00:00') ASC, g.`id` ASC
              LIMIT ?",
            [$limit]
        );
    }

    private static function acquireLock(int $mappingId, string $token): bool
    {
        $prefix = Database::prefix();
        $affected = Database::execute(
            "UPDATE `{$prefix}ycy_goods`
                SET `sync_lock_token` = ?, `sync_lock_until` = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE `id` = ? AND (`sync_lock_until` IS NULL OR `sync_lock_until` < NOW())",
            [$token, self::LOCK_TTL_SECONDS, $mappingId]
        );
        return (int) $affected > 0;
    }

    private static function releaseLock(int $mappingId, string $token): void
    {
        $prefix = Database::prefix();
        Database::execute(
            "UPDATE `{$prefix}ycy_goods`
                SET `sync_lock_token` = NULL, `sync_lock_until` = NULL
              WHERE `id` = ? AND `sync_lock_token` = ?",
            [$mappingId, $token]
        );
    }

    private static function markStockSuccess(int $mappingId): void
    {
        $prefix = Database::prefix();
        Database::execute(
            "UPDATE `{$prefix}ycy_goods`
                SET `next_stock_sync_at` = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    `stock_fail_count` = 0,
                    `last_stock_error` = ''
              WHERE `id` = ?",
            [self::STOCK_DELAY_SECONDS, $mappingId]
        );
    }

    private static function markPriceSuccess(int $mappingId): void
    {
        $prefix = Database::prefix();
        Database::execute(
            "UPDATE `{$prefix}ycy_goods`
                SET `next_price_sync_at` = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    `price_fail_count` = 0,
                    `last_price_error` = ''
              WHERE `id` = ?",
            [self::PRICE_DELAY_SECONDS, $mappingId]
        );
    }

    private static function markStockFailure(array $row, string $message): void
    {
        $mappingId = (int) ($row['id'] ?? 0);
        if ($mappingId <= 0) {
            return;
        }
        $fails = (int) ($row['stock_fail_count'] ?? 0) + 1;
        $delay = self::backoffSeconds(self::STOCK_BACKOFF_BASE, $fails);
        $prefix = Database::prefix();
        Database::execute(
            "UPDATE `{$prefix}ycy_goods`
                SET `next_stock_sync_at` = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    `stock_fail_count` = ?,
                    `last_stock_error` = ?
              WHERE `id` = ?",
            [$delay, $fails, self::trimError($message), $mappingId]
        );
    }

    private static function markPriceFailure(array $row, string $message): void
    {
        $mappingId = (int) ($row['id'] ?? 0);
        if ($mappingId <= 0) {
            return;
        }
        $fails = (int) ($row['price_fail_count'] ?? 0) + 1;
        $delay = self::backoffSeconds(self::PRICE_BACKOFF_BASE, $fails);
        $prefix = Database::prefix();
        Database::execute(
            "UPDATE `{$prefix}ycy_goods`
                SET `next_price_sync_at` = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    `price_fail_count` = ?,
                    `last_price_error` = ?
              WHERE `id` = ?",
            [$delay, $fails, self::trimError($message), $mappingId]
        );
    }

    private static function backoffSeconds(int $base, int $failCount): int
    {
        $exp = min(max($failCount - 1, 0), 6);
        $seconds = $base * (2 ** $exp);
        return min($seconds, self::BACKOFF_MAX_SECONDS);
    }

    private static function trimError(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return (string) mb_substr($message, 0, 250, 'UTF-8');
        }
        return substr($message, 0, 250);
    }

    private static function makeLockToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return md5(uniqid((string) mt_rand(), true));
        }
    }

    private static function readSettingInt(string $key, int $default, int $min, int $max): int
    {
        $raw = Storage::getInstance('ycy_shared')->getValue($key);
        $val = (int) $raw;
        if ($val <= 0) {
            $val = $default;
        }
        if ($val < $min) {
            $val = $min;
        }
        if ($val > $max) {
            $val = $max;
        }
        return $val;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fetchDueTrades(int $limit): array
    {
        $prefix = Database::prefix();
        return Database::query(
            "SELECT t.*, og.`order_id`, og.`goods_id`, og.`spec_id`, og.`delivery_content`,
                    s.`enabled`, s.`version` AS `site_version`, s.`host`, s.`app_id`, s.`app_key`, s.`markup_ratio`, s.`min_markup`
               FROM `{$prefix}ycy_trade` t
               LEFT JOIN `{$prefix}order_goods` og ON og.`id` = t.`order_goods_id`
               LEFT JOIN `{$prefix}ycy_site` s ON s.`id` = t.`site_id`
              WHERE t.`status` = 'pending'
                AND (t.`next_poll_at` IS NULL OR t.`next_poll_at` <= NOW())
              ORDER BY COALESCE(t.`next_poll_at`, '1970-01-01 00:00:00') ASC, t.`id` ASC
              LIMIT ?",
            [$limit]
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function pollTradeOne(array $row): void
    {
        $tradeId = (int) ($row['id'] ?? 0);
        $orderGoodsId = (int) ($row['order_goods_id'] ?? 0);
        if ($tradeId <= 0 || $orderGoodsId <= 0) {
            throw new RuntimeException('流水数据不完整');
        }
        if ((int) ($row['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('站点已停用');
        }
        if (!empty($row['delivery_content'])) {
            self::markTradeSuccess($tradeId, (int) ($row['poll_attempts'] ?? 0));
            return;
        }
        $tradeNo = trim((string) ($row['upstream_trade_no'] ?? ''));
        if ($tradeNo === '') {
            self::markTradeFailed($tradeId, '缺少 upstream_trade_no，无法轮询');
            return;
        }

        $site = self::hydrateSite($row);
        $client = Client::make($site);
        $q = $client->queryOrder($tradeNo);
        $contents = trim((string) ($q['contents'] ?? ''));
        $isManual = self::isManualDeliveryGoods((int) ($row['goods_id'] ?? 0));
        if ($isManual) {
            $previous = self::extractKnownDeliveryContent((string) ($row['response'] ?? ''));
            $changed = self::hasDeliveryContentChanged($previous, $contents);
            Logger::info('人工发货轮询回包', '人工发货订单轮询结果', [
                'trade_id' => $tradeId,
                'order_goods_id' => (int) ($row['order_goods_id'] ?? 0),
                'upstream_trade_no' => $tradeNo,
                'poll_attempts_next' => (int) ($row['poll_attempts'] ?? 0) + 1,
                'found' => !empty($q['found']) ? 1 : 0,
                'status' => (int) ($q['status'] ?? 0),
                'content_changed' => $changed ? 1 : 0,
                'previous_content' => self::truncateLogText($previous, 100),
                'current_content' => self::truncateLogText($contents, 100),
            ]);
            if (!empty($q['found']) && $contents !== '' && $changed) {
                self::finalizeTradeDelivery($row, $contents, $q);
                return;
            }
            $attempts = (int) ($row['poll_attempts'] ?? 0) + 1;
            $msg = '人工发货等待中（发货内容未变化）';
            if (empty($q['found'])) {
                $msg = '人工发货等待中（上游订单未找到）';
            } elseif ($contents === '') {
                $msg = '人工发货等待中（上游未返回发货内容）';
            }
            self::markTradePending($tradeId, $attempts, $msg, self::ORDER_POLL_INTERVAL_SECONDS, $q);
            return;
        }

        if (!empty($q['found']) && $contents !== '') {
            self::finalizeTradeDelivery($row, $contents, $q);
            return;
        }
        $attempts = (int) ($row['poll_attempts'] ?? 0) + 1;
        $msg = !empty($q['found']) ? '上游待发货' : '上游订单未找到';
        self::markTradePending($tradeId, $attempts, $msg, self::ORDER_POLL_INTERVAL_SECONDS, $q);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $queryResp
     */
    private static function finalizeTradeDelivery(array $row, string $contents, array $queryResp): void
    {
        $tradeId = (int) ($row['id'] ?? 0);
        $orderGoodsId = (int) ($row['order_goods_id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);
        $goodsId = (int) ($row['goods_id'] ?? 0);
        $specId = (int) ($row['spec_id'] ?? 0);
        $qty = max(1, (int) ($row['quantity'] ?? 1));
        $prefix = Database::prefix();

        $og = Database::fetchOne(
            "SELECT `delivery_content` FROM `{$prefix}order_goods` WHERE `id` = ? LIMIT 1",
            [$orderGoodsId]
        );
        $alreadyDelivered = !empty($og['delivery_content']);
        if (!$alreadyDelivered) {
            Database::execute(
                "UPDATE `{$prefix}order_goods` SET `delivery_content` = ?, `delivery_at` = NOW() WHERE `id` = ?",
                [$contents, $orderGoodsId]
            );
            if ($specId > 0) {
                Database::execute(
                    "UPDATE `{$prefix}goods_spec` SET `stock` = GREATEST(`stock` - ?, 0) WHERE `id` = ?",
                    [$qty, $specId]
                );
                GoodsModel::incrementSoldCount($specId, $qty);
            }
            if ($goodsId > 0) {
                GoodsModel::updatePriceStockCache($goodsId);
            }
            // 轮询补齐发货后，补发下游回调并推进订单整单发货状态。
            OrderModel::notifyDeliveryCallback($orderGoodsId);
            if ($orderId > 0) {
                OrderModel::checkDeliveryComplete($orderId);
            }
        }

        Database::update('ycy_trade', [
            'status' => 'success',
            'response' => json_encode($queryResp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => '',
            'next_poll_at' => null,
            'last_poll_error' => '',
        ], $tradeId);
    }

    private static function markTradeSuccess(int $tradeId, int $attempts): void
    {
        Database::update('ycy_trade', [
            'status' => 'success',
            'error_message' => '',
            'next_poll_at' => null,
            'poll_attempts' => $attempts,
            'last_poll_error' => '',
        ], $tradeId);
    }

    private static function markTradeFailed(int $tradeId, string $message): void
    {
        Database::update('ycy_trade', [
            'status' => 'failed',
            'error_message' => self::trimError($message),
            'next_poll_at' => null,
            'last_poll_error' => self::trimError($message),
        ], $tradeId);
    }

    /**
     * @param array<string,mixed>|null $queryResp
     */
    private static function markTradePending(int $tradeId, int $attempts, string $message, int $delaySeconds, ?array $queryResp = null): void
    {
        $prefix = Database::prefix();
        $error = self::trimError($message);
        $delay = max(30, $delaySeconds);
        if ($queryResp !== null) {
            $respText = json_encode($queryResp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            Database::execute(
                "UPDATE `{$prefix}ycy_trade`
                    SET `status` = 'pending',
                        `response` = ?,
                        `poll_attempts` = ?,
                        `last_poll_error` = ?,
                        `next_poll_at` = DATE_ADD(NOW(), INTERVAL ? SECOND)
                  WHERE `id` = ?",
                [$respText, $attempts, $error, $delay, $tradeId]
            );
            return;
        }
        Database::execute(
            "UPDATE `{$prefix}ycy_trade`
                SET `status` = 'pending',
                    `poll_attempts` = ?,
                    `last_poll_error` = ?,
                    `next_poll_at` = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE `id` = ?",
            [$attempts, $error, $delay, $tradeId]
        );
    }

    private static function isManualDeliveryGoods(int $goodsId): bool
    {
        if ($goodsId <= 0) {
            return false;
        }
        $prefix = Database::prefix();
        $row = Database::fetchOne(
            "SELECT `configs` FROM `{$prefix}goods` WHERE `id` = ? LIMIT 1",
            [$goodsId]
        );
        if (!$row) {
            return false;
        }
        $cfg = json_decode((string) ($row['configs'] ?? '{}'), true);
        if (!is_array($cfg)) {
            return false;
        }
        return (int) ($cfg['ycy_shared']['delivery_way'] ?? 0) === 1;
    }

    private static function hasDeliveryContentChanged(string $previous, string $current): bool
    {
        return trim($current) !== '' && trim($previous) !== trim($current);
    }

    private static function extractKnownDeliveryContent(string $responseText): string
    {
        if (trim($responseText) === '') {
            return '';
        }
        $decoded = json_decode($responseText, true);
        if (!is_array($decoded)) {
            return '';
        }
        if (!empty($decoded['contents'])) {
            return (string) $decoded['contents'];
        }
        if (!empty($decoded['secret'])) {
            return (string) $decoded['secret'];
        }
        if (!empty($decoded['data']) && is_array($decoded['data'])) {
            $data = $decoded['data'];
            if (!empty($data['secret'])) {
                return (string) $data['secret'];
            }
            if (!empty($data['contents'])) {
                return (string) $data['contents'];
            }
        }
        if (!empty($decoded['raw']) && is_array($decoded['raw']) && !empty($decoded['raw']['data']) && is_array($decoded['raw']['data'])) {
            $rawData = $decoded['raw']['data'];
            if (!empty($rawData['secret'])) {
                return (string) $rawData['secret'];
            }
            if (!empty($rawData['contents'])) {
                return (string) $rawData['contents'];
            }
        }
        return '';
    }

    private static function truncateLogText(string $text, int $maxLen = 100): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $maxLen) {
                return $text;
            }
            return mb_substr($text, 0, $maxLen, 'UTF-8');
        }
        if (strlen($text) <= $maxLen) {
            return $text;
        }
        return substr($text, 0, $maxLen);
    }
}
