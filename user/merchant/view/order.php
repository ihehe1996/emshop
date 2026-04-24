<?php
/** @var array<string, mixed> $frontUser */
/** @var array<string, mixed> $currentMerchant */
/** @var array<string, mixed> $uc */
$csrfToken = Csrf::token();
?>
<div class="mc-page">
    <div class="mc-page-header">
        <h2 class="mc-page-title">订单管理</h2>
        <p class="mc-page-desc">只读视图：订单状态由主站维护，这里可查看每项的拿货价 / 手续费快照与净收入</p>
    </div>

    <!-- 状态筛选 tabs（参考 /user/order.php：卡片式胶囊按钮 + 计数徽章） -->
    <div class="uc-order-tabs" id="mcOrderTabs">
        <a href="javascript:;" class="uc-order-tab is-active" data-status="all">全部</a>
        <a href="javascript:;" class="uc-order-tab" data-status="pending">待付款</a>
        <a href="javascript:;" class="uc-order-tab" data-status="paid">待发货</a>
        <a href="javascript:;" class="uc-order-tab" data-status="delivered">待收货</a>
        <a href="javascript:;" class="uc-order-tab" data-status="completed">已完成</a>
        <a href="javascript:;" class="uc-order-tab" data-status="refunded">已退款</a>
        <a href="javascript:;" class="uc-order-tab" data-status="cancelled">已取消</a>
    </div>

    <div class="mc-section">
        <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
            <input type="text" class="layui-input" id="mcOrderKeyword" placeholder="订单号 / 联系信息搜索" style="width:260px;">
            <button type="button" class="layui-btn" id="mcOrderSearchBtn"><i class="fa fa-search"></i> 搜索</button>
            <button type="button" class="layui-btn layui-btn-primary" id="mcOrderResetBtn"><i class="fa fa-rotate-left"></i> 重置</button>
        </div>

        <table id="mcOrderTable" lay-filter="mcOrderTable"></table>
    </div>
</div>

<style>
.uc-order-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
.uc-order-tab {
    padding:8px 16px; border-radius:8px; font-size:14px;
    color:#666; transition: all 0.2s; background:#fff;
    display:inline-flex; align-items:center;
}
.uc-order-tab:hover { background:#f5f7fa; color:#333; }
.uc-order-tab.is-active { background:#eef2ff; color:#4e6ef2; font-weight:500; }
.uc-order-tab__count {
    display:inline-block; min-width:18px; padding:0 5px;
    margin-left:4px; border-radius:9px;
    background:#ffece8; color:#fa5252;
    font-size:11px; line-height:16px; text-align:center;
    vertical-align:1px;
}
.uc-order-tab.is-active .uc-order-tab__count { background:#4e6ef2; color:#fff; }
.mc-status-badge { padding: 2px 8px; border-radius: 10px; font-size: 12px; }
.mc-status-pending    { background:#fff7ed;color:#c2410c; }
.mc-status-paid       { background:#eff6ff;color:#1d4ed8; }
.mc-status-delivering { background:#f0f9ff;color:#0369a1; }
.mc-status-delivered  { background:#f0fdf4;color:#15803d; }
.mc-status-completed  { background:#dcfce7;color:#166534; }
.mc-status-refunding  { background:#fef2f2;color:#b91c1c; }
.mc-status-refunded   { background:#f3f4f6;color:#6b7280; }
.mc-status-cancelled  { background:#f3f4f6;color:#9ca3af; }
.mc-status-other      { background:#f3f4f6;color:#6b7280; }
</style>

<script type="text/html" id="mcOrderNoTpl">
    <div style="line-height:1.4;text-align:left;">
        <div style="font-family:Consolas,Monaco,monospace;font-size:12px;">{{ d.order_no }}</div>
        <div style="color:#9ca3af;font-size:11px;">{{ d.buyer_label }} · {{ d.items_count }} 件</div>
    </div>
</script>

<script type="text/html" id="mcOrderAmountTpl">
    <div style="text-align:right;line-height:1.4;">
        <div style="color:#1f2937;font-weight:600;">{{ d.pay_amount_view }}</div>
        <div style="font-size:11px;color:#9ca3af;">成本 {{ d.total_cost_view }}{{# if(d.total_fee > 0){ }} · 手续费 {{ d.total_fee_view }}{{# } }}</div>
    </div>
</script>

<script type="text/html" id="mcOrderNetTpl">
    <span style="color:#16a34a;font-weight:600;">{{ d.net_income_view }}</span>
</script>

<script type="text/html" id="mcOrderChannelTpl">
    {{# if(d.pay_channel === 'merchant'){ }}
        <span class="layui-badge layui-bg-blue">独立收款</span>
    {{# } else { }}
        <span class="layui-badge layui-bg-gray">主站</span>
    {{# } }}
</script>

<script type="text/html" id="mcOrderStatusTpl">
    {{# var map = {pending:'待付',paid:'已付',delivering:'发货中',delivered:'已发货',
                   completed:'已完成',refunding:'退款中',refunded:'已退款',cancelled:'已取消',
                   expired:'已过期',failed:'失败',delivery_failed:'发货失败'}; }}
    <span class="mc-status-badge mc-status-{{ d.status }}">{{ map[d.status] || d.status }}</span>
</script>

<script type="text/html" id="mcOrderActionTpl">
    <a class="layui-btn layui-btn-sm layui-btn-primary" lay-event="detail"><i class="fa fa-eye"></i> 详情</a>
</script>

<script>
$(function(){
    'use strict';
    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    window.updateCsrf = function (t) { if (t) csrfToken = t; };

    layui.use(['layer', 'form', 'table'], function () {
        var layer = layui.layer;
        var form = layui.form;
        var table = layui.table;

        var currentStatus = 'all';

        function where() {
            return {
                _action: 'list',
                status: currentStatus,
                keyword: $('#mcOrderKeyword').val() || ''
            };
        }

        function applyTabCounts(counts) {
            if (!counts) return;
            // "待发货" tab = paid + delivering 合并；"已退款" 显示 refunded（退款中不单独显示）
            var merged = {
                all: counts.all || 0,
                pending: counts.pending || 0,
                paid: (counts.paid || 0) + (counts.delivering || 0),
                delivered: counts.delivered || 0,
                completed: counts.completed || 0,
                refunded: (counts.refunded || 0) + (counts.refunding || 0),
                cancelled: counts.cancelled || 0,
            };
            $('#mcOrderTabs .uc-order-tab').each(function () {
                var $t = $(this);
                var st = $t.data('status');
                var c = merged[st] != null ? merged[st] : 0;
                $t.find('.uc-order-tab__count').remove();
                if (c > 0) $t.append(' <span class="uc-order-tab__count">' + c + '</span>');
            });
        }

        table.render({
            elem: '#mcOrderTable',
            id: 'mcOrderTableId',
            url: '/user/merchant/order.php',
            method: 'POST',
            where: where(),
            page: true,
            limit: 20,
            lineStyle: 'height: 60px;',
            cols: [[
                {title: '订单', minWidth: 260, templet: '#mcOrderNoTpl'},
                {title: '支付金额 / 成本', minWidth: 160, templet: '#mcOrderAmountTpl', align: 'right'},
                {title: '净收入', minWidth: 100, templet: '#mcOrderNetTpl', align: 'right'},
                {title: '通道', minWidth: 90, templet: '#mcOrderChannelTpl', align: 'center'},
                {title: '状态', minWidth: 90, templet: '#mcOrderStatusTpl', align: 'center'},
                {field: 'created_at', title: '下单时间', minWidth: 150, align: 'center'},
                {title: '操作', width: 90, templet: '#mcOrderActionTpl', align: 'center'}
            ]],
            parseData: function (res) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                if (res.data && res.data.tab_counts) applyTabCounts(res.data.tab_counts);
                return {
                    'code': res.code === 200 ? 0 : res.code,
                    'msg': res.msg,
                    'data': res.data ? res.data.data : [],
                    'count': res.data ? res.data.total : 0
                };
            }
        });

        $(document).on('click', '#mcOrderTabs .uc-order-tab', function () {
            $('#mcOrderTabs .uc-order-tab').removeClass('is-active');
            $(this).addClass('is-active');
            currentStatus = $(this).data('status');
            table.reload('mcOrderTableId', {page: {curr: 1}, where: where()});
        });
        $(document).on('click', '#mcOrderSearchBtn', function () {
            table.reload('mcOrderTableId', {page: {curr: 1}, where: where()});
        });
        $(document).on('click', '#mcOrderResetBtn', function () {
            $('#mcOrderKeyword').val('');
            table.reload('mcOrderTableId', {page: {curr: 1}, where: where()});
        });

        table.on('tool(mcOrderTable)', function (obj) {
            if (obj.event === 'detail') openDetail(obj.data.id);
        });

        function openDetail(id) {
            $.ajax({
                url: '/user/merchant/order.php',
                type: 'POST', dataType: 'json',
                data: {_action: 'detail', csrf_token: csrfToken, id: id},
                success: function (res) {
                    if (res.code !== 200) { layer.msg(res.msg || '加载失败'); return; }
                    if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                    renderDetail(res.data.order, res.data.items);
                }
            });
        }

        function renderDetail(order, items) {
            var statusMap = {pending:'待付',paid:'已付',delivering:'发货中',delivered:'已发货',
                completed:'已完成',refunding:'退款中',refunded:'已退款',cancelled:'已取消'};
            var contact = '';
            try {
                var c = order.contact_info;
                if (c && c.indexOf('{') === 0) {
                    var p = JSON.parse(c);
                    contact = Object.keys(p).map(function (k) { return k + ': ' + p[k]; }).join('<br>');
                } else {
                    contact = (c || '').replace(/\n/g, '<br>');
                }
            } catch (e) { contact = order.contact_info || ''; }

            var itemsHtml = items.map(function (it) {
                return '<tr>'
                     + '<td>' + (it.goods_title || '') + (it.spec_name ? '<div style="color:#9ca3af;font-size:12px;">' + it.spec_name + '</div>' : '') + '</td>'
                     + '<td style="text-align:right;">' + it.price_view + ' × ' + it.quantity + '</td>'
                     + '<td style="text-align:right;color:#8b5cf6;">' + it.cost_amount_view + '</td>'
                     + '<td style="text-align:right;color:#f59e0b;">' + it.fee_amount_view + '</td>'
                     + '<td style="text-align:right;color:#16a34a;font-weight:600;">' + it.line_net_view + '</td>'
                     + '</tr>';
            }).join('');

            var html = '<div style="padding:18px;">'
                + '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px 24px;margin-bottom:16px;font-size:13px;">'
                +   '<div><span style="color:#9ca3af;">订单号</span> <span style="font-family:Consolas,Monaco,monospace;">' + order.order_no + '</span></div>'
                +   '<div><span style="color:#9ca3af;">状态</span> <span class="mc-status-badge mc-status-' + order.status + '">' + (statusMap[order.status] || order.status) + '</span></div>'
                +   '<div><span style="color:#9ca3af;">下单时间</span> ' + order.created_at + '</div>'
                +   '<div><span style="color:#9ca3af;">支付通道</span> ' + (order.pay_channel === 'merchant' ? '独立收款' : '主站') + '</div>'
                +   '<div><span style="color:#9ca3af;">支付方式</span> ' + (order.payment_name || '-') + '</div>'
                +   '<div><span style="color:#9ca3af;">支付时间</span> ' + (order.pay_time || '-') + '</div>'
                + '</div>'
                + '<div style="margin-bottom:10px;font-weight:600;">商品明细</div>'
                + '<table class="layui-table">'
                +   '<thead><tr><th>商品</th><th style="text-align:right;">单价 × 数量</th><th style="text-align:right;">成本</th><th style="text-align:right;">手续费</th><th style="text-align:right;">净收入</th></tr></thead>'
                +   '<tbody>' + itemsHtml + '</tbody>'
                + '</table>'
                + '<div style="display:flex;justify-content:flex-end;gap:24px;margin:14px 0;font-size:13px;">'
                +   '<div><span style="color:#9ca3af;">商品金额</span> ' + order.goods_amount_view + '</div>'
                +   '<div><span style="color:#9ca3af;">优惠</span> -' + order.discount_amount_view + '</div>'
                +   '<div style="font-weight:600;">实付 ' + order.pay_amount_view + '</div>'
                + '</div>'
                + '<div style="margin-top:14px;padding:12px;background:#f9fafb;border-radius:6px;">'
                +   '<div style="font-weight:600;margin-bottom:6px;">买家联系方式</div>'
                +   '<div style="color:#374151;font-size:13px;line-height:1.7;">' + (contact || '—') + '</div>'
                + '</div>'
                + '</div>';

            layer.open({
                type: 1,
                title: '订单详情',
                skin: 'admin-modal',
                area: [window.innerWidth >= 1000 ? '780px' : '95%', window.innerHeight >= 700 ? '680px' : '90%'],
                shadeClose: true,
                content: html
            });
        }
    });
});
</script>
