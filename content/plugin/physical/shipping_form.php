<?php
/**
 * 实物商品 - 发货表单（填写快递信息）
 * 由物理商品插件通过 admin_plugin_action 钩子加载
 */
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

$action = (string) Input::get('_action', '');
$orderId = (int) Input::get('order_id', 0);
if ($orderId <= 0) {
    exit('无效的订单ID');
}

// CSRF token
$csrfToken = Csrf::token();

if ($action === 'shipping_submit') {
    // POST 处理：保存快递信息
    $csrf = (string) Input::post('csrf_token', '');
    if (!Csrf::validate($csrf)) {
        Response::error('CSRF验证失败，请刷新页面后重试');
    }

    $orderId = (int) Input::post('order_id', 0);
    if ($orderId <= 0) {
        Response::error('无效的订单ID');
    }

    $expressCompany = trim((string) Input::post('express_company', ''));
    $expressNo = trim((string) Input::post('express_no', ''));

    if (empty($expressCompany)) {
        Response::error('请选择或输入快递公司');
    }
    if (empty($expressNo)) {
        Response::error('请输入快递单号');
    }

    // 查找订单
    $order = Database::query(
        "SELECT id, status, shipping_status FROM " . Database::prefix() . "order WHERE id = ? LIMIT 1",
        [$orderId]
    );
    if (empty($order)) {
        Response::error('订单不存在');
    }

    $o = $order[0];

    // 只有已付款、发货中、已发货状态才能填写快递
    $allowedStatuses = ['paid', 'delivering', 'delivered'];
    if (!in_array($o['status'], $allowedStatuses)) {
        Response::error('当前订单状态不允许填写快递信息（状态：' . $o['status'] . '）');
    }

    // 更新订单快递信息
    Database::update('order', [
        'express_company' => $expressCompany,
        'express_no' => $expressNo,
        'shipping_status' => 'shipped',
        'updated_at' => date('Y-m-d H:i:s'),
    ], $orderId);

    // 如果订单状态为 paid 或 delivering，改为 delivered
    if (in_array($o['status'], ['paid', 'delivering'])) {
        Database::update('order', [
            'status' => 'delivered',
            'delivery_time' => date('Y-m-d H:i:s'),
        ], $orderId);
    }

    // 获取订单号用于通知
    $orderInfo = Database::query(
        "SELECT order_no FROM " . Database::prefix() . "order WHERE id = ? LIMIT 1",
        [$orderId]
    );
    $orderNo = !empty($orderInfo) ? $orderInfo[0]['order_no'] : '';

    // 触发发货通知
    $deliveryContent = "快递公司：{$expressCompany}\n快递单号：{$expressNo}";
    if (function_exists('doAction')) {
        doAction('notice_delivery', $orderId, $orderNo, $deliveryContent);
    }

    if (function_exists('log_message')) {
        log_message('info', "[physical] shipping filled: order #{$orderId}, express #{$expressNo}");
    }

    Response::success('发货信息已保存', [
        'reload' => true,
    ]);
}

// GET 处理：显示发货表单
$order = Database::query(
    "SELECT * FROM " . Database::prefix() . "order WHERE id = ? LIMIT 1",
    [$orderId]
);
if (empty($order)) {
    exit('订单不存在');
}

$o = $order[0];
$expressCompany = $o['express_company'] ?? '';
$expressNo = $o['express_no'] ?? '';
$addressInfo = !empty($o['address_info']) ? json_decode($o['address_info'], true) : [];
$contactInfo = !empty($o['contact_info']) ? json_decode($o['contact_info'], true) : [];

// 常用快递公司列表
$expressCompanies = [
    '顺丰速运' => '顺丰速运',
    '中通快递' => '中通快递',
    '圆通速递' => '圆通速递',
    '申通快递' => '申通快递',
    '韵达快递' => '韵达快递',
    '极兔速递' => '极兔速递',
    '京东物流' => '京东物流',
    '邮政EMS' => '邮政EMS',
    '邮政小包' => '邮政小包',
    '德邦快递' => '德邦快递',
    '安能物流' => '安能物流',
    '其他' => '其他',
];

$contactText = is_string($contactInfo) ? $contactInfo : ($contactInfo['name'] ?? '') . ' ' . ($contactInfo['phone'] ?? '');
$addressText = !empty($addressInfo) ? ($addressInfo['name'] ?? '') . ' ' . ($addressInfo['phone'] ?? '') . "\n" . ($addressInfo['province'] ?? '') . ($addressInfo['city'] ?? '') . ($addressInfo['district'] ?? '') . "\n" . ($addressInfo['detail'] ?? '') : '';

$esc = function (string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>填写物流信息</title>
<link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
<link rel="stylesheet" href="/content/static/lib/font-awesome-4.7.0/css/font-awesome.min.css">
<style>
body { margin: 0; padding: 20px; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.shipping-form { background: #fff; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
.form-item { margin-bottom: 15px; }
.form-label { display: block; font-size: 14px; color: #333; margin-bottom: 6px; font-weight: 500; }
.form-label span { color: #f56c6c; margin-left: 2px; }
.form-input { width: 100%; padding: 8px 12px; border: 1px solid #dcdfe6; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
.form-input:focus { outline: none; border-color: #409eff; }
select.form-input { background: #fff; }
.order-info { background: #f6f8fa; padding: 12px 15px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; line-height: 1.8; color: #666; }
.order-info strong { color: #333; }
.order-info .section-title { font-size: 13px; color: #999; margin-bottom: 4px; }
.btn-row { margin-top: 20px; text-align: center; }
.btn { padding: 9px 30px; border-radius: 4px; font-size: 14px; cursor: pointer; border: none; }
.btn-primary { background: #409eff; color: #fff; }
.btn-primary:hover { background: #66b1ff; }
.btn-default { background: #fff; color: #666; border: 1px solid #dcdfe6; margin-left: 10px; }
.btn-default:hover { border-color: #c0c4cc; }
textarea.form-input { resize: vertical; min-height: 80px; }
</style>
</head>
<body>
<div class="shipping-form">
    <form class="layui-form" id="shippingForm" lay-filter="shippingForm">
        <input type="hidden" name="_action" value="shipping_submit">
        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrfToken); ?>">
        <input type="hidden" name="order_id" value="<?php echo (int)$orderId; ?>">

        <!-- 订单信息 -->
        <div class="order-info">
            <div class="section-title">订单信息</div>
            <div><strong>订单号：</strong><?php echo $esc($o['order_no'] ?? ''); ?></div>
            <?php if (!empty($contactText)): ?>
            <div><strong>联系方式：</strong><?php echo $esc($contactText); ?></div>
            <?php endif; ?>
            <?php if (!empty($addressText)): ?>
            <div style="margin-top:4px;"><strong>收货地址：</strong></div>
            <div style="padding-left:60px;"><?php echo nl2br($esc($addressText)); ?></div>
            <?php endif; ?>
        </div>

        <!-- 快递信息 -->
        <div class="form-item">
            <label class="form-label">快递公司 <span>*</span></label>
            <select name="express_company" class="form-input" lay-verify="required" lay-regex="^(?!请选择).+$" lay-regex-text="请选择快递公司">
                <option value="">请选择快递公司</option>
                <?php foreach ($expressCompanies as $key => $name): ?>
                <option value="<?php echo $esc($name); ?>" <?php echo $expressCompany === $name ? 'selected' : ''; ?>><?php echo $esc($name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-item">
            <label class="form-label">快递单号 <span>*</span></label>
            <input type="text" name="express_no" class="form-input" lay-verify="required" placeholder="请输入快递单号" value="<?php echo $esc($expressNo); ?>">
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" lay-submit lay-filter="submitShipping">
                <i class="fa fa-check"></i> 确认发货
            </button>
        </div>
    </form>
</div>

<script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
<script>
window.submitShipping = function() {
    var form = layui.form;
    var layer = layui.layer;
    var $ = layui.$;

    var expressCompany = $('select[name="express_company"]').val();
    var expressNo = $('input[name="express_no"]').val().trim();

    if (!expressCompany || expressCompany === '') {
        layer.msg('请选择快递公司');
        return false;
    }
    if (!expressNo) {
        layer.msg('请输入快递单号');
        return false;
    }

    $.post('/admin/?_action=shipping_submit', {
        _action: 'shipping_submit',
        csrf_token: $('input[name="csrf_token"]').val(),
        order_id: $('input[name="order_id"]').val(),
        express_company: expressCompany,
        express_no: expressNo
    }, function(res) {
        if (res.code === 200) {
            layer.msg('发货成功', function() {
                // 关闭当前弹窗，触发父窗口刷新
                var index = parent.layer.getFrameIndex(window.name);
                parent.layer.close(index);
            });
        } else {
            layer.msg(res.msg || '保存失败');
        }
    }, 'json').fail(function() {
        layer.msg('网络错误，请重试');
    });
    return false;
};

layui.use(['form', 'layer'], function() {
    var form = layui.form;
    var layer = layui.layer;
    var $ = layui.$;

    form.on('submit(submitShipping)', function(data) {
        window.submitShipping();
        return false;
    });
});
</script>
</body>
</html>
