<?php
defined('EM_ROOT') || exit('access denied!');
?>
<!-- 商城首页 · GoodsController::_index() -->

<?php include __DIR__ . '/hero.php'; ?>

<div class="page-body">
    <div class="blog-layout">

        <!-- 主内容 -->
        <div class="blog-main">

            <!-- 推荐商品 -->
            <div class="section">
                <div class="section-header">
                    <div class="section-title">推荐商品</div>
                    <a href="<?= url_goods_list() ?>" data-pjax class="section-more">查看更多 &rarr;</a>
                </div>
                <?php if (!empty($recommended_goods)): ?>
                <div class="goods-grid">
                    <?php foreach ($recommended_goods as $g): ?>
                    <a href="<?= url_goods((int) $g['id']) ?>" class="card goods-card">
                        <div class="card-img">
                            <img src="<?= htmlspecialchars($g['image'] ?? '') ?>" alt="<?= htmlspecialchars($g['name']) ?>">
                            <?php if (($g['delivery_type'] ?? '') === 'auto'): ?>
                            <span class="goods-badge goods-badge--auto">自动发货</span>
                            <?php elseif (($g['delivery_type'] ?? '') === 'manual'): ?>
                            <span class="goods-badge goods-badge--manual">人工发货</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="card-title"><?= htmlspecialchars($g['name']) ?></div>
                            <div class="card-stats">
                                <span>库存 <?= htmlspecialchars((string) ($g['stock_text'] ?? '0')) ?></span>
                                <span>销量 <?= (int) ($g['sold'] ?? 0) ?></span>
                            </div>
                            <div class="card-bottom">
                                <span class="price"><?= Currency::displayMain((float) $g['price']) ?></span>
                                <?php if (!empty($g['original_price'])): ?>
                                <span class="price-original"><?= Currency::displayMain((float) $g['original_price']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="card empty-state empty-state--rich">
                    <div class="empty-decor">
                        <span class="empty-decor__dot empty-decor__dot--1"></span>
                        <span class="empty-decor__dot empty-decor__dot--2"></span>
                        <span class="empty-decor__dot empty-decor__dot--3"></span>
                        <span class="empty-decor__ring"></span>
                    </div>
                    <div class="empty-icon empty-icon--glow"><i class="fa fa-shopping-bag"></i></div>
                    <h3>商品正在精心挑选中</h3>
                    <p>店主正在为你筛选最值得入手的好物，稍后再来看看吧～</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- 最新文章 -->
            <div class="section">
                <div class="section-header">
                    <div class="section-title">最新文章</div>
                    <a href="<?= $nav_blog_url ?? '?c=blog_list' ?>" data-pjax class="section-more">查看更多 &rarr;</a>
                </div>
                <?php if (!empty($recent_articles)): ?>
                <div class="article-grid">
                    <?php foreach ($recent_articles as $a): ?>
                    <a href="<?= url_blog((int) $a['id']) ?>" class="card article-grid-card">
                        <?php if (!empty($a['image'])): ?>
                        <div class="article-grid-img"><img src="<?= htmlspecialchars($a['image']) ?>" alt="<?= htmlspecialchars($a['title']) ?>"></div>
                        <?php endif; ?>
                        <div class="article-grid-body">
                            <div class="card-title"><?= htmlspecialchars($a['title']) ?></div>
                            <div class="card-excerpt"><?= htmlspecialchars(truncate($a['excerpt'], 60)) ?></div>
                            <div class="card-meta">
                                <span><?= htmlspecialchars($a['date']) ?></span>
                                <span>&middot;</span>
                                <span><?= (int) $a['views'] ?> 阅读</span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="card empty-state empty-state--rich">
                    <div class="empty-decor">
                        <span class="empty-decor__dot empty-decor__dot--1"></span>
                        <span class="empty-decor__dot empty-decor__dot--2"></span>
                        <span class="empty-decor__dot empty-decor__dot--3"></span>
                        <span class="empty-decor__ring"></span>
                    </div>
                    <div class="empty-icon empty-icon--glow"><i class="fa fa-pencil-square-o"></i></div>
                    <h3>博客频道建设中</h3>
                    <p>站长正在打磨第一批精选内容，敬请期待。</p>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- 侧边栏 -->
        <?php include __DIR__ . '/goods_side.php'; ?>

    </div>
</div>
