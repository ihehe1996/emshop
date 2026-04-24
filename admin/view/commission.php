<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
$tab = (string) ($tab ?? 'log');
?>
<div class="layui-collapse admin-search-collapse" lay-filter="commissionSearchCollapse">
    <div class="layui-colla-item">
        <div class="layui-colla-title"><i class="fa fa-filter"></i> 搜索条件</div>
        <div class="layui-colla-content">
            <div class="layui-form layui-row layui-col-space12">
                <div class="layui-col-md3">
                    <div class="layui-form-item">
                        <label class="layui-form-label">用户ID</label>
                        <div class="layui-input-block">
                            <input type="number" id="commissionSearchUid" class="layui-input" placeholder="按归属用户ID筛选">
                        </div>
                    </div>
                </div>
                <?php if ($tab === 'log'): ?>
                <div class="layui-col-md3">
                    <div class="layui-form-item">
                        <label class="layui-form-label">状态</label>
                        <div class="layui-input-block">
                            <select id="commissionSearchStatus" class="layui-input">
                                <option value="">全部</option>
                                <option value="frozen">冻结中</option>
                                <option value="available">可提现</option>
                                <option value="withdrawn">已提现</option>
                                <option value="reverted">已倒扣</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-form-item">
                        <label class="layui-form-label">分销级别</label>
                        <div class="layui-input-block">
                            <select id="commissionSearchLevel" class="layui-input">
                                <option value="">全部</option>
                                <option value="1">一级</option>
                                <option value="2">二级</option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="layui-form-item search-btn-group">
                    <button class="layui-btn" id="commissionSearchBtn"><i class="fa fa-search mr-6"></i>搜索</button>
                    <button type="button" class="layui-btn layui-btn-primary" id="commissionResetBtn"><i class="fa fa-rotate-left mr-6"></i>重置</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="admin-page">
    <h1 class="admin-page__title"><?= $tab === 'withdraw' ? '佣金提现记录' : '佣金流水' ?></h1>
    <div style="margin-bottom:12px;">
        <a href="/admin/commission.php?tab=log" data-pjax="#adminContent" class="layui-btn <?= $tab === 'log' ? '' : 'layui-btn-primary' ?>">佣金流水</a>
        <a href="/admin/commission.php?tab=withdraw" data-pjax="#adminContent" class="layui-btn <?= $tab === 'withdraw' ? '' : 'layui-btn-primary' ?>">提现记录</a>
    </div>
    <table id="commissionTable" lay-filter="commissionTable"></table>
</div>

<script type="text/html" id="commissionStatusTpl">
    {{# if (d.status === 'frozen') { }}<span class="layui-badge layui-bg-orange">冻结中</span>
    {{# } else if (d.status === 'available') { }}<span class="layui-badge layui-bg-green">可提现</span>
    {{# } else if (d.status === 'withdrawn') { }}<span class="layui-badge layui-bg-blue">已提现</span>
    {{# } else if (d.status === 'reverted') { }}<span class="layui-badge">已倒扣</span>
    {{# } else { }}{{ d.status }}{{# } }}
</script>

<script>
$(function () {
    'use strict';
    var csrfToken = <?= json_encode($csrfToken) ?>;
    var tab = <?= json_encode($tab) ?>;

    layui.use(['layer', 'form', 'table', 'element'], function () {
        var layer = layui.layer, form = layui.form, table = layui.table, element = layui.element;

        var cols;
        if (tab === 'withdraw') {
            cols = [
                { field: 'id', title: 'ID', width: 80, align: 'center' },
                { field: 'user_id', title: '用户ID', width: 80, align: 'center' },
                { field: 'amount_display', title: '提现金额', width: 120, align: 'center', templet: function(d){ return '¥' + d.amount_display; } },
                { field: 'before_balance', title: '提现前', width: 130, align: 'center', templet: function(d){ return '¥' + (parseFloat(d.before_balance)/1000000).toFixed(2); } },
                { field: 'after_balance', title: '提现后', width: 130, align: 'center', templet: function(d){ return '¥' + (parseFloat(d.after_balance)/1000000).toFixed(2); } },
                { field: 'status', title: '状态', width: 90, align: 'center' },
                { field: 'remark', title: '备注' },
                { field: 'created_at', title: '时间', width: 160, align: 'center' }
            ];
        } else {
            cols = [
                { field: 'id', title: 'ID', width: 70, align: 'center' },
                { field: 'user_id', title: '归属用户', width: 90, align: 'center' },
                { field: 'order_no', title: '订单号', width: 180, align: 'center' },
                { field: 'from_user_id', title: '下单人', width: 90, align: 'center', templet: function(d){ return d.from_user_id > 0 ? d.from_user_id : '游客'; } },
                { field: 'level', title: '级别', width: 70, align: 'center', templet: function(d){ return 'L' + d.level; } },
                { field: 'amount_display', title: '佣金', width: 110, align: 'center', templet: function(d){ return '¥' + d.amount_display; } },
                { field: 'rate', title: '比例', width: 100, align: 'center', templet: function(d){ return (d.rate/100).toFixed(2) + '%'; } },
                { field: 'basis_amount_display', title: '计算基数', width: 110, align: 'center', templet: function(d){ return '¥' + d.basis_amount_display; } },
                { field: 'status', title: '状态', width: 90, align: 'center', templet: '#commissionStatusTpl' },
                { field: 'frozen_until', title: '解冻时间', width: 160, align: 'center' },
                { field: 'created_at', title: '创建时间', width: 160, align: 'center' }
            ];
        }

        table.render({
            elem: '#commissionTable',
            id: 'commissionTableId',
            url: '/admin/commission.php',
            method: 'POST',
            where: { _action: 'list', tab: tab },
            page: true,
            limit: 20,
            cols: [cols],
            lineStyle: 'height: 48px;',
            parseData: function (res) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                return {
                    code: res.code === 200 ? 0 : res.code, msg: res.msg,
                    data: res.data ? res.data.data : [], count: res.data ? res.data.total : 0
                };
            }
        });

        $(document).on('click', '#commissionSearchBtn', function () {
            var where = { _action: 'list', tab: tab, user_id: $('#commissionSearchUid').val() || '' };
            if (tab === 'log') {
                where.status = $('#commissionSearchStatus').val() || '';
                where.level  = $('#commissionSearchLevel').val() || '';
            }
            table.reload('commissionTableId', { page: { curr: 1 }, where: where });
        });

        $(document).on('click', '#commissionResetBtn', function () {
            $('#commissionSearchUid').val('');
            $('#commissionSearchStatus').val('');
            $('#commissionSearchLevel').val('');
            form.render('select');
            table.reload('commissionTableId', { page: { curr: 1 }, where: { _action: 'list', tab: tab } });
        });
    });
});
</script>
