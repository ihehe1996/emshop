<?php

declare(strict_types=1);

namespace YcyShared;

use Database;
use GoodsModel;
use RuntimeException;
use Throwable;

/**
 * 商品导入服务：把上游商品落库到本地 em_goods + em_goods_spec + em_ycy_goods。
 *
 * 导入策略：
 *   - 本地价格 = 上游价 × 有效加价系数（商品级覆盖优先，站点级兜底）
 *   - v4：每个上游 sku → 一条 em_goods_spec
 *   - v3：单条 em_goods_spec（INI 配置解析留待下阶段按实际数据扩展）
 *   - em_goods.source_type = 'ycy_shared' / source_id = '{site_id}:{ref}' 方便反查
 */
final class ImportService
{
    /**
     * 拉取上游目录，并标注每条是否已经导入到本地。
     *
     * @return array<int, array<string, mixed>>  与 Client::fetchItems 一致，额外加 imported / local_goods_id
     */
    public static function listUpstreamItems(array $site): array
    {
        $items = Client::make($site)->fetchItems();
        if ($items === []) return [];

        $refs = array_values(array_filter(array_column($items, 'ref'), static fn($v) => $v !== ''));
        if ($refs === []) return $items;

        $placeholders = implode(',', array_fill(0, count($refs), '?'));
        $rows = Database::query(
            'SELECT yg.`upstream_ref`, yg.`goods_id`
               FROM `' . Database::prefix() . 'ycy_goods` yg
               INNER JOIN `' . Database::prefix() . 'goods` g ON g.`id` = yg.`goods_id` AND g.`deleted_at` IS NULL
              WHERE yg.`site_id` = ? AND yg.`upstream_ref` IN (' . $placeholders . ')',
            array_merge([(int) $site['id']], $refs)
        );
        $map = [];
        foreach ($rows as $r) $map[(string) $r['upstream_ref']] = (int) $r['goods_id'];

        foreach ($items as &$it) {
            $ref = (string) $it['ref'];
            $it['imported']       = isset($map[$ref]);
            $it['local_goods_id'] = $map[$ref] ?? 0;
        }
        unset($it);
        return $items;
    }

    /**
     * 导入单条上游商品。
     *
     * 幂等：已存在映射时直接返回已有 goods_id，不重复创建。
     *
     * @return array{goods_id:int, already_imported:bool}
     */
    public static function importItem(array $site, string $upstreamRef): array
    {
        if ($upstreamRef === '') {
            throw new RuntimeException('upstream_ref 为空');
        }
        $prefix = Database::prefix();
        $existing = Database::fetchOne(
            'SELECT yg.`id` AS `mapping_id`, yg.`goods_id`, yg.`sku_map`, yg.`markup_ratio`,
                    g.`id` AS `active_goods_id`, g.`deleted_at`
               FROM `' . $prefix . 'ycy_goods` yg
               LEFT JOIN `' . $prefix . 'goods` g ON g.`id` = yg.`goods_id`
              WHERE yg.`site_id` = ? AND yg.`upstream_ref` = ? LIMIT 1',
            [(int) $site['id'], $upstreamRef]
        );

        $client = Client::make($site);
        $item = $client->fetchItem($upstreamRef);
        if (empty($item['name'])) {
            // 某些接口用 catalog 才有完整信息，回退到 fetchItems 搜一下
            foreach ($client->fetchItems() as $candidate) {
                if ((string) $candidate['ref'] === $upstreamRef) { $item = $candidate; break; }
            }
        }
        if (empty($item['name'])) {
            throw new RuntimeException('上游商品不存在：' . $upstreamRef);
        }

        // 加价系数：商品级（暂无）> 站点 markup_ratio；必须 >= 站点 min_markup
        $ratio = max((float) ($site['markup_ratio'] ?? 1.2), (float) ($site['min_markup'] ?? 1.05));
        $skus = self::buildSkuRows($item, $ratio);
        self::hydrateUpstreamStocks($client, $site, $upstreamRef, $skus);

        $deliveryWay = (int) ($item['delivery_way'] ?? 0);
        if ($deliveryWay !== 1) {
            $deliveryWay = 0;
        }
        $goodsConfigs = [
            'ycy_shared' => [
                'site_id'       => (int) $site['id'],
                'upstream_ref'  => $upstreamRef,
                'delivery_way'  => $deliveryWay, // 1=manual, 0=auto
            ],
        ];
        $coverImages = null;
        if (!empty($item['image'])) {
            $coverImages = json_encode([$item['image']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        Database::begin();
        try {
            // 并发幂等：事务内再查一遍，防止并发导入同一 upstream_ref
            $existingInTx = Database::fetchOne(
                'SELECT yg.`id` AS `mapping_id`, yg.`goods_id`, yg.`sku_map`, yg.`markup_ratio`,
                        g.`id` AS `active_goods_id`, g.`deleted_at`
                   FROM `' . $prefix . 'ycy_goods` yg
                   LEFT JOIN `' . $prefix . 'goods` g ON g.`id` = yg.`goods_id`
                  WHERE yg.`site_id` = ? AND yg.`upstream_ref` = ? LIMIT 1',
                [(int) $site['id'], $upstreamRef]
            );
            $hasMapping = is_array($existingInTx);
            $activeGoodsId = (int) ($existingInTx['active_goods_id'] ?? 0);
            $isSoftDeleted = !empty($existingInTx['deleted_at']);
            $isUpdate = $hasMapping && $activeGoodsId > 0 && !$isSoftDeleted;
            $existingMap = $isUpdate ? (json_decode((string) ($existingInTx['sku_map'] ?? '[]'), true) ?: []) : [];
            $goodsId = $isUpdate ? $activeGoodsId : 0;

            if ($isUpdate) {
                $ok = GoodsModel::update($goodsId, [
                    'title'         => (string) $item['name'],
                    'cover_images'  => $coverImages,
                    'intro'         => mb_substr(strip_tags((string) ($item['introduce'] ?? $item['category'] ?? '')), 0, 200),
                    'content'       => (string) ($item['introduce'] ?? ''),
                    'goods_type'    => 'ycy_shared',
                    'is_on_sale'    => 1,
                    'status'        => 1,
                    'unit'          => '件',
                    'configs'       => json_encode($goodsConfigs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'source_type'   => 'ycy_shared',
                    'source_id'     => $site['id'] . ':' . $upstreamRef,
                    'source_version'=> 1,
                ]);
                if (!$ok) {
                    throw new RuntimeException('更新本地商品失败');
                }
            } else {
                $goodsId = (int) GoodsModel::create([
                    'title'         => (string) $item['name'],
                    'category_id'   => 0, // 用户可在商品列表页后续归类
                    'cover_images'  => $coverImages,
                    'intro'         => mb_substr(strip_tags((string) ($item['introduce'] ?? $item['category'] ?? '')), 0, 200),
                    'content'       => (string) ($item['introduce'] ?? ''),
                    'goods_type'    => 'ycy_shared',
                    'is_on_sale'    => 1,
                    'status'        => 1,
                    'unit'          => '件',
                    'configs'       => json_encode($goodsConfigs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'source_type'   => 'ycy_shared',
                    'source_id'     => $site['id'] . ':' . $upstreamRef,
                    'source_version'=> 1,
                ]);
                if ($goodsId <= 0) {
                    throw new RuntimeException('创建本地商品失败');
                }
            }

            $skuMap = self::syncSpecs($goodsId, $skus, is_array($existingMap) ? $existingMap : []);
            self::syncSpecMatrix($goodsId, $skuMap);

            // 刷新 em_goods 的 min_price / max_price / total_stock 缓存
            GoodsModel::updatePriceStockCache($goodsId);

            $mappingPayload = [
                'site_id'               => (int) $site['id'],
                'goods_id'              => $goodsId,
                'upstream_ref'          => $upstreamRef,
                'upstream_name'         => (string) $item['name'],
                'sku_map'               => json_encode($skuMap, JSON_UNESCAPED_UNICODE),
                'last_stock'            => (int) array_sum(array_column($skus, 'stock')),
                'last_price_raw'        => (int) round(((float) ($item['price'] ?? 0)) * 1000000),
                'last_stock_synced_at'  => date('Y-m-d H:i:s'),
                'last_catalog_synced_at'=> date('Y-m-d H:i:s'),
                'next_stock_sync_at'    => date('Y-m-d H:i:s'),
                'next_price_sync_at'    => date('Y-m-d H:i:s'),
                'stock_fail_count'      => 0,
                'price_fail_count'      => 0,
                'last_stock_error'      => '',
                'last_price_error'      => '',
                'sync_lock_token'       => null,
                'sync_lock_until'       => null,
            ];
            if ($hasMapping) {
                // 无论之前商品是否被删，都复用同一映射行，避免触发 uk_site_ref 唯一键冲突
                Database::update('ycy_goods', $mappingPayload, (int) ($existingInTx['mapping_id'] ?? 0));
            } else {
                $mappingPayload['markup_ratio'] = null;
                Database::insert('ycy_goods', $mappingPayload);
            }

            Database::commit();
            return ['goods_id' => $goodsId, 'already_imported' => $isUpdate];
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * 批量导入：返回 [ref => {ok, msg, goods_id, already_imported}]。
     *
     * 失败的条目不中断整批；逐条独立事务。
     *
     * @param string[] $refs
     */
    public static function importBatch(array $site, array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            $ref = (string) $ref;
            try {
                $r = self::importItem($site, $ref);
                $out[$ref] = [
                    'ok' => true,
                    'already_imported' => $r['already_imported'],
                    'goods_id' => $r['goods_id'],
                    'msg' => $r['already_imported'] ? '更新成功' : '导入成功',
                ];
            } catch (Throwable $e) {
                $out[$ref] = ['ok' => false, 'msg' => $e->getMessage()];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<int, array<string,mixed>>
     */
    private static function buildSkuRows(array $item, float $ratio): array
    {
        $skus = [];
        if (!empty($item['sku']) && is_array($item['sku'])) {
            foreach ($item['sku'] as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $name = trim((string) ($s['name'] ?? ''));
                if ($name === '') {
                    $name = '默认';
                }
                $upPrice = (float) ($s['price'] ?? 0);
                $skus[] = [
                    'name'            => $name,
                    'upstream_sku_id' => $s['sku_id'] ?? null,
                    'upstream_price'  => $upPrice,
                    'local_price_raw' => (int) round($upPrice * $ratio * 1000000),
                    'stock'           => (int) ($s['stock'] ?? 0),
                    'sku_fields'      => is_array($s['sku_fields'] ?? null) ? $s['sku_fields'] : [],
                ];
            }
        }
        if ($skus === []) {
            $upPrice = (float) ($item['price'] ?? 0);
            $skus[] = [
                'name'            => '默认',
                'upstream_sku_id' => null,
                'upstream_price'  => $upPrice,
                'local_price_raw' => (int) round($upPrice * $ratio * 1000000),
                'stock'           => (int) ($item['stock'] ?? 0),
                'sku_fields'      => [],
            ];
        }
        return $skus;
    }

    /**
     * @param array<int,array<string,mixed>> $skus
     * @param array<int,array<string,mixed>> $existingSkuMap
     * @return array<int,array<string,mixed>>
     */
    private static function syncSpecs(int $goodsId, array $skus, array $existingSkuMap): array
    {
        $prefix = Database::prefix();
        $oldSpecs = Database::query(
            "SELECT `id`, `name` FROM `{$prefix}goods_spec` WHERE `goods_id` = ?",
            [$goodsId]
        );
        $oldById = [];
        $oldByName = [];
        foreach ($oldSpecs as $sp) {
            $sid = (int) ($sp['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $oldById[$sid] = $sp;
            $nm = trim((string) ($sp['name'] ?? ''));
            if ($nm !== '' && !isset($oldByName[$nm])) {
                $oldByName[$nm] = $sid;
            }
        }
        $mapSpecByKey = [];
        foreach ($existingSkuMap as $m) {
            if (!is_array($m)) {
                continue;
            }
            $sid = (int) ($m['local_spec_id'] ?? 0);
            if ($sid <= 0 || !isset($oldById[$sid])) {
                continue;
            }
            $key = self::skuKey($m['upstream_sku_id'] ?? null, (string) ($m['name'] ?? ''));
            if (!isset($mapSpecByKey[$key])) {
                $mapSpecByKey[$key] = $sid;
            }
        }

        $used = [];
        $skuMap = [];
        foreach ($skus as $idx => $s) {
            $name = (string) ($s['name'] ?? '默认');
            $key = self::skuKey($s['upstream_sku_id'] ?? null, $name);
            $specId = 0;
            if (isset($mapSpecByKey[$key])) {
                $specId = (int) $mapSpecByKey[$key];
            } elseif (isset($oldByName[$name])) {
                $specId = (int) $oldByName[$name];
            }

            $payload = [
                'name'       => $name,
                'price'      => (int) ($s['local_price_raw'] ?? 0),
                'stock'      => max(0, (int) ($s['stock'] ?? 0)),
                'sort'       => (int) $idx,
                'is_default' => $idx === 0 ? 1 : 0,
                'status'     => 1,
            ];
            if ($specId > 0 && isset($oldById[$specId])) {
                Database::update('goods_spec', $payload, $specId);
            } else {
                $specId = (int) Database::insert('goods_spec', array_merge($payload, [
                    'goods_id' => $goodsId,
                ]));
            }
            $used[$specId] = true;
            $skuMap[] = [
                'local_spec_id'   => $specId,
                'upstream_sku_id' => $s['upstream_sku_id'] ?? null,
                'upstream_price'  => (float) ($s['upstream_price'] ?? 0),
                'local_price_raw' => (int) ($s['local_price_raw'] ?? 0),
                'stock'           => max(0, (int) ($s['stock'] ?? 0)),
                'name'            => $name,
                'sku_fields'      => is_array($s['sku_fields'] ?? null) ? $s['sku_fields'] : [],
            ];
        }

        foreach (array_keys($oldById) as $sid) {
            if (!isset($used[$sid])) {
                Database::update('goods_spec', ['status' => 0], (int) $sid);
            }
        }
        return $skuMap;
    }

    private static function skuKey($upstreamSkuId, string $name): string
    {
        $up = trim((string) $upstreamSkuId);
        if ($up !== '' && $up !== '0') {
            return 'id:' . $up;
        }
        return 'name:' . trim($name);
    }

    /**
     * 导入阶段补齐真实库存：
     * - V4：优先用 upstream_sku_id 逐个查库存
     * - V3：有 sku_fields 的多维规格，逐组合查询库存
     *
     * @param array<string,mixed> $site
     * @param array<int,array<string,mixed>> $skus
     */
    private static function hydrateUpstreamStocks(Client $client, array $site, string $upstreamRef, array &$skus): void
    {
        if ($skus === [] || $upstreamRef === '') {
            return;
        }
        $version = strtolower(trim((string) ($site['version'] ?? 'v3')));
        foreach ($skus as $idx => $sku) {
            if (!is_array($sku)) {
                continue;
            }
            $stockArg = null;
            $upSkuId = (int) ($sku['upstream_sku_id'] ?? 0);
            if ($version === 'v4' && $upSkuId > 0) {
                $stockArg = $upSkuId;
            } elseif (!empty($sku['sku_fields']) && is_array($sku['sku_fields'])) {
                $stockArg = $sku['sku_fields'];
            } elseif ($upSkuId > 0) {
                $stockArg = $upSkuId;
            }
            if ($stockArg === null) {
                continue;
            }
            try {
                $realStock = (int) $client->fetchStock($upstreamRef, $stockArg);
                $skus[$idx]['stock'] = max(0, $realStock);
            } catch (Throwable $e) {
                // 单规格库存查询失败不打断导入，保留当前估算值
            }
        }
    }

    /**
     * 根据 sku_map 回写多维规格结构（goods_spec_dim/value/combo）。
     *
     * @param array<int,array<string,mixed>> $skuMap
     */
    private static function syncSpecMatrix(int $goodsId, array $skuMap): void
    {
        $prefix = Database::prefix();
        Database::execute("DELETE FROM `{$prefix}goods_spec_combo` WHERE `goods_id` = ?", [$goodsId]);
        Database::execute("DELETE FROM `{$prefix}goods_spec_value` WHERE `goods_id` = ?", [$goodsId]);
        Database::execute("DELETE FROM `{$prefix}goods_spec_dim` WHERE `goods_id` = ?", [$goodsId]);

        if ($skuMap === []) {
            return;
        }

        $dimNames = [];
        foreach ($skuMap as $row) {
            $skuPayload = $row['sku_fields']['sku'] ?? null;
            if (!is_array($skuPayload)) {
                continue;
            }
            foreach ($skuPayload as $dimName => $_) {
                $dimName = trim((string) $dimName);
                if ($dimName === '' || in_array($dimName, $dimNames, true)) {
                    continue;
                }
                $dimNames[] = $dimName;
            }
        }

        if ($dimNames === []) {
            // 单规格商品保持与后台规格编辑一致
            Database::insert('goods_spec_dim', [
                'goods_id' => $goodsId,
                'name'     => '规格',
                'sort'     => 0,
            ]);
            return;
        }

        $valueLists = [];
        foreach ($skuMap as $row) {
            $skuPayload = $row['sku_fields']['sku'] ?? null;
            if (!is_array($skuPayload)) {
                continue;
            }
            foreach ($dimNames as $dimName) {
                $val = trim((string) ($skuPayload[$dimName] ?? ''));
                if ($val === '') {
                    continue;
                }
                if (!isset($valueLists[$dimName])) {
                    $valueLists[$dimName] = [];
                }
                if (!in_array($val, $valueLists[$dimName], true)) {
                    $valueLists[$dimName][] = $val;
                }
            }
        }

        $dimIdByName = [];
        $valueIdByKey = [];
        foreach ($dimNames as $idx => $dimName) {
            $dimId = (int) Database::insert('goods_spec_dim', [
                'goods_id' => $goodsId,
                'name'     => $dimName,
                'sort'     => $idx,
            ]);
            $dimIdByName[$dimName] = $dimId;
            $values = $valueLists[$dimName] ?? [];
            foreach ($values as $vIdx => $valName) {
                $valId = (int) Database::insert('goods_spec_value', [
                    'dim_id'   => $dimId,
                    'goods_id' => $goodsId,
                    'name'     => $valName,
                    'sort'     => $vIdx,
                ]);
                $valueIdByKey[$dimName . '|' . $valName] = $valId;
            }
        }

        foreach ($skuMap as $row) {
            $specId = (int) ($row['local_spec_id'] ?? 0);
            if ($specId <= 0) {
                continue;
            }
            $skuPayload = $row['sku_fields']['sku'] ?? null;
            if (!is_array($skuPayload)) {
                continue;
            }
            $valueIds = [];
            foreach ($dimNames as $dimName) {
                $val = trim((string) ($skuPayload[$dimName] ?? ''));
                if ($val === '') {
                    continue;
                }
                $key = $dimName . '|' . $val;
                if (!empty($valueIdByKey[$key])) {
                    $valueIds[] = (int) $valueIdByKey[$key];
                }
            }
            if ($valueIds === []) {
                continue;
            }
            Database::insert('goods_spec_combo', [
                'goods_id'   => $goodsId,
                'spec_id'    => $specId,
                'combo_hash' => md5(implode('|', $valueIds)),
                'combo_text' => (string) ($row['name'] ?? ''),
                'value_ids'  => json_encode($valueIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }
}
