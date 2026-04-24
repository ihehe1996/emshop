<?php

declare(strict_types=1);

namespace YcyShared;

use Cache;
use Database;
use GoodsModel;
use Storage;
use Throwable;

/**
 * 定时同步服务。
 *
 * 每次 swoole_timer_tick（60s）触发 tick()，内部按 Cache 里的"上次运行时间"节流：
 *   - 库存轮询：每 3 分钟
 *   - 价格轮询：每 15 分钟（价格需要 fetchItem，调用更重，放慢）
 *   - 目录扫描：留作手动（设置页"拉取目录"）；自动全量扫可能拖慢服务端
 *
 * 单条 ycy_goods 的失败不打断整批；错误记 error_log。
 */
final class SyncService
{
    private const CK_STOCK_LAST = 'ycy_shared_stock_last_run';
    private const CK_PRICE_LAST = 'ycy_shared_price_last_run';

    public static function tick(): void
    {
        $now = time();
        // 库存：3 分钟
        if ((int) (Cache::get(self::CK_STOCK_LAST) ?? 0) + 180 < $now) {
            Cache::set(self::CK_STOCK_LAST, $now, 3600, 'ycy_shared');
            self::syncStockAll();
        }
        // 价格：15 分钟
        if ((int) (Cache::get(self::CK_PRICE_LAST) ?? 0) + 900 < $now) {
            Cache::set(self::CK_PRICE_LAST, $now, 3600, 'ycy_shared');
            self::syncPriceAll();
        }
    }

    /**
     * 遍历所有映射，拉最新库存写回 em_goods_spec + em_ycy_goods.last_stock。
     */
    public static function syncStockAll(): void
    {
        $rows = Database::query(
            'SELECT g.*, s.`enabled` AS `site_enabled`, s.`version` AS `site_version`,
                    s.`host`, s.`app_id`, s.`app_key`
               FROM `' . Database::prefix() . 'ycy_goods` g
               JOIN `' . Database::prefix() . 'ycy_site`  s ON s.`id` = g.`site_id`
              WHERE s.`enabled` = 1'
        );
        foreach ($rows as $row) {
            try {
                self::syncStockOne($row);
            } catch (Throwable $e) {
                error_log('[ycy_shared] syncStock #' . $row['id'] . ' ' . $e->getMessage());
            }
        }
    }

    /**
     * 遍历所有映射，重新拉一次 item 刷新本地规格价格（按站点加价系数）。
     */
    public static function syncPriceAll(): void
    {
        $rows = Database::query(
            'SELECT g.*, s.`enabled`, s.`version` AS `site_version`, s.`host`, s.`app_id`, s.`app_key`,
                    s.`markup_ratio`, s.`min_markup`
               FROM `' . Database::prefix() . 'ycy_goods` g
               JOIN `' . Database::prefix() . 'ycy_site`  s ON s.`id` = g.`site_id`
              WHERE s.`enabled` = 1'
        );
        foreach ($rows as $row) {
            try {
                self::syncPriceOne($row);
            } catch (Throwable $e) {
                error_log('[ycy_shared] syncPrice #' . $row['id'] . ' ' . $e->getMessage());
            }
        }
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
        self::syncStockOne($row);
        self::syncPriceOne($row);
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
            $stock = $client->fetchStock((string) $row['upstream_ref'], $sku['upstream_sku_id'] ?? null);
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
}
