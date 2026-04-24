<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 商户后台 - 模板管理。
 *
 * 与主站 admin/template.php 结构对齐；scope='merchant_{id}'，物理文件全站共享、
 * DB 记录按 scope 隔离。商户可独立启用 / 卸载 / 配置自己作用域下的模板。
 */
merchantRequireLogin();

$merchantId = (int) ($currentMerchant['id'] ?? 0);
$scope      = 'merchant_' . $merchantId;
$memberCode = AppLicenseGuard::memberCodeForMerchant($currentMerchant);
$model      = new TemplateModel();

/**
 * 校验模板目录名。
 */
function merchantTemplateValidateName(string $name): string
{
    $name = trim($name);
    if ($name === '')                               throw new RuntimeException('模板名称不能为空');
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name))   throw new RuntimeException('模板名称不合法');
    return $name;
}

// ============================================================
// POST 处理
// ============================================================
if (Request::isPost()) {
    try {
        if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $action = (string) Input::post('_action', '');
        $name   = merchantTemplateValidateName((string) Input::post('name', ''));

        switch ($action) {
            case 'install': {
                if ($model->isInstalled($name, $scope)) {
                    Response::error('该模板已安装');
                }
                $scanned = $model->scanTemplates();
                if (!isset($scanned[$name])) {
                    Response::error('模板目录不存在或缺失 header.php');
                }
                // 首次安装该模板到本 scope → 调 callback_init
                $callbackFile = $model->getCallbackFilePath($name);
                if (is_file($callbackFile)) {
                    include_once $callbackFile;
                    if (function_exists('callback_init')) call_user_func('callback_init');
                }
                $id = $model->install($name, $scanned[$name], $scope);
                Response::success('模板安装成功', ['csrf_token' => Csrf::refresh(), 'id' => $id]);
                break;
            }

            case 'activate_pc':
            case 'activate_mobile': {
                $client = $action === 'activate_pc' ? 'pc' : 'mobile';
                if (!$model->isInstalled($name, $scope)) {
                    Response::error('该模板未安装，请先安装后再启用');
                }
                // 已启用状态下再点则取消（与主站行为一致）
                if ($model->isActive($name, $client, $scope)) {
                    $model->clearActiveTheme($client, $scope);
                    Response::success($client === 'pc' ? '已取消PC端模板' : '已取消手机端模板', ['csrf_token' => Csrf::refresh()]);
                }
                $model->setActiveTheme($client, $name, $scope);
                Response::success($client === 'pc' ? 'PC端模板已切换' : '手机端模板已切换', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            case 'save_config': {
                if (!$model->isInstalled($name, $scope)) Response::error('该模板未安装');
                $settingFile = $model->getSettingFilePath($name);
                if (is_file($settingFile)) {
                    include_once $settingFile;
                    if (function_exists('template_setting')) {
                        call_user_func('template_setting');
                        return;
                    }
                    if (function_exists('plugin_setting')) {
                        call_user_func('plugin_setting');
                        return;
                    }
                }
                $config = [];
                foreach (Input::allPost() as $k => $v) {
                    if (in_array($k, ['_action', 'csrf_token', 'name'], true)) continue;
                    $config[$k] = $v;
                }
                $model->update($name, ['config' => $config], $scope);
                Response::success('配置已保存', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            case 'uninstall': {
                if (!$model->isInstalled($name, $scope)) Response::error('该模板未安装');
                if ($model->isActive($name, 'pc', $scope) || $model->isActive($name, 'mobile', $scope)) {
                    Response::error('当前启用中的模板不允许卸载，请先切换到其它模板');
                }
                $model->uninstall($name, $scope);
                Response::success('模板已卸载', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            default:
                Response::error('未知操作');
        }
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error('系统繁忙，请稍后再试');
    }
}

// ============================================================
// 弹窗模式：模板设置页
// ============================================================
if (Input::get('_popup', '') === '1') {
    $name = merchantTemplateValidateName((string) Input::get('name', ''));
    $info = $model->findByName($name, $scope);
    if ($info === null) exit('模板未安装');
    $settingFile = $model->getSettingFilePath($name);
    if (!is_file($settingFile)) exit('该模板暂无设置页面');

    $csrfToken = Csrf::token();
    $pageTitle = '模板设置：' . htmlspecialchars((string) ($info['title'] ?? $name), ENT_QUOTES, 'UTF-8');
    // 让模板 setting.php 里的 AJAX 把 save_config 提交到商户路径，而不是默认的 /admin/template.php
    $popupTemplateSaveUrl = '/user/merchant/theme.php';
    include __DIR__ . '/../../admin/view/popup/header.php';

    include_once $settingFile;
    if (function_exists('template_setting_view')) {
        call_user_func('template_setting_view');
    } elseif (function_exists('plugin_setting_view')) {
        call_user_func('plugin_setting_view');
    } else {
        echo '<div style="padding:16px;color:#6b7280;">该模板未提供可渲染的设置视图。</div>';
    }
    include __DIR__ . '/../../admin/view/popup/footer.php';
    return;
}

// ============================================================
// 普通页面：扫磁盘 → 授权过滤 → 叠加本 scope 的 is_installed / 启用态
// ============================================================
try {
    $activeThemePc     = (string) ($model->getActiveTheme('pc', $scope) ?? '');
    $activeThemeMobile = (string) ($model->getActiveTheme('mobile', $scope) ?? '');
} catch (Throwable $e) {
    $activeThemePc = '';
    $activeThemeMobile = '';
}

$scanned = $model->scanTemplates();
$licenseError = null;
$availableThemes = AppLicenseGuard::filter($scanned, $memberCode, 2, $licenseError);
$licenseError = (string) ($licenseError ?? '');

// 叠加当前 scope 下的安装/启用状态
$installedMap = [];
foreach ($model->getAllInstalled($scope) as $row) {
    $installedMap[(string) $row['name']] = $row;
}
foreach ($availableThemes as $name => &$info) {
    $db = $installedMap[$name] ?? null;
    $info['is_installed']     = $db !== null;
    $info['is_active_pc']     = $db !== null && (int) ($db['is_active_pc'] ?? 0) === 1;
    $info['is_active_mobile'] = $db !== null && (int) ($db['is_active_mobile'] ?? 0) === 1;
    $info['has_setting']      = $model->hasSettingFile($name);
}
unset($info);

merchantRenderPage(__DIR__ . '/view/theme.php', [
    'activeTheme'       => $activeThemePc,  // 兼容旧视图（"当前模板"块显示 PC 的）
    'activeThemePc'     => $activeThemePc,
    'activeThemeMobile' => $activeThemeMobile,
    'availableThemes'   => $availableThemes,
    'licenseError'      => $licenseError,
    'csrfToken'         => Csrf::token(),
]);
