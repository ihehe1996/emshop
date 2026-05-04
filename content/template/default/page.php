<?php
/**
 * 自定义页面兜底模板（default 主题）
 *
 * 由 PageController::_detail 按如下优先级选择模板：
 *   page-{slug}.php → page-{template_name}.php → page.php → default/page.php（本文件）
 *
 * 可用变量：
 *   $page : [
 *     'id', 'title', 'slug', 'content', 'template_name',
 *     'seo_title', 'seo_keywords', 'seo_description',
 *     'views', 'created_at', 'updated_at',
 *   ]
 */
if (!defined('EM_ROOT')) { exit('Access Denied'); }

/** @var array $page */
$pageVar = $page ?? [];
$title   = (string) ($pageVar['title'] ?? '');
$content = (string) ($pageVar['content'] ?? '');
?>
<main class="em-page" role="main">
    <style>
    .em-page { max-width: 880px; margin: 40px auto; padding: 0 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif; color: #111827; line-height: 1.75; }
    .em-page__header { border-bottom: 1px solid #e5e7eb; padding-bottom: 18px; margin-bottom: 28px; }
    .em-page__title { font-size: 30px; font-weight: 700; color: #0f172a; margin: 0 0 8px; line-height: 1.3; }
    .em-page__meta { font-size: 13px; color: #9ca3af; }
    .em-page__content { font-size: 15px; }
    .em-page__content p { margin: 0 0 1em; }
    .em-page__content h1, .em-page__content h2, .em-page__content h3 { color: #0f172a; margin: 1.4em 0 .6em; }
    .em-page__content img { max-width: 100%; height: auto; border-radius: 6px; }
    .em-page__content a { color: #4f46e5; }
    .em-page__content pre { background: #f3f4f6; padding: 12px 14px; border-radius: 6px; overflow-x: auto; }
    .em-page__content blockquote { border-left: 3px solid #e5e7eb; padding: .2em 1em; color: #6b7280; margin: 1em 0; background: #fafafa; }
    </style>

    <header class="em-page__header">
        <h1 class="em-page__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if (!empty($pageVar['updated_at'])): ?>
        <div class="em-page__meta">
            最近更新：<?= htmlspecialchars(substr((string) $pageVar['updated_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>
            <?php if (isset($pageVar['views'])): ?>
            · 阅读 <?= (int) $pageVar['views'] ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </header>

    <article class="em-page__content">
        <?= $content /* 富文本 HTML，不转义 */ ?>
    </article>
</main>
