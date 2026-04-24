<?php
declare(strict_types=1);

require __DIR__ . '/global.php';

// 后台登录校验
adminRequireLogin();

$action = $_GET['_action'] ?? $_POST['_action'] ?? '';

// 非 list 操作统一校验 CSRF
if ($action !== 'list' && $action !== '') {
    $csrf = (string) ($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }
}

// ========== 列表接口（给 layui table 用）==========
if ($action === 'list') {
    $page = (int) ($_POST['page'] ?? 1);
    $limit = (int) ($_POST['limit'] ?? 20);
    $keyword = trim((string) ($_POST['keyword'] ?? ''));
    $status = isset($_POST['status']) && $_POST['status'] !== '' ? (int) $_POST['status'] : null;

    $where = [];
    if ($keyword) $where['keyword'] = $keyword;
    if ($status !== null) $where['status'] = $status;

    $result = PageModel::getList($where, $page, $limit);

    // 状态计数（供 em-tabs 计数徽章使用）
    $countAll = PageModel::getList([], 1, 1)['total'];
    $countPublished = PageModel::getList(['status' => 1], 1, 1)['total'];
    $countDraft = PageModel::getList(['status' => 0], 1, 1)['total'];

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 0,
        'msg' => '',
        'count' => $result['total'],
        'data' => $result['list'],
        'csrf_token' => Csrf::token(),
        'tab_counts' => [
            'all' => $countAll,
            'published' => $countPublished,
            'draft' => $countDraft,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 切换发布状态 ==========
if ($action === 'toggle_status') {
    $id = (int) ($_POST['id'] ?? 0);
    $page = PageModel::getById($id);
    if (!$page) {
        Response::error('页面不存在');
    }
    $newStatus = (int) $page['status'] ? 0 : 1;
    PageModel::update($id, ['status' => $newStatus]);
    Response::success('状态已更新', ['csrf_token' => Csrf::token()]);
}

// ========== 删除 ==========
if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if (PageModel::delete($id)) {
        Response::success('删除成功', ['csrf_token' => Csrf::token()]);
    } else {
        Response::error('删除失败');
    }
}

// ========== 批量操作 ==========
if ($action === 'batch') {
    $batchAction = (string) ($_POST['batch_action'] ?? '');
    $ids = is_array($_POST['ids'] ?? null) ? array_map('intval', $_POST['ids']) : (json_decode($_POST['ids'] ?? '[]', true) ?: []);
    if (empty($ids)) {
        Response::error('请选择页面');
    }
    $failed = 0;
    foreach ($ids as $id) {
        try {
            if ($batchAction === 'publish')    PageModel::update($id, ['status' => 1]);
            elseif ($batchAction === 'draft')  PageModel::update($id, ['status' => 0]);
            elseif ($batchAction === 'delete') PageModel::delete($id);
            else { $failed++; break; }
        } catch (Throwable $e) {
            $failed++;
        }
    }
    if ($failed === 0) {
        Response::success('批量操作成功', ['csrf_token' => Csrf::token()]);
    } else {
        Response::error('批量操作部分失败（' . $failed . '/' . count($ids) . '）');
    }
}

// ========== 默认：渲染列表页 ==========
if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/page.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/page.php';
    require __DIR__ . '/index.php';
}
