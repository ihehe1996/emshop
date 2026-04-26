<?php
/**
 * 测试模板 - 通用底部
 */
?>
</div><!-- #main -->

<!-- 底部：极简版权 + ICP 备案 + 第三方统计代码注入 -->
<footer class="site-footer">
<div class="wrapper">
    <div class="site-footer__copy">
        <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($site_name ?? 'EMSHOP') ?></span>
        <span>Powered by <?= htmlspecialchars($site_name ?? 'EMSHOP') ?></span>
    </div>
    <?php if (!empty($site_icp)): ?>
    <div class="site-footer__icp">
        <?php
        // 中国大陆备案号惯例：跳工信部公示页（不强制，让用户能自助核验）
        $_icpHref = 'https://beian.miit.gov.cn/';
        ?>
        <a href="<?= htmlspecialchars($_icpHref) ?>" target="_blank" rel="nofollow noopener">
            <?= htmlspecialchars($site_icp) ?>
        </a>
    </div>
    <?php endif; ?>
</div>
</footer>

<?php
// 第三方统计代码（百度统计 / Google Analytics / 自定义脚本）
// 直接 raw 输出（管理员粘贴的 <script> 片段不做转义）；商户站不注入主站统计，避免混淆数据归属
if (!empty($site_statistical_code) && MerchantContext::isMaster()) {
    echo $site_statistical_code;
}
?>

<script>
/**
 * 根据当前 URL 更新导航 active 高亮。
 *
 * 策略（和 test/module.php 初始渲染保持一致）：
 *   1) 主匹配：当前 URL path 与 nav 项的 href path 精确一致（覆盖 CMS 页面、商品分类导航、自定义链接等）
 *   2) 兜底：对系统导航（首页 / 商城 / 博客），按 $('#main')[data-nav-id] 做名称匹配
 *      （让商品详情、博客详情这种子页仍然高亮其父级导航）
 */
function updateNavActive(url) {
    var $nav = $('.main-nav');
    if (!$nav.length) return;

    // --- 规范化当前路径 ---
    var currentPath = '/';
    try {
        // url 可能是绝对地址（http://…/p/about）或相对（/p/about）
        var a = document.createElement('a');
        a.href = url || window.location.href;
        currentPath = a.pathname || '/';
    } catch (e) {
        currentPath = String(url || '/').split('?')[0].split('#')[0];
    }
    currentPath = '/' + currentPath.replace(/^\/+|\/+$/g, '');

    // 服务端按当前控制器塞入的 nav_id（page 控制器为空）
    var navId = $('#main').attr('data-nav-id') || '';

    $nav.find('a').removeClass('active');

    // 1) 先 URL path 精确匹配（首页除外，'/' 和任何页都不精确等于）
    var matched = false;
    $nav.find('a[href]').each(function () {
        var href = $(this).attr('href') || '';
        var itemPath = '/';
        try {
            var b = document.createElement('a');
            b.href = href;
            itemPath = b.pathname || '/';
        } catch (e) {}
        itemPath = '/' + itemPath.replace(/^\/+|\/+$/g, '');
        if (itemPath !== '/' && itemPath === currentPath) {
            $(this).addClass('active');
            matched = true;
        }
    });
    if (matched) return;

    // 2) 兜底：按系统名称匹配
    if (navId) {
        $nav.find('a').filter(function () {
            var text = $(this).text().trim();
            if (navId === 'home' && text === '首页') return true;
            if (navId === 'goods' && text === '商城') return true;
            if (navId === 'blog' && text === '博客') return true;
            return false;
        }).addClass('active');
    }
}

// ============================================================
// 购物车角标
// ============================================================
function updateCartBadge(count) {
    var $badge = $('#cartBadge');
    count = parseInt(count) || 0;
    if (count > 0) {
        $badge.text(count > 99 ? '99+' : count).show();
    } else {
        $badge.hide();
    }
}
// 初始加载购物车数量
$.get('?c=cart&a=count', function (res) {
    if (res.code === 200 && res.data) updateCartBadge(res.data.cart_count);
}, 'json');

// ============================================================
// PJAX 初始化
// ============================================================
(function () {
    var $bar = $('#pjaxBar');

    // 禁用浏览器默认的 scroll restoration
    if (window.history.scrollRestoration !== undefined) {
        window.history.scrollRestoration = 'manual';
    }

    // 对所有带 data-pjax 的链接启用 PJAX
    $(document).pjax('[data-pjax]', '#main', {
        fragment: '#main',
        timeout: 10000,
        scrollTo: false
    });

    // 对 #main 容器内的所有内部链接启用 PJAX
    $(document).on('click', '#main a[href]', function (e) {
        var href = this.href;
        if (!href || (href.indexOf('//') > -1 && href.indexOf(location.host) === -1)) return;
        if (href.indexOf('#') === 0) return;
        if (this.hasAttribute('download')) return;
        if (/^(mailto|tel|javascript):/i.test(href)) return;
        if (this.hasAttribute('data-pjax')) return;

        $.pjax.click(e, {
            url: href,
            container: '#main',
            fragment: '#main',
            timeout: 10000,
            scrollTo: false
        });
    });

    // 对带 data-pjax 的表单启用 PJAX 提交
    $(document).on('submit', 'form[data-pjax]', function (e) {
        $.pjax.submit(e, { container: '#main', fragment: '#main', timeout: 10000 });
    });

    // 进度条：开始
    $(document).on('pjax:send', function () {
        $bar.removeClass('done').addClass('running');
    });

    // 进度条：完成
    $(document).on('pjax:complete', function () {
        $bar.removeClass('running').addClass('done');
    });

    // 页面标题 & 导航更新
    $(document).on('pjax:success', function (event, data, status, xhr, options) {
        $bar.removeClass('running').addClass('done');
        var rawTitle = xhr.getResponseHeader('X-PJAX-Title');
        if (rawTitle) {
            try { rawTitle = decodeURIComponent(rawTitle); } catch (e) {}
            if (rawTitle) document.title = rawTitle;
        }
        // PJAX 不会替换 #main 自身的属性，需要从响应头同步 data-nav-id
        var navIdHeader = xhr.getResponseHeader('X-PJAX-Nav-Id');
        if (navIdHeader !== null) {
            try { navIdHeader = decodeURIComponent(navIdHeader); } catch (e) {}
            $('#main').attr('data-nav-id', navIdHeader || '');
        }
        updateNavActive(options.url);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // 错误/超时回退
    $(document).on('pjax:error', function (xhr, textStatus, error, options) {
        $bar.removeClass('running');
        if (textStatus === 'timeout') {
            var msg = document.createElement('div');
            msg.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:99999;';
            msg.innerHTML = '<div style="background:#fff;padding:24px 40px;border-radius:8px;text-align:center;"><div style="font-size:15px;margin-bottom:8px;">请求超时</div><div style="color:#999;font-size:13px;">正在跳转...</div></div>';
            document.body.appendChild(msg);
            setTimeout(function () {
                document.body.removeChild(msg);
                window.location.href = options.url;
            }, 1200);
        }
        return false;
    });

    // 清理进度条
    $(document).on('pjax:end', function () {
        setTimeout(function () {
            $bar.removeClass('running done');
        }, 200);
    });

    // 退出登录（直接跳转，刷新整页以更新页头状态）
    $(document).on('click', 'a[href*="a=logout"]', function (e) {
        e.preventDefault();
        location.href = this.href;
    });

    // 侧边栏分类手风琴折叠（全局委托，一次绑定，商品/博客侧边栏共用）
    $(document).on('click', '.sidebar-cat-arrow', function(){
        var $arrow = $(this);
        var $group = $arrow.closest('.sidebar-cat-group');
        var $children = $group.find('.sidebar-cat-children');
        if (!$children.length) return;

        if ($arrow.hasClass('is-open')) {
            $arrow.removeClass('is-open');
            $children.stop(true).slideUp(250);
        } else {
            // 同一侧边栏内只展开一个
            $group.siblings('.sidebar-cat-group').each(function(){
                var $other = $(this);
                var $otherArrow = $other.find('.sidebar-cat-arrow.is-open');
                if ($otherArrow.length) {
                    $otherArrow.removeClass('is-open');
                    $other.find('.sidebar-cat-children').stop(true).slideUp(250);
                }
            });
            $arrow.addClass('is-open');
            $children.stop(true).slideDown(250);
        }
    });
})();

// ============================================================
// 搜索弹窗
// ============================================================
(function () {
    var $modal = $('#searchModal');
    var $input = $('#searchModalInput');
    var searchType = 'all';

    // 打开弹窗
    $('#searchToggle').on('click', function (e) {
        e.preventDefault();
        $modal.addClass('active');
        setTimeout(function () { $input.focus(); }, 100);
    });

    // 关闭弹窗
    function closeModal() { $modal.removeClass('active'); }
    $modal.find('.search-modal-mask').on('click', closeModal);
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $modal.hasClass('active')) closeModal();
    });

    // 切换搜索类型
    $modal.on('click', '.search-modal-tab', function () {
        $modal.find('.search-modal-tab').removeClass('active');
        $(this).addClass('active');
        searchType = $(this).data('type');
    });

    // 提交搜索
    $('#searchModalForm').on('submit', function (e) {
        e.preventDefault();
        var q = $.trim($input.val());
        if (!q) return;
        closeModal();
        var url = '?c=search&q=' + encodeURIComponent(q) + '&type=' + searchType;
        $.pjax({ url: url, container: '#main', fragment: '#main', timeout: 10000, scrollTo: false });
    });

    // PJAX 导航时自动关闭弹窗
    $(document).on('pjax:start', closeModal);
})();

// ============================================================
// 页头：滚动阴影 + 移动端菜单
// ============================================================
(function () {
    var $header = $('#siteHeader');
    var $menuBtn = $('#menuToggle');
    var $mobileNav = $('#mobileNav');

    // 滚动超过 10px 后为页头添加阴影
    var ticking = false;
    window.addEventListener('scroll', function () {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(function () {
                $header.toggleClass('scrolled', window.scrollY > 10);
                ticking = false;
            });
        }
    });

    // 移动端菜单开关
    $menuBtn.on('click', function () {
        var isOpen = $menuBtn.hasClass('active');
        $menuBtn.toggleClass('active', !isOpen);
        $mobileNav.toggleClass('open', !isOpen);
    });

    // 移动端二级菜单折叠
    $(document).on('click', '.mobile-nav-toggle', function () {
        var $this = $(this);
        var $sub = $this.next('.mobile-nav-sub');
        var isOpen = $this.hasClass('open');
        // 先收起其他已展开的
        $('.mobile-nav-toggle.open').not($this).removeClass('open')
            .next('.mobile-nav-sub').removeClass('open');
        $this.toggleClass('open', !isOpen);
        $sub.toggleClass('open', !isOpen);
    });

    // PJAX 跳转时收起移动端菜单
    $(document).on('pjax:start', function () {
        $menuBtn.removeClass('active');
        $mobileNav.removeClass('open');
    });
})();
</script>

</body>
</html>
