<?php
/** @var array<string, mixed> $frontUser */
/** @var string $siteName */
/** @var bool $selfOpenEnabled */
/** @var array<int, array<string, mixed>> $levels */
/** @var string $csrfToken */
/** @var string $displayMoney */
/** @var string $currencySymbol */

$esc = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$tip = (string) (Config::get('merchant_custom_domain_tip') ?? '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>开通分站 - <?= $esc($siteName) ?></title>
    <link rel="stylesheet" href="/content/static/lib/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <link rel="stylesheet" href="/user/static/css/user.css">
    <link rel="stylesheet" href="/user/merchant/static/css/merchant.css?v=<?= @filemtime(EM_ROOT . '/user/merchant/static/css/merchant.css') ?: time() ?>">
    <script src="/content/static/lib/jquery.min.3.5.1.js"></script>
    <script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
</head>
<body>

<header class="uc-header">
    <div class="uc-header-inner">
        <a href="/" class="uc-header-logo"><?= $esc($siteName) ?></a>
        <span class="uc-header-title">开通分站</span>
        <div class="uc-header-right">
            <a href="/user/home.php" class="uc-header-link"><i class="fa fa-user"></i> 个人中心</a>
            <a href="/" class="uc-header-link"><i class="fa fa-home"></i> 返回首页</a>
        </div>
    </div>
</header>

<div class="mc-apply-wrap">
    <h1 class="mc-apply-title"><i class="fa fa-sitemap" style="color:#1890ff;"></i> 开通分站</h1>
    <p class="mc-apply-desc">
        成为分站后可使用主站商品转售 / 自建商品 / 独立分站页面等能力。<br>
        开通费用将从账户余额扣除。
    </p>

    <?php if (!$selfOpenEnabled): ?>
    <div style="padding:40px 20px;text-align:center;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;color:#c2410c;">
        <i class="fa fa-exclamation-triangle" style="font-size:28px;margin-bottom:10px;display:block;"></i>
        当前暂未开放自助开通，请联系管理员协助开通。
    </div>

    <?php elseif ($levels === []): ?>
    <div style="padding:40px 20px;text-align:center;background:#f3f4f6;border:1px dashed #d1d5db;border-radius:8px;color:#6b7280;">
        <i class="fa fa-inbox" style="font-size:28px;margin-bottom:10px;display:block;"></i>
        当前暂无可自助开通的分站等级。
    </div>

    <?php else: ?>
    <?php if (!empty($inviterMerchant)): ?>
    <div style="margin-bottom:18px;padding:12px 14px;background:#ecfeff;border:1px solid #a5f3fc;border-radius:6px;color:#0e7490;font-size:13px;line-height:1.7;">
        <i class="fa fa-share-alt"></i> 您通过分站「<strong><?= $esc((string) $inviterMerchant['name']) ?></strong>」的邀请链接到达，开通成功后将自动成为其下级分站
    </div>
    <?php endif; ?>
    <form id="mcApplyForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">

        <div class="mc-apply-form-row">
            <label>选择分站等级</label>
            <?php foreach ($levels as $i => $lv):
                // 等级开通价按访客当前展示币种换算（不含符号，下方 span 前面会拼 $currencySymbol）
                $priceYuan = Currency::displayAmount((int) $lv['price'], null, false);
                $feeRate = rtrim(rtrim(number_format(((int) $lv['self_goods_fee_rate']) / 100, 2, '.', ''), '0'), '.');
                $wdFee = rtrim(rtrim(number_format(((int) $lv['withdraw_fee_rate']) / 100, 2, '.', ''), '0'), '.');
            ?>
            <label class="mc-apply-level <?= $i === 0 ? 'is-selected' : '' ?>" data-lvl-id="<?= (int) $lv['id'] ?>">
                <div class="mc-apply-level__head">
                    <span>
                        <input type="radio" name="level_id" value="<?= (int) $lv['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                        <span class="mc-apply-level__name"><?= $esc($lv['name']) ?></span>
                    </span>
                    <span class="mc-apply-level__price"><?= $esc($currencySymbol) ?><?= $esc($priceYuan) ?></span>
                </div>
                <div class="mc-apply-level__meta">
                    自建手续费 <?= $feeRate ?>% · 提现手续费 <?= $wdFee ?>%
                </div>
            </label>
            <?php endforeach; ?>
        </div>

        <div class="mc-apply-form-row">
            <label>分站 URL（slug）</label>
            <input type="text" name="slug" id="mcApplySlug" maxlength="32" placeholder="3-32 字符，字母 / 数字 / 短横线">
            <div class="mc-apply-form-tip">开通后分站地址为 /s/{slug}/，开通后不可更改</div>
        </div>

        <div class="mc-apply-form-row">
            <label>分站名称</label>
            <input type="text" name="name" id="mcApplyName" maxlength="100" placeholder="分站对外展示名称">
        </div>

        <div class="mc-apply-form-row" style="padding:12px 14px;background:#f0f8ff;border-radius:6px;color:#1e40af;font-size:13px;">
            <div>账户余额：<strong><?= $esc($currencySymbol) ?><?= $displayMoney ?></strong></div>
            <div style="margin-top:4px;font-size:12px;color:#64748b;">开通费用将从此余额扣除，请确保余额充足</div>
        </div>

        <div class="mc-apply-actions">
            <button type="button" class="mc-apply-btn" id="mcApplySubmit">立即开通</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
$(function () {
    layui.use(['layer'], function () {
        var layer = layui.layer;

        // 等级卡片点击高亮
        $(document).on('click', '.mc-apply-level', function () {
            $('.mc-apply-level').removeClass('is-selected');
            $(this).addClass('is-selected');
            $(this).find('input[type="radio"]').prop('checked', true);
        });

        $('#mcApplySubmit').on('click', function () {
            var slug = $.trim($('#mcApplySlug').val());
            var name = $.trim($('#mcApplyName').val());
            var levelId = $('input[name="level_id"]:checked').val();

            if (!levelId) { layer.msg('请选择等级'); return; }
            if (!/^[a-z0-9]([a-z0-9\-]{1,30})[a-z0-9]$/i.test(slug)) {
                layer.msg('slug 格式不合法');
                return;
            }
            if (!name) { layer.msg('请填写分站名称'); return; }

            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fa fa-refresh fa-spin"></i> 开通中...');

            $.ajax({
                url: '/user/merchant/apply.php',
                type: 'POST',
                dataType: 'json',
                data: $('#mcApplyForm').serialize(),
                success: function (res) {
                    if (res.code === 200) {
                        layer.msg(res.msg || '开通成功', {time: 800});
                        setTimeout(function () {
                            location.href = (res.data && res.data.redirect) || '/user/merchant/home.php';
                        }, 800);
                    } else {
                        layer.msg(res.msg || '开通失败');
                        $btn.prop('disabled', false).text('立即开通');
                    }
                },
                error: function () {
                    layer.msg('网络错误，请重试');
                    $btn.prop('disabled', false).text('立即开通');
                }
            });
        });
    });
});
</script>

</body>
</html>
