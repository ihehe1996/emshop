<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<div class="layui-collapse admin-search-collapse" lay-filter="rechargeSearchCollapse">
    <div class="layui-colla-item">
        <div class="layui-colla-title"><i class="fa fa-filter"></i> 搜索条件</div>
        <div class="layui-colla-content">
            <div class="layui-form layui-row layui-col-space12">
                <div class="layui-col-md3">
                    <div class="layui-form-item">
                        <label class="layui-form-label">关键字</label>
                        <div class="layui-input-block">
                            <input type="text" id="rechargeSearchKw" class="layui-input" placeholder="单号 / 用户名 / 昵称">
                        </div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-form-item">
                        <label class="layui-form-label">状态</label>
                        <div class="layui-input-block">
                            <select id="rechargeSearchStatus" class="layui-input">
                                <option value="">全部</option>
                                <option value="pending">待支付</option>
                                <option value="paid">已充值</option>
                                <option value="cancelled">已取消</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="layui-form-item search-btn-group">
                    <button class="layui-btn" id="rechargeSearchBtn"><i class="fa fa-search mr-6"></i>搜索</button>
                    <button type="button" class="layui-btn layui-btn-primary" id="rechargeResetBtn"><i class="fa fa-rotate-left mr-6"></i>重置</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="admin-page">
    <h1 class="admin-page__title">钱包充值订单</h1>
    <table id="rechargeTable" lay-filter="rechargeTable"></table>
</div>

<script type="text/html" id="rechargeStatusTpl">
    {{# if (d.status === 'pending') { }}<span class="layui-badge layui-bg-orange">待支付</span>
    {{# } else if (d.status === 'paid') { }}<span class="layui-badge layui-bg-green">已充值</span>
    {{# } else if (d.status === 'cancelled') { }}<span class="layui-badge">已取消</span>
    {{# } else { }}{{ d.status }}{{# } }}
</script>

<script type="text/html" id="rechargeBarTpl">
    {{# if (d.status === 'pending') { }}
    <a class="layui-btn layui-btn-xs layui-btn-warm" lay-event="cancel">取消</a>
    {{# } else { }}
    <span style="color:#999;">—</span>
    {{# } }}
</script>

<script>
$(function () {
    'use strict';
    var csrfToken = <?= json_encode($csrfToken) ?>;

    layui.use(['layer', 'form', 'table', 'element'], function () {
        var layer = layui.layer, form = layui.form, table = layui.table, element = layui.element;

        table.render({
            elem: '#rechargeTable',
            id: 'rechargeTableId',
            url: '/admin/recharge.php',
            method: 'POST',
            where: { _action: 'list' },
            page: true,
            limit: 20,
            cols: [[
                { field: 'id', title: 'ID', width: 70, align: 'center' },
                { field: 'order_no', title: '充值单号', width: 200, align: 'center' },
                { field: 'user_id', title: '用户', width: 140, align: 'center',
                  templet: function(d){ return (d.nickname || d.username || '-') + ' <span style="color:#999;">#'+d.user_id+'</span>'; } },
                { field: 'amount_display', title: '金额', width: 110, align: 'center',
                  templet: function(d){ return '¥' + d.amount_display; } },
                { field: 'payment_code', title: '支付方式', width: 120, align: 'center' },
                { field: 'trade_no', title: '第三方流水号', width: 200 },
                { field: 'status', title: '状态', width: 90, align: 'center', templet: '#rechargeStatusTpl' },
                { field: 'created_at', title: '创建时间', width: 160, align: 'center' },
                { field: 'paid_at', title: '支付时间', width: 160, align: 'center' },
                { fixed: 'right', title: '操作', width: 90, align: 'center', toolbar: '#rechargeBarTpl' }
            ]],
            parseData: function (res) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                return {
                    code: res.code === 200 ? 0 : res.code, msg: res.msg,
                    data: res.data ? res.data.data : [], count: res.data ? res.data.total : 0
                };
            }
        });

        table.on('tool(rechargeTable)', function (obj) {
            if (obj.event === 'cancel') {
                layer.confirm('确认取消该笔待支付充值单？', function (idx) {
                    layer.close(idx);
                    $.post('/admin/recharge.php', { _action: 'cancel', id: obj.data.id, csrf_token: csrfToken }, function (res) {
                        if (res.code === 200) {
                            layer.msg('已取消');
                            table.reload('rechargeTableId');
                        } else {
                            layer.msg(res.msg || '操作失败');
                        }
                    }, 'json');
                });
            }
        });

        $(document).on('click', '#rechargeSearchBtn', function () {
            table.reload('rechargeTableId', {
                page: { curr: 1 },
                where: {
                    _action: 'list',
                    keyword: $('#rechargeSearchKw').val() || '',
                    status:  $('#rechargeSearchStatus').val() || '',
                }
            });
        });
        $(document).on('click', '#rechargeResetBtn', function () {
            $('#rechargeSearchKw').val(''); $('#rechargeSearchStatus').val('');
            form.render('select');
            table.reload('rechargeTableId', { page: { curr: 1 }, where: { _action: 'list' } });
        });
    });
});
</script>
