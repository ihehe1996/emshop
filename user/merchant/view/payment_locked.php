<?php
/** @var array<string, mixed>|null $merchantLevel */
?>
<div class="mc-page">
    <div class="mc-page-header">
        <h2 class="mc-page-title">独立收款</h2>
        <p class="mc-page-desc">使用自己的支付通道直接接收自建商品的货款</p>
    </div>
    <div class="mc-placeholder">
        <i class="fa fa-lock"></i>
        <div>当前商户等级「<?= htmlspecialchars((string) ($merchantLevel['name'] ?? '—')) ?>」不允许独立收款</div>
        <div style="margin-top:8px;font-size:12px;">如需开启，请联系主站管理员升级等级</div>
    </div>
</div>
