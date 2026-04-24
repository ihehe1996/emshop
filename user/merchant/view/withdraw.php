<?php
/** @var array<string, mixed> $frontUser */
/** @var array<string, mixed> $currentMerchant */
/** @var array<string, mixed> $uc */
/** @var int $feeRate  商户等级提现手续费率（万分位） */

$csrfToken = Csrf::token();
$feeRateView = rtrim(rtrim(number_format($feeRate / 100, 2, '.', ''), '0'), '.');
?>
<div class="mc-page">
    <div class="mc-page-header">
        <h2 class="mc-page-title">提现申请</h2>
        <p class="mc-page-desc">店铺余额提现到消费余额，实时到账；手续费率 <strong><?= $feeRateView ?>%</strong></p>
    </div>

    <div style="display:grid;grid-template-columns:360px 1fr;gap:16px;align-items:start;">
        <!-- 左：申请卡 -->
        <div class="mc-section" style="margin-bottom:0;">
            <div class="mc-section-title">新增提现</div>

            <div style="padding:14px 16px;background:linear-gradient(135deg,#4e6ef2,#1890ff);color:#fff;border-radius:8px;margin-bottom:16px;">
                <div style="font-size:12px;opacity:.85;">可提现余额</div>
                <div style="font-size:24px;font-weight:600;letter-spacing:0.5px;"><?= htmlspecialchars((string) $uc['shopBalance']) ?></div>
            </div>

            <form id="mcWdForm" autocomplete="off">
                <input type="hidden" name="_action" value="apply">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:13px;color:#374151;margin-bottom:6px;">提现金额（<?= htmlspecialchars((string) $uc['currencySymbol']) ?>）</label>
                    <div class="layui-input-wrap">
                        <input type="number" class="layui-input" name="amount" id="mcWdAmount" step="0.01" min="0.01" placeholder="请输入金额">
                        <div class="layui-input-suffix"><?= htmlspecialchars((string) $uc['currencySymbol']) ?></div>
                    </div>
                    <div style="margin-top:6px;font-size:12px;" id="mcWdPreview">—</div>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                    <button type="button" class="layui-btn layui-btn-sm layui-btn-primary mc-wd-quick" data-ratio="0.25">25%</button>
                    <button type="button" class="layui-btn layui-btn-sm layui-btn-primary mc-wd-quick" data-ratio="0.5">50%</button>
                    <button type="button" class="layui-btn layui-btn-sm layui-btn-primary mc-wd-quick" data-ratio="1">全部</button>
                </div>

                <button type="button" class="layui-btn layui-btn-normal" id="mcWdSubmit" style="width:100%;">立即提现</button>

                <div style="margin-top:14px;padding:10px;background:#f9fafb;border-radius:6px;font-size:12px;color:#6b7280;line-height:1.7;">
                    <i class="fa fa-info-circle"></i> 提现到消费余额后可用于站内消费。<br>
                    手续费按毛额的 <strong><?= $feeRateView ?>%</strong> 计算并从店铺余额扣除，到账金额 = 申请 − 手续费。
                </div>
            </form>
        </div>

        <!-- 右：记录 -->
        <div class="mc-section" style="margin-bottom:0;">
            <div class="mc-section-title">提现记录</div>
            <table id="mcWdTable" lay-filter="mcWdTable"></table>
        </div>
    </div>
</div>

<style>
.mc-wd-status { padding: 2px 8px; border-radius: 10px; font-size: 12px; }
.mc-wd-status-done     { background:#dcfce7;color:#166534; }
.mc-wd-status-pending  { background:#fff7ed;color:#c2410c; }
.mc-wd-status-rejected { background:#fef2f2;color:#b91c1c; }
</style>

<script type="text/html" id="mcWdAmountTpl">
    <div style="text-align:right;line-height:1.4;font-size:12px;">
        <div style="color:#1f2937;font-weight:600;">{{ d.amount_view }}</div>
        {{# if(d.fee_amount && parseInt(d.fee_amount) > 0){ }}
        <div style="color:#9ca3af;">手续费 {{ d.fee_view }}</div>
        {{# } }}
    </div>
</script>

<script type="text/html" id="mcWdNetTpl">
    <span style="color:#16a34a;font-weight:600;">{{ d.net_view }}</span>
</script>

<script type="text/html" id="mcWdStatusTpl">
    {{# var labels = {done:'已到账',pending:'审核中',rejected:'已驳回'}; }}
    <span class="mc-wd-status mc-wd-status-{{ d.status }}">{{ labels[d.status] || d.status }}</span>
</script>

<script>
$(function(){
    'use strict';
    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var feeRate = <?= (int) $feeRate ?>;

    // 余额全部在访客当前展示币种语义下做加减；提交时再换回主货币传给后端
    var CUR = window.EMSHOP_CURRENCY || {symbol: '¥', rate: 1};
    var balanceRaw = <?= (int) ($uc['shopBalanceRaw'] ?? 0) ?>;       // BIGINT 主货币
    var balancePrimaryMain = balanceRaw / 1000000;                      // 主货币"元"
    var balanceVisitorMain = balancePrimaryMain * (CUR.rate || 1);      // 访客币"元"

    function toPrimaryMain(visitorMain) {
        return visitorMain / (CUR.rate || 1);
    }

    layui.use(['layer', 'table'], function () {
        var layer = layui.layer;
        var table = layui.table;

        function updatePreview() {
            var amtVisitor = parseFloat($('#mcWdAmount').val()) || 0;
            if (amtVisitor <= 0) { $('#mcWdPreview').html('<span style="color:#9ca3af;">输入金额后查看手续费和实到金额</span>'); return; }
            // 手续费按主货币毛额 × feeRate/10000 取整（后端口径一致），再换回访客币显示
            var amtPrimary = toPrimaryMain(amtVisitor);
            var feePrimary = Math.floor(amtPrimary * 1000000 * feeRate / 10000) / 1000000;
            var netPrimary = amtPrimary - feePrimary;
            var feeVisitor = feePrimary * (CUR.rate || 1);
            var netVisitor = netPrimary * (CUR.rate || 1);
            $('#mcWdPreview').html(
                '手续费 <strong>' + CUR.symbol + feeVisitor.toFixed(2) + '</strong>'
                + ' · 实到 <strong style="color:#16a34a;">' + CUR.symbol + netVisitor.toFixed(2) + '</strong>'
            );
        }
        $('#mcWdAmount').on('input', updatePreview);
        updatePreview();

        $(document).on('click', '.mc-wd-quick', function () {
            var ratio = parseFloat($(this).data('ratio')) || 1;
            $('#mcWdAmount').val((balanceVisitorMain * ratio).toFixed(2));
            updatePreview();
        });

        $('#mcWdSubmit').on('click', function () {
            var amtVisitor = parseFloat($('#mcWdAmount').val()) || 0;
            if (amtVisitor <= 0) { layer.msg('请填写金额'); return; }
            // +0.005 容忍浮点误差（100% 按钮回填时可能产生 0.00499 类误差）
            if (amtVisitor > balanceVisitorMain + 0.005) { layer.msg('超过可提现余额'); return; }

            var amtPrimary = toPrimaryMain(amtVisitor);
            var $btn = $(this);
            $btn.prop('disabled', true).text('处理中...');

            $.ajax({
                url: '/user/merchant/withdraw.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    _action: 'apply',
                    csrf_token: $('#mcWdForm [name="csrf_token"]').val(),
                    // 换算成主货币再入库；保留 6 位小数避免 round-trip 损失
                    amount: amtPrimary.toFixed(6)
                },
                success: function (res) {
                    if (res.code === 200) {
                        if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                        // 后端已返回带符号的完整字符串，直接拼接
                        layer.msg('提现 ' + res.data.amount + '，实到 ' + res.data.net, {time: 1200});
                        // 重载页（余额、记录同步）
                        setTimeout(function () { location.reload(); }, 1200);
                    } else {
                        layer.msg(res.msg || '提现失败');
                        $btn.prop('disabled', false).text('立即提现');
                    }
                },
                error: function () {
                    layer.msg('网络异常');
                    $btn.prop('disabled', false).text('立即提现');
                }
            });
        });

        table.render({
            elem: '#mcWdTable',
            id: 'mcWdTableId',
            url: '/user/merchant/withdraw.php',
            method: 'POST',
            where: {_action: 'list', csrf_token: csrfToken},
            page: true,
            limit: 10,
            lineStyle: 'height: 50px;',
            cols: [[
                {title: '申请金额', width: 150, templet: '#mcWdAmountTpl', align: 'right'},
                {title: '实到账', width: 110, templet: '#mcWdNetTpl', align: 'right'},
                {title: '状态', width: 90, templet: '#mcWdStatusTpl', align: 'center'},
                {field: 'created_at', title: '申请时间', minWidth: 150, align: 'center'},
                {field: 'audited_at', title: '到账时间', minWidth: 150, align: 'center'}
            ]],
            parseData: function (res) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                return {
                    'code': res.code === 200 ? 0 : res.code,
                    'msg': res.msg,
                    'data': res.data ? res.data.data : [],
                    'count': res.data ? res.data.total : 0
                };
            }
        });
    });
});
</script>
