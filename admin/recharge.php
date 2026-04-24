<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * 后台 - 钱包充值订单（只读查看）。
 *
 * POST _action = list / cancel
 */
adminRequireLogin();
$user = $adminUser;
$siteName = Config::get('sitename', 'EMSHOP');

if (Request::isPost()) {
    try {
        $action = (string) Input::post('_action', '');
        $model = new UserRechargeModel();

        if ($action === 'list') {
            $page    = max(1, (int) Input::post('page', 1));
            $perPage = max(1, min(100, (int) Input::post('limit', 20)));
            $filter  = [
                'status'  => (string) Input::post('status', ''),
                'keyword' => trim((string) Input::post('keyword', '')),
            ];
            $result = $model->paginate($filter, $page, $perPage);
            foreach ($result['list'] as &$r) {
                $r['amount_display'] = bcdiv((string) $r['amount'], '1000000', 2);
            }
            unset($r);
            Response::success('', [
                'data'       => $result['list'],
                'total'      => $result['total'],
                'csrf_token' => Csrf::token(),
            ]);
        }

        if ($action === 'cancel') {
            // 管理员手动取消 pending 单（只改状态，不退款——pending 单本就没扣过钱）
            if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
                Response::error('令牌失效，请刷新后重试');
            }
            $id = (int) Input::post('id', 0);
            $row = $model->findById($id);
            if (!$row) Response::error('记录不存在');
            if ($row['status'] !== UserRechargeModel::STATUS_PENDING) {
                Response::error('仅待支付的充值单可取消');
            }
            Database::execute(
                'UPDATE ' . Database::prefix() . 'user_recharge SET status = ? WHERE id = ? AND status = ?',
                [UserRechargeModel::STATUS_CANCELLED, $id, UserRechargeModel::STATUS_PENDING]
            );
            Response::success('已取消');
        }

        Response::error('未知操作');
    } catch (Throwable $e) {
        Response::error('系统繁忙：' . $e->getMessage());
    }
}

$csrfToken = Csrf::token();

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/recharge.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/recharge.php';
    require __DIR__ . '/index.php';
}
