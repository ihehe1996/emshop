<?php
defined('EM_ROOT') || exit('access denied!');

// 从模板配置读取轮播图数据
// 调用方可通过 $_hero_scene 指定场景：mall（默认）或 blog
$_heroStorage = TemplateStorage::getInstance('test');
$_heroKey = 'hero_slides_' . ($_hero_scene ?? 'mall');
// getValue 会自动 JSON 解码，返回值可能已经是数组
$_slides = $_heroStorage->getValue($_heroKey);
if (is_string($_slides)) {
    $_slides = json_decode($_slides, true);
}
// 兼容旧数据：商城场景回退到旧 key
if ((empty($_slides) || !is_array($_slides)) && ($_hero_scene ?? 'mall') === 'mall') {
    $_slides = $_heroStorage->getValue('hero_slides');
    if (is_string($_slides)) { $_slides = json_decode($_slides, true); }
}
if (empty($_slides) || !is_array($_slides)) {
    $isBlog = (($_hero_scene ?? 'mall') === 'blog');
    $_slides = [
        [
            'image'    => '',
            'title'    => $isBlog ? (htmlspecialchars($site_name ?? 'EMSHOP') . ' 博客') : ('欢迎来到 ' . htmlspecialchars($site_name ?? 'EMSHOP')),
            'subtitle' => $isBlog ? '分享技术心得，记录成长历程' : '精选优质商品，安全快捷交易',
            'link'     => $isBlog ? '?c=blog_list' : '?c=goods_list',
            'btn_text' => $isBlog ? '浏览文章' : '立即选购',
        ],
    ];
}
$_slideCount = count($_slides);
?>
<!-- Hero 轮播 -->
<div class="hero-carousel" id="heroCarousel">
    <div class="hero-track" id="heroTrack">
        <?php foreach ($_slides as $_si => $_slide): ?>
        <div class="hero-slide<?= $_si === 0 ? ' active' : '' ?>"
            <?php if (!empty($_slide['image'])): ?>
             style="background-image:url('<?= htmlspecialchars($_slide['image']) ?>');background-size:cover;background-position:center;"
            <?php endif; ?>
        >
            <div class="hero-slide__overlay"></div>
            <div class="wrapper hero-slide__content">
                <?php if (!empty($_slide['title'])): ?>
                <div class="hero-title"><?= htmlspecialchars($_slide['title']) ?></div>
                <?php endif; ?>
                <?php if (!empty($_slide['subtitle'])): ?>
                <div class="hero-desc"><?= htmlspecialchars($_slide['subtitle']) ?></div>
                <?php endif; ?>
                <div class="hero-btns">
                    <?php if (!empty($_slide['btn_text'])): ?>
                    <a href="<?= htmlspecialchars($_slide['link'] ?: '#') ?>" data-pjax class="btn"><?= htmlspecialchars($_slide['btn_text']) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($_slideCount > 1): ?>
    <div class="hero-dots" id="heroDots">
        <?php for ($_di = 0; $_di < $_slideCount; $_di++): ?>
        <span class="hero-dot<?= $_di === 0 ? ' active' : '' ?>" data-index="<?= $_di ?>"></span>
        <?php endfor; ?>
    </div>
    <button class="hero-arrow hero-arrow--prev" id="heroPrev">&lsaquo;</button>
    <button class="hero-arrow hero-arrow--next" id="heroNext">&rsaquo;</button>
    <?php endif; ?>
</div>

<?php if ($_slideCount > 1): ?>
<script>
(function(){
    var current = 0;
    var total = <?= $_slideCount ?>;
    var $slides = document.querySelectorAll('.hero-slide');
    var $dots = document.querySelectorAll('.hero-dot');
    var timer = null;

    function goTo(idx) {
        $slides[current].classList.remove('active');
        $dots[current] && $dots[current].classList.remove('active');
        current = (idx + total) % total;
        $slides[current].classList.add('active');
        $dots[current] && $dots[current].classList.add('active');
    }

    function startAuto() {
        stopAuto();
        timer = setInterval(function(){ goTo(current + 1); }, 5000);
    }

    function stopAuto() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    document.getElementById('heroPrev').addEventListener('click', function(){ goTo(current - 1); startAuto(); });
    document.getElementById('heroNext').addEventListener('click', function(){ goTo(current + 1); startAuto(); });

    for (var i = 0; i < $dots.length; i++) {
        $dots[i].addEventListener('click', (function(idx){ return function(){ goTo(idx); startAuto(); }; })(i));
    }

    var $carousel = document.getElementById('heroCarousel');
    $carousel.addEventListener('mouseenter', stopAuto);
    $carousel.addEventListener('mouseleave', startAuto);

    startAuto();
})();
</script>
<?php endif; ?>
