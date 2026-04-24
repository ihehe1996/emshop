<?php
/**
Plugin Name: 实物商品
Version: 1.0.0
Plugin URL:
Description: 实物商品插件，需要人工发货或对接物流系统。支持配置发货方式、预计发货时间、运费模板等。
Author: EMSHOP
Author URL:
Category: 商品插件
*/

defined('EM_ROOT') || exit('Access Denied');

// ================================================================
// 第一步：注册商品类型
// ================================================================
addAction('goods_type_register', function (&$types) {
    $types['physical'] = [
        'name' => '实物商品',
        'description' => '实物产品，支持快递发货，需填写收货地址，人工或系统发货',
        'icon' => '/content/plugin/physical/icon.png',
        'default' => false,
        'delivery_type' => 'manual', // auto=自动发货, manual=人工发货
        'needs_address' => true,     // 核心在 checkout 自动渲染地址选择器，OrderModel 快照地址到订单
    ];
});

// 友好展示名：订单详情里的 goods_type 徽章走这个 filter 显示中文
addFilter('goods_type_label', function ($label, $og) {
    if (($og['goods_type'] ?? '') === 'physical') {
        return '实物商品';
    }
    return $label;
});

// ================================================================
// 后台手动发货：核心 order_ship popup 按 goods_type 路由到本插件的表单 + 处理器
// ================================================================

/**
 * 发货表单 HTML：快递公司 + 快递单号 + 备注。
 * 字段 name 可以随便起，但 submit filter 里要从 $args['post'] 能读到。
 */
addFilter('goods_type_physical_manual_delivery_form', function ($html, $og) {
    $ogId = (int) ($og['id'] ?? 0);
    // 常用快递（按需增减；要长期维护建议换成 Config::get 驱动）
    $carriers = [
        '顺丰速运', '京东物流', '中通快递', '圆通速递', '韵达快递',
        '申通快递', '邮政EMS', '极兔速递', '德邦快递', '百世快递',
    ];
    ob_start();
    ?>
    <div class="ship-field-row" style="display:flex;gap:10px;margin-bottom:10px;">
        <div style="flex:1;min-width:0;">
            <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;">快递公司</label>
            <input type="text" list="ship-carriers-<?= $ogId ?>" name="express_company" required
                   placeholder="可选择或手填" class="ship-input"
                   style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
            <datalist id="ship-carriers-<?= $ogId ?>">
                <?php foreach ($carriers as $c): ?>
                <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div style="flex:1;min-width:0;">
            <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;">快递单号</label>
            <input type="text" name="express_no" required maxlength="64"
                   placeholder="请输入运单号" class="ship-input"
                   style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
        </div>
    </div>
    <div>
        <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;">发货备注（选填，会展示给买家）</label>
        <textarea name="ship_remark" maxlength="255" rows="2"
                  placeholder="例如：已打包，预计 2 天内到达"
                  style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;line-height:1.6;resize:vertical;min-height:56px;"></textarea>
    </div>
    <?php
    return (string) ob_get_clean();
});

/**
 * 处理发货提交：校验字段 + 准备 delivery_content 和 plugin_data，交回给核心。
 * 返回 string → 核心当作错误消息弹给管理员；
 * 返回 ['delivery_content' => ..., 'plugin_data' => ...] → 核心落库 + 流转状态。
 */
addFilter('goods_type_physical_manual_delivery_submit', function ($_, $args) {
    $post = $args['post'] ?? [];
    $carrier = trim((string) ($post['express_company'] ?? ''));
    $expressNo = trim((string) ($post['express_no'] ?? ''));
    $remark = trim((string) ($post['ship_remark'] ?? ''));

    if ($carrier === '')   return '请填写快递公司';
    if ($expressNo === '') return '请填写快递单号';
    if (mb_strlen($carrier) > 32)     return '快递公司名称过长';
    if (mb_strlen($expressNo) > 64)   return '快递单号过长';
    if (mb_strlen($remark) > 255)     return '备注过长';

    // 展示给买家的文字（订单详情"发货内容"处显示的就是这个）
    $content = $carrier . ' / 单号：' . $expressNo;
    if ($remark !== '') {
        $content .= "\n备注：" . $remark;
    }

    return [
        'delivery_content' => $content,
        'plugin_data' => [
            'express_company' => $carrier,
            'express_no'      => $expressNo,
            'ship_remark'     => $remark,
        ],
    ];
});

// ================================================================
// 第二步：后台创建/编辑表单钩子
// ================================================================
addAction('goods_type_physical_create_form', function ($goods = null) {
    $data = [];
    if ($goods && !empty($goods['plugin_data'])) {
        $data = json_decode($goods['plugin_data'], true) ?: [];
    }

    ?>
    <div class="layui-form-item">
        <label class="layui-form-label">预计发货时间</label>
        <div class="layui-input-block" style="display:flex;align-items:center;gap:8px;">
            <input type="number" name="plugin_data[delivery_days]" class="layui-input"
                   value="<?php echo htmlspecialchars($data['delivery_days'] ?? '3', ENT_QUOTES); ?>"
                   placeholder="天数" style="width:100px;" min="0">
            <span style="color:#666;">个工作日内发货</span>
        </div>
        <div class="layui-form-mid" style="color:#909399;">
            用户下单后，系统显示预计发货时间。设置为 0 表示自动发货无需等待
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">运费设置</label>
        <div class="layui-input-block">
            <select name="plugin_data[shipping_fee_type]" lay-filter="shippingFeeType">
                <option value="fixed" <?php echo ($data['shipping_fee_type'] ?? 'fixed') === 'fixed' ? 'selected' : ''; ?>>固定运费</option>
                <option value="free" <?php echo ($data['shipping_fee_type'] ?? '') === 'free' ? 'selected' : ''; ?>>包邮</option>
                <option value="template" <?php echo ($data['shipping_fee_type'] ?? '') === 'template' ? 'selected' : ''; ?>>运费模板</option>
            </select>
        </div>
    </div>
    <div class="layui-form-item" id="fixedFeeBlock" style="<?php echo ($data['shipping_fee_type'] ?? 'fixed') !== 'fixed' ? 'display:none;' : ''; ?>">
        <label class="layui-form-label">固定运费</label>
        <div class="layui-input-inline" style="width:120px;">
            <input type="number" step="0.01" name="plugin_data[shipping_fee]" class="layui-input"
                   value="<?php echo htmlspecialchars($data['shipping_fee'] ?? '0.00', ENT_QUOTES); ?>"
                   placeholder="0.00">
        </div>
        <div class="layui-form-mid" style="color:#666;">元</div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">发货备注模板</label>
        <div class="layui-input-block">
            <textarea name="plugin_data[delivery_remark]" class="layui-textarea"
                      placeholder="例如：发货时间周一至周六 9:00-18:00，节假日顺延"><?php echo htmlspecialchars($data['delivery_remark'] ?? '', ENT_QUOTES); ?></textarea>
        </div>
        <div class="layui-form-mid" style="color:#909399;">
            在商品详情页展示，帮助用户了解发货规则
        </div>
    </div>

    <blockquote class="layui-elem-quote" style="margin:10px 15px;">
        请保存商品后在商品列表操作栏中点击库存按钮进行库存设置
    </blockquote>

    <script>
    // 运费类型切换
    layui.use(['form'], function() {
        var form = layui.form;
        form.on('select(shippingFeeType)', function(data) {
            var $fixedBlock = $('#fixedFeeBlock');
            if (data.value === 'fixed') {
                $fixedBlock.slideDown();
            } else {
                $fixedBlock.slideUp();
            }
        });
    });
    </script>
    <?php
});

// ================================================================
// 第二步半：类型配置必填校验钩子
// ================================================================
addFilter('goods_type_physical_validate', function ($error, $pluginData) {
    $deliveryDays = $pluginData['delivery_days'] ?? '';
    if ($deliveryDays === '' || $deliveryDays === null) {
        return '请在商品类型配置中填写预计发货时间';
    }
    return $error;
});

// ================================================================
// 第三步：保存钩子
// ================================================================
addAction('goods_type_physical_save', function ($goodsId, $postData) {
    $pluginData = $postData['plugin_data'] ?? [];

    $goods = GoodsModel::getById($goodsId);
    $oldData = [];
    if ($goods && !empty($goods['plugin_data'])) {
        $oldData = json_decode($goods['plugin_data'], true) ?: [];
    }

    $newData = array_merge($oldData, [
        'delivery_days' => max(0, (int)($pluginData['delivery_days'] ?? 3)),
        'shipping_fee_type' => $pluginData['shipping_fee_type'] ?? 'fixed',
        'shipping_fee' => max(0, (float)($pluginData['shipping_fee'] ?? 0)),
        'require_address' => 1, // 实物商品始终要求收货地址
        'delivery_remark' => trim($pluginData['delivery_remark'] ?? ''),
    ]);

    Database::update('goods', [
        'plugin_data' => json_encode($newData, JSON_UNESCAPED_UNICODE),
    ], $goodsId);
});

// ================================================================
// 第三步半：库存管理弹窗钩子（完全接管库存管理界面）
// ================================================================
addAction('goods_type_physical_stock_form', function ($goods, $specs) {
    include __DIR__ . '/stock_form.php';
});

// ================================================================
// 第四步：前台渲染钩子（商品详情页购买区域）
// ================================================================
addAction('goods_type_physical_render', function ($goods, $spec) {
    $pluginData = $goods && !empty($goods['plugin_data'])
        ? json_decode($goods['plugin_data'], true) ?: []
        : [];

    $shippingMethod = $pluginData['shipping_method'] ?? 'manual';
    $deliveryDays = $pluginData['delivery_days'] ?? 3;
    $feeType = $pluginData['shipping_fee_type'] ?? 'fixed';
    $fee = $pluginData['shipping_fee'] ?? 0;
    $freeByQty = $pluginData['free_by_quantity'] ?? 0;
    $requireAddress = !empty($pluginData['require_address']);
    $remark = $pluginData['delivery_remark'] ?? '';

    $shippingLabel = $shippingMethod === 'auto' ? '自动发货' : '人工发货';
    $feeLabel = $feeType === 'free' ? '包邮'
        : ($feeType === 'template' ? '按模板计算' : number_format($fee, 2) . ' 元');
    ?>
    <style>
    .goods-type-physical { margin: 10px 0; }
    .goods-type-physical .goods-type-badge { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
    .goods-type-physical .badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 3px; font-size: 12px;
    }
    .goods-type-physical .badge--shipping {
        background: #fff7e6; color: #fa8c16; border: 1px solid #ffd591;
    }
    .goods-type-physical .badge--fee {
        background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f;
    }
    .goods-type-physical .badge--address {
        background: #f0f5ff; color: #1890ff; border: 1px solid #adc6ff;
    }
    .goods-type-physical .shipping-info {
        font-size: 13px; color: #666; line-height: 1.8;
    }
    .goods-type-physical .shipping-info strong { color: #333; }
    </style>
    <div class="goods-type-physical">
        <div class="goods-type-badge">
            <span class="badge badge--shipping">
                <i class="fa fa-truck"></i> <?php echo $shippingLabel; ?>
            </span>
            <span class="badge badge--fee">
                <i class="fa fa-rmb"></i> <?php echo $feeLabel; ?>
            </span>
            <?php if ($requireAddress): ?>
            <span class="badge badge--address">
                <i class="fa fa-map-marker"></i> 需填写收货地址
            </span>
            <?php endif; ?>
        </div>
        <?php if ($deliveryDays > 0): ?>
        <div class="shipping-info">
            <i class="fa fa-clock-o" style="color:#fa8c16;"></i>
            预计 <?php echo $deliveryDays; ?> 个工作日内发货
        </div>
        <?php endif; ?>
        <?php if ($freeByQty > 0): ?>
        <div class="shipping-info" style="margin-top:4px;">
            <i class="fa fa-gift" style="color:#52c41a;"></i>
            购买 <?php echo $freeByQty; ?> 件及以上包邮
        </div>
        <?php endif; ?>
        <?php if (!empty($remark)): ?>
        <div class="shipping-info" style="margin-top:4px;font-size:12px;color:#999;">
            <i class="fa fa-info-circle"></i> <?php echo nl2br(htmlspecialchars($remark)); ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
});

// ================================================================
// 第五步：订单提交前校验钩子（Filter）
// ================================================================
addFilter('goods_type_physical_order_submit', function ($result, $orderData) {
    $pluginData = $orderData['plugin_data'] ?? [];
    $requireAddress = !empty($pluginData['require_address']);

    if ($requireAddress) {
        $address = $orderData['address'] ?? [];
        if (empty($address) || empty($address['name']) || empty($address['phone']) || empty($address['detail'])) {
            return '请填写完整的收货地址后再下单';
        }
    }

    return $result;
});

// ================================================================
// 第六步：后台订单详情钩子
// ================================================================
addAction('goods_type_physical_admin_order_detail', function ($orderId) {
    $order = Database::query(
        "SELECT * FROM " . Database::prefix() . "order WHERE id = ? LIMIT 1",
        [$orderId]
    );

    if (empty($order)) {
        return;
    }

    $o = $order[0];
    $status = $o['status'] ?? '';
    $shippingStatus = $o['shipping_status'] ?? 'pending';
    $expressCompany = $o['express_company'] ?? '';
    $expressNo = $o['express_no'] ?? '';

    $statusMap = [
        'pending' => ['待付款', 'default'],
        'paid' => ['已付款', 'blue'],
        'delivering' => ['发货中', 'orange'],
        'delivered' => ['已发货', 'green'],
        'completed' => ['已完成', 'gray'],
        'expired' => ['已过期', 'gray'],
        'cancelled' => ['已取消', 'gray'],
        'refunding' => ['退款中', 'orange'],
        'refunded' => ['已退款', 'gray'],
    ];

    $shippingStatusMap = [
        'pending' => ['待发货', 'default'],
        'shipped' => ['已发货', 'blue'],
        'delivered' => ['已收货', 'green'],
    ];

    $sInfo = $statusMap[$status] ?? ['未知', 'default'];
    $ssInfo = $shippingStatusMap[$shippingStatus] ?? ['未知', 'default'];

    $colorMap = [
        'default' => '#999',
        'blue' => '#1890ff',
        'green' => '#52c41a',
        'orange' => '#fa8c16',
        'gray' => '#999',
    ];
    $sColor = $colorMap[$sInfo[1]] ?? '#999';
    $ssColor = $colorMap[$ssInfo[1]] ?? '#999';
    ?>
    <div class="layui-form-item" style="border-top:1px dashed #e2e2e2;padding-top:10px;margin-top:10px;">
        <label class="layui-form-label" style="width:120px;"><i class="fa fa-truck"></i> 物流信息</label>
        <div class="layui-input-inline" style="width:calc(100% - 130px);">
            <div style="display:flex;gap:15px;margin-bottom:10px;flex-wrap:wrap;">
                <span>订单状态：
                    <span class="layui-badge" style="background:<?php echo $sColor; ?>;color:#fff;">
                        <?php echo $sInfo[0]; ?>
                    </span>
                </span>
                <span>发货状态：
                    <span class="layui-badge" style="background:<?php echo $ssColor; ?>;color:#fff;">
                        <?php echo $ssInfo[0]; ?>
                    </span>
                </span>
            </div>

            <?php if (!empty($expressCompany) || !empty($expressNo)): ?>
            <div style="background:#f6f8fa;padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;">
                <div style="color:#666;margin-bottom:4px;">
                    <i class="fa fa-truck"></i> 快递公司：<strong style="color:#333;"><?php echo htmlspecialchars($expressCompany); ?></strong>
                </div>
                <div style="color:#666;">
                    <i class="fa fa-barcode"></i> 快递单号：
                    <strong style="color:#1890ff;font-family:Consolas,monospace;"><?php echo htmlspecialchars($expressNo); ?></strong>
                    <?php if (!empty($expressNo)): ?>
                    <a href="javascript:;" onclick="copyExpressNo('<?php echo htmlspecialchars($expressNo, ENT_QUOTES); ?>')"
                       style="margin-left:8px;color:#1890ff;font-size:12px;">复制</a>
                    <a href="https://www.kuaidi100.com/?nu=<?php echo urlencode($expressNo); ?>" target="_blank"
                       style="margin-left:8px;color:#1890ff;font-size:12px;">查快递</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($status === 'delivering' || $status === 'delivered'): ?>
            <div style="margin-bottom:8px;">
                <button type="button" class="layui-btn layui-btn-sm" onclick="editShipping(<?php echo $orderId; ?>)">
                    <i class="fa fa-edit"></i> <?php echo $shippingStatus === 'shipped' || $shippingStatus === 'delivered' ? '修改物流' : '填写物流'; ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    window.copyExpressNo = function(no) {
        var input = document.createElement('input');
        input.value = no;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        layui.layer.msg('已复制：' + no);
    };

    window.editShipping = function(orderId) {
        layui.layer.open({
            type: 2,
            title: '填写物流信息',
            skin: 'admin-modal',
            area: ['500px', '300px'],
            content: '/admin/?_action=shipping_form&order_id=' + orderId,
            btn: ['保存', '取消'],
            yes: function(index, layero) {
                var iframeWin = layero.find('iframe')[0].contentWindow;
                iframeWin.submitShipping && iframeWin.submitShipping();
            }
        });
    };
    </script>
    <?php
});

// ================================================================
// 第七步：类型切换警告钩子
// ================================================================
addAction('goods_type_physical_switch_warning', function (&$warnings, $goodsId, $oldType, $newType) {
    $warnings[] = [
        'type' => 'info',
        'message' => '切换离开「实物商品」后，该商品的物流配置（运费、发货时间等）将被保留。切换到其他类型后，相关配置不会自动清除。',
    ];
});

// ================================================================
// 第八步：类型切换——从本类型切出
// ================================================================
addAction('goods_type_physical_switch_from', function ($goodsId) {
    // 仅记录日志
    if (function_exists('log_message')) {
        log_message('info', "[physical] switched from: goods_id={$goodsId}");
    }
});

// ================================================================
// 第九步：类型切换——切换到本类型
// ================================================================
addAction('goods_type_physical_switch_to', function ($goodsId) {
    $goods = GoodsModel::getById($goodsId);
    if ($goods) {
        $data = [];
        if (!empty($goods['plugin_data'])) {
            $data = json_decode($goods['plugin_data'], true) ?: [];
        }
        $defaults = [
            'delivery_days' => $data['delivery_days'] ?? 3,
            'shipping_fee_type' => $data['shipping_fee_type'] ?? 'fixed',
            'shipping_fee' => $data['shipping_fee'] ?? 0,
            'require_address' => 1, // 实物商品始终要求收货地址
            'delivery_remark' => $data['delivery_remark'] ?? '',
        ];
        Database::update('goods', [
            'plugin_data' => json_encode($defaults, JSON_UNESCAPED_UNICODE),
        ], $goodsId);
    }
});

// ================================================================
// 第十步：订单支付成功钩子
// ================================================================
addAction('goods_type_physical_order_paid', function ($orderId, $orderGoodsId, $pluginData) {
    // 实物商品不支持自动发货，需要管理员手动填写快递信息
    if (function_exists('log_message')) {
        log_message('info', "[physical] order_paid: order #{$orderId} needs manual shipping");
    }

    // 递增规格已售数量
    $og = Database::query(
        "SELECT spec_id, quantity FROM " . Database::prefix() . "order_goods WHERE id = ? LIMIT 1",
        [$orderGoodsId]
    );
    if (!empty($og)) {
        $specId = (int)$og[0]['spec_id'];
        $qty = (int)$og[0]['quantity'];
        if ($specId > 0 && $qty > 0) {
            GoodsModel::incrementSoldCount($specId, $qty);
        }
    }

    // 触发发货提醒通知
    $order = Database::query(
        "SELECT * FROM " . Database::prefix() . "order WHERE id = ? LIMIT 1",
        [$orderId]
    );
    if (!empty($order)) {
        $o = $order[0];
        $addressInfo = !empty($o['address_info']) ? json_decode($o['address_info'], true) : [];
        $contactInfo = !empty($o['contact_info']) ? json_decode($o['contact_info'], true) : [];
        $contactText = is_string($contactInfo) ? $contactInfo : ($contactInfo['name'] ?? '') . ' ' . ($contactInfo['phone'] ?? '');
        $addressText = !empty($addressInfo) ? ($addressInfo['name'] ?? '') . ' ' . ($addressInfo['phone'] ?? '') . ' ' . ($addressInfo['detail'] ?? '') : '';

        if (function_exists('doAction')) {
            // notice_admin 参数签名：$adminId, $title, $content
            // adminId=0 表示通知所有管理员
            doAction('notice_admin', 0, '实物订单待发货',
                "订单 #{$orderId} 已付款，请及时发货。\n收货人：{$contactText}\n地址：{$addressText}"
            );
        }
    }
});

// ================================================================
// 第十一步：订单取消钩子
// ================================================================
addAction('goods_type_physical_order_cancel', function ($orderId) {
    if (function_exists('log_message')) {
        log_message('info', "[physical] order_cancel: order #{$orderId}");
    }
});

// ================================================================
// 第十二步：订单退款钩子
// ================================================================
addAction('goods_type_physical_order_refund', function ($orderId) {
    // 检查是否已发货
    $order = Database::query(
        "SELECT shipping_status, express_no FROM " . Database::prefix() . "order WHERE id = ? LIMIT 1",
        [$orderId]
    );
    if (!empty($order)) {
        $o = $order[0];
        if (($o['shipping_status'] ?? 'pending') === 'shipped' && !empty($o['express_no'])) {
            if (function_exists('log_message')) {
                log_message('warning', "[physical] refund: order #{$orderId} already shipped, express #{$o['express_no']}");
            }
        }
    }
    if (function_exists('log_message')) {
        log_message('info', "[physical] order_refund: order #{$orderId}");
    }
});

// ================================================================
// 发货表单：通过 admin_plugin_action 钩子处理，不在核心代码中
// ================================================================
addAction('admin_plugin_action_shipping_form', function () {
    require __DIR__ . '/shipping_form.php';
    exit;
});

addAction('admin_plugin_action_shipping_submit', function () {
    require __DIR__ . '/shipping_form.php';
    exit;
});
