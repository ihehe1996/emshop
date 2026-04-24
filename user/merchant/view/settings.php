<?php
/** @var array<string, mixed> $frontUser */
/** @var array<string, mixed> $currentMerchant */
/** @var array<string, mixed>|null $merchantLevel */
/** @var array<string, mixed> $uc */
/** @var string $mainDomain */
/** @var string $customDomainTip */

$csrfToken = Csrf::token();
$esc = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
$allowSubdomain = (int) ($merchantLevel['allow_subdomain'] ?? 0) === 1;
$allowCustom = (int) ($merchantLevel['allow_custom_domain'] ?? 0) === 1;
$domainVerified = (int) ($currentMerchant['domain_verified'] ?? 0) === 1;
?>
<div class="mc-page">
    <div class="mc-page-header">
        <h2 class="mc-page-title">店铺设置</h2>
        <p class="mc-page-desc">店铺信息 / 域名绑定</p>
    </div>

    <!-- 基本信息 -->
    <div class="mc-settings-card">
        <div class="mc-settings-card__title"><i class="fa fa-info-circle"></i> 基本信息</div>
        <form id="mcProfileForm">
            <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
            <input type="hidden" name="_action" value="save_profile">

            <div class="mc-field">
                <label class="mc-field__label">店铺名</label>
                <input type="text" class="mc-input" name="name" maxlength="100"
                       value="<?= $esc((string) $currentMerchant['name']) ?>">
            </div>

            <div class="mc-field">
                <label class="mc-field__label">slug</label>
                <input type="text" class="mc-input mc-input--readonly" readonly
                       value="<?= $esc((string) $currentMerchant['slug']) ?>">
                <div class="mc-field__hint">开通后不可修改</div>
            </div>

            <div class="mc-field">
                <label class="mc-field__label">Logo URL</label>
                <input type="text" class="mc-input" name="logo" maxlength="500"
                       placeholder="店铺 Logo 图片地址"
                       value="<?= $esc((string) ($currentMerchant['logo'] ?? '')) ?>">
            </div>

            <div class="mc-field">
                <label class="mc-field__label">Slogan</label>
                <input type="text" class="mc-input" name="slogan" maxlength="255"
                       placeholder="店铺一句话介绍"
                       value="<?= $esc((string) ($currentMerchant['slogan'] ?? '')) ?>">
            </div>

            <div class="mc-field">
                <label class="mc-field__label">详细介绍</label>
                <textarea class="mc-input mc-input--textarea" name="description" rows="4"><?= htmlspecialchars((string) ($currentMerchant['description'] ?? ''), ENT_QUOTES) ?></textarea>
            </div>

            <div class="mc-field">
                <label class="mc-field__label">备案号</label>
                <input type="text" class="mc-input" name="icp" maxlength="100"
                       placeholder="选填"
                       value="<?= $esc((string) ($currentMerchant['icp'] ?? '')) ?>">
            </div>

            <?php
            // 默认加价率：万分位 → 百分数显示（1000 → "10"、1050 → "10.5"）
            $defaultMarkupRaw = (int) ($currentMerchant['default_markup_rate'] ?? 1000);
            $defaultMarkupPct = number_format($defaultMarkupRaw / 100, 2, '.', '');
            $defaultMarkupPct = rtrim(rtrim($defaultMarkupPct, '0'), '.') ?: '0';
            ?>
            <div class="mc-field">
                <label class="mc-field__label">默认加价率</label>
                <div class="mc-input-group">
                    <input type="number" class="mc-input" name="default_markup_rate"
                           step="0.01" min="0" max="1000"
                           value="<?= $esc($defaultMarkupPct) ?>">
                    <span class="mc-input-group__suffix">%</span>
                </div>
                <div class="mc-field__hint">主站商品在本店的默认加价率；单个商品可在"商品管理"里单独覆盖。填 10 表示 10%</div>
            </div>

            <div class="mc-field mc-field--action">
                <button type="button" class="mc-save-btn" id="mcProfileSubmit">
                    <i class="fa fa-check"></i> 保存基本信息
                </button>
            </div>
        </form>
    </div>

    <!-- 域名绑定 -->
    <div class="mc-settings-card">
        <div class="mc-settings-card__title"><i class="fa fa-globe"></i> 域名绑定</div>

        <form id="mcDomainForm">
            <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
            <input type="hidden" name="_action" value="save_domain">

            <!-- 二级域名 -->
            <div class="mc-field">
                <label class="mc-field__label">二级域名</label>
                <?php if ($allowSubdomain): ?>
                <div class="mc-input-group">
                    <input type="text" class="mc-input" name="subdomain" maxlength="64"
                           placeholder="shop1"
                           value="<?= $esc((string) ($currentMerchant['subdomain'] ?? '')) ?>">
                    <?php if ($mainDomain !== ''): ?>
                    <span class="mc-input-group__suffix">.<?= $esc($mainDomain) ?></span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <input type="text" class="mc-input mc-input--readonly" readonly placeholder="当前等级不允许">
                <?php endif; ?>
                <div class="mc-field__hint">
                    <?php if ($allowSubdomain): ?>
                        <?php if ($mainDomain === ''): ?>
                        主站根域名未配置，二级域名暂不生效
                        <?php else: ?>
                        需在 DNS 中把 <code><?= $esc($mainDomain) ?></code> 的 <code>*</code> 子域名 CNAME 指向主站
                        <?php endif; ?>
                    <?php else: ?>
                        当前等级不允许绑定二级域名
                    <?php endif; ?>
                </div>
            </div>

            <!-- 自定义顶级域名 -->
            <div class="mc-field">
                <label class="mc-field__label">自定义域名</label>
                <?php if ($allowCustom): ?>
                <input type="text" class="mc-input" name="custom_domain" maxlength="200"
                       placeholder="www.myshop.com"
                       value="<?= $esc((string) ($currentMerchant['custom_domain'] ?? '')) ?>">
                <?php else: ?>
                <input type="text" class="mc-input mc-input--readonly" readonly placeholder="当前等级不允许">
                <?php endif; ?>
                <div class="mc-field__hint">
                    <?php if ($allowCustom): ?>
                        <?php if (!empty($currentMerchant['custom_domain'])): ?>
                            状态：
                            <?php if ($domainVerified): ?>
                            <span class="mc-badge mc-badge--success">已验证</span>
                            <?php else: ?>
                            <span class="mc-badge mc-badge--pending">待主站审核</span>
                            <?php endif; ?>
                            &nbsp;|&nbsp;
                        <?php endif; ?>
                        <?= $customDomainTip !== '' ? $esc($customDomainTip) : '域名改动后需主站管理员审核方生效' ?>
                    <?php else: ?>
                        当前等级不允许绑定自定义顶级域名
                    <?php endif; ?>
                </div>
            </div>

            <div class="mc-field mc-field--action">
                <button type="button" class="mc-save-btn" id="mcDomainSubmit"
                        <?= ($allowSubdomain || $allowCustom) ? '' : 'disabled' ?>>
                    <i class="fa fa-check"></i> 保存域名设置
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ===== 店铺设置卡片 & 表单控件（绕开 layui form 默认样式） ===== */
.mc-settings-card {
    background:#fff; border-radius:10px; padding:22px 24px; margin-bottom:16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.mc-settings-card__title {
    font-size:15px; font-weight:600; color:#1f2937;
    padding-bottom:14px; margin-bottom:18px;
    border-bottom:1px solid #f0f1f4;
    display:flex; align-items:center; gap:8px;
}
.mc-settings-card__title .fa { color:#4e6ef2; }

.mc-field { margin-bottom:18px; max-width:560px; }
.mc-field--action { margin-top:24px; margin-bottom:0; }
.mc-field__label {
    display:block; font-size:13px; color:#374151;
    margin-bottom:8px; font-weight:500;
}
.mc-field__hint {
    margin-top:6px; font-size:12px; color:#9ca3af; line-height:1.7;
}
.mc-field__hint code {
    padding:1px 6px; background:#f3f4f6; border-radius:3px;
    font-family:Consolas,Monaco,monospace; font-size:11px; color:#4b5563;
}

.mc-input {
    display:block; width:100%; box-sizing:border-box;
    padding:8px 12px; font-size:14px; color:#1f2937;
    border:1px solid #e5e7eb; border-radius:6px; outline:none;
    background:#fff; transition: border-color .15s, box-shadow .15s;
    font-family: inherit;
}
.mc-input:focus { border-color:#4e6ef2; box-shadow: 0 0 0 3px rgba(78,110,242,0.08); }
.mc-input--readonly { background:#f9fafb; color:#6b7280; cursor:not-allowed; }
.mc-input--readonly:focus { border-color:#e5e7eb; box-shadow:none; }
.mc-input--textarea { resize: vertical; min-height:96px; line-height:1.6; }

.mc-input-group {
    display:flex; align-items:stretch;
    border:1px solid #e5e7eb; border-radius:6px; overflow:hidden;
    transition: border-color .15s, box-shadow .15s;
}
.mc-input-group:focus-within { border-color:#4e6ef2; box-shadow: 0 0 0 3px rgba(78,110,242,0.08); }
.mc-input-group .mc-input { border:0; border-radius:0; }
.mc-input-group .mc-input:focus { box-shadow:none; }
.mc-input-group__suffix {
    display:flex; align-items:center; padding:0 12px;
    background:#f5f7fa; color:#6b7280; font-size:13px;
}

.mc-save-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:9px 22px; border:0; border-radius:6px;
    background:#4e6ef2; color:#fff; font-size:14px; cursor:pointer;
    transition: background .15s, box-shadow .15s;
}
.mc-save-btn:hover { background:#3d5bd9; box-shadow: 0 2px 8px rgba(78,110,242,0.25); }
.mc-save-btn:disabled { background:#d1d5db; cursor:not-allowed; box-shadow:none; }

.mc-badge {
    display:inline-block; padding:1px 8px; border-radius:10px; font-size:12px;
}
.mc-badge--success { background:#ecfdf5; color:#059669; }
.mc-badge--pending { background:#f3f4f6; color:#6b7280; }
</style>

<script>
$(function(){
    'use strict';
    var csrfToken = <?php echo json_encode($csrfToken); ?>;

    layui.use(['layer'], function () {
        var layer = layui.layer;

        function submitForm(formSel, $btn, originalHtml) {
            var $b = $btn;
            $b.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 保存中...');
            $.ajax({
                url: '/user/merchant/settings.php',
                type: 'POST',
                dataType: 'json',
                data: $(formSel).serialize(),
                success: function (res) {
                    if (res.code === 200) {
                        if (res.data && res.data.csrf_token) {
                            csrfToken = res.data.csrf_token;
                            $('input[name="csrf_token"]').val(csrfToken);
                        }
                        layer.msg(res.msg || '保存成功');
                    } else {
                        layer.msg(res.msg || '保存失败');
                    }
                },
                error: function () { layer.msg('网络异常'); },
                complete: function () { $b.prop('disabled', false).html(originalHtml); }
            });
        }

        $('#mcProfileSubmit').on('click', function () {
            submitForm('#mcProfileForm', $(this), '<i class="fa fa-check"></i> 保存基本信息');
        });
        $('#mcDomainSubmit').on('click', function () {
            submitForm('#mcDomainForm', $(this), '<i class="fa fa-check"></i> 保存域名设置');
        });
    });
});
</script>
