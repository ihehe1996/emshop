<?php
/** @var array<string, mixed> $frontUser */
/** @var array<string, mixed> $currentMerchant */
/** @var array<string, mixed> $uc */

$csrfToken = Csrf::token();
?>
<div class="mc-page">
    <div class="mc-page-header">
        <h2 class="mc-page-title">余额明细</h2>
        <p class="mc-page-desc">店铺余额的所有变动流水，按月 / 按类型筛选</p>
    </div>

    <!-- 本月汇总 -->
    <div class="mc-stat-grid" id="mcFinSummary">
        <div class="mc-stat-card">
            <div class="mc-stat-icon" style="background:#eef2ff;color:#4e6ef2;"><i class="fa fa-rmb"></i></div>
            <div class="mc-stat-body">
                <div class="mc-stat-label">店铺余额</div>
                <div class="mc-stat-value"><?= htmlspecialchars((string) $uc['shopBalance']) ?></div>
            </div>
        </div>
        <div class="mc-stat-card">
            <div class="mc-stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-arrow-up"></i></div>
            <div class="mc-stat-body">
                <div class="mc-stat-label">本月进账</div>
                <div class="mc-stat-value" id="mcFinSumIncrease">—</div>
            </div>
        </div>
        <div class="mc-stat-card">
            <div class="mc-stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fa fa-arrow-down"></i></div>
            <div class="mc-stat-body">
                <div class="mc-stat-label">本月退款</div>
                <div class="mc-stat-value" id="mcFinSumRefund">—</div>
            </div>
        </div>
        <div class="mc-stat-card">
            <div class="mc-stat-icon" style="background:#fff7e6;color:#fa8c16;"><i class="fa fa-credit-card"></i></div>
            <div class="mc-stat-body">
                <div class="mc-stat-label">本月提现</div>
                <div class="mc-stat-value" id="mcFinSumWithdraw">—</div>
            </div>
        </div>
        <div class="mc-stat-card">
            <div class="mc-stat-icon" style="background:#ecfeff;color:#0891b2;"><i class="fa fa-share-alt"></i></div>
            <div class="mc-stat-body">
                <div class="mc-stat-label">本月子商返佣</div>
                <div class="mc-stat-value" id="mcFinSumSubRebate">—</div>
            </div>
        </div>
    </div>

    <div class="mc-section">
        <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
            <select id="mcFinType" style="width:160px;">
                <option value="">全部类型</option>
                <option value="increase">进账</option>
                <option value="refund">退款</option>
                <option value="withdraw">提现</option>
                <option value="withdraw_fee">提现手续费</option>
                <option value="decrease">减少（其他）</option>
                <option value="adjust">人工调整</option>
            </select>
            <input type="text" class="layui-input" id="mcFinMonth" placeholder="YYYY-MM" style="width:140px;">
            <button type="button" class="layui-btn" id="mcFinSearchBtn"><i class="fa fa-search"></i> 筛选</button>
            <button type="button" class="layui-btn layui-btn-primary" id="mcFinResetBtn"><i class="fa fa-rotate-left"></i> 重置</button>
        </div>

        <table id="mcFinTable" lay-filter="mcFinTable"></table>
    </div>
</div>

<style>
.mc-fin-type { padding: 2px 8px; border-radius: 10px; font-size: 12px; }
.mc-fin-type-increase    { background:#dcfce7;color:#166534; }
.mc-fin-type-refund      { background:#fef2f2;color:#b91c1c; }
.mc-fin-type-withdraw    { background:#fff7ed;color:#c2410c; }
.mc-fin-type-withdraw_fee{ background:#fef3c7;color:#92400e; }
.mc-fin-type-sub_rebate  { background:#ecfeff;color:#0e7490; }
.mc-fin-type-decrease    { background:#f3f4f6;color:#6b7280; }
.mc-fin-type-adjust      { background:#e0e7ff;color:#4338ca; }
</style>

<script type="text/html" id="mcFinTypeTpl">
    {{# var labels = {increase:'进账',refund:'退款',withdraw:'提现',withdraw_fee:'提现手续费',decrease:'减少',adjust:'调整'}; }}
    <span class="mc-fin-type mc-fin-type-{{ d.type }}">{{ labels[d.type] || d.type }}</span>
</script>

<script type="text/html" id="mcFinAmountTpl">
    {{# if(d.direction === '+'){ }}
        <span style="color:#16a34a;font-weight:600;">+{{ d.amount_view }}</span>
    {{# } else { }}
        <span style="color:#dc2626;font-weight:600;">-{{ d.amount_view }}</span>
    {{# } }}
</script>

<script type="text/html" id="mcFinBalanceTpl">
    <div style="line-height:1.4;text-align:right;font-size:12px;">
        <div>{{ d.after_view }}</div>
        <div style="color:#9ca3af;">前 {{ d.before_view }}</div>
    </div>
</script>

<script type="text/html" id="mcFinOrderTpl">
    {{# if(d.order_id > 0){ }}
        <span style="font-family:Consolas,Monaco,monospace;font-size:11px;color:#6b7280;">#{{ d.order_id }}</span>
    {{# } else { }}
        <span style="color:#cbd5e1;">—</span>
    {{# } }}
</script>

<script>
$(function(){
    'use strict';
    var csrfToken = <?php echo json_encode($csrfToken); ?>;

    layui.use(['layer', 'form', 'table', 'laydate'], function () {
        var layer = layui.layer;
        var form = layui.form;
        var table = layui.table;
        var laydate = layui.laydate;

        form.render('select');
        laydate.render({
            elem: '#mcFinMonth',
            type: 'month',
            format: 'yyyy-MM',
        });

        function where() {
            return {
                _action: 'list',
                type: $('#mcFinType').val() || '',
                month: $('#mcFinMonth').val() || ''
            };
        }

        table.render({
            elem: '#mcFinTable',
            id: 'mcFinTableId',
            url: '/user/merchant/finance.php',
            method: 'POST',
            where: where(),
            page: true,
            limit: 20,
            lineStyle: 'height: 50px;',
            cols: [[
                {title: '类型', width: 130, templet: '#mcFinTypeTpl', align: 'center'},
                {title: '金额', width: 140, templet: '#mcFinAmountTpl', align: 'right'},
                {title: '余额变化', width: 150, templet: '#mcFinBalanceTpl', align: 'right'},
                {title: '关联订单', width: 110, templet: '#mcFinOrderTpl', align: 'center'},
                {field: 'remark', title: '备注', minWidth: 200, align: 'left'},
                {field: 'created_at', title: '时间', minWidth: 150, align: 'center'}
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

        $(document).on('click', '#mcFinSearchBtn', function () {
            table.reload('mcFinTableId', {page: {curr: 1}, where: where()});
        });
        $(document).on('click', '#mcFinResetBtn', function () {
            $('#mcFinType').val('');
            $('#mcFinMonth').val('');
            form.render('select');
            table.reload('mcFinTableId', {page: {curr: 1}, where: where()});
        });

        // 加载本月汇总
        $.ajax({
            url: '/user/merchant/finance.php',
            type: 'POST',
            dataType: 'json',
            data: {_action: 'summary'},
            success: function (res) {
                if (res.code !== 200 || !res.data || !res.data.data) return;
                var s = res.data.data;
                $('#mcFinSumIncrease').text(s.increase || '—');
                $('#mcFinSumRefund').text(s.refund || '—');
                $('#mcFinSumWithdraw').text(s.withdraw || '—');
                $('#mcFinSumSubRebate').text(s.sub_rebate || '—');
            }
        });
    });
});
</script>
