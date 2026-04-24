<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 商户后台 - 独立收款配置
 *
 * 仅当等级 allow_own_pay = 1 时可见。
 *
 * v1 能力（方案 §6.4）：
 *   - 保存支付通道 JSON 配置（mch_id / appid / key 等字段由支付插件自定义）
 *   - 提交审核：设置完配置后点击按钮，后台管理员收到审核；v1 暂不实际切换通道，仅把申请状态改为"审核中"
 *   - 审核通过由主站后台把 em_merchant.own_pay_enabled 置 1
 *
 * v1 审核状态的推断：
 *   - 未申请：pay_channel_config 为空 / null
 *   - 审核中：pay_channel_config 有内容，own_pay_enabled = 0
 *   - 已启用：own_pay_enabled = 1
 *   - 已拒绝：v1 不实现（v2 加 status 字段单独记）
 */
merchantRequireLogin();

$merchantId = (int) $currentMerchant['id'];
$allowOwnPay = (int) ($merchantLevel['allow_own_pay'] ?? 0) === 1;

if (!$allowOwnPay) {
    merchantRenderPage(__DIR__ . '/view/payment_locked.php');
    return;
}

if (Request::isPost()) {
    try {
        if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效');
        }
        $action = (string) Input::post('_action', '');

        switch ($action) {
            case 'save_config': {
                $raw = trim((string) Input::post('pay_channel_config', ''));
                if ($raw !== '') {
                    $parsed = json_decode($raw, true);
                    if (!is_array($parsed)) {
                        Response::error('配置必须是合法的 JSON 对象');
                    }
                    // 规范化：重新编码压缩存入
                    $raw = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                // 配置修改 → 把 own_pay_enabled 置 0（v1：每次改配置都需重新审核）
                Database::update('merchant', [
                    'pay_channel_config' => $raw !== '' ? $raw : null,
                    'own_pay_enabled' => 0,
                ], $merchantId);

                Response::success('配置已保存，待主站审核', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            case 'submit_audit': {
                // 无实际配置就不能提交
                $raw = (string) ($currentMerchant['pay_channel_config'] ?? '');
                if ($raw === '') {
                    Response::error('请先保存配置再提交审核');
                }
                // v1：标记为"待审"（own_pay_enabled=0 即可视为待审；此处仅返回提示）
                Response::success('已提交审核，请耐心等待', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            default:
                Response::error('未知操作');
        }
    } catch (Throwable $e) {
        Response::error($e->getMessage() ?: '系统繁忙');
    }
}

// 当前审核状态
$configRaw = (string) ($currentMerchant['pay_channel_config'] ?? '');
$ownPayEnabled = (int) ($currentMerchant['own_pay_enabled'] ?? 0) === 1;
if ($ownPayEnabled) {
    $auditStatus = 'enabled';
    $auditLabel = '已启用';
    $auditColor = 'green';
} elseif ($configRaw !== '') {
    $auditStatus = 'pending';
    $auditLabel = '审核中';
    $auditColor = 'orange';
} else {
    $auditStatus = 'none';
    $auditLabel = '未申请';
    $auditColor = 'gray';
}

// 格式化 JSON 用于编辑框显示
$configPretty = '';
if ($configRaw !== '') {
    $decoded = json_decode($configRaw, true);
    if (is_array($decoded)) {
        $configPretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

merchantRenderPage(__DIR__ . '/view/payment.php', [
    'auditStatus' => $auditStatus,
    'auditLabel' => $auditLabel,
    'auditColor' => $auditColor,
    'configPretty' => $configPretty,
    'feeRatePct' => rtrim(rtrim(number_format(((int) ($merchantLevel['self_goods_fee_rate'] ?? 0)) / 100, 2, '.', ''), '0'), '.'),
]);
