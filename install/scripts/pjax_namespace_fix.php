<?php
/**
 * 一次性脚本：给 view 文件里的 $(document).on / $(window).on 加 PJAX 命名空间，
 * 并在 $(function(){ ... }) 顶部插入 $(document).off('.<ns>') / $(window).off('.<ns>')。
 *
 * 这样 PJAX 反复进同一页时旧的 delegated handler 会被先 off 掉，避免点击成倍触发。
 *
 * 跑完后语法 / 行为不变，只是事件挂的时候多了命名空间后缀。
 */

declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/../..');

// [文件相对路径 → 命名空间]
// 已修过的 3 个（admin home / 商户分类 / 商户商品）不在列表里
// 跳过：popup（iframe 打开就刷新，无 PJAX 累积）、index 布局壳（首屏一次加载）
$targets = [
    // admin/view
    'admin/view/attachment.php'      => 'admAttach',
    'admin/view/blog.php'            => 'admBlog',
    'admin/view/blog_category.php'   => 'admBlogCat',
    'admin/view/blog_comment.php'    => 'admBlogComment',
    'admin/view/blog_tag.php'        => 'admBlogTag',
    'admin/view/category.php'        => 'admCategory',
    'admin/view/category_temp.php'   => 'admCategoryTemp',
    'admin/view/commission.php'      => 'admCommission',
    'admin/view/coupon.php'          => 'admCoupon',
    'admin/view/currency.php'        => 'admCurrency',
    'admin/view/friend_link.php'     => 'admFriendLink',
    'admin/view/goods.php'           => 'admGoods',
    'admin/view/goods_category.php'  => 'admGoodsCat',
    'admin/view/goods_tag.php'       => 'admGoodsTag',
    'admin/view/lang.php'            => 'admLang',
    'admin/view/language.php'        => 'admLanguage',
    'admin/view/media.php'           => 'admMedia',
    'admin/view/merchant.php'        => 'admMerchant',
    'admin/view/merchant_level.php'  => 'admMerchantLevel',
    'admin/view/multi_spec_modal.php'=> 'admMultiSpec',
    'admin/view/navi.php'            => 'admNavi',
    'admin/view/order.php'           => 'admOrder',
    'admin/view/page.php'            => 'admPage',
    'admin/view/plugin.php'          => 'admPlugin',
    'admin/view/profile.php'         => 'admProfile',
    'admin/view/recharge.php'        => 'admRecharge',
    'admin/view/settings.php'        => 'admSettings',
    'admin/view/swoole.php'          => 'admSwoole',
    'admin/view/system_log.php'      => 'admSystemLog',
    'admin/view/user_level.php'      => 'admUserLevel',
    'admin/view/user_list.php'       => 'admUserList',
    'admin/view/withdraw.php'        => 'admWithdraw',
    // user/merchant/view
    'user/merchant/view/apply.php'   => 'mcApplyPage',
    'user/merchant/view/finance.php' => 'mcFinPage',
    'user/merchant/view/media.php'   => 'mcMediaPage',
    'user/merchant/view/order.php'   => 'mcOrderPage',
    'user/merchant/view/withdraw.php'=> 'mcWdPage',
    // user/view
    'user/view/address.php'          => 'ucAddrPage',
    'user/view/api.php'              => 'ucApiPage',
    'user/view/rebate.php'           => 'ucRebatePage',
    'user/view/wallet.php'           => 'ucWalletPage',
];

$fixed = 0;
$skipped = 0;
$failed = [];

foreach ($targets as $relPath => $ns) {
    $abs = $ROOT . '/' . $relPath;
    if (!is_file($abs)) {
        $failed[] = "$relPath（文件不存在）";
        continue;
    }
    $src = file_get_contents($abs);
    if ($src === false) {
        $failed[] = "$relPath（读取失败）";
        continue;
    }
    $orig = $src;

    // 1. 给 $(document).on('xxx', ...) 加命名空间 —— 仅当事件名是纯字母（无 . 表示已加过命名空间）
    //    匹配示例：$(document).on('click', '.foo', ...) → $(document).on('click.<ns>', '.foo', ...)
    $src = preg_replace_callback(
        '/\$\(document\)\.on\(\s*\'([a-zA-Z]+)\'/',
        static function ($m) use ($ns) {
            return '$(document).on(\'' . $m[1] . '.' . $ns . '\'';
        },
        $src
    );
    // 2. $(window).on 同理（admin/view/home.php 走过 resize 这种）
    $src = preg_replace_callback(
        '/\$\(window\)\.on\(\s*\'([a-zA-Z]+)\'/',
        static function ($m) use ($ns) {
            return '$(window).on(\'' . $m[1] . '.' . $ns . '\'';
        },
        $src
    );

    if ($src === $orig) {
        // 没匹配到 = 可能事件已经全部带过命名空间，跳过
        $skipped++;
        continue;
    }

    // 3. 在 $(function(){...}) 第一个块顶部插入 off 调用
    //    目标：找到第一个 $(function () { 或 $(function(){
    //    在它后面紧跟一行插入 off 注释 + 两条 off
    $offBlock =
        "\n    // PJAX 防重复绑定：清掉本页历史 .{$ns} handler，避免事件成倍触发\n" .
        "    \$(document).off('.{$ns}');\n" .
        "    \$(window).off('.{$ns}');\n";

    // 仅替换第一次出现：$(function () {  或  $(function(){
    $patterns = [
        '/\$\(function \(\) \{/',
        '/\$\(function\(\)\{/',
    ];
    $injected = false;
    foreach ($patterns as $p) {
        $tmp = preg_replace($p, '$0' . $offBlock, $src, 1, $count);
        if ($count > 0) {
            $src = $tmp;
            $injected = true;
            break;
        }
    }

    if (!$injected) {
        // 没找到 IIFE 起点 —— 不动，记录跳过
        $failed[] = "$relPath（找不到 \$(function(){）";
        continue;
    }

    file_put_contents($abs, $src);
    $fixed++;
    echo "✓ $relPath  → .$ns\n";
}

echo "\n========\n";
echo "已修复 $fixed 个文件\n";
echo "已跳过 $skipped 个文件（事件已全带命名空间）\n";
if ($failed) {
    echo "失败 " . count($failed) . " 项：\n";
    foreach ($failed as $f) echo "  - $f\n";
}
