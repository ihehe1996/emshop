<?php

declare(strict_types=1);

/**
 * 前台模板辅助函数。
 *
 * 提供模板中常用的 URL 构建和格式化函数。
 * 该文件由 init.php 自动加载，所有模板中可直接调用。
 */

/**
 * 当前 URL 格式（读一次 Config 缓存到 static）。
 *
 * 四种模式：
 *   default  原始 query string 格式      ?post=1 / ?blog=1 / ?c=goods_list
 *   file     文件格式 (.html 结尾)        /post-1.html / /blog-1.html / /post-list.html
 *   dir1     目录格式 · 前缀 post/blog    /post/1 / /blog/1 / /post/list
 *   dir2     目录格式 · 前缀 buy/blog     /buy/1 / /blog/1 / /buy/list
 */
function url_format(): string
{
    static $fmt = null;
    if ($fmt === null) {
        $fmt = (string) Config::get('url_format', 'default');
        if (!in_array($fmt, ['default', 'file', 'dir1', 'dir2'], true)) $fmt = 'default';
    }
    return $fmt;
}

/**
 * 根据当前模式返回商品路径前缀（post / buy）。
 */
function url_goods_prefix(): string
{
    return url_format() === 'dir2' ? 'buy' : 'post';
}

/**
 * 把 query 参数附到已有 URL 上，兼容 '?' 与 '&'。
 *
 * @param string $url 已经带 '?' 或全路径的 URL
 * @param array  $params 额外参数
 */
function url_append(string $url, array $params): string
{
    if (empty($params)) return $url;
    $sep = strpos($url, '?') === false ? '?' : '&';
    $parts = [];
    foreach ($params as $k => $v) $parts[] = urlencode((string) $k) . '=' . urlencode((string) $v);
    return $url . $sep . implode('&', $parts);
}

/**
 * 构建商品详情页 URL。
 */
function url_goods(int $id, array $params = []): string
{
    $prefix = url_goods_prefix();
    switch (url_format()) {
        case 'file':
            return url_append('/' . $prefix . '-' . $id . '.html', $params);
        case 'dir1':
        case 'dir2':
            return url_append('/' . $prefix . '/' . $id, $params);
        default:
            return url_append('/?post=' . $id, $params);
    }
}

/**
 * 构建商品列表页 URL。
 * 支持 slug / category_id / tag_id / page 等 params。
 *
 * 文件 / 目录格式分类 + 分页路径规则：
 *   /post-list.html               → 全部 · 第 1 页
 *   /post-list-all-N.html         → 全部 · 第 N 页
 *   /post-list-ID.html            → 分类 id=ID · 第 1 页
 *   /post-list-ID-N.html          → 分类 id=ID · 第 N 页
 *   /post-SLUG.html               → slug 分类 · 第 1 页
 *   /post-SLUG-N.html             → slug 分类 · 第 N 页（末段数字=页码）
 *
 *   目录格式同构：/post/list、/post/list/all/N、/post/list/ID[/N]、/post/c/SLUG[/N]
 */
function url_goods_list(array $params = []): string
{
    $prefix     = url_goods_prefix();
    $fmt        = url_format();
    $slug       = isset($params['slug']) ? (string) $params['slug'] : '';
    $categoryId = isset($params['category_id']) ? (int) $params['category_id'] : 0;
    $page       = isset($params['page']) ? (int) $params['page'] : 0;
    unset($params['slug'], $params['category_id'], $params['page']);

    switch ($fmt) {
        case 'file':
            if ($slug !== '') {
                $pageSuffix = $page > 1 ? '-' . $page : '';
                return url_append('/' . $prefix . '-' . rawurlencode($slug) . $pageSuffix . '.html', $params);
            }
            if ($categoryId > 0) {
                $pageSuffix = $page > 1 ? '-' . $page : '';
                return url_append('/' . $prefix . '-list-' . $categoryId . $pageSuffix . '.html', $params);
            }
            if ($page > 1) {
                return url_append('/' . $prefix . '-list-all-' . $page . '.html', $params);
            }
            return url_append('/' . $prefix . '-list.html', $params);

        case 'dir1':
        case 'dir2':
            if ($slug !== '') {
                $pageSuffix = $page > 1 ? '/' . $page : '';
                return url_append('/' . $prefix . '/c/' . rawurlencode($slug) . $pageSuffix, $params);
            }
            if ($categoryId > 0) {
                $pageSuffix = $page > 1 ? '/' . $page : '';
                return url_append('/' . $prefix . '/list/' . $categoryId . $pageSuffix, $params);
            }
            if ($page > 1) {
                return url_append('/' . $prefix . '/list/all/' . $page, $params);
            }
            return url_append('/' . $prefix . '/list', $params);

        default: // query string
            $parts = ['c=goods_list'];
            if ($slug !== '')      $parts[] = 'slug=' . rawurlencode($slug);
            if ($categoryId > 0)   $parts[] = 'category_id=' . $categoryId;
            if ($page > 1)         $parts[] = 'page=' . $page;
            foreach ($params as $k => $v) $parts[] = urlencode((string) $k) . '=' . urlencode((string) $v);
            return '/?' . implode('&', $parts);
    }
}

/**
 * 按分类构建商品列表页 URL（有 slug 优先 slug，无 slug 用 id）。
 */
function url_goods_category(array $cat): string
{
    $slug = trim((string) ($cat['slug'] ?? ''));
    if ($slug !== '') return url_goods_list(['slug' => $slug]);
    return url_goods_list(['category_id' => (int) $cat['id']]);
}

/**
 * 商城首页 URL。
 * 默认模式下 "/" 就是首页；pretty 模式下也用 "/" 进入入口控制器。
 */
function url_goods_index(): string { return '/'; }

/**
 * 兼容别名：url_home() 与 url_goods_index() 语义一致。
 */
function url_home(): string { return url_goods_index(); }

/**
 * 构建商品标签页 URL。
 * file/dir 模式下 page 走路径：/tag-1-2.html、/tag/1/2
 */
function url_goods_tag(int $id, array $params = []): string
{
    $page = isset($params['page']) ? (int) $params['page'] : 0;
    unset($params['page']);

    switch (url_format()) {
        case 'file':
            $pageSuffix = $page > 1 ? '-' . $page : '';
            return url_append('/tag-' . $id . $pageSuffix . '.html', $params);
        case 'dir1':
        case 'dir2':
            $pageSuffix = $page > 1 ? '/' . $page : '';
            return url_append('/tag/' . $id . $pageSuffix, $params);
        default:
            $base = '/?c=goods_tag&id=' . $id;
            if ($page > 1) $base .= '&page=' . $page;
            return url_append($base, $params);
    }
}

/**
 * 构建博客详情页 URL。
 */
function url_blog(int $id, array $params = []): string
{
    switch (url_format()) {
        case 'file': return url_append('/blog-' . $id . '.html', $params);
        case 'dir1':
        case 'dir2': return url_append('/blog/' . $id, $params);
        default:     return url_append('/?blog=' . $id, $params);
    }
}

/**
 * 构建文章列表页 URL。
 *
 * 路径规则与商品列表同构：
 *   /blog-list.html / /blog-list-all-N.html / /blog-list-ID[-N].html / /blog-SLUG[-N].html
 *   /blog/list / /blog/list/all/N / /blog/list/ID[/N] / /blog/c/SLUG[/N]
 */
function url_blog_list(array $params = []): string
{
    $slug       = isset($params['slug']) ? (string) $params['slug'] : '';
    $categoryId = isset($params['category_id']) ? (int) $params['category_id'] : 0;
    $page       = isset($params['page']) ? (int) $params['page'] : 0;
    unset($params['slug'], $params['category_id'], $params['page']);

    switch (url_format()) {
        case 'file':
            if ($slug !== '') {
                $pageSuffix = $page > 1 ? '-' . $page : '';
                return url_append('/blog-' . rawurlencode($slug) . $pageSuffix . '.html', $params);
            }
            if ($categoryId > 0) {
                $pageSuffix = $page > 1 ? '-' . $page : '';
                return url_append('/blog-list-' . $categoryId . $pageSuffix . '.html', $params);
            }
            if ($page > 1) return url_append('/blog-list-all-' . $page . '.html', $params);
            return url_append('/blog-list.html', $params);

        case 'dir1':
        case 'dir2':
            if ($slug !== '') {
                $pageSuffix = $page > 1 ? '/' . $page : '';
                return url_append('/blog/c/' . rawurlencode($slug) . $pageSuffix, $params);
            }
            if ($categoryId > 0) {
                $pageSuffix = $page > 1 ? '/' . $page : '';
                return url_append('/blog/list/' . $categoryId . $pageSuffix, $params);
            }
            if ($page > 1) return url_append('/blog/list/all/' . $page, $params);
            return url_append('/blog/list', $params);

        default:
            $parts = ['c=blog_list'];
            if ($slug !== '')    $parts[] = 'slug=' . rawurlencode($slug);
            if ($categoryId > 0) $parts[] = 'category_id=' . $categoryId;
            if ($page > 1)       $parts[] = 'page=' . $page;
            foreach ($params as $k => $v) $parts[] = urlencode((string) $k) . '=' . urlencode((string) $v);
            return '/?' . implode('&', $parts);
    }
}

/**
 * 博客首页 URL。
 */
function url_blog_index(array $params = []): string
{
    switch (url_format()) {
        case 'file': return url_append('/blog.html', $params);
        case 'dir1':
        case 'dir2': return url_append('/blog/', $params);
        default:     return url_append('/?c=blog_index', $params);
    }
}

/**
 * 博客标签页 URL。
 * file/dir 模式下 page 走路径：/blog-tag-1-2.html、/blog/tag/1/2
 */
function url_blog_tag(int $id, array $params = []): string
{
    $page = isset($params['page']) ? (int) $params['page'] : 0;
    unset($params['page']);

    switch (url_format()) {
        case 'file':
            $pageSuffix = $page > 1 ? '-' . $page : '';
            return url_append('/blog-tag-' . $id . $pageSuffix . '.html', $params);
        case 'dir1':
        case 'dir2':
            $pageSuffix = $page > 1 ? '/' . $page : '';
            return url_append('/blog/tag/' . $id . $pageSuffix, $params);
        default:
            $base = '/?c=blog_tag&id=' . $id;
            if ($page > 1) $base .= '&page=' . $page;
            return url_append($base, $params);
    }
}

/**
 * 搜索结果页 URL。
 */
function url_search(string $keyword = ''): string
{
    $kw = trim($keyword);
    switch (url_format()) {
        case 'file':
            return $kw === '' ? '/search.html' : '/search-' . rawurlencode($kw) . '.html';
        case 'dir1':
        case 'dir2':
            return $kw === '' ? '/search/' : '/search/' . rawurlencode($kw);
        default:
            return $kw === '' ? '/?c=search' : '/?c=search&q=' . urlencode($kw);
    }
}

/**
 * 优惠券中心 URL。
 */
function url_coupon(): string
{
    switch (url_format()) {
        case 'file': return '/coupon.html';
        case 'dir1':
        case 'dir2': return '/coupon/';
        default:     return '/?c=coupon';
    }
}

/**
 * 购物车 URL。
 */
function url_cart(): string
{
    switch (url_format()) {
        case 'file': return '/cart.html';
        case 'dir1':
        case 'dir2': return '/cart/';
        default:     return '/?c=cart';
    }
}

/**
 * 格式化价格。
 */
function format_price(float $price): string
{
    return '¥' . number_format($price, 2);
}

/**
 * 截断文本。
 */
function truncate(string $text, int $length = 100, string $suffix = '...'): string
{
    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length, 'UTF-8') . $suffix;
}
