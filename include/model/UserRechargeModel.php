<?php

declare(strict_types=1);

/**
 * 钱包充值订单模型。
 *
 * 用户发起充值 → 创建一行 pending 记录 → 第三方支付回调 → markPaid 给用户 money 加钱。
 */
class UserRechargeModel
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    private string $table;

    public function __construct()
    {
        $this->table = Database::prefix() . 'user_recharge';
    }

    /**
     * 创建一条 pending 充值单。
     *
     * @return array{id:int, order_no:string}
     */
    public function create(int $userId, int $amountRaw, string $paymentCode, string $paymentPlugin): array
    {
        $orderNo = $this->generateOrderNo();
        $id = (int) Database::insert('user_recharge', [
            'user_id'        => $userId,
            'order_no'       => $orderNo,
            'amount'         => $amountRaw,
            'payment_code'   => $paymentCode,
            'payment_plugin' => $paymentPlugin,
            'trade_no'       => '',
            'status'         => self::STATUS_PENDING,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        return ['id' => $id, 'order_no' => $orderNo];
    }

    public function findByOrderNo(string $orderNo): ?array
    {
        return Database::fetchOne("SELECT * FROM {$this->table} WHERE order_no = ?", [$orderNo]) ?: null;
    }

    public function findById(int $id): ?array
    {
        return Database::find('user_recharge', $id);
    }

    /**
     * 标记已支付并给用户加余额（幂等：已 paid 的直接返回 true）。
     */
    public function markPaid(int $id, string $tradeNo): bool
    {
        $row = $this->findById($id);
        if (!$row) return false;
        if ($row['status'] === self::STATUS_PAID) return true;
        if ($row['status'] !== self::STATUS_PENDING) return false;

        Database::begin();
        try {
            $affected = Database::execute(
                "UPDATE {$this->table} SET status = ?, trade_no = ?, paid_at = NOW()
                 WHERE id = ? AND status = ?",
                [self::STATUS_PAID, $tradeNo, $id, self::STATUS_PENDING]
            );
            if ($affected === 0) {
                // 并发场景：已被其他回调处理
                Database::rollBack();
                return true;
            }

            // 给用户 money 加钱（UserBalanceLogModel::increase 内部再开一层事务——同连接下嵌套会被压平，实际只开一次）
            $ok = (new UserBalanceLogModel())->increase(
                (int) $row['user_id'],
                (int) $row['amount'],
                '钱包充值 #' . $row['order_no']
            );
            if (!$ok) throw new RuntimeException('加余额失败');

            Database::commit();
            return true;
        } catch (Throwable $e) {
            Database::rollBack();
            return false;
        }
    }

    /**
     * 用户侧分页：只查自己的充值记录。
     *
     * @return array{list:array, total:int, total_pages:int}
     */
    public function paginateByUser(int $userId, int $page = 1, int $perPage = 15): array
    {
        $countRow = Database::fetchOne("SELECT COUNT(*) AS cnt FROM {$this->table} WHERE user_id = ?", [$userId]);
        $total = (int) ($countRow['cnt'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $perPage;

        $list = Database::query(
            "SELECT * FROM {$this->table} WHERE user_id = ? ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            [$userId]
        );
        return ['list' => $list, 'total' => $total, 'total_pages' => $totalPages];
    }

    /**
     * 管理员侧分页：可按状态筛选、关键字（单号/用户名）搜索。
     */
    public function paginate(array $filter, int $page = 1, int $perPage = 20): array
    {
        $prefix = Database::prefix();
        $where = ['1=1'];
        $params = [];

        if (!empty($filter['status'])) {
            $where[] = 'r.status = ?';
            $params[] = (string) $filter['status'];
        }
        if (!empty($filter['keyword'])) {
            $kw = '%' . $filter['keyword'] . '%';
            $where[] = '(r.order_no LIKE ? OR u.username LIKE ? OR u.nickname LIKE ?)';
            $params[] = $kw; $params[] = $kw; $params[] = $kw;
        }
        $whereSql = implode(' AND ', $where);

        $countRow = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} r
             LEFT JOIN {$prefix}user u ON u.id = r.user_id
             WHERE {$whereSql}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $perPage;

        $list = Database::query(
            "SELECT r.*, u.username, u.nickname
             FROM {$this->table} r
             LEFT JOIN {$prefix}user u ON u.id = r.user_id
             WHERE {$whereSql}
             ORDER BY r.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['list' => $list, 'total' => $total, 'total_pages' => $totalPages, 'page' => $page];
    }

    /**
     * 生成唯一充值单号：R + yyyymmddHHiiss + 4 位随机。
     */
    private function generateOrderNo(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $no = 'R' . date('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $exist = Database::fetchOne("SELECT id FROM {$this->table} WHERE order_no = ?", [$no]);
            if (!$exist) return $no;
        }
        throw new RuntimeException('生成充值单号失败');
    }
}
