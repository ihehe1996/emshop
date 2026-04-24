<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 商户后台 - 订单管理
 *
 * 只读视图：商户能看自家订单、看到每项的拿货价 / 手续费 快照，但不能改订单状态。
 * 状态机流转由主站管理员负责（OrderModel::changeStatus）。
 */
merchantRequireLogin();

$merchantId = (int) $currentMerchant['id'];

if (Request::isPost()) {
    try {
        $action = (string) Input::post('_action', '');
        if ($action !== 'list' && $action !== 'detail') {
            if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
                Response::error('请求已失效，请刷新页面后重试');
            }
        }

        $orderTable = Database::prefix() . 'order';
        $orderGoodsTable = Database::prefix() . 'order_goods';

        switch ($action) {
            case 'list': {
                $keyword = trim((string) Input::post('keyword', ''));
                $status = (string) Input::post('status', '');
                $page = max(1, (int) Input::post('page', 1));
                $pageSize = max(1, min(100, (int) Input::post('limit', 20)));
                $offset = ($page - 1) * $pageSize;

                $conds = ['o.merchant_id = ?'];
                $params = [$merchantId];

                if ($keyword !== '') {
                    $conds[] = '(o.order_no LIKE ? OR o.contact_info LIKE ?)';
                    $params[] = '%' . $keyword . '%';
                    $params[] = '%' . $keyword . '%';
                }
                // status 支持合并：paid 含 paid+delivering、refunded 含 refunded+refunding
                if ($status !== '' && $status !== 'all') {
                    if ($status === 'paid') {
                        $conds[] = 'o.status IN (?, ?)';
                        $params[] = 'paid'; $params[] = 'delivering';
                    } elseif ($status === 'refunded') {
                        $conds[] = 'o.status IN (?, ?)';
                        $params[] = 'refunded'; $params[] = 'refunding';
                    } else {
                        $conds[] = 'o.status = ?';
                        $params[] = $status;
                    }
                }
                $whereSql = 'WHERE ' . implode(' AND ', $conds);

                $count = Database::fetchOne(
                    'SELECT COUNT(*) AS c FROM `' . $orderTable . '` o ' . $whereSql,
                    $params
                );
                $total = (int) ($count['c'] ?? 0);

                // 每单 cost + fee 合计，从 order_goods 里聚合取
                // 多 SELECT 两个字段：订单下单时冻结的展示币种 / 汇率，供 displaySnapshot 用
                $sql = 'SELECT o.id, o.order_no, o.user_id, o.guest_token, o.pay_amount, o.status,
                               o.pay_channel, o.payment_name, o.pay_time, o.delivery_time, o.complete_time,
                               o.created_at, o.display_currency_code, o.display_rate,
                               (SELECT SUM(og.cost_amount) FROM `' . $orderGoodsTable . '` og WHERE og.order_id = o.id) AS total_cost,
                               (SELECT SUM(og.fee_amount)  FROM `' . $orderGoodsTable . '` og WHERE og.order_id = o.id) AS total_fee,
                               (SELECT COUNT(*)            FROM `' . $orderGoodsTable . '` og WHERE og.order_id = o.id) AS items_count
                          FROM `' . $orderTable . '` o ' . $whereSql . '
                         ORDER BY o.created_at DESC
                         LIMIT ' . $pageSize . ' OFFSET ' . $offset;
                $rows = Database::query($sql, $params);

                foreach ($rows as &$r) {
                    $pay = (int) $r['pay_amount'];
                    $cost = (int) ($r['total_cost'] ?? 0);
                    $fee = (int) ($r['total_fee'] ?? 0);
                    $net = $pay - $cost - $fee;
                    // 金额按下单时快照币种展示 —— 和买家看到的金额完全一致，不受访客当前切币种影响
                    $dispCode = (string) ($r['display_currency_code'] ?? '');
                    $dispRate = (int) ($r['display_rate'] ?? 0);
                    $r['pay_amount_view'] = Currency::displaySnapshot($pay, $dispCode, $dispRate);
                    $r['total_cost_view'] = Currency::displaySnapshot($cost, $dispCode, $dispRate);
                    $r['total_fee_view']  = Currency::displaySnapshot($fee, $dispCode, $dispRate);
                    $r['net_income_view'] = Currency::displaySnapshot($net, $dispCode, $dispRate);
                    $r['buyer_label'] = (int) $r['user_id'] > 0
                        ? '用户 #' . $r['user_id']
                        : ('游客 ' . substr((string) ($r['guest_token'] ?? ''), 0, 8));
                }
                unset($r);

                // 顺便算 Tab 计数
                $tabCounts = [];
                $statusList = ['pending', 'paid', 'delivering', 'delivered', 'completed', 'refunding', 'refunded', 'cancelled'];
                foreach ($statusList as $st) {
                    $cRow = Database::fetchOne(
                        'SELECT COUNT(*) AS c FROM `' . $orderTable . '` WHERE `merchant_id` = ? AND `status` = ?',
                        [$merchantId, $st]
                    );
                    $tabCounts[$st] = (int) ($cRow['c'] ?? 0);
                }
                $all = Database::fetchOne(
                    'SELECT COUNT(*) AS c FROM `' . $orderTable . '` WHERE `merchant_id` = ?',
                    [$merchantId]
                );
                $tabCounts['all'] = (int) ($all['c'] ?? 0);

                Response::success('', [
                    'data' => $rows,
                    'total' => $total,
                    'tab_counts' => $tabCounts,
                    'csrf_token' => Csrf::token(),
                ]);
                break;
            }

            case 'detail': {
                $id = (int) Input::post('id', 0);
                $order = Database::fetchOne(
                    'SELECT * FROM `' . $orderTable . '` WHERE `id` = ? AND `merchant_id` = ? LIMIT 1',
                    [$id, $merchantId]
                );
                if ($order === null) {
                    Response::error('订单不存在');
                }
                $items = Database::query(
                    'SELECT * FROM `' . $orderGoodsTable . '` WHERE `order_id` = ? ORDER BY `id` ASC',
                    [$id]
                );
                // 订单详情所有金额都按下单时快照币种展示
                $dispCode = (string) ($order['display_currency_code'] ?? '');
                $dispRate = (int) ($order['display_rate'] ?? 0);
                foreach ($items as &$it) {
                    $it['price_view']       = Currency::displaySnapshot((int) $it['price'], $dispCode, $dispRate);
                    $it['cost_amount_view'] = Currency::displaySnapshot((int) ($it['cost_amount'] ?? 0), $dispCode, $dispRate);
                    $it['fee_amount_view']  = Currency::displaySnapshot((int) ($it['fee_amount'] ?? 0), $dispCode, $dispRate);
                    $line = ((int) $it['price']) * ((int) $it['quantity']);
                    $lineCost = (int) ($it['cost_amount'] ?? 0) * (int) $it['quantity'];
                    $lineFee  = (int) ($it['fee_amount'] ?? 0);
                    $it['line_net_view'] = Currency::displaySnapshot($line - $lineCost - $lineFee, $dispCode, $dispRate);
                }
                unset($it);

                $order['pay_amount_view']      = Currency::displaySnapshot((int) $order['pay_amount'], $dispCode, $dispRate);
                $order['goods_amount_view']    = Currency::displaySnapshot((int) $order['goods_amount'], $dispCode, $dispRate);
                $order['discount_amount_view'] = Currency::displaySnapshot((int) $order['discount_amount'], $dispCode, $dispRate);

                Response::success('', [
                    'order' => $order,
                    'items' => $items,
                    'csrf_token' => Csrf::token(),
                ]);
                break;
            }

            default:
                Response::error('未知操作');
        }
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error($e->getMessage() ?: '系统繁忙，请稍后再试');
    }
}

merchantRenderPage(__DIR__ . '/view/order.php');
