<?php

declare(strict_types=1);

/**
 * 购物车模型。
 *
 * 支持两种所有者：
 *   - 登录用户：user_id > 0
 *   - 游客：guest_token（来自 Cookie，由 GuestToken 管理；与订单表 guest_token 一致）
 */
class CartModel
{
    private string $table;

    public function __construct()
    {
        $this->table = Database::prefix() . 'cart';
    }

    /**
     * 根据用户身份返回 WHERE 条件。
     * 登录用户按 user_id，游客按 guest_token。
     *
     * @return array{sql: string, params: array}
     */
    private function ownerWhere(int $userId, string $guestToken): array
    {
        if ($userId > 0) {
            return ['sql' => 'user_id = ?', 'params' => [$userId]];
        }
        return ['sql' => 'guest_token = ?', 'params' => [$guestToken]];
    }

    /**
     * 添加商品到购物车（已存在则累加数量）。
     */
    public function addItem(int $userId, string $guestToken, int $goodsId, int $specId, int $quantity = 1): bool
    {
        $owner = $this->ownerWhere($userId, $guestToken);
        $sql = "SELECT id, quantity FROM {$this->table} WHERE {$owner['sql']} AND goods_id = ? AND spec_id = ? LIMIT 1";
        $params = array_merge($owner['params'], [$goodsId, $specId]);
        $existing = Database::fetchOne($sql, $params);

        if ($existing) {
            $newQty = (int) $existing['quantity'] + $quantity;
            $sql = "UPDATE {$this->table} SET quantity = ? WHERE id = ?";
            return Database::execute($sql, [$newQty, (int) $existing['id']]) > 0;
        }

        $sql = "INSERT INTO {$this->table} (user_id, guest_token, goods_id, spec_id, quantity) VALUES (?, ?, ?, ?, ?)";
        return Database::execute($sql, [$userId, $guestToken ?: null, $goodsId, $specId, $quantity]) > 0;
    }

    /**
     * 更新购物车项数量；数量 ≤ 0 时删除该项。
     */
    public function updateQuantity(int $id, int $quantity, int $userId, string $guestToken): bool
    {
        $owner = $this->ownerWhere($userId, $guestToken);
        if ($quantity <= 0) {
            return $this->removeItem($id, $userId, $guestToken);
        }
        $sql = "UPDATE {$this->table} SET quantity = ? WHERE id = ? AND {$owner['sql']}";
        return Database::execute($sql, array_merge([$quantity, $id], $owner['params'])) > 0;
    }

    /**
     * 移除购物车项。
     */
    public function removeItem(int $id, int $userId, string $guestToken): bool
    {
        $owner = $this->ownerWhere($userId, $guestToken);
        $sql = "DELETE FROM {$this->table} WHERE id = ? AND {$owner['sql']}";
        return Database::execute($sql, array_merge([$id], $owner['params'])) > 0;
    }

    /**
     * 清空购物车。
     */
    public function clearCart(int $userId, string $guestToken): bool
    {
        $owner = $this->ownerWhere($userId, $guestToken);
        $sql = "DELETE FROM {$this->table} WHERE {$owner['sql']}";
        return Database::execute($sql, $owner['params']) >= 0;
    }

    /**
     * 获取购物车项数（不同商品/规格的条目数）。
     */
    public function getCount(int $userId, string $guestToken): int
    {
        $owner = $this->ownerWhere($userId, $guestToken);
        $sql = "SELECT COUNT(*) AS cnt FROM {$this->table} WHERE {$owner['sql']}";
        $row = Database::fetchOne($sql, $owner['params']);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * 获取购物车列表（含商品快照、规格信息、商品 configs 解码后 JSON）。
     *
     * 返回项额外字段：
     *   - configs    商品 configs 解码后的数组（含 extra_fields 等）
     *   - subtotal   实付小计（单价 × 数量）
     *   - is_valid   商品当前是否可购买
     */
    public function getItems(int $userId, string $guestToken): array
    {
        $owner = $this->ownerWhere($userId, $guestToken);
        $prefix = Database::prefix();

        // 商户上下文：左联 em_goods_merchant_ref，用于判断归属 + 价格重写
        $merchantId = class_exists('MerchantContext') ? MerchantContext::currentId() : 0;
        $merchantOwnerId = $merchantId > 0 ? MerchantContext::currentOwnerId() : 0;
        $mgrJoin = '';
        $mgrCols = '';
        $params = $owner['params'];
        if ($merchantId > 0) {
            $mgrJoin = " LEFT JOIN {$prefix}goods_merchant_ref mgr ON mgr.goods_id = g.id AND mgr.merchant_id = ?";
            $mgrCols = ', mgr.markup_rate AS mgr_markup_rate, mgr.is_on_sale AS mgr_is_on_sale, g.owner_id AS g_owner_id';
            array_unshift($params, $merchantId);
        }

        $sql = "SELECT c.id, c.goods_id, c.spec_id, c.quantity,
                    g.title AS goods_name, g.cover_images, g.unit, g.is_on_sale, g.status AS goods_status,
                    g.deleted_at, g.configs AS goods_configs, g.goods_type AS goods_type,
                    s.name AS spec_name, s.price AS spec_price, s.market_price AS spec_market_price,
                    s.stock AS spec_stock, s.min_buy, s.max_buy, s.status AS spec_status{$mgrCols}
                FROM {$this->table} c
                LEFT JOIN {$prefix}goods g ON c.goods_id = g.id{$mgrJoin}
                LEFT JOIN {$prefix}goods_spec s ON c.spec_id = s.id
                WHERE {$owner['sql']}
                ORDER BY c.updated_at DESC";

        $rows = Database::query($sql, $params);
        $items = [];

        // 价格 factor：买家折扣始终生效；商户站 + 引用商品再额外乘 (1+markup)
        // 自建商品 / 主站作用域的商品都只乘买家折扣
        $buyerDiscount = GoodsModel::resolveBuyerDiscountRate();
        $defaultMarkup = 0;
        if ($merchantId > 0) {
            $mRow = Database::fetchOne(
                "SELECT `default_markup_rate` FROM {$prefix}merchant WHERE `id` = ? LIMIT 1",
                [$merchantId]
            );
            $defaultMarkup = (int) ($mRow['default_markup_rate'] ?? 1000);
        }

        foreach ($rows as $row) {
            $covers = json_decode($row['cover_images'] ?? '[]', true) ?: [];
            $specPriceRaw = (int) ($row['spec_price'] ?? 0);
            $specMarketRaw = (int) ($row['spec_market_price'] ?? 0);

            // 商户上下文下判断归属 + 价格重写
            $belongsToCurrentShop = true;
            $shopBadge = '';
            $factor = $buyerDiscount;

            if ($merchantId > 0) {
                $goodsOwnerId = (int) ($row['g_owner_id'] ?? 0);
                // 主站货默认可见：ref 行不存在（mgr_is_on_sale === null）或显式 =1 都算上架
                $mgrOnSale = $row['mgr_is_on_sale'] ?? null;
                $isRef = ($goodsOwnerId === 0) && ($mgrOnSale === null || (int) $mgrOnSale === 1);
                // merchantOwnerId > 0 防御：商户 user_id 异常为 0 时不能把 owner_id=0 的主站商品
                // 当成"自建"漏出，绕过 mgr 下架过滤
                $isSelf = ($merchantOwnerId > 0) && ($goodsOwnerId === $merchantOwnerId);
                $belongsToCurrentShop = $isRef || $isSelf;

                if ($isRef) {
                    // 引用商品：先 markup 再买家折扣
                    $markup = isset($row['mgr_markup_rate']) && $row['mgr_markup_rate'] !== null
                        ? (int) $row['mgr_markup_rate']
                        : $defaultMarkup;
                    $factor = (1 + $markup / 10000) * $buyerDiscount;
                }
                // 不属于本店：保持原价展示，加标记让 UI 提示 / 结算时过滤
                if (!$belongsToCurrentShop) {
                    $shopBadge = ($goodsOwnerId === 0) ? '主站商品' : '其它店铺';
                    $factor = 1.0; // 跨店商品按原价展示，不加任何 factor
                }
            }

            if ($factor !== 1.0) {
                $specPriceRaw = (int) round($specPriceRaw * $factor);
                $specMarketRaw = (int) round($specMarketRaw * $factor);
            }

            $price = $specPriceRaw > 0 ? (float) GoodsModel::moneyFromDb($specPriceRaw) : 0;
            $marketPrice = $specMarketRaw > 0 ? (float) GoodsModel::moneyFromDb($specMarketRaw) : null;
            $configs = json_decode((string) ($row['goods_configs'] ?? '{}'), true) ?: [];

            $isValid = ($row['goods_status'] ?? 0) == 1
                && ($row['is_on_sale'] ?? 0) == 1
                && $row['deleted_at'] === null
                && ($row['spec_status'] ?? 1) == 1;

            $items[] = [
                'id'             => (int) $row['id'],
                'goods_id'       => (int) $row['goods_id'],
                'spec_id'        => (int) $row['spec_id'],
                'quantity'       => (int) $row['quantity'],
                'goods_type'     => (string) ($row['goods_type'] ?? ''),
                'goods_name'     => $row['goods_name'] ?? '商品已删除',
                'image'          => $covers[0] ?? '',
                'unit'           => $row['unit'] ?: '件',
                'spec_name'      => $row['spec_name'] ?? '',
                'price'          => $price,
                'market_price'   => ($marketPrice && $marketPrice > $price) ? $marketPrice : null,
                'stock'          => (int) ($row['spec_stock'] ?? 0),
                'min_buy'        => (int) ($row['min_buy'] ?? 1),
                'max_buy'        => (int) ($row['max_buy'] ?? 0),
                'subtotal'       => $price * (int) $row['quantity'],
                'is_valid'       => $isValid,
                'configs'        => $configs,
                // 跨店标记：UI 可按此置灰 / 禁用勾选；结算时后端也会再校验
                'belongs_to_current_shop' => $belongsToCurrentShop,
                'shop_badge'     => $shopBadge,
            ];
        }

        return $items;
    }

    /**
     * 登录时把游客购物车合并到用户购物车。
     *
     * 有同商品同规格则累加数量，否则直接转移归属。
     */
    public function mergeGuestToUser(string $guestToken, int $userId): void
    {
        if ($guestToken === '' || $userId <= 0) {
            return;
        }

        $sql = "SELECT goods_id, spec_id, quantity FROM {$this->table} WHERE guest_token = ?";
        $guestItems = Database::query($sql, [$guestToken]);

        foreach ($guestItems as $item) {
            $sql = "SELECT id, quantity FROM {$this->table} WHERE user_id = ? AND goods_id = ? AND spec_id = ? LIMIT 1";
            $existing = Database::fetchOne($sql, [$userId, $item['goods_id'], $item['spec_id']]);

            if ($existing) {
                $newQty = (int) $existing['quantity'] + (int) $item['quantity'];
                Database::execute("UPDATE {$this->table} SET quantity = ? WHERE id = ?", [$newQty, (int) $existing['id']]);
            } else {
                Database::execute(
                    "INSERT INTO {$this->table} (user_id, guest_token, goods_id, spec_id, quantity) VALUES (?, NULL, ?, ?, ?)",
                    [$userId, $item['goods_id'], $item['spec_id'], $item['quantity']]
                );
            }
        }

        Database::execute("DELETE FROM {$this->table} WHERE guest_token = ?", [$guestToken]);
    }
}
