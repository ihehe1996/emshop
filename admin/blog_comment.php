<?php
declare(strict_types=1);

require __DIR__ . '/global.php';

// 后台登录校验
adminRequireLogin();

$action = $_GET['_action'] ?? $_POST['_action'] ?? '';

// 非 list 操作统一验证 CSRF
if ($action !== 'list' && $action !== '') {
    $csrf = (string)(($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? ''));
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }
}

// 列表接口（供 layui table 使用）
if ($action === 'list') {
    $page    = (int)($_POST['page'] ?? 1);
    $limit   = (int)($_POST['limit'] ?? 20);
    $keyword = trim($_POST['keyword'] ?? '');
    $status  = isset($_POST['status']) && $_POST['status'] !== '' ? (int)$_POST['status'] : null;

    $where = [];
    if ($keyword !== '')   $where['keyword'] = $keyword;
    if ($status !== null)  $where['status'] = $status;

    $result = BlogCommentModel::getAdminList($where, $page, $limit);
    $counts = BlogCommentModel::getStatusCounts();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code'       => 0,
        'msg'        => '',
        'count'      => $result['total'],
        'data'       => $result['list'],
        'csrf_token' => Csrf::token(),
        'tab_counts' => $counts,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 审核通过
if ($action === 'approve') {
    $id = (int)($_POST['id'] ?? 0);
    $comment = BlogCommentModel::getById($id);
    if (!$comment) {
        Response::error('评论不存在');
    }
    BlogCommentModel::updateStatus($id, 1);
    Response::success('已通过', ['csrf_token' => Csrf::token()]);
}

// 拒绝
if ($action === 'reject') {
    $id = (int)($_POST['id'] ?? 0);
    $comment = BlogCommentModel::getById($id);
    if (!$comment) {
        Response::error('评论不存在');
    }
    BlogCommentModel::updateStatus($id, 2);
    Response::success('已拒绝', ['csrf_token' => Csrf::token()]);
}

// 删除评论（逻辑删除）
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (BlogCommentModel::delete($id)) {
        Response::success('删除成功', ['csrf_token' => Csrf::token()]);
    } else {
        Response::error('删除失败');
    }
}

// 批量操作
if ($action === 'batch') {
    $batchAction = $_POST['batch_action'] ?? '';
    $ids = is_array($_POST['ids'] ?? null)
        ? array_map('intval', $_POST['ids'])
        : (json_decode($_POST['ids'] ?? '[]', true) ?: []);
    if (empty($ids)) {
        Response::error('请选择评论');
    }

    if ($batchAction === 'approve') {
        BlogCommentModel::batchUpdateStatus($ids, 1);
    } elseif ($batchAction === 'reject') {
        BlogCommentModel::batchUpdateStatus($ids, 2);
    } elseif ($batchAction === 'delete') {
        BlogCommentModel::batchDelete($ids);
    } else {
        Response::error('未知操作');
    }

    Response::success('批量操作成功', ['csrf_token' => Csrf::token()]);
}

// 默认：显示列表页面
if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/blog_comment.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/blog_comment.php';
    require __DIR__ . '/index.php';
}
