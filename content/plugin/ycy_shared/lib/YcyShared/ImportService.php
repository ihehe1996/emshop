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
            'SELECT `upstream_ref`, `goods_id` FROM `' . Database::prefix() . 'ycy_goods`
              WHERE `site_id` = ? AND `upstream_ref` IN (' . $placeholders . ')',
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
        $existing = Database::fetchOne(
            'SELECT `goods_id` FROM `' . Database::prefix() . 'ycy_goods`
              WHERE `site_id` = ? AND `upstream_ref` = ? LIMIT 1',
            [(int) $site['id'], $upstreamRef]
        );
        if ($existing) {
            return ['goods_id' => (int) $existing['goods_id'], 'already_imported' => true];
        }

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

        // 构造 SKU：v4 原生 / v3 单 SKU
        $skus = [];
        if (!empty($item['sku']) && is_array($item['sku'])) {
            foreach ($item['sku'] as $s) {
                $skus[] = [
                    'name'            => (string) ($s['name'] ?: '默认'),
                    'upstream_sku_id' => $s['sku_id'] ?? null,
                    'upstream_price'  => (float) $s['price'],
                    'local_price_raw' => (int) round(((float) $s['price']) * $ratio * 1000000),
                    'stock'           => (int) $s['stock'],
                ];
            }
        } else {
            $skus[] = [
                'name'            => '默认',
                'upstream_sku_id' => null,
                'upstream_price'  => (float) ($item['price'] ?? 0),
                'local_price_raw' => (int) round(((float) ($item['price'] ?? 0)) * $ratio * 1000000),
                'stock'           => (int) ($item['stock'] ?? 0),
            ];
        }

        $coverImages = null;
        if (!empty($item['image'])) {
            $coverImages = json_encode([$item['image']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // 创建 em_goods
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
            'source_type'   => 'ycy_shared',
            'source_id'     => $site['id'] . ':' . $upstreamRef,
            'source_version'=> 1,
        ]);
        if ($goodsId <= 0) {
            throw new RuntimeException('创建本地商品失败');
        }

        // 创建 em_goods_spec 并构建 sku_map
        $skuMap = [];
        foreach ($skus as $idx => $s) {
            $specId = (int) Database::insert('goods_spec', [
                'goods_id'   => $goodsId,
                'name'       => $s['name'],
                'price'      => $s['local_price_raw'],
                'stock'      => $s['stock'],
                'sort'       => $idx,
                'is_default' => $idx === 0 ? 1 : 0,
                'status'     => 1,
            ]);
            $skuMap[] = [
                'local_spec_id'   => $specId,
                'upstream_sku_id' => $s['upstream_sku_id'],
                'upstream_price'  => $s['upstream_price'],
                'local_price_raw' => $s['local_price_raw'],
                'stock'           => $s['stock'],
                'name'            => $s['name'],
            ];
        }

        // 刷新 em_goods 的 min_price / max_price / total_stock 缓存
        GoodsModel::updatePriceStockCache($goodsId);

        // 写映射表
        Database::insert('ycy_goods', [
            'site_id'               => (int) $site['id'],
            'goods_id'              => $goodsId,
            'upstream_ref'          => $upstreamRef,
            'upstream_name'         => (string) $item['name'],
            'sku_map'               => json_encode($skuMap, JSON_UNESCAPED_UNICODE),
            'markup_ratio'          => null,
            'last_stock'            => (int) array_sum(array_column($skus, 'stock')),
            'last_price_raw'        => (int) round(((float) ($item['price'] ?? 0)) * 1000000),
            'last_stock_synced_at'  => date('Y-m-d H:i:s'),
            'last_catalog_synced_at'=> date('Y-m-d H:i:s'),
        ]);

        return ['goods_id' => $goodsId, 'already_imported' => false];
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
                    'msg' => $r['already_imported'] ? '已存在' : '导入成功',
                ];
            } catch (Throwable $e) {
                $out[$ref] = ['ok' => false, 'msg' => $e->getMessage()];
            }
        }
        return $out;
    }
}
