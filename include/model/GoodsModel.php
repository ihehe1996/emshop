<?php
/**
 * 商品模型
 *
 * 金额字段统一使用 BIGINT 存储（实际金额 × MONEY_SCALE），读取时还原
 *
 * @package EM\Core\Model
 */

if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

class GoodsModel
{
    /** 金额放大倍数：1,000,000 */
    const MONEY_SCALE = 1000000;

    /**
     * 将前端金额转为数据库存储值（× MONEY_SCALE）
     *
     * @param mixed $amount 前端金额（如 0.01）
     * @return int 数据库存储值（如 10000）
     */
    public static function moneyToDb($amount): int
    {
        return (int)round((float)$amount * self::MONEY_SCALE);
    }

    /**
     * 将数据库存储值还原为前端金额（÷ MONEY_SCALE）
     *
     * @param mixed $dbValue 数据库存储值
     * @return string 保留两位小数的金额字符串
     */
    public static function moneyFromDb($dbValue): ?string
    {
        if ($dbValue === null) return null;
        return number_format((int)$dbValue / self::MONEY_SCALE, 2, '.', '');
    }

    /**
     * 批量转换数组中的金额字段（DB → 前端）
     *
     * @param array &$row 数据行
     * @param array $fields 需要转换的字段名列表
     */
    private static function convertMoneyFields(array &$row, array $fields): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = self::moneyFromDb($row[$field]);
            }
        }
    }

    /**
     * 生成商品唯一标识 code
     * 格式：前缀 + 日期 + 6位序号，如 G202604090001
     */
    public static function generateCode($prefix = 'G')
    {
        $date = date('Ymd');
        $like = $prefix . $date . '%';

        $last = Database::query(
            "SELECT code FROM " . Database::prefix() . "goods WHERE code LIKE ? ORDER BY id DESC LIMIT 1",
            [$like]
        );

        if (empty($last)) {
            $seq = 1;
        } else {
            $lastCode = $last[0]['code'];
            $seq = (int)substr($lastCode, -6) + 1;
        }

        return $prefix . $date . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 获取商品列表（金额字段自动转换为前端展示值）
     */
    public static function getList($where = [], $page = 1, $limit = 20, $orderBy = 'id DESC')
    {
        $conditions = [];
        $params = [];

        if (isset($where['owner_id']) && $where['owner_id'] !== '') {
            $conditions[] = 'g.owner_id = ?';
            $params[] = $where['owner_id'];
        }

        if (isset($where['status']) && $where['status'] !== '') {
            $conditions[] = 'g.status = ?';
            $params[] = $where['status'];
        }

        if (isset($where['is_on_sale']) && $where['is_on_sale'] !== '') {
            $conditions[] = 'g.is_on_sale = ?';
            $params[] = $where['is_on_sale'];
        }

        if (!empty($where['category_id'])) {
            $conditions[] = 'g.category_id = ?';
            $params[] = $where['category_id'];
        }

        if (!empty($where['keyword'])) {
            $conditions[] = '(g.title LIKE ? OR g.code LIKE ? OR g.intro LIKE ?)';
            $keyword = '%' . $where['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
        }

        if (!empty($where['goods_type'])) {
            $conditions[] = 'g.goods_type = ?';
            $params[] = $where['goods_type'];
        }

        if (isset($where['is_recommended']) && $where['is_recommended'] !== '') {
            $conditions[] = 'g.is_recommended = ?';
            $params[] = (int) $where['is_recommended'];
        }

        // 默认排除已软删除的商品
        $conditions[] = 'g.deleted_at IS NULL';

        $whereSql = 'WHERE ' . implode(' AND ', $conditions);

        $total = Database::query(
            "SELECT COUNT(*) as count FROM " . Database::prefix() . "goods g
            LEFT JOIN " . Database::prefix() . "goods_category c ON g.category_id = c.id
            $whereSql",
            $params
        );
        $totalCount = (int)($total[0]['count'] ?? 0);

        $offset = ($page - 1) * $limit;
        $sql = "SELECT g.*,
            c.name as category_name,
            (SELECT COALESCE(SUM(sold_count), 0) FROM " . Database::prefix() . "goods_spec WHERE goods_id = g.id) as total_sales
            FROM " . Database::prefix() . "goods g
            LEFT JOIN " . Database::prefix() . "goods_category c ON g.category_id = c.id
            $whereSql ORDER BY $orderBy LIMIT $offset, $limit";
        $list = Database::query($sql, $params);

        // 转换金额字段（BIGINT → 前端展示值）
        foreach ($list as &$row) {
            self::convertMoneyFields($row, ['min_price', 'max_price']);
        }
        unset($row);

        return [
            'total' => $totalCount,
            'page' => $page,
            'limit' => $limit,
            'list' => $list,
        ];
    }

    /**
     * 获取商品详情（金额字段自动转换）。
     *
     * 商户上下文下（MerchantContext 激活）额外做两件事：
     *   1. 可见性过滤：不属于本店（既非自建、也非引用上架）→ 返回 null
     *   2. 价格重写：引用商品的 min_price / max_price 按店内售价（base × d_user × (1+markup)）
     *      同时把 _shop_markup_rate / _shop_price_factor 挂到返回行，供 getSpecsByGoodsId 沿用
     *
     * 主站上下文或 admin 调用路径（MerchantContext::currentId()=0）行为完全不变。
     */
    public static function getById($id)
    {
        $result = Database::query(
            "SELECT * FROM " . Database::prefix() . "goods WHERE id = ? LIMIT 1",
            [$id]
        );
        $row = $result[0] ?? null;
        if (!$row) return $row;

        $merchantId = class_exists('MerchantContext') ? MerchantContext::currentId() : 0;
        if ($merchantId > 0) {
            $ownerUserId = MerchantContext::currentOwnerId();
            $goodsOwner = (int) ($row['owner_id'] ?? 0);

            if ($goodsOwner === $ownerUserId) {
                // 自建商品 → 正常显示
                $row['_shop_markup_rate'] = 0;
                $row['_shop_price_factor'] = 1.0;
            } elseif ($goodsOwner === 0) {
                // 主站货：默认全部可见；商户覆盖行存在时才按覆盖来
                $ref = Database::fetchOne(
                    'SELECT `markup_rate`, `is_on_sale` FROM `' . Database::prefix() . 'goods_merchant_ref`
                      WHERE `merchant_id` = ? AND `goods_id` = ? LIMIT 1',
                    [$merchantId, (int) $row['id']]
                );
                $isOnSale = $ref ? (int) $ref['is_on_sale'] : 1;
                if ($isOnSale !== 1) {
                    return null; // 商户显式下架 → 本店不可见
                }
                // 加价率：覆盖行 > 商户默认值（em_merchant.default_markup_rate）> 0
                $markup = $ref ? (int) $ref['markup_rate'] : self::resolveMerchantDefaultMarkup($merchantId);
                $discount = self::resolveMerchantDiscount($ownerUserId);
                $factor = $discount * (1 + $markup / 10000);
                $row['_shop_markup_rate'] = $markup;
                $row['_shop_price_factor'] = $factor;
                // 重写缓存价格
                if (isset($row['min_price'])) {
                    $row['min_price'] = (int) round(((int) $row['min_price']) * $factor);
                }
                if (isset($row['max_price'])) {
                    $row['max_price'] = (int) round(((int) $row['max_price']) * $factor);
                }
            } else {
                // 其他商户的自建商品 → 不可见
                return null;
            }
        }

        self::convertMoneyFields($row, ['min_price', 'max_price']);
        return $row;
    }

    /**
     * 商户默认加价率（万分位）。没配 / 读不到 → 1000（10%，InstallService 的默认值）。
     * 同一请求内缓存。
     */
    private static function resolveMerchantDefaultMarkup(int $merchantId): int
    {
        static $cache = [];
        if (isset($cache[$merchantId])) return $cache[$merchantId];
        $row = Database::fetchOne(
            'SELECT `default_markup_rate` FROM `' . Database::prefix() . 'merchant` WHERE `id` = ? LIMIT 1',
            [$merchantId]
        );
        return $cache[$merchantId] = (int) ($row['default_markup_rate'] ?? 1000);
    }

    /**
     * 商户主用户等级折扣率（9.9 折 → 0.99）。
     * 同一请求内缓存。
     */
    private static function resolveMerchantDiscount(int $userId): float
    {
        static $cache = [];
        if (isset($cache[$userId])) return $cache[$userId];
        $row = Database::fetchOne(
            'SELECT ul.`discount` AS d
               FROM `' . Database::prefix() . 'user` u
          LEFT JOIN `' . Database::prefix() . 'user_levels` ul ON ul.`id` = u.`level_id` AND ul.`enabled` = \'y\'
              WHERE u.`id` = ? LIMIT 1',
            [$userId]
        );
        $raw = (int) ($row['d'] ?? 0);
        if ($raw <= 0) return $cache[$userId] = 1.0;
        $rate = ($raw / 1000000) / 10;
        if ($rate <= 0 || $rate > 1) $rate = 1.0;
        return $cache[$userId] = $rate;
    }

    /**
     * 根据商品编码获取商品
     */
    public static function getByCode($code)
    {
        $result = Database::query(
            "SELECT * FROM " . Database::prefix() . "goods WHERE code = ? LIMIT 1",
            [$code]
        );
        $row = $result[0] ?? null;
        if ($row) {
            self::convertMoneyFields($row, ['min_price', 'max_price']);
        }
        return $row;
    }

    /**
     * 创建商品
     */
    public static function create($data)
    {
        if (empty($data['code'])) {
            $data['code'] = self::generateCode();
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $id = Database::insert('goods', $data);
        if ($id) {
            doAction('goods_after_create', $id, $data);
        }
        return $id;
    }

    /**
     * 更新商品
     */
    public static function update($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $old = self::getById($id);
        $result = Database::update('goods', $data, $id);

        if ($result) {
            doAction('goods_after_update', $id, $old, $data);
            self::logChange($id, $old, $data);
        }

        return $result;
    }

    /**
     * 删除商品（逻辑删除）
     */
    public static function delete($id)
    {
        $result = Database::update('goods', [
            'status' => 0,
            'deleted_at' => date('Y-m-d H:i:s'),
        ], $id);
        if ($result) {
            doAction('goods_after_delete', $id);
        }
        return $result;
    }

    /**
     * 物理删除商品
     */
    public static function forceDelete($id)
    {
        $prefix = Database::prefix();

        // 先通知插件清理各自的关联数据（如卡密库存、物流信息等），
        // 此时规格数据尚存，插件可据此做清理
        doAction('goods_before_force_delete', $id);

        Database::execute(
            "DELETE FROM {$prefix}goods_price_level WHERE spec_id IN (SELECT id FROM {$prefix}goods_spec WHERE goods_id = ?)",
            [$id]
        );
        Database::execute(
            "DELETE FROM {$prefix}goods_price_user WHERE spec_id IN (SELECT id FROM {$prefix}goods_spec WHERE goods_id = ?)",
            [$id]
        );

        Database::execute("DELETE FROM {$prefix}goods_spec_combo WHERE goods_id = ?", [$id]);
        Database::execute("DELETE FROM {$prefix}goods_spec_value WHERE goods_id = ?", [$id]);
        Database::execute("DELETE FROM {$prefix}goods_spec_dim WHERE goods_id = ?", [$id]);
        Database::execute("DELETE FROM {$prefix}goods_spec WHERE goods_id = ?", [$id]);

        return Database::execute("DELETE FROM {$prefix}goods WHERE id = ?", [$id]);
    }

    /**
     * 设置/取消分类置顶
     */
    public static function setTopCategory($id, $value)
    {
        return (bool)Database::update('goods', ['is_top_category' => $value ? 1 : 0], $id);
    }

    /**
     * 设置/取消推荐商品
     */
    public static function setRecommended($id, $value)
    {
        return (bool)Database::update('goods', ['is_recommended' => $value ? 1 : 0], $id);
    }

    /**
     * 克隆商品
     */
    public static function clone($id)
    {
        $old = self::getById($id);
        if (!$old) {
            return false;
        }

        $prefix = Database::prefix();

        $newData = [];
        $skipFields = ['id', 'code', 'views_count', 'created_at', 'updated_at'];
        // min_price / max_price 已被 getById 转为前端值，克隆时需还原为 DB 值
        $moneyFields = ['min_price', 'max_price'];
        foreach ($old as $key => $value) {
            if (!in_array($key, $skipFields)) {
                if (in_array($key, $moneyFields)) {
                    $newData[$key] = self::moneyToDb($value);
                } else {
                    $newData[$key] = $value;
                }
            }
        }
        $newData['title'] = $old['title'] . '（克隆）';
        $newData['code'] = self::generateCode();
        $newData['views_count'] = 0;

        $newId = self::create($newData);
        if (!$newId) {
            return false;
        }

        // 复制规格（从数据库直接读取原始 BIGINT 值，不经过转换）
        $specIdMap = [];
        $rawSpecs = Database::query(
            "SELECT * FROM {$prefix}goods_spec WHERE goods_id = ? AND status = 1 ORDER BY sort ASC, id ASC",
            [$id]
        );
        foreach ($rawSpecs as $spec) {
            $oldSpecId = $spec['id'];
            $newSpec = [];
            $skipSpecFields = ['id', 'goods_id', 'sold_count', 'created_at', 'updated_at'];
            foreach ($spec as $key => $value) {
                if (!in_array($key, $skipSpecFields)) {
                    $newSpec[$key] = $value;
                }
            }
            $newSpec['goods_id'] = $newId;
            $newSpec['sold_count'] = 0;
            $newSpecId = Database::insert('goods_spec', $newSpec);
            $specIdMap[$oldSpecId] = $newSpecId;
        }

        // 复制多维规格数据
        $oldDims = Database::query(
            "SELECT * FROM {$prefix}goods_spec_dim WHERE goods_id = ? ORDER BY sort ASC",
            [$id]
        );
        $dimIdMap = [];
        $valueIdMap = [];

        foreach ($oldDims as $dim) {
            $newDimId = Database::insert('goods_spec_dim', [
                'goods_id' => $newId,
                'name' => $dim['name'],
                'sort' => $dim['sort'],
            ]);
            $dimIdMap[$dim['id']] = $newDimId;

            $oldValues = Database::query(
                "SELECT * FROM {$prefix}goods_spec_value WHERE dim_id = ? ORDER BY sort ASC",
                [$dim['id']]
            );
            foreach ($oldValues as $val) {
                $newValId = Database::insert('goods_spec_value', [
                    'dim_id' => $newDimId,
                    'goods_id' => $newId,
                    'name' => $val['name'],
                    'cover_image' => $val['cover_image'],
                    'sort' => $val['sort'],
                ]);
                $valueIdMap[$val['id']] = $newValId;
            }
        }

        // 复制 SKU 组合映射
        $oldCombos = Database::query(
            "SELECT * FROM {$prefix}goods_spec_combo WHERE goods_id = ? ORDER BY sort ASC",
            [$id]
        );
        foreach ($oldCombos as $combo) {
            $newSpecId = $specIdMap[$combo['spec_id']] ?? null;
            if (!$newSpecId) continue;

            $oldValueIds = json_decode($combo['value_ids'], true) ?: [];
            $newValueIds = [];
            foreach ($oldValueIds as $oldVid) {
                $newValueIds[] = $valueIdMap[$oldVid] ?? $oldVid;
            }

            $newComboHash = md5(implode('|', $newValueIds));

            Database::insert('goods_spec_combo', [
                'goods_id' => $newId,
                'spec_id' => $newSpecId,
                'combo_hash' => $newComboHash,
                'combo_text' => $combo['combo_text'],
                'value_ids' => json_encode($newValueIds),
                'sort' => $combo['sort'],
            ]);
        }

        doAction('goods_after_clone', $newId, $id);
        return $newId;
    }

    /**
     * 获取商品的所有规格（金额字段自动转换）。
     *
     * 商户上下文下对引用商品的价格按店内售价重写：
     *   price_raw       主站原价 × factor（下单时用，必须与详情页一致）
     *   price/cost_price/market_price   视图小数价
     * factor 来自 getById 返回的 _shop_price_factor；这里反查一次 goods 即可。
     *
     * 每条 spec 还会挂 `_shop_markup_rate` 供 OrderModel 反推拿货价。
     */
    public static function getSpecsByGoodsId($goodsId)
    {
        $specs = Database::query(
            "SELECT * FROM " . Database::prefix() . "goods_spec WHERE goods_id = ? AND status = 1 ORDER BY sort ASC, id ASC",
            [$goodsId]
        );
        if ($specs === []) return $specs;

        // 解析商户上下文的价格重写参数
        $factor = 1.0;
        $markup = 0;
        $merchantId = class_exists('MerchantContext') ? MerchantContext::currentId() : 0;
        if ($merchantId > 0) {
            $goods = Database::fetchOne(
                'SELECT `owner_id` FROM `' . Database::prefix() . 'goods` WHERE `id` = ? LIMIT 1',
                [(int) $goodsId]
            );
            if ($goods !== null && (int) $goods['owner_id'] === 0) {
                // 主站商品：折扣始终生效；加价率从覆盖行取，没覆盖行走商户默认值
                $ref = Database::fetchOne(
                    'SELECT `markup_rate` FROM `' . Database::prefix() . 'goods_merchant_ref`
                      WHERE `merchant_id` = ? AND `goods_id` = ? LIMIT 1',
                    [$merchantId, (int) $goodsId]
                );
                $markup = $ref !== null ? (int) $ref['markup_rate'] : self::resolveMerchantDefaultMarkup($merchantId);
                $ownerUserId = MerchantContext::currentOwnerId();
                $discount = self::resolveMerchantDiscount($ownerUserId);
                $factor = $discount * (1 + $markup / 10000);
            }
        }

        foreach ($specs as &$spec) {
            // 保留原始价格（BIGINT），供订单等内部使用
            $spec['price_raw'] = (int) $spec['price'];
            // 商户上下文下重写为店内价
            if ($factor !== 1.0) {
                $spec['price_raw'] = (int) round($spec['price_raw'] * $factor);
                $spec['price'] = (int) round(((int) $spec['price']) * $factor);
                if (isset($spec['cost_price'])) {
                    $spec['cost_price'] = (int) round(((int) $spec['cost_price']) * $factor);
                }
                if (isset($spec['market_price'])) {
                    $spec['market_price'] = (int) round(((int) $spec['market_price']) * $factor);
                }
            }
            // 快照信息留给 OrderModel 反推拿货价 / fee
            $spec['_shop_markup_rate'] = $markup;
            self::convertMoneyFields($spec, ['price', 'cost_price', 'market_price']);
        }
        unset($spec);
        return $specs;
    }

    /**
     * 获取商品的最小/最大价格和总库存（返回 DB 原始值，供内部缓存用）
     */
    public static function getPriceAndStockRange($goodsId)
    {
        $prefix = Database::prefix();

        $result = Database::query(
            "SELECT MIN(price) as min_price, MAX(price) as max_price,
                    SUM(stock) as total_stock
             FROM {$prefix}goods_spec WHERE goods_id = ? AND status = 1",
            [$goodsId]
        );

        $row = $result[0] ?? [];
        $totalStock = max(0, (int) ($row['total_stock'] ?? 0));

        return [
            'min_price' => (int)($row['min_price'] ?? 0),
            'max_price' => (int)($row['max_price'] ?? 0),
            'total_stock' => $totalStock,
        ];
    }

    /**
     * 更新商品的价格和库存缓存字段（写入 DB 原始值）
     */
    public static function updatePriceStockCache($goodsId)
    {
        $range = self::getPriceAndStockRange($goodsId);
        return Database::update('goods', [
            'min_price' => $range['min_price'],
            'max_price' => $range['max_price'],
            'total_stock' => $range['total_stock'],
        ], $goodsId);
    }

    /**
     * 递增规格已售数量
     *
     * @param int $specId 规格ID
     * @param int $quantity 售出数量（默认1）
     * @return bool
     */
    public static function incrementSoldCount(int $specId, int $quantity = 1): bool
    {
        $prefix = Database::prefix();
        return (bool)Database::execute(
            "UPDATE {$prefix}goods_spec SET sold_count = sold_count + ? WHERE id = ?",
            [$quantity, $specId]
        );
    }

    /**
     * 记录商品变更日志
     *
     * 注意：$old 来自 getById()，金额字段已转换为展示值；
     * $new 是写入数据库的原始值。比较前需将金额字段统一。
     */
    private static function logChange($goodsId, $old, $new)
    {
        // 金额字段列表：$old 中为展示值，$new 中为 DB 原始值
        $moneyFields = ['min_price', 'max_price'];

        $changed = [];
        foreach ($new as $key => $value) {
            if (!isset($old[$key])) continue;
            $oldVal = $old[$key];

            // 金额字段：将 $old 的展示值转回 DB 值再比较
            if (in_array($key, $moneyFields, true)) {
                $oldVal = self::moneyToDb($oldVal);
            }

            if ($oldVal != $value) {
                $changed[] = [
                    'field' => $key,
                    'old' => $old[$key],
                    'new' => in_array($key, $moneyFields, true) ? self::moneyFromDb($value) : $value,
                ];
            }
        }

        if (empty($changed)) {
            return;
        }

        // 写入系统日志（字段匹配 em_system_log 表结构）
        $admin = $_SESSION['em_admin_auth'] ?? [];
        Database::insert('system_log', [
            'level' => 'info',
            'type' => 'admin_operation',
            'action' => 'goods_update',
            'message' => '商品#' . $goodsId . ' 字段变更',
            'detail' => json_encode($changed, JSON_UNESCAPED_UNICODE),
            'user_id' => $admin['id'] ?? 0,
            'username' => $admin['username'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
