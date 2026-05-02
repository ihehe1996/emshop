<?php

declare(strict_types=1);

namespace EmshopPlugin;

use AttachmentModel;
use Database;
use GoodsCategoryModel;
use GoodsModel;
use GoodsTagModel;
use RuntimeException;
use Throwable;

/**
 * 从对接站点 API 拉取商品并写入本站（主站后台导入）。
 */
final class GoodsImportService
{
    public const SOURCE_TYPE = 'emshop_remote';

    /**
     * 本站商品分类（供 select），二级缩进展示名。
     *
     * @return list<array{id:int, label:string}>
     */
    public static function localCategoryOptions(): array
    {
        $model = new GoodsCategoryModel();
        $all = $model->getAll();
        $children = [];
        foreach ($all as $row) {
            $pid = (int) ($row['parent_id'] ?? 0);
            $children[$pid][] = $row;
        }
        foreach ($children as &$list) {
            usort($list, static function ($a, $b) {
                $sa = (int) ($a['sort'] ?? 0);
                $sb = (int) ($b['sort'] ?? 0);
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            });
        }
        unset($list);

        $out = [];
        foreach ($children[0] ?? [] as $top) {
            $tid = (int) ($top['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $out[] = [
                'id'    => $tid,
                'label' => (string) ($top['name'] ?? ''),
            ];
            foreach ($children[$tid] ?? [] as $sub) {
                $sid = (int) ($sub['id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $out[] = [
                    'id'    => $sid,
                    'label' => '　└ ' . (string) ($sub['name'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $site RemoteSiteModel::find 行（含 secret）
     * @param list<int>            $remoteGoodsIds
     * @return array{ok:int, fail:int, errors:list<string>}
     */
    public static function syncGoods(
        array $site,
        int $targetCategoryId,
        string $markupMode,
        float $markupValue,
        string $imageMode,
        array $remoteGoodsIds
    ): array {
        $siteId = (int) ($site['id'] ?? 0);
        $baseUrl = (string) ($site['base_url'] ?? '');
        $appid = (string) ($site['appid'] ?? '');
        $secret = (string) ($site['secret'] ?? '');
        if ($siteId <= 0 || $baseUrl === '' || $appid === '' || $secret === '') {
            throw new RuntimeException('对接站点数据不完整');
        }
        if ($targetCategoryId <= 0) {
            throw new RuntimeException('请选择本站分类');
        }
        $cat = (new GoodsCategoryModel())->findById($targetCategoryId);
        if ($cat === null || (int) ($cat['status'] ?? 0) !== 1) {
            throw new RuntimeException('本站分类无效或已停用');
        }

        $markupMode = $markupMode === 'amount' ? 'amount' : 'percent';
        $imageMode = $imageMode === 'local' ? 'local' : 'remote';

        $ids = [];
        foreach ($remoteGoodsIds as $gid) {
            $gid = (int) $gid;
            if ($gid > 0) {
                $ids[$gid] = true;
            }
        }
        $idList = array_keys($ids);
        if ($idList === []) {
            throw new RuntimeException('请至少选择一个商品');
        }

        $adminId = (int) ($GLOBALS['adminUser']['id'] ?? 0);

        $ok = 0;
        $fail = 0;
        $errors = [];

        $chunks = array_chunk($idList, 40);
        foreach ($chunks as $chunk) {
            try {
                $list = RemoteApiClient::fetchGoodsList($baseUrl, $appid, $secret, [
                    'goods_ids' => implode(',', $chunk),
                ]);
            } catch (Throwable $e) {
                foreach ($chunk as $gid) {
                    $fail++;
                    $errors[] = '#' . $gid . ' 拉取失败：' . $e->getMessage();
                }
                continue;
            }
            $byId = [];
            foreach ($list as $row) {
                $rid = (int) ($row['goods_id'] ?? 0);
                if ($rid > 0) {
                    $byId[$rid] = $row;
                }
            }
            foreach ($chunk as $gid) {
                if (!isset($byId[$gid])) {
                    $fail++;
                    $errors[] = '#' . $gid . ' 对方未返回该商品';
                    continue;
                }
                try {
                    self::importOne(
                        $siteId,
                        $baseUrl,
                        $byId[$gid],
                        $targetCategoryId,
                        $markupMode,
                        $markupValue,
                        $imageMode,
                        $adminId
                    );
                    $ok++;
                } catch (Throwable $e) {
                    $fail++;
                    $errors[] = '#' . $gid . ' ' . $e->getMessage();
                }
            }
        }

        return ['ok' => $ok, 'fail' => $fail, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $item 对方 goods_list 单条
     */
    private static function importOne(
        int $siteId,
        string $baseUrl,
        array $item,
        int $targetCategoryId,
        string $markupMode,
        float $markupValue,
        string $imageMode,
        int $adminId
    ): void {
        $remoteId = (int) ($item['goods_id'] ?? 0);
        if ($remoteId <= 0) {
            throw new RuntimeException('商品 ID 无效');
        }
        $sourceId = $siteId . ':' . $remoteId;
        $prefix = Database::prefix();
        $dup = Database::fetchOne(
            "SELECT `id` FROM `{$prefix}goods` WHERE `source_type` = ? AND `source_id` = ? AND `deleted_at` IS NULL LIMIT 1",
            [self::SOURCE_TYPE, $sourceId]
        );
        $goodsId = (int) ($dup['id'] ?? 0);
        $isUpdate = $goodsId > 0;

        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') {
            $title = '导入商品 #' . $remoteId;
        }

        $basePrice = (float) ($item['min_price'] ?? 0);
        if ($basePrice < 0) {
            $basePrice = 0;
        }
        if ($markupMode === 'amount') {
            $sale = $basePrice + $markupValue;
        } else {
            $sale = $basePrice * (1 + $markupValue / 100.0);
        }
        if ($sale < 0) {
            $sale = 0;
        }

        $coverList = self::jsonDecodeStringList($item['cover_images'] ?? null);
        $coverSources = [];
        foreach ($coverList as $u) {
            $u = trim((string) $u);
            if ($u !== '') {
                $coverSources[] = $u;
            }
        }
        $coverRaw = trim((string) ($item['cover_image'] ?? ''));
        if ($coverRaw !== '') {
            $coverSources[] = $coverRaw;
        }
        $covers = self::buildCoverImagesList($baseUrl, $coverSources, $imageMode);

        $intro = trim((string) ($item['intro'] ?? ''));
        if (function_exists('mb_strlen') && mb_strlen($intro, 'UTF-8') > 8000) {
            $intro = mb_substr($intro, 0, 8000, 'UTF-8');
        }
        $content = array_key_exists('content', $item) ? (string) $item['content'] : '';
        if ($content === '' && !array_key_exists('content', $item)) {
            $content = '<p>（由 EMSHOP 对接站点导入）</p>';
        }
        if (function_exists('mb_strlen') && mb_strlen($content, 'UTF-8') > 500000) {
            $content = mb_substr($content, 0, 500000, 'UTF-8');
        }

        $upstreamType = trim((string) ($item['upstream_goods_type'] ?? ''));
        if ($upstreamType === '') {
            $upstreamType = trim((string) ($item['goods_type'] ?? ''));
        }
        $configs = self::jsonDecodeAssoc($item['configs'] ?? null);
        $efOnly = self::decodeExtraFieldsPayload($item['extra_fields'] ?? null);
        if ($efOnly !== [] && empty($configs['extra_fields'])) {
            $configs['extra_fields'] = $efOnly;
        }
        $configs['emshop_import'] = [
            'remote_site_id'       => $siteId,
            'remote_goods_id'      => $remoteId,
            'upstream_goods_type'  => $upstreamType,
        ];

        $minBuyRaw = $item['min_buy'] ?? null;
        $maxBuyRaw = $item['max_buy'] ?? null;
        $specMinBuy = ($minBuyRaw === null || $minBuyRaw === '') ? null : max(1, (int) $minBuyRaw);
        $specMaxBuy = ($maxBuyRaw === null || $maxBuyRaw === '') ? null : max(0, (int) $maxBuyRaw);

        // 独立商品类型：发货走 goods_type_emshop_remote_order_paid，避免误用 virtual_card / physical 的卡密与运费逻辑
        $goodsType = 'emshop_remote';

        $goodsRow = [
            'title'         => mb_substr($title, 0, 200, 'UTF-8'),
            'category_id'   => $targetCategoryId,
            'cover_images'  => json_encode($covers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'intro'         => $intro,
            'content'       => $content,
            'goods_type'    => $goodsType,
            'configs'       => $configs !== [] ? json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'is_on_sale'    => 1,
            'status'        => 1,
            'unit'          => '个',
            'owner_id'      => 0,
            'source_type'   => self::SOURCE_TYPE,
            'source_id'     => $sourceId,
            'api_enabled'   => 1,
            'jump_url'      => '',
            'sort'          => 0,
        ];

        if ($isUpdate) {
            $ok = GoodsModel::update($goodsId, $goodsRow);
            if (!$ok) {
                throw new RuntimeException('更新已导入商品失败');
            }
        } else {
            $goodsRow['created_by'] = $adminId;
            $goodsId = (int) GoodsModel::create($goodsRow);
            if ($goodsId <= 0) {
                throw new RuntimeException('创建商品失败');
            }
        }

        $stock = max(0, (int) ($item['stock'] ?? 0));

        $specRows = self::decodeSpecRowsPayload($item['specs'] ?? null);
        if ($specRows === []) {
            $specRows = [[
                'name'       => '默认规格',
                'price'      => number_format($basePrice, 2, '.', ''),
                'cost_price' => number_format($basePrice, 2, '.', ''),
                'stock'      => $stock,
                'min_buy'    => $specMinBuy,
                'max_buy'    => $specMaxBuy,
                'sort'       => 0,
                'is_default' => 1,
                'status'     => 1,
            ]];
        }
        $dimNameRaw = trim((string) ($item['spec_dim_name'] ?? ''));
        self::syncSpecRows(
            $goodsId,
            $specRows,
            $dimNameRaw,
            $markupMode,
            $markupValue
        );

        $tagNames = self::jsonDecodeStringList($item['tag_names'] ?? null);
        $tagIds = [];
        foreach ($tagNames as $nm) {
            $nm = trim((string) $nm);
            if ($nm !== '') {
                $tagIds[] = GoodsTagModel::findOrCreate($nm);
            }
        }
        GoodsTagModel::syncGoodsTags($goodsId, $tagIds);
        GoodsTagModel::refreshAllCounts();

        doAction('goods_type_emshop_remote_save', $goodsId, ['plugin_data' => []]);
        GoodsModel::updatePriceStockCache($goodsId);
    }

    /**
     * @param mixed $v
     * @return list<array<string,mixed>>
     */
    private static function decodeSpecRowsPayload($v): array
    {
        $x = self::jsonDecodeFlexible($v);
        if (!is_array($x)) {
            return [];
        }
        $out = [];
        foreach ($x as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * 按后台 goods_edit 的规则同步规格（就地更新 + 重建维度/组合）。
     *
     * @param list<array<string,mixed>> $specRows
     */
    private static function syncSpecRows(
        int $goodsId,
        array $specRows,
        string $specDimNameRaw,
        string $markupMode,
        float $markupValue
    ): void {
        $prefix = Database::prefix();

        $oldSpecs = Database::query(
            "SELECT `id`, `name` FROM `{$prefix}goods_spec` WHERE `goods_id` = ?",
            [$goodsId]
        );
        $oldMap = [];
        foreach ($oldSpecs as $os) {
            $nm = trim((string) ($os['name'] ?? ''));
            if ($nm !== '') {
                $oldMap[$nm] = (int) ($os['id'] ?? 0);
            }
        }

        $normalized = [];
        foreach ($specRows as $idx => $sp) {
            $name = trim((string) ($sp['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $upPrice = max(0, (float) ($sp['price'] ?? 0));
            $sale = $markupMode === 'amount'
                ? $upPrice + $markupValue
                : $upPrice * (1 + $markupValue / 100.0);
            if ($sale < 0) {
                $sale = 0;
            }
            $minRaw = $sp['min_buy'] ?? null;
            $maxRaw = $sp['max_buy'] ?? null;
            $minBuy = ($minRaw === null || $minRaw === '') ? null : max(1, (int) $minRaw);
            $maxBuy = ($maxRaw === null || $maxRaw === '') ? null : max(0, (int) $maxRaw);

            $tagJson = null;
            $tags = $sp['tags'] ?? null;
            if (is_string($tags)) {
                $decoded = json_decode($tags, true);
                if (is_array($decoded)) {
                    $tags = $decoded;
                }
            }
            if (is_array($tags) && $tags !== []) {
                $cleanTags = array_values(array_filter(array_map('strval', $tags), 'strlen'));
                if ($cleanTags !== []) {
                    $tagJson = json_encode($cleanTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            $cfg = $sp['configs'] ?? null;
            $cfgArr = self::jsonDecodeAssoc($cfg);
            $keepCfg = [];
            if (!empty($cfgArr['images']) && is_array($cfgArr['images'])) {
                $keepCfg['images'] = array_values(array_filter($cfgArr['images'], 'is_string'));
            }
            $cfgJson = $keepCfg !== []
                ? json_encode($keepCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            $normalized[] = [
                'name'         => $name,
                'spec_no'      => trim((string) ($sp['spec_no'] ?? '')),
                'price'        => GoodsModel::moneyToDb(number_format($sale, 2, '.', '')),
                'cost_price'   => GoodsModel::moneyToDb(number_format($upPrice, 2, '.', '')),
                'market_price' => ($sp['market_price'] ?? '') !== ''
                    ? GoodsModel::moneyToDb((float) $sp['market_price'])
                    : null,
                'stock'        => max(0, (int) ($sp['stock'] ?? 0)),
                'tags'         => $tagJson,
                'configs'      => $cfgJson,
                'min_buy'      => $minBuy,
                'max_buy'      => $maxBuy,
                'sort'         => (int) ($sp['sort'] ?? $idx),
                'is_default'   => (int) ($sp['is_default'] ?? 0) === 1 ? 1 : 0,
            ];
        }
        if ($normalized === []) {
            return;
        }
        $defaultIdx = -1;
        foreach ($normalized as $idx => $n) {
            if ($n['is_default'] === 1) {
                $defaultIdx = $idx;
                break;
            }
        }
        if ($defaultIdx < 0) {
            $defaultIdx = 0;
        }
        foreach ($normalized as $idx => $n) {
            $normalized[$idx]['is_default'] = $idx === $defaultIdx ? 1 : 0;
        }

        $newNames = array_map(static function ($x) { return $x['name']; }, $normalized);
        foreach ($oldMap as $oldName => $oldId) {
            if (!in_array($oldName, $newNames, true)) {
                Database::execute("DELETE FROM `{$prefix}goods_spec_combo` WHERE `spec_id` = ?", [$oldId]);
                Database::execute("DELETE FROM `{$prefix}goods_price_level` WHERE `spec_id` = ?", [$oldId]);
                Database::execute("DELETE FROM `{$prefix}goods_price_user` WHERE `spec_id` = ?", [$oldId]);
                Database::execute("DELETE FROM `{$prefix}goods_spec` WHERE `id` = ?", [$oldId]);
            }
        }

        $specIdMap = [];
        Database::execute("UPDATE `{$prefix}goods_spec` SET `is_default` = 0 WHERE `goods_id` = ?", [$goodsId]);
        foreach ($normalized as $i => $row) {
            $payload = [
                'spec_no'     => $row['spec_no'],
                'price'       => $row['price'],
                'cost_price'  => $row['cost_price'],
                'market_price'=> $row['market_price'],
                'stock'       => $row['stock'],
                'tags'        => $row['tags'],
                'configs'     => $row['configs'],
                'min_buy'     => $row['min_buy'],
                'max_buy'     => $row['max_buy'],
                'sort'        => $row['sort'],
                'is_default'  => $row['is_default'],
                'status'      => 1,
            ];
            if (isset($oldMap[$row['name']])) {
                $specId = (int) $oldMap[$row['name']];
                Database::update('goods_spec', $payload, $specId);
            } else {
                $specId = (int) Database::insert('goods_spec', array_merge($payload, [
                    'goods_id' => $goodsId,
                    'name'     => $row['name'],
                ]));
            }
            $specIdMap[$i] = $specId;
        }

        Database::execute("DELETE FROM `{$prefix}goods_spec_combo` WHERE `goods_id` = ?", [$goodsId]);
        Database::execute("DELETE FROM `{$prefix}goods_spec_value` WHERE `goods_id` = ?", [$goodsId]);
        Database::execute("DELETE FROM `{$prefix}goods_spec_dim` WHERE `goods_id` = ?", [$goodsId]);

        $specDimNames = $specDimNameRaw !== '' ? array_map('trim', explode('/', $specDimNameRaw)) : [];
        $dimCounts = [];
        foreach ($normalized as $row) {
            $parts = array_values(array_filter(array_map('trim', explode('/', $row['name'])), 'strlen'));
            $dimCounts[] = count($parts);
        }
        $dimCount = $dimCounts !== [] ? (int) ($dimCounts[0] ?? 1) : 1;

        if ($dimCount <= 1) {
            if ($normalized !== []) {
                Database::insert('goods_spec_dim', [
                    'goods_id' => $goodsId,
                    'name'     => $specDimNames[0] ?? '规格',
                    'sort'     => 0,
                ]);
            }
            return;
        }

        $dimValues = [];
        $partsByIdx = [];
        foreach ($normalized as $idx => $row) {
            $parts = array_values(array_filter(array_map('trim', explode('/', $row['name'])), 'strlen'));
            $partsByIdx[$idx] = $parts;
            foreach ($parts as $dIdx => $val) {
                if (!isset($dimValues[$dIdx])) {
                    $dimValues[$dIdx] = [];
                }
                if (!in_array($val, $dimValues[$dIdx], true)) {
                    $dimValues[$dIdx][] = $val;
                }
            }
        }

        $valueIdMap = [];
        for ($i = 0; $i < $dimCount; $i++) {
            $dimId = (int) Database::insert('goods_spec_dim', [
                'goods_id' => $goodsId,
                'name'     => $specDimNames[$i] ?? ('规格' . ($i + 1)),
                'sort'     => $i,
            ]);
            foreach ($dimValues[$i] ?? [] as $valSort => $valName) {
                $valId = (int) Database::insert('goods_spec_value', [
                    'dim_id'   => $dimId,
                    'goods_id' => $goodsId,
                    'name'     => $valName,
                    'sort'     => (int) $valSort,
                ]);
                $valueIdMap[$i . '|' . $valName] = $valId;
            }
        }

        foreach ($normalized as $idx => $row) {
            $parts = $partsByIdx[$idx] ?? [];
            $valueIds = [];
            foreach ($parts as $dIdx => $val) {
                $valueIds[] = (int) ($valueIdMap[$dIdx . '|' . $val] ?? 0);
            }
            $valueIds = array_values(array_filter($valueIds));
            if ($valueIds === []) {
                continue;
            }
            Database::insert('goods_spec_combo', [
                'goods_id'    => $goodsId,
                'spec_id'     => (int) ($specIdMap[$idx] ?? 0),
                'combo_hash'  => md5(implode('|', $valueIds)),
                'combo_text'  => $row['name'],
                'value_ids'   => json_encode($valueIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    /** @param mixed $v 上游 JSON 可能二次编码为字符串 */
    private static function jsonDecodeFlexible($v)
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v)) {
            return null;
        }
        $s = trim($v);
        if ($s === '') {
            return null;
        }
        $decoded = json_decode($s, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        if (is_string($decoded)) {
            $decoded2 = json_decode($decoded, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded2 !== null) {
                return $decoded2;
            }
        }
        return $decoded;
    }

    /** @param mixed $v @return array<string, mixed> */
    private static function jsonDecodeAssoc($v): array
    {
        $x = self::jsonDecodeFlexible($v);
        return is_array($x) ? $x : [];
    }

    /** @param mixed $v @return list<string> */
    private static function jsonDecodeStringList($v): array
    {
        $x = self::jsonDecodeFlexible($v);
        if ($x === null) {
            return [];
        }
        if (is_string($x)) {
            $t = trim($x);
            return $t !== '' ? [$t] : [];
        }
        if (!is_array($x)) {
            return [];
        }
        $out = [];
        foreach ($x as $e) {
            if (is_string($e) || is_numeric($e)) {
                $t = trim((string) $e);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
        return $out;
    }

    /** 与后台 goods_edit 的 extra_fields 行结构一致（数组的数组） */
    private static function decodeExtraFieldsPayload($v): array
    {
        $x = self::jsonDecodeFlexible($v);
        if (!is_array($x)) {
            return [];
        }
        $out = [];
        foreach ($x as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $relativeOrAbsoluteUrls
     * @return list<string>
     */
    private static function buildCoverImagesList(string $baseUrl, array $relativeOrAbsoluteUrls, string $imageMode): array
    {
        $relativeOrAbsoluteUrls = array_slice($relativeOrAbsoluteUrls, 0, 20);
        $seen = [];
        $out = [];
        foreach ($relativeOrAbsoluteUrls as $u) {
            $u = trim((string) $u);
            if ($u === '' || isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;
            $one = self::buildCoverImages($baseUrl, $u, $imageMode);
            foreach ($one as $path) {
                $path = trim((string) $path);
                if ($path !== '' && !isset($seen['@' . $path])) {
                    $seen['@' . $path] = true;
                    $out[] = $path;
                }
            }
        }
        return $out;
    }

    /**
     * @return list<string> 封面 URL 或本站路径，JSON 数组一项
     */
    private static function buildCoverImages(string $baseUrl, string $coverRaw, string $imageMode): array
    {
        if ($coverRaw === '') {
            return [];
        }
        if ($imageMode === 'remote') {
            return [self::absoluteUrl($baseUrl, $coverRaw)];
        }
        $saved = self::downloadCoverToUploads(self::absoluteUrl($baseUrl, $coverRaw));
        return $saved !== '' ? [$saved] : [self::absoluteUrl($baseUrl, $coverRaw)];
    }

    private static function absoluteUrl(string $baseUrl, string $pathOrUrl): string
    {
        $pathOrUrl = trim($pathOrUrl);
        if ($pathOrUrl === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return $pathOrUrl;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($pathOrUrl, '/');
    }

    private static function downloadCoverToUploads(string $absoluteUrl): string
    {
        if ($absoluteUrl === '' || !preg_match('#^https?://#i', $absoluteUrl)) {
            return '';
        }
        $bin = self::httpGetBinary($absoluteUrl);
        if ($bin === '' || $bin === null) {
            return '';
        }
        $ext = 'jpg';
        if (preg_match('#\.(jpe?g|png|gif|webp)(?:\?|$)#i', $absoluteUrl, $m)) {
            $ext = strtolower($m[1]);
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
        }
        $dateDir = date('Ymd');
        $dir = EM_ROOT . '/content/uploads/emshop_import/' . $dateDir;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return '';
        }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $rel = '/content/uploads/emshop_import/' . $dateDir . '/' . $name;
        $full = EM_ROOT . $rel;
        if (@file_put_contents($full, $bin) === false) {
            return '';
        }
        $size = @filesize($full) ?: 0;
        try {
            $att = new AttachmentModel();
            $att->insert([
                'user_id'    => (int) ($GLOBALS['adminUser']['id'] ?? 0),
                'file_name'  => basename(parse_url($absoluteUrl, PHP_URL_PATH) ?: 'cover.' . $ext),
                'file_path'  => $rel,
                'file_url'   => $rel,
                'file_size'  => (int) $size,
                'file_ext'   => $ext,
                'mime_type'  => 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext),
                'md5'        => md5($bin),
                'driver'     => 'local',
                'context'    => 'product',
                'context_id' => null,
            ]);
        } catch (Throwable $e) {
            // 附件记录失败不影响商品主图路径
        }
        return $rel;
    }

    private static function httpGetBinary(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 20],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $out = @file_get_contents($url, false, $ctx);
            return is_string($out) ? $out : null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || !is_string($out)) {
            return null;
        }
        return $out;
    }
}
