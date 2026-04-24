<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 商户后台 - 插件管理。
 *
 * 与主站 admin/plugin.php 结构对齐，关键差异：
 *   - 身份：merchantRequireLogin + 当前商户上下文
 *   - scope：'merchant_{id}'，独立于主站 'main'
 *   - AppLicenseGuard::filter(scope=2) 先按购买 + 角色裁剪磁盘扫描结果
 *   - 物理文件全站共享一份；install 只在本 scope 写一条 DB 记录
 */
merchantRequireLogin();

$merchantId = (int) ($currentMerchant['id'] ?? 0);
$scope      = 'merchant_' . $merchantId;
$memberCode = AppLicenseGuard::memberCodeForMerchant($currentMerchant);
$model      = new PluginModel();

// ============================================================
// POST 处理：install / uninstall / enable / disable / save_config
// ============================================================
if (Request::isPost()) {
    try {
        if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $action = (string) Input::post('_action', '');
        $name   = trim((string) Input::post('name', ''));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            Response::error('非法插件名');
        }

        switch ($action) {
            case 'install': {
                if ($model->isInstalled($name, $scope)) {
                    Response::error('该插件已安装');
                }

                $pluginDir = EM_ROOT . '/content/plugin/' . $name;
                if (!is_dir($pluginDir)) Response::error('插件目录不存在');
                $mainFile = $pluginDir . '/' . $name . '.php';
                if (!is_file($mainFile)) Response::error('插件主文件不存在');

                $info = $model->parseHeader($mainFile);
                if ($info === null) Response::error('无法解析插件信息，请检查插件头部注释');

                $info['name']      = $name;
                $info['main_file'] = $name . '.php';
                if (is_file($pluginDir . '/' . $name . '_setting.php')) {
                    $info['setting_file'] = $name . '_setting.php';
                }
                if (is_file($pluginDir . '/' . $name . '_show.php')) {
                    $info['show_file'] = $name . '_show.php';
                }
                if (is_file($pluginDir . '/icon.png')) {
                    $info['icon'] = '/content/plugin/' . $name . '/icon.png';
                } elseif (is_file($pluginDir . '/icon.gif')) {
                    $info['icon'] = '/content/plugin/' . $name . '/icon.gif';
                }
                if (is_file($pluginDir . '/preview.jpg')) {
                    $info['preview'] = '/content/plugin/' . $name . '/preview.jpg';
                }

                // 仅当该插件在本 scope 下首次安装时才调 callback_init（避免重置其他 scope 的表/配置）
                $callbackFile = $pluginDir . '/' . $name . '_callback.php';
                if (is_file($callbackFile)) {
                    include_once $callbackFile;
                    if (function_exists('callback_init')) {
                        call_user_func('callback_init');
                    }
                }

                $id = $model->install($name, $info, $scope);
                Response::success('插件安装成功', ['csrf_token' => Csrf::refresh(), 'id' => $id]);
                break;
            }

            case 'uninstall': {
                if (!$model->isInstalled($name, $scope)) Response::error('该插件未安装');
                // callback_rm 在多 scope 场景会重置共享资源，这里不再触发；仅删本 scope 记录
                $model->uninstall($name, $scope);
                Response::success('插件卸载成功', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            case 'enable': {
                if (!$model->isInstalled($name, $scope)) Response::error('该插件未安装，请先安装');
                if ($model->isEnabled($name, $scope))    Response::error('该插件已经是启用状态');
                $model->enable($name, $scope);
                Response::success('插件已启用', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            case 'disable': {
                if (!$model->isInstalled($name, $scope)) Response::error('该插件未安装');
                if (!$model->isEnabled($name, $scope))   Response::error('该插件已经是禁用状态');
                $model->disable($name, $scope);
                Response::success('插件已禁用', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            case 'save_config': {
                if (!$model->isInstalled($name, $scope)) Response::error('该插件未安装');
                // 插件自己有 setting 函数就交它处理（plugin_setting 会自行 Response 退出）
                $settingFile = EM_ROOT . '/content/plugin/' . $name . '/' . $name . '_setting.php';
                if (is_file($settingFile)) {
                    include_once $settingFile;
                    if (function_exists('plugin_setting')) {
                        call_user_func('plugin_setting');
                        return;
                    }
                }
                // 否则通用收集 POST 字段写入 config
                $config = [];
                foreach (Input::allPost() as $k => $v) {
                    if (in_array($k, ['_action', 'csrf_token', 'name'], true)) continue;
                    $config[$k] = $v;
                }
                $model->update($name, ['config' => $config], $scope);
                Response::success('配置已保存', ['csrf_token' => Csrf::refresh()]);
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
// 弹窗模式：渲染插件设置页
// ============================================================
if (Input::get('_popup', '') === '1') {
    $name = trim((string) Input::get('name', ''));
    if ($name === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
        exit('非法插件名');
    }
    if (!$model->isInstalled($name, $scope)) {
        exit('该插件未安装');
    }
    $settingFile = EM_ROOT . '/content/plugin/' . $name . '/' . $name . '_setting.php';
    if (!is_file($settingFile)) {
        exit('该插件暂无设置页面');
    }

    $csrfToken = Csrf::token();
    $info = $model->findByName($name, $scope);
    $pageTitle = '插件设置：' . htmlspecialchars((string) ($info['title'] ?? $name), ENT_QUOTES, 'UTF-8');
    // 让插件 setting.php 里的 AJAX 把 save_config 提交到商户路径，而不是默认的 /admin/plugin.php
    $popupPluginSaveUrl = '/user/merchant/plugin.php';
    include __DIR__ . '/../../admin/view/popup/header.php';

    include_once $settingFile;
    if (function_exists('plugin_setting_view')) {
        call_user_func('plugin_setting_view');
    } else {
        echo '<div style="padding:16px;color:#6b7280;">该插件未提供可渲染的设置视图。</div>';
    }

    include __DIR__ . '/../../admin/view/popup/footer.php';
    return;
}

// ============================================================
// 普通页面：扫磁盘 → 授权过滤 → 叠加本 scope 的 is_installed / is_enabled
// ============================================================
$scanned = $model->scanPlugins();
$licenseError = null;
$availablePlugins = AppLicenseGuard::filter($scanned, $memberCode, 2, $licenseError);
$licenseError = (string) ($licenseError ?? '');

// 取本 scope 下的 DB 记录，合并到可用列表
$installedMap = [];
foreach ($model->getAllInstalled($scope) as $row) {
    $installedMap[(string) $row['name']] = $row;
}
foreach ($availablePlugins as $name => &$info) {
    $db = $installedMap[$name] ?? null;
    $info['is_installed']   = $db !== null;
    $info['is_enabled']     = $db !== null && (int) ($db['is_enabled'] ?? 0) === 1;
    $info['has_setting']    = $db !== null && (string) ($db['setting_file'] ?? '') !== '';
}
unset($info);

merchantRenderPage(__DIR__ . '/view/plugin.php', [
    'availablePlugins' => $availablePlugins,
    'licenseError'     => $licenseError,
    'csrfToken'        => Csrf::token(),
]);
