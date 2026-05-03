<?php
/**
Plugin Name: 异次元共享店铺
Version: 1.0.0
Plugin URL:
Description: 对接异次元/萌次元系统的共享店铺，支持 V3 / V4 协议；自动同步上游商品、库存、价格，订单自动代付上游并发货。
Author: EMSHOP
Author URL:
Category: 对接插件
*/

defined('EM_ROOT') || exit('Access Denied');

// 插件内部 class autoload：命名空间 YcyShared\* 映射到 lib/YcyShared/*.php
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'YcyShared\\') !== 0) return;
    $relative = str_replace('\\', '/', $class);
    $file = __DIR__ . '/lib/' . $relative . '.php';
    if (is_file($file)) require_once $file;
});

// ============================================================
// 注册新的 goods_type：ycy_shared
//   由 YcyShared\GoodsType 处理：商品编辑锁价/锁量、发货代付上游
// ============================================================
addAction('goods_type_register', function (array &$types): void {
    $types['ycy_shared'] = [
        'name'            => '异次元共享',
        'description'     => '来自异次元共享店铺的商品，价格和库存由上游同步，不可手动修改',
        'delivery_type'   => 'auto',           // 自动发货（代付上游获取卡密）
        'needs_address'   => false,            // 自动发货类商品，不要求收货地址
        'icon'            => 'fa-link',
        'plugin'          => 'ycy_shared',
        // 占位：后续由 YcyShared\GoodsType 注册各阶段钩子
    ];
});

// ============================================================
// 定时同步：每 3 分钟拉一次库存+价格；每 60 分钟全量同步商品目录
//   依赖核心 swoole_timer_tick（60s 一次）钩子，插件内部做节流
// ============================================================
addAction('swoole_timer_tick', function (): void {
    try {
        YcyShared\SyncService::tick();
    } catch (Throwable $e) {
        YcyShared\Logger::error('定时同步失败', $e->getMessage(), [
            'scene' => 'swoole_timer_tick',
        ]);
    }
});

// ============================================================
// 商品编辑页横幅：提示价格/库存由上游同步，禁用输入
//   hook 时机见 admin/view/popup/goods_edit.php → doAction('goods_type_ycy_shared_create_form', $goods)
// ============================================================
addAction('goods_type_ycy_shared_create_form', function ($goods): void {
    $goodsId = (int) ($goods['id'] ?? 0);
    $sourceId = (string) ($goods['source_id'] ?? '');
    ?>
    <div style="margin:0 0 14px;padding:12px 16px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:13px;color:#92400e;">
        <i class="fa fa-link" style="margin-right:6px;"></i>
        <strong>异次元共享商品</strong> · 上游标识 <code style="background:rgba(146,64,14,.1);padding:1px 6px;border-radius:3px;"><?= htmlspecialchars($sourceId, ENT_QUOTES, 'UTF-8') ?></code>
        · 价格和库存由后台同步任务管理，手动修改会在下次同步时被覆盖。
    </div>
    <script>
    (function(){
        // 禁用价格/库存/SKU 相关输入，防止用户误改
        layui.use(['jquery'], function(){
            var $ = layui.$;
            function lockInputs() {
                $('input[name^="specs"][name*="[price]"], input[name^="specs"][name*="[stock]"], input[name="price_raw"], input[name="stock"]')
                    .prop('readonly', true).css({ background:'#f3f4f6', cursor:'not-allowed' })
                    .attr('title', '上游同步管理，不可手动修改');
            }
            lockInputs();
            // 规格行动态添加时重跑一次
            $(document).on('click', '.spec-add, .spec-row-add', function(){ setTimeout(lockInputs, 100); });
        });
    })();
    </script>
    <?php
});

// 库存管理弹窗的横幅 + 锁定
addAction('goods_type_ycy_shared_stock_form', function ($goods, $specs): void {
    ?>
    <div style="margin:0 0 14px;padding:12px 16px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:13px;color:#92400e;">
        <i class="fa fa-link" style="margin-right:6px;"></i>
        此商品库存由异次元上游同步，不建议在此手动修改；每 3 分钟会自动校准一次。
    </div>
    <?php
});

// ============================================================
// 下单前实时校验：避开 3 分钟库存轮询窗口期的超卖
//   核心在 OrderModel::create() 里执行 goods_type_{type}_order_submit 过滤器，
//   返回非空字符串就会把这条错误抛回给用户。
// ============================================================
addFilter('goods_type_ycy_shared_order_submit', function ($currentError, array $ctx) {
    if (is_string($currentError) && $currentError !== '') return $currentError; // 已有其他插件报错
    $goods = [];
    $spec = [];
    $qty = 0;
    try {
        $goods = $ctx['goods'] ?? [];
        $spec  = $ctx['spec']  ?? [];
        $qty   = (int) ($ctx['quantity'] ?? 0);
        if (empty($goods['id']) || empty($spec['id']) || $qty <= 0) return '';

        // 查映射
        $mapping = Database::fetchOne(
            'SELECT * FROM `' . Database::prefix() . 'ycy_goods` WHERE `goods_id` = ? LIMIT 1',
            [(int) $goods['id']]
        );
        if (!$mapping) return '上游映射丢失，该商品暂不可购买';

        $site = \YcyShared\SiteModel::find((int) $mapping['site_id']);
        if ($site === null || (int) $site['enabled'] !== 1) {
            return '上游站点已停用，该商品暂不可购买';
        }

        $skuMap = json_decode((string) $mapping['sku_map'], true) ?: [];
        $hitSku = null;
        foreach ($skuMap as $s) {
            if ((int) ($s['local_spec_id'] ?? 0) === (int) $spec['id']) { $hitSku = $s; break; }
        }
        if ($hitSku === null) return '规格映射丢失，请重新导入商品';

        // 实时拉上游库存
        $client = \YcyShared\Client::make($site);
        $liveStock = $client->fetchStock((string) $mapping['upstream_ref'], $hitSku['upstream_sku_id'] ?? null);
        if ($liveStock < $qty) {
            // 顺便把最新库存写回本地，避免后面重复失败
            Database::update('goods_spec', ['stock' => max(0, $liveStock)], (int) $spec['id']);
            return '库存不足：上游剩余 ' . $liveStock;
        }
    } catch (Throwable $e) {
        // 网络异常不阻塞下单（保守放行，代付阶段上游会再兜一次）
        YcyShared\Logger::warning('下单前实时校验异常', $e->getMessage(), [
            'goods_id' => (int) ($goods['id'] ?? 0),
            'spec_id'  => (int) ($spec['id'] ?? 0),
            'quantity' => $qty,
        ]);
    }
    return '';
});

// ============================================================
// 后台订单详情：展示代付上游的流水 + 卡密内容
//   核心在 admin/view/order_detail 按 goods_type 调 goods_type_{type}_admin_order_detail
// ============================================================
addAction('goods_type_ycy_shared_admin_order_detail', function ($orderId): void {
    $prefix = Database::prefix();

    // 该订单的 ycy_shared 订单行 + 关联流水 + 映射信息
    $rows = Database::query(
        "SELECT og.*, t.`status` AS `trade_status`, t.`upstream_trade_no`, t.`error_message`,
                t.`cost_amount_raw`, t.`created_at` AS `trade_created_at`,
                s.`name` AS `site_name`, y.`upstream_ref`, y.`upstream_name`
           FROM `{$prefix}order_goods` og
           LEFT JOIN `{$prefix}goods`       g ON g.`id` = og.`goods_id`
           LEFT JOIN `{$prefix}ycy_goods`   y ON y.`goods_id` = og.`goods_id`
           LEFT JOIN `{$prefix}ycy_site`    s ON s.`id` = y.`site_id`
           LEFT JOIN `{$prefix}ycy_trade`   t ON t.`order_goods_id` = og.`id`
          WHERE og.`order_id` = ? AND g.`goods_type` = 'ycy_shared'
          ORDER BY og.`id` ASC, t.`id` DESC",
        [(int) $orderId]
    );
    if (empty($rows)) return;

    // 去重：每个 order_goods 只取最新一条流水
    $seen = [];
    $items = [];
    foreach ($rows as $r) {
        $ogId = (int) $r['id'];
        if (isset($seen[$ogId])) continue;
        $seen[$ogId] = true;
        $items[] = $r;
    }
    ?>
    <div class="layui-form-item" style="border-top:1px dashed #e2e2e2;padding-top:10px;margin-top:10px;">
        <label class="layui-form-label" style="width:120px;"><i class="fa fa-link"></i> 异次元代付</label>
        <div class="layui-input-inline" style="width:calc(100% - 130px);">
            <?php foreach ($items as $r): ?>
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;margin-bottom:8px;">
                    <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">
                        <strong><?= htmlspecialchars((string) ($r['site_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                        · Ref <code style="background:#eef2ff;color:#6366f1;padding:1px 6px;border-radius:3px;"><?= htmlspecialchars((string) ($r['upstream_ref'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
                        <?php if (!empty($r['upstream_trade_no'])): ?>
                            · 上游单号 <?= htmlspecialchars((string) $r['upstream_trade_no'], ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                    <?php if (($r['trade_status'] ?? '') === 'success'): ?>
                        <div style="font-size:12px;color:#059669;margin-bottom:6px;">
                            <i class="fa fa-check-circle"></i> 代付成功
                            <?php if (!empty($r['cost_amount_raw'])): ?>
                                · 上游成本 ¥<?= number_format(((int) $r['cost_amount_raw']) / 1000000, 2) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($r['delivery_content'])): ?>
                        <div style="font-family:Consolas,'Courier New',monospace;word-break:break-all;font-size:13px;line-height:1.8;background:#fff;border:1px solid #e5e7eb;border-radius:4px;padding:8px;">
                            <?= nl2br(htmlspecialchars((string) $r['delivery_content'], ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                        <?php endif; ?>
                    <?php elseif (($r['trade_status'] ?? '') === 'failed'): ?>
                        <div style="font-size:12px;color:#e11d48;">
                            <i class="fa fa-times-circle"></i> 代付失败：<?= htmlspecialchars((string) ($r['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div style="font-size:11px;color:#9ca3af;margin-top:4px;">可到插件设置页的"代付流水" Tab 手动重试</div>
                    <?php else: ?>
                        <div style="font-size:12px;color:#f59e0b;"><i class="fa fa-clock-o"></i> 等待代付（Swoole 队列将自动处理）</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
});

// ============================================================
// 订单发货：goods_type = ycy_shared 的行被 swoole 队列处理时触发
//   核心会调 goods_type_{goodsType}_order_paid($orderId, $orderGoodsId, $payloadJson)
// ============================================================
addAction('goods_type_ycy_shared_order_paid', function (int $orderId, int $orderGoodsId, string $payloadJson): void {
    try {
        YcyShared\DeliveryService::handle($orderId, $orderGoodsId, $payloadJson);
    } catch (Throwable $e) {
        // 抛出去让 swoole 队列标记为 failed 并按策略重试
        YcyShared\Logger::error('代付发货失败', $e->getMessage(), [
            'order_id' => $orderId,
            'order_goods_id' => $orderGoodsId,
        ]);
        throw $e;
    }
});
