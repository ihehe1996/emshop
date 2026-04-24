<?php
/** @var array<string, mixed> $currentMerchant */
/** @var array<string, mixed>|null $merchantLevel */
/** @var string $auditStatus  none / pending / enabled */
/** @var string $auditLabel */
/** @var string $auditColor */
/** @var string $configPretty */
/** @var string $feeRatePct */

$csrfToken = Csrf::token();
$esc = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
?>
<div class="mc-page">
    <div class="mc-page-header">
        <h2 class="mc-page-title">独立收款</h2>
        <p class="mc-page-desc">使用自己的支付通道直接接收自建商品的货款（手续费 <strong><?= $esc($feeRatePct) ?>%</strong> 仍挂账待结算）</p>
    </div>

    <!-- 当前状态 -->
    <div class="mc-section">
        <div class="mc-section-title">当前状态</div>
        <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
            <div>
                <div style="color:#9ca3af;font-size:12px;margin-bottom:4px;">审核状态</div>
                <?php
                $badgeBg = [
                    'enabled' => '#dcfce7',
                    'pending' => '#fff7ed',
                    'none'    => '#f3f4f6',
                ][$auditStatus] ?? '#f3f4f6';
                $badgeFg = [
                    'enabled' => '#166534',
                    'pending' => '#c2410c',
                    'none'    => '#6b7280',
                ][$auditStatus] ?? '#6b7280';
                ?>
                <span style="padding:6px 14px;border-radius:14px;font-size:13px;font-weight:500;background:<?= $badgeBg ?>;color:<?= $badgeFg ?>;">
                    <?= $esc($auditLabel) ?>
                </span>
            </div>
            <div style="margin-left:20px;color:#6b7280;font-size:12px;line-height:1.7;max-width:560px;">
                <?php if ($auditStatus === 'enabled'): ?>
                    独立收款已生效。买家购买本店的自建商品时款项将直接进入商户账户。
                <?php elseif ($auditStatus === 'pending'): ?>
                    配置已提交，等待主站管理员审核。审核期间仍走主站统一收款。
                <?php else: ?>
                    尚未提交独立收款配置。开启后仅对自建商品生效；订单含主站商品时强制走主站通道。
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- v1 说明 -->
    <div class="mc-section" style="background:#fffbeb;border-color:#fde68a;">
        <div style="display:flex;gap:10px;">
            <i class="fa fa-exclamation-triangle" style="color:#f59e0b;font-size:18px;margin-top:2px;"></i>
            <div style="flex:1;color:#78350f;font-size:13px;line-height:1.8;">
                <strong>v1 交付说明</strong><br>
                当前版本已落地独立收款所需的字段（pay_channel_config / own_pay_enabled / order.pay_channel 等），但实际支付通道切换留待 v2 接入商户安装的支付插件后再启用。
                v1 启用状态下订单仍走主站统一收款，账面按 S3b 记账方式挂账手续费。
            </div>
        </div>
    </div>

    <!-- 配置 -->
    <div class="mc-section">
        <div class="mc-section-title">支付通道配置</div>

        <form id="mcPayForm">
            <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
            <input type="hidden" name="_action" value="save_config">

            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:13px;color:#374151;margin-bottom:6px;">通道配置 JSON</label>
                <textarea class="layui-textarea" name="pay_channel_config" id="mcPayConfig" rows="12"
                          style="font-family:Consolas,Monaco,monospace;font-size:13px;"
                          placeholder='{
  "wechat": {
    "mch_id": "",
    "app_id": "",
    "key": ""
  },
  "alipay": {
    "app_id": "",
    "private_key": ""
  }
}'><?= $esc($configPretty) ?></textarea>
                <div style="margin-top:6px;color:#9ca3af;font-size:12px;">
                    具体字段由支付插件定义。保存后会重置审核状态为"审核中"。
                </div>
            </div>

            <div style="display:flex;gap:10px;">
                <button type="button" class="layui-btn" id="mcPaySaveBtn">保存配置</button>
                <button type="button" class="layui-btn layui-btn-primary" id="mcPaySubmitBtn"
                        <?= $auditStatus === 'none' ? 'disabled' : '' ?>>提交审核</button>
            </div>
        </form>
    </div>

    <!-- 可用支付插件列表（占位） -->
    <div class="mc-section">
        <div class="mc-section-title">可用支付插件</div>
        <div class="mc-placeholder" style="padding:40px 20px;">
            <i class="fa fa-plug"></i>
            <div>商户插件商店即将开放</div>
            <div style="margin-top:6px;font-size:12px;">届时可在 <a href="/user/merchant/plugin.php" data-pjax="#merchantContent" style="color:#1890ff;">插件管理</a> 购买 / 安装独立收款插件</div>
        </div>
    </div>
</div>

<script>
$(function(){
    'use strict';
    var csrfToken = <?php echo json_encode($csrfToken); ?>;

    layui.use(['layer'], function () {
        var layer = layui.layer;

        $('#mcPaySaveBtn').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text('保存中...');
            $.ajax({
                url: '/user/merchant/payment.php',
                type: 'POST',
                dataType: 'json',
                data: $('#mcPayForm').serialize(),
                success: function (res) {
                    if (res.code === 200) {
                        if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                        layer.msg(res.msg || '已保存', {time: 1000}, function () { location.reload(); });
                    } else {
                        layer.msg(res.msg || '保存失败');
                        $btn.prop('disabled', false).text('保存配置');
                    }
                },
                error: function () {
                    layer.msg('网络异常');
                    $btn.prop('disabled', false).text('保存配置');
                }
            });
        });

        $('#mcPaySubmitBtn').on('click', function () {
            if ($(this).prop('disabled')) return;
            layer.confirm('确定提交审核？主站管理员审核通过后独立收款生效。', function (idx) {
                $.ajax({
                    url: '/user/merchant/payment.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {_action: 'submit_audit', csrf_token: csrfToken},
                    success: function (res) {
                        layer.close(idx);
                        if (res.code === 200) {
                            if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                            layer.msg(res.msg || '已提交审核');
                        } else {
                            layer.msg(res.msg || '提交失败');
                        }
                    }
                });
            });
        });
    });
});
</script>
