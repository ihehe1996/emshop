<?php

declare(strict_types=1);

/**
 * 前台控制器基类。
 *
 * 所有前台控制器继承此类，获取视图实例和调度器引用。
 */
abstract class BaseController
{
    /** @var View */
    protected View $view;

    /** @var Dispatcher */
    protected Dispatcher $dispatcher;

    /** @var string 当前控制器名（用于模板导航高亮等） */
    protected string $controllerName;

    /**
     * @param View       $view           视图实例
     * @param Dispatcher $dispatcher    调度器引用
     * @param string     $controllerName 当前控制器名
     */
    public function __construct(View $view, Dispatcher $dispatcher, string $controllerName)
    {
        $this->view = $view;
        $this->dispatcher = $dispatcher;
        $this->controllerName = $controllerName;
        // $_controller 和 $_nav 已由 Dispatcher 在 $this->view->assign() 中统一注入，
        // 此处无需重复设置。
    }

    /**
     * 获取 URL 路径参数。
     *
     * @param string|int $key
     * @param mixed $default
     * @return mixed
     */
    protected function getArg($key, $default = null)
    {
        return $this->dispatcher->getArg($key, $default);
    }

    /**
     * 获取所有路径参数。
     *
     * @return array<string, mixed>
     */
    protected function getPathArgs(): array
    {
        return $this->dispatcher->getPathArgs();
    }

    /**
     * 获取当前控制器名（URL 中的 c 参数）。
     */
    protected function getControllerName(): string
    {
        return $this->dispatcher->getController();
    }

    /**
     * 获取当前动作名。
     */
    protected function getActionName(): string
    {
        return $this->dispatcher->getAction();
    }

    /**
     * 获取站点 URL。
     */
    protected function getSiteUrl(): string
    {
        return $this->view->getData()['site_url'] ?? '';
    }

    /**
     * 获取商品列表页 URL。
     */
    protected function urlGoodsList(array $params = []): string
    {
        return $this->buildUrl('goods_list', $params);
    }

    /**
     * 获取商品详情页 URL。
     */
    protected function urlGoods(int $id): string
    {
        return '?c=goods&id=' . $id;
    }

    /**
     * 获取文章列表页 URL。
     */
    protected function urlBlogList(array $params = []): string
    {
        return $this->buildUrl('blog_list', $params);
    }

    /**
     * 获取文章详情页 URL。
     */
    protected function urlBlog(int $id): string
    {
        return '?c=blog&id=' . $id;
    }

    /**
     * 获取搜索页 URL。
     */
    protected function urlSearch(string $keyword = ''): string
    {
        if ($keyword !== '') {
            return '?c=search&q=' . urlencode($keyword);
        }
        return '?c=search';
    }

    /**
     * 构建带参数的 URL。
     */
    protected function buildUrl(string $c, array $params = []): string
    {
        $query = 'c=' . urlencode($c);
        foreach ($params as $k => $v) {
            $query .= '&' . urlencode($k) . '=' . urlencode((string) $v);
        }
        return '?' . $query;
    }

    // ============================================================
    // 前台公共查询方法
    // ============================================================

    /**
     * 查询前台商品列表（已上架、未删除，字段映射为模板格式）。
     *
     * @param array  $where 筛选：category_id / is_recommended / keyword
     * @param int    $limit 条数
     * @param string $orderBy 排序
     * @return array<array{id:int, name:string, price:float, original_price:float|null}>
     */
    protected function queryGoodsList(array $where = [], int $limit = 8, string $orderBy = 'g.sort ASC, g.id DESC'): array
    {
        $prefix = Database::prefix();
        $conditions = ['g.status = 1', 'g.is_on_sale = 1', 'g.deleted_at IS NULL'];
        // 分开收集：JOIN 里的 ? 要绑的参数放 $joinParams，WHERE 里的放 $whereParams；
        // 最终 SQL 里 JOIN 排在 WHERE 之前，参数也按 joinParams ++ whereParams 顺序拼接。
        $joinParams = [];
        $whereParams = [];
        $join = '';

        if (!empty($where['category_ids']) && is_array($where['category_ids'])) {
            $placeholders = implode(',', array_fill(0, count($where['category_ids']), '?'));
            $conditions[] = "g.category_id IN ({$placeholders})";
            foreach ($where['category_ids'] as $cid) {
                $whereParams[] = (int) $cid;
            }
        } elseif (!empty($where['category_id'])) {
            $conditions[] = 'g.category_id = ?';
            $whereParams[] = (int) $where['category_id'];
        }
        if (!empty($where['is_recommended'])) {
            // 商户上下文下用 COALESCE（商户覆盖优先，否则跟随主站）
            $conditions[] = (class_exists('MerchantContext') && MerchantContext::currentId() > 0)
                ? 'COALESCE(mgr.is_recommended, g.is_recommended) = 1'
                : 'g.is_recommended = 1';
        }
        if (!empty($where['keyword'])) {
            $conditions[] = '(g.title LIKE ? OR g.intro LIKE ?)';
            $kw = '%' . $where['keyword'] . '%';
            $whereParams[] = $kw;
            $whereParams[] = $kw;
        }
        // 标签筛选（INNER JOIN，?要绑 tag_id）
        if (!empty($where['tag_id'])) {
            $join = " INNER JOIN {$prefix}goods_tag_relation gtr ON gtr.goods_id = g.id AND gtr.tag_id = ?";
            $joinParams[] = (int) $where['tag_id'];
        }

        // 商户上下文过滤：只看本店可见的商品（引用 + 自建）并附带 markup_rate 供价格重写
        [$selectExtra, $joinMerchant] = $this->applyMerchantScope($conditions, $whereParams, $joinParams);
        $join .= $joinMerchant;

        $params = array_merge($joinParams, $whereParams);
        $whereSql = implode(' AND ', $conditions);
        $sql = "SELECT g.id, g.title, g.cover_images, g.min_price, g.max_price,
                    g.total_stock, g.goods_type, g.plugin_data, g.owner_id" . $selectExtra . ",
                    (SELECT market_price FROM {$prefix}goods_spec
                     WHERE goods_id = g.id AND is_default = 1 AND status = 1 LIMIT 1) as default_market_price,
                    (SELECT COALESCE(SUM(sold_count), 0) FROM {$prefix}goods_spec
                     WHERE goods_id = g.id AND status = 1) as total_sold
                FROM {$prefix}goods g{$join}
                WHERE {$whereSql}
                ORDER BY {$orderBy}
                LIMIT {$limit}";

        $rows = Database::query($sql, $params);
        $list = [];
        foreach ($rows as $row) {
            $this->rewriteMerchantPrice($row);

            $minPrice = (float) GoodsModel::moneyFromDb($row['min_price']);
            $marketPrice = $row['default_market_price']
                ? (float) GoodsModel::moneyFromDb($row['default_market_price'])
                : null;

            $covers = json_decode($row['cover_images'] ?? '[]', true) ?: [];
            $stock = (int) $row['total_stock'];
            // 发货类型由插件过滤器决定（例如 virtual_card 按 plugin_data.auto_delivery 动态切换）
            // 默认为空字符串，插件未响应则模板不显示徽标
            $deliveryType = applyFilter('goods_delivery_type', '', $row);
            $list[] = [
                'id'             => (int) $row['id'],
                'name'           => $row['title'],
                'image'          => $covers[0] ?? '',
                'price'          => $minPrice,
                'original_price' => ($marketPrice && $marketPrice > $minPrice) ? $marketPrice : null,
                // stock 是原始整数，供 JS/业务逻辑判断是否有货
                'stock'          => $stock,
                // stock_text 是展示文字（核心默认千分位；插件可通过 goods_stock_display 过滤器重写）
                'stock_text'     => self::formatStockText($stock),
                'sold'           => (int) $row['total_sold'],
                'goods_type'     => $row['goods_type'] ?? 'default',
                'delivery_type'  => $deliveryType,     // '' / 'auto' / 'manual'
            ];
        }
        return $list;
    }

    /**
     * 分页查询前台商品列表。
     *
     * @param array  $where   筛选条件
     * @param int    $page    当前页码（从1开始）
     * @param int    $perPage 每页条数
     * @param string $orderBy 排序
     * @return array{list:array, total:int, page:int, per_page:int, total_pages:int}
     */
    protected function queryGoodsListPaginated(array $where = [], int $page = 1, int $perPage = 20, string $orderBy = 'g.sort ASC, g.id DESC'): array
    {
        $prefix = Database::prefix();
        $conditions = ['g.status = 1', 'g.is_on_sale = 1', 'g.deleted_at IS NULL'];
        // 分开收集 JOIN / WHERE 两类占位符参数；最终拼 params 前者在前后者在后
        $joinParams = [];
        $whereParams = [];
        $join = '';

        if (!empty($where['category_ids']) && is_array($where['category_ids'])) {
            $placeholders = implode(',', array_fill(0, count($where['category_ids']), '?'));
            $conditions[] = "g.category_id IN ({$placeholders})";
            foreach ($where['category_ids'] as $cid) {
                $whereParams[] = (int) $cid;
            }
        } elseif (!empty($where['category_id'])) {
            $conditions[] = 'g.category_id = ?';
            $whereParams[] = (int) $where['category_id'];
        }
        if (!empty($where['is_recommended'])) {
            // 商户上下文下用 COALESCE（商户覆盖优先，否则跟随主站）
            $conditions[] = (class_exists('MerchantContext') && MerchantContext::currentId() > 0)
                ? 'COALESCE(mgr.is_recommended, g.is_recommended) = 1'
                : 'g.is_recommended = 1';
        }
        if (!empty($where['keyword'])) {
            $conditions[] = '(g.title LIKE ? OR g.intro LIKE ?)';
            $kw = '%' . $where['keyword'] . '%';
            $whereParams[] = $kw;
            $whereParams[] = $kw;
        }
        // 标签筛选（INNER JOIN）
        if (!empty($where['tag_id'])) {
            $join = " INNER JOIN {$prefix}goods_tag_relation gtr ON gtr.goods_id = g.id AND gtr.tag_id = ?";
            $joinParams[] = (int) $where['tag_id'];
        }

        // 商户上下文过滤
        [$selectExtra, $joinMerchant] = $this->applyMerchantScope($conditions, $whereParams, $joinParams);
        $join .= $joinMerchant;

        $params = array_merge($joinParams, $whereParams);
        $whereSql = implode(' AND ', $conditions);

        // 查询总数
        $countSql = "SELECT COUNT(*) as cnt FROM {$prefix}goods g{$join} WHERE {$whereSql}";
        $countRow = Database::fetchOne($countSql, $params);
        $total = (int) ($countRow['cnt'] ?? 0);

        $page = max(1, $page);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        // 查询当前页数据
        $sql = "SELECT g.id, g.title, g.cover_images, g.min_price, g.max_price,
                    g.total_stock, g.goods_type, g.plugin_data, g.owner_id" . $selectExtra . ",
                    (SELECT market_price FROM {$prefix}goods_spec
                     WHERE goods_id = g.id AND is_default = 1 AND status = 1 LIMIT 1) as default_market_price,
                    (SELECT COALESCE(SUM(sold_count), 0) FROM {$prefix}goods_spec
                     WHERE goods_id = g.id AND status = 1) as total_sold
                FROM {$prefix}goods g{$join}
                WHERE {$whereSql}
                ORDER BY {$orderBy}
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = Database::query($sql, $params);
        $list = [];
        foreach ($rows as $row) {
            $this->rewriteMerchantPrice($row);

            $minPrice = (float) GoodsModel::moneyFromDb($row['min_price']);
            $marketPrice = $row['default_market_price']
                ? (float) GoodsModel::moneyFromDb($row['default_market_price'])
                : null;

            $covers = json_decode($row['cover_images'] ?? '[]', true) ?: [];
            $stock = (int) $row['total_stock'];
            $deliveryType = applyFilter('goods_delivery_type', '', $row);
            $list[] = [
                'id'             => (int) $row['id'],
                'name'           => $row['title'],
                'image'          => $covers[0] ?? '',
                'price'          => $minPrice,
                'original_price' => ($marketPrice && $marketPrice > $minPrice) ? $marketPrice : null,
                // stock 是原始整数，供 JS/业务逻辑判断是否有货
                'stock'          => $stock,
                // stock_text 是展示文字（核心默认千分位；插件可通过 goods_stock_display 过滤器重写）
                'stock_text'     => self::formatStockText($stock),
                'sold'           => (int) $row['total_sold'],
                'goods_type'     => $row['goods_type'] ?? 'default',
                'delivery_type'  => $deliveryType,     // '' / 'auto' / 'manual'
            ];
        }

        return [
            'list'        => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * 当前若在商户上下文，给 $conditions / $params 追加作用域过滤：
     *   商户只看得到：自建商品（g.owner_id = 商户主 user_id）
     *   或引用商品（g.owner_id = 0 AND mgr.is_on_sale = 1）
     *
     * 返回 [SELECT 扩展列, JOIN 扩展字符串]；主站返回 ['', '']。
     *
     * @param array<int, string> $conditions
     * @param array<int, mixed> $params
     * @return array{0:string,1:string}
     */
    private function applyMerchantScope(array &$conditions, array &$whereParams, array &$joinParams): array
    {
        $merchantId = MerchantContext::currentId();
        if ($merchantId <= 0) {
            // 主站前台：只看主站自营商品（owner_id=0），避免商户自建商品漏到主站前台
            $conditions[] = 'g.owner_id = 0';
            return ['', ''];
        }
        $ownerUserId = MerchantContext::currentOwnerId();
        $prefix = Database::prefix();

        // JOIN 里的 ? 在最终 SQL 中排在 WHERE 之前，参数必须写入 $joinParams
        $join = " LEFT JOIN {$prefix}goods_merchant_ref mgr ON mgr.goods_id = g.id AND mgr.merchant_id = ?";
        $joinParams[] = $merchantId;

        // 主站商品默认全部可见；商户只有显式把 is_on_sale=0 才下架
        // （没 ref 行 → COALESCE 得 1 → 默认上架）
        $conditions[] = '(g.owner_id = ? OR (g.owner_id = 0 AND COALESCE(mgr.is_on_sale, 1) = 1))';
        $whereParams[] = $ownerUserId;

        $selectExtra = ', mgr.markup_rate AS mgr_markup_rate, mgr.is_on_sale AS mgr_is_on_sale';
        return [$selectExtra, $join];
    }

    /**
     * 商户上下文下：对查询结果的 min_price / max_price 重写为店内售价。
     *   自建商品（owner_id = 商户主 user_id）：保持原价
     *   引用商品（owner_id = 0）：店内售价 = 原价 × d_user × (1 + markup_rate/10000)
     *
     * @param array<string, mixed> $row 引用传入，原地修改
     */
    private function rewriteMerchantPrice(array &$row): void
    {
        $merchantId = MerchantContext::currentId();
        if ($merchantId <= 0) return;
        if ((int) ($row['owner_id'] ?? 0) !== 0) return; // 自建商品，原价展示

        $ownerUserId = MerchantContext::currentOwnerId();
        $discount = self::resolveMerchantDiscount($ownerUserId);
        // 没覆盖行时 mgr_markup_rate 是 NULL（PHP 取 0）→ 回退到商户 default_markup_rate
        $markup = isset($row['mgr_markup_rate']) && $row['mgr_markup_rate'] !== null
            ? (int) $row['mgr_markup_rate']
            : self::resolveMerchantDefaultMarkup($merchantId);
        $factor = $discount * (1 + $markup / 10000);

        if (isset($row['min_price'])) {
            $row['min_price'] = (int) round(((int) $row['min_price']) * $factor);
        }
        if (isset($row['max_price'])) {
            $row['max_price'] = (int) round(((int) $row['max_price']) * $factor);
        }
        // market_price（划线价）：商户店内看到的划线价，按同倍率放大以保留"原价对比"效果
        if (!empty($row['default_market_price'])) {
            $row['default_market_price'] = (int) round(((int) $row['default_market_price']) * $factor);
        }
    }

    /**
     * 读取商户默认加价率（万分位）。同一请求内缓存。
     * 没配 / 读不到 → 1000（10%，InstallService 的默认值）。
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
     * 读商户主的用户等级折扣率：9.9 折 → 0.99。
     * 未设等级 / 等级禁用 / 读不到 → 返回 1.0（不打折）。
     *
     * 同一请求内缓存以免反复查询。
     */
    private static function resolveMerchantDiscount(int $userId): float
    {
        static $cache = [];
        if (isset($cache[$userId])) return $cache[$userId];

        $userTable = Database::prefix() . 'user';
        $levelTable = Database::prefix() . 'user_levels';
        $row = Database::fetchOne(
            'SELECT ul.`discount` AS d
               FROM `' . $userTable . '` u
          LEFT JOIN `' . $levelTable . '` ul ON ul.`id` = u.`level_id` AND ul.`enabled` = \'y\'
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
     * 查询前台文章列表（已发布、未删除，字段映射为模板格式）。
     *
     * @param array  $where 筛选：category_id / keyword
     * @param int    $limit 条数
     * @return array<array{id:int, title:string, excerpt:string, date:string, author:string, category:string, views:int}>
     */
    protected function queryArticleList(array $where = [], int $limit = 6): array
    {
        $prefix = Database::prefix();
        $conditions = ['a.status = 1', 'a.deleted_at IS NULL'];
        $params = [];

        if (!empty($where['category_id'])) {
            $conditions[] = 'a.category_id = ?';
            $params[] = (int) $where['category_id'];
        }
        if (!empty($where['keyword'])) {
            $conditions[] = '(a.title LIKE ? OR a.excerpt LIKE ?)';
            $kw = '%' . $where['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }

        $whereSql = implode(' AND ', $conditions);
        $sql = "SELECT a.id, a.title, a.excerpt, a.cover_image, a.views_count, a.created_at,
                    c.name as category_name,
                    COALESCE(u.nickname, u.username, '管理员') as author
                FROM {$prefix}blog a
                LEFT JOIN {$prefix}blog_category c ON a.category_id = c.id
                LEFT JOIN {$prefix}user u ON a.user_id = u.id
                WHERE {$whereSql}
                ORDER BY a.is_top DESC, a.sort ASC, a.id DESC
                LIMIT {$limit}";

        $rows = Database::query($sql, $params);
        $list = [];
        $blogIds = [];
        foreach ($rows as $row) {
            $blogIds[] = (int) $row['id'];
            $list[] = [
                'id'       => (int) $row['id'],
                'title'    => $row['title'],
                'excerpt'  => $row['excerpt'] ?: '',
                'image'    => $row['cover_image'] ?? '',
                'date'     => substr($row['created_at'], 0, 10),
                'author'   => $row['author'] ?: '管理员',
                'category' => $row['category_name'] ?: '未分类',
                'views'    => (int) $row['views_count'],
                'tags'     => [],
            ];
        }

        // 批量加载标签
        if ($blogIds) {
            $tagMap = BlogTagModel::getTagsByBlogIds($blogIds);
            foreach ($list as &$item) {
                $item['tags'] = $tagMap[$item['id']] ?? [];
            }
            unset($item);
        }

        return $list;
    }

    /**
     * 分页查询前台文章列表。
     *
     * @param array  $where   筛选条件：category_id / category_ids / keyword
     * @param int    $page    当前页码（从1开始）
     * @param int    $perPage 每页条数
     * @return array{list:array, total:int, page:int, per_page:int, total_pages:int}
     */
    protected function queryArticleListPaginated(array $where = [], int $page = 1, int $perPage = 10): array
    {
        $prefix = Database::prefix();
        $conditions = ['a.status = 1', 'a.deleted_at IS NULL'];
        $params = [];

        if (!empty($where['category_ids']) && is_array($where['category_ids'])) {
            $placeholders = implode(',', array_fill(0, count($where['category_ids']), '?'));
            $conditions[] = "a.category_id IN ({$placeholders})";
            foreach ($where['category_ids'] as $cid) {
                $params[] = (int) $cid;
            }
        } elseif (!empty($where['category_id'])) {
            $conditions[] = 'a.category_id = ?';
            $params[] = (int) $where['category_id'];
        }
        if (!empty($where['keyword'])) {
            $conditions[] = '(a.title LIKE ? OR a.excerpt LIKE ?)';
            $kw = '%' . $where['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }

        // 标签筛选
        $joinTag = '';
        if (!empty($where['tag_id'])) {
            $joinTag = "INNER JOIN {$prefix}blog_tag_relation btr ON a.id = btr.blog_id AND btr.tag_id = ?";
            $params[] = (int) $where['tag_id'];
        }

        $whereSql = implode(' AND ', $conditions);

        // 查询总数
        $countSql = "SELECT COUNT(*) as cnt FROM {$prefix}blog a {$joinTag} WHERE {$whereSql}";
        $countRow = Database::fetchOne($countSql, $params);
        $total = (int) ($countRow['cnt'] ?? 0);

        $page = max(1, $page);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT a.id, a.title, a.excerpt, a.cover_image, a.views_count, a.created_at,
                    c.name as category_name,
                    COALESCE(u.nickname, u.username, '管理员') as author
                FROM {$prefix}blog a
                {$joinTag}
                LEFT JOIN {$prefix}blog_category c ON a.category_id = c.id
                LEFT JOIN {$prefix}user u ON a.user_id = u.id
                WHERE {$whereSql}
                ORDER BY a.is_top DESC, a.sort ASC, a.id DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = Database::query($sql, $params);
        $list = [];
        $blogIds = [];
        foreach ($rows as $row) {
            $blogIds[] = (int) $row['id'];
            $list[] = [
                'id'       => (int) $row['id'],
                'title'    => $row['title'],
                'excerpt'  => $row['excerpt'] ?: '',
                'image'    => $row['cover_image'] ?? '',
                'date'     => substr($row['created_at'], 0, 10),
                'author'   => $row['author'] ?: '管理员',
                'category' => $row['category_name'] ?: '未分类',
                'views'    => (int) $row['views_count'],
                'tags'     => [],
            ];
        }

        // 批量加载标签
        if ($blogIds) {
            $tagMap = BlogTagModel::getTagsByBlogIds($blogIds);
            foreach ($list as &$item) {
                $item['tags'] = $tagMap[$item['id']] ?? [];
            }
            unset($item);
        }

        return [
            'list'        => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * 获取商品侧边栏数据（分类列表 + 各分类商品数）。
     *
     * @return array{goods_categories:array}
     */
    protected function getGoodsSidebarData(): array
    {
        $prefix = Database::prefix();
        $rows = Database::query(
            "SELECT c.id, c.parent_id, c.name, c.slug, c.icon, c.cover_image, COUNT(g.id) as goods_count
             FROM {$prefix}goods_category c
             LEFT JOIN {$prefix}goods g ON g.category_id = c.id
                 AND g.status = 1 AND g.is_on_sale = 1 AND g.deleted_at IS NULL
             WHERE c.status = 1
             GROUP BY c.id
             ORDER BY c.sort ASC, c.id ASC"
        );

        // 构建二级树：顶级分类 + children
        $map = [];
        $tree = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $map[(int) $row['id']] = $row;
        }
        foreach ($map as $id => &$item) {
            $pid = (int) $item['parent_id'];
            if ($pid > 0 && isset($map[$pid])) {
                $map[$pid]['children'][] = &$item;
            } else {
                $tree[] = &$item;
            }
        }
        unset($item);

        // 热门标签
        $popularTags = GoodsTagModel::getPopularTags(20);

        return [
            'goods_categories' => $tree,
            'popular_tags'     => $popularTags,
        ];
    }

    /**
     * 获取博客侧边栏数据（分类二级树 + 热门标签）。
     *
     * @return array{blog_categories:array, popular_blog_tags:array}
     */
    protected function getBlogSidebarData(): array
    {
        $prefix = Database::prefix();
        // 分类 + 各分类文章数（仅统计已发布未删除的文章）
        $rows = Database::query(
            "SELECT c.id, c.parent_id, c.name, c.icon, COUNT(a.id) as article_count
             FROM {$prefix}blog_category c
             LEFT JOIN {$prefix}blog a ON a.category_id = c.id
                 AND a.status = 1 AND a.deleted_at IS NULL
             WHERE c.status = 1
             GROUP BY c.id
             ORDER BY c.sort ASC, c.id ASC"
        );

        // 构建二级树
        $map = [];
        $tree = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $map[(int) $row['id']] = $row;
        }
        foreach ($map as $id => &$item) {
            $pid = (int) $item['parent_id'];
            if ($pid > 0 && isset($map[$pid])) {
                $map[$pid]['children'][] = &$item;
            } else {
                $tree[] = &$item;
            }
        }
        unset($item);

        return [
            'blog_categories'   => $tree,
            'popular_blog_tags' => BlogTagModel::getPopularTags(20),
        ];
    }

    /**
     * 生成商品库存的展示文字（stock_text 字段来源）。
     *
     * 默认行为：千分位格式化（如 1234567 → "1,234,567"）。
     * 过滤器 goods_stock_display 可重写展示结果（例如 fuzzy_stock 插件把数字改成"充足"等）。
     * 核心不感知任何插件；插件返回非字符串时自动 fallback 为默认千分位结果。
     *
     * @param int $stock 原始库存数量（整数）
     * @return string 展示文本
     */
    public static function formatStockText(int $stock): string
    {
        $default = number_format($stock);
        $filtered = applyFilter('goods_stock_display', $stock);
        return is_string($filtered) ? $filtered : $default;
    }

}
