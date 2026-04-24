# SEO URL 伪静态配置

后台 SEO 设置里把链接格式从「默认格式」切到其它任一格式后，需要配一下伪静态。

> 默认格式不用配。

## Nginx（含宝塔）

把这一段粘进站点配置（宝塔：网站 → 设置 → 伪静态）：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

就这一段，别的不用。

## Apache

根目录的 `.htaccess` 写入：

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L,QSA]
</IfModule>
```

## URL 映射

| 场景 | 默认 | 文件 | 目录① | 目录② |
|---|---|---|---|---|
| 商品详情 | `?post=1` | `/post-1.html` | `/post/1` | `/buy/1` |
| 商品列表(全部) | `?c=goods_list` | `/post-list.html` | `/post/list` | `/buy/list` |
| 商品列表(全部)分页 | `&page=2` | `/post-list-all-2.html` | `/post/list/all/2` | `/buy/list/all/2` |
| 商品分类(id) | `&category_id=2` | `/post-list-2.html` | `/post/list/2` | `/buy/list/2` |
| 商品分类(id)分页 | `&category_id=2&page=3` | `/post-list-2-3.html` | `/post/list/2/3` | `/buy/list/2/3` |
| 商品分类(slug) | `&slug=x` | `/post-x.html` | `/post/c/x` | `/buy/c/x` |
| 商品分类(slug)分页 | `&slug=x&page=2` | `/post-x-2.html` | `/post/c/x/2` | `/buy/c/x/2` |
| 商品标签 | `?c=goods_tag&id=1` | `/tag-1.html` | `/tag/1` | `/tag/1` |
| 商品标签分页 | `&page=2` | `/tag-1-2.html` | `/tag/1/2` | `/tag/1/2` |
| 博客详情 | `?blog=1` | `/blog-1.html` | `/blog/1` | `/blog/1` |
| 博客列表(全部) | `?c=blog_list` | `/blog-list.html` | `/blog/list` | `/blog/list` |
| 博客列表(全部)分页 | `&page=2` | `/blog-list-all-2.html` | `/blog/list/all/2` | `/blog/list/all/2` |
| 博客分类(id) | `&category_id=2` | `/blog-list-2.html` | `/blog/list/2` | `/blog/list/2` |
| 博客分类(id)分页 | `&category_id=2&page=3` | `/blog-list-2-3.html` | `/blog/list/2/3` | `/blog/list/2/3` |
| 博客分类(slug) | `&slug=x` | `/blog-x.html` | `/blog/c/x` | `/blog/c/x` |
| 博客分类(slug)分页 | `&slug=x&page=2` | `/blog-x-2.html` | `/blog/c/x/2` | `/blog/c/x/2` |
| 博客标签 | `?c=blog_tag&id=1` | `/blog-tag-1.html` | `/blog/tag/1` | `/blog/tag/1` |
| 博客标签分页 | `&page=2` | `/blog-tag-1-2.html` | `/blog/tag/1/2` | `/blog/tag/1/2` |
| 博客首页 | `?c=blog_index` | `/blog.html` | `/blog/` | `/blog/` |
| 搜索 | `?c=search&q=x` | `/search-x.html` | `/search/x` | `/search/x` |
| 优惠券 | `?c=coupon` | `/coupon.html` | `/coupon/` | `/coupon/` |
| 购物车 | `?c=cart` | `/cart.html` | `/cart/` | `/cart/` |

> ⚠️ slug 约束：slug 末段不能是纯数字（否则会被解析为页码），slug 也不能取 `list` 本身（保留字）。
