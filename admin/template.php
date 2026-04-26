<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 模板管理控制器。
 *
 * 负责模板列表展示、安装、PC/手机端切换、设置页弹窗、卸载与删除目录。
 */
adminRequireLogin();
$user = $adminUser;
$siteName = Config::get('sitename', 'EMSHOP');
$model = new TemplateModel();
// 当前作用域：主站后台固定 main；商户走另一份控制器
$scope = 'main';
$isPopup = Input::get('_popup', '') === '1';

/**
 * 校验模板目录名。
 *
 * 只允许使用安全的目录标识，避免目录穿越和非法路径操作。
 */
function templateManageValidateName(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('模板名称不能为空');
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
        throw new RuntimeException('模板名称不合法');
    }
    return $name;
}

/**
 * 从磁盘扫描结果中读取指定模板信息。
 *
 * 这里只认 content/template 下真实存在的模板目录。
 *
 * @return array<string, mixed>
 */
function templateManageLoadInfo(TemplateModel $model, string $name): array
{
    $scanned = $model->scanTemplates();
    if (!isset($scanned[$name])) {
        throw new RuntimeException('模板不存在');
    }
    return $scanned[$name];
}

/**
 * 调用模板生命周期回调。
 *
 * callback.php 存在时才加载，并仅调用指定函数。
 */
function templateManageCallCallback(TemplateModel $model, string $name, string $callback): void
{
    $callbackFile = $model->getCallbackFilePath($name);
    if (!is_file($callbackFile)) {
        return;
    }

    include_once $callbackFile;
    if (function_exists($callback)) {
        call_user_func($callback);
    }
}

/**
 * 递归删除模板目录树。
 *
 * 删除前会校验真实路径必须位于 content/template 根目录内。
 */
function templateManageDeleteTree(string $dir, string $root): void
{
    $realRoot = realpath($root);
    $realDir = realpath($dir);

    if ($realRoot === false || $realDir === false || strpos($realDir, $realRoot . DIRECTORY_SEPARATOR) !== 0) {
        throw new RuntimeException('模板目录异常，无法删除');
    }

    $items = scandir($realDir);
    if ($items === false) {
        throw new RuntimeException('模板目录读取失败');
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $realDir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            templateManageDeleteTree($path, $root);
            continue;
        }

        if (!unlink($path)) {
            throw new RuntimeException('删除模板文件失败');
        }
    }

    if (!rmdir($realDir)) {
        throw new RuntimeException('删除模板目录失败');
    }
}

/**
 * 删除指定模板目录。
 */
function templateManageDeleteDir(string $name): void
{
    $root = EM_ROOT . '/content/template';
    $dir = $root . '/' . $name;
    templateManageDeleteTree($dir, $root);
}

// POST：处理模板安装、切换、设置保存、卸载与删除目录。
if (Request::isPost()) {
    try {
        $csrf = (string) Input::post('csrf_token', '');
        if (!Csrf::validate($csrf)) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $action = (string) Input::post('_action', '');

        switch ($action) {
            case 'install': {
                $name = templateManageValidateName((string) Input::post('name', ''));
                if ($model->isInstalled($name, $scope)) {
                    Response::error('该模板已安装');
                }

                $info = templateManageLoadInfo($model, $name);
                $id = $model->install($name, $info, $scope);
                $csrfToken = Csrf::refresh();
                Response::success('模板安装成功', ['csrf_token' => $csrfToken, 'id' => $id]);
                break;
            }

            case 'activate_pc':
            case 'activate_mobile': {
                $name = templateManageValidateName((string) Input::post('name', ''));
                $client = $action === 'activate_pc' ? 'pc' : 'mobile';

                if (!$model->isInstalled($name, $scope)) {
                    Response::error('该模板未安装，请先安装后再启用');
                }

                templateManageLoadInfo($model, $name);

                if ($model->isActive($name, $client, $scope)) {
                    $model->clearActiveTheme($client, $scope);
                    $csrfToken = Csrf::refresh();
                    Response::success($client === 'pc' ? '已取消PC端模板' : '已取消手机端模板', ['csrf_token' => $csrfToken]);
                }

                templateManageCallCallback($model, $name, 'callback_init');
                $model->setActiveTheme($client, $name, $scope);

                $csrfToken = Csrf::refresh();
                Response::success($client === 'pc' ? 'PC端模板已切换' : '手机端模板已切换', ['csrf_token' => $csrfToken]);
                break;
            }

            case 'save_config': {
                $name = templateManageValidateName((string) Input::post('name', ''));
                if (!$model->isInstalled($name, $scope)) {
                    Response::error('该模板未安装');
                }

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
                $allPost = Input::allPost();
                foreach ($allPost as $k => $v) {
                    if (in_array($k, ['_action', 'csrf_token', 'name'], true)) {
                        continue;
                    }
                    $config[$k] = $v;
                }

                $model->update($name, ['config' => $config], $scope);
                $csrfToken = Csrf::refresh();
                Response::success('配置已保存', ['csrf_token' => $csrfToken]);
                break;
            }

            case 'uninstall': {
                $name = templateManageValidateName((string) Input::post('name', ''));
                if (!$model->isInstalled($name, $scope)) {
                    Response::error('该模板未安装');
                }
                if ($model->isActive($name, 'pc', $scope) || $model->isActive($name, 'mobile', $scope)) {
                    Response::error('当前启用中的模板不允许卸载');
                }

                templateManageCallCallback($model, $name, 'callback_rm');
                $model->uninstall($name, $scope);
                $csrfToken = Csrf::refresh();
                Response::success('模板已卸载', ['csrf_token' => $csrfToken]);
                break;
            }

            case 'delete': {
                $name = templateManageValidateName((string) Input::post('name', ''));
                if ($model->isActive($name, 'pc', $scope) || $model->isActive($name, 'mobile', $scope)) {
                    Response::error('当前启用中的模板不允许删除');
                }
                if ($model->isInstalled($name, $scope)) {
                    Response::error('已安装模板请先卸载后再删除');
                }
                templateManageDeleteDir($name);
                $csrfToken = Csrf::refresh();
                Response::success('模板已删除', ['csrf_token' => $csrfToken]);
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

// GET：返回模板列表 JSON，供后台卡片页异步加载。
if (!$isPopup && Input::get('_action', '') === 'list') {
    header('Content-Type: application/json; charset=utf-8');

    $scannedTemplates = $model->scanTemplates();
    // 授权过滤：服务端注册过 ∩ 已购买 ∩ 通过 scope 边界 → 才保留；default 等系统内置直通
    // 中心服务挂了：仍然保留直通项（SYSTEM_APPS），错误通过 $licenseError 出参透给前端
    $licenseError = null;
    $scannedTemplates = AppLicenseGuard::filter($scannedTemplates, '', 1, 'template', $licenseError);
    $licenseError = (string) ($licenseError ?? '');
    $installedTemplates = $model->getAllInstalled($scope);
    $installedMap = [];
    foreach ($installedTemplates as $item) {
        $installedMap[$item['name']] = $item;
    }

    // 去中心批量查已装模板的最新版本，用来在卡片上把"配置"按钮替换成"更新 vX.X.X"
    // 中心不可达时 $latestVersions 为空，页面不再显示更新按钮，其它功能不受影响
    $latestVersions = [];
    if ($installedMap !== []) {
        try {
            $latestVersions = LicenseClient::appLatestVersions(array_keys($installedMap), 'template');
        } catch (Throwable $e) {
            // 静默降级：本地已装信息仍可正常展示
        }
    }

    $data = [];
    foreach ($scannedTemplates as $name => $info) {
        $item = [
            'name' => $name,
            'title' => $info['title'] ?: $name,
            'version' => $info['version'] ?: '1.0.0',
            'author' => $info['author'] ?: '',
            'author_url' => $info['author_url'] ?: '',
            'template_url' => $info['template_url'] ?: '',
            'description' => $info['description'] ?: '',
            'preview' => $info['preview'] ?: '',
            'setting_file' => $model->hasSettingFile($name) ? 'setting.php' : '',
            'plugin_file' => $info['plugin_file'] ?: '',
            'callback_file' => $info['callback_file'] ?: '',
            'is_installed' => isset($installedMap[$name]),
            'is_active_pc' => false,
            'is_active_mobile' => false,
            'id' => 0,
        ];

        if (isset($installedMap[$name])) {
            $db = $installedMap[$name];
            $item['id'] = (int) $db['id'];
            $item['title'] = $db['title'] ?: $item['title'];
            $item['version'] = $db['version'] ?: $item['version'];
            $item['author'] = $db['author'] ?: $item['author'];
            $item['author_url'] = $db['author_url'] ?: $item['author_url'];
            $item['template_url'] = $db['template_url'] ?: $item['template_url'];
            $item['description'] = $db['description'] ?: $item['description'];
            $item['preview'] = $db['preview'] ?: $item['preview'];
            $item['setting_file'] = $model->hasSettingFile($name) ? 'setting.php' : '';
            $item['plugin_file'] = $info['plugin_file'] ?: ($db['plugin_file'] ?? '');
            $item['callback_file'] = $info['callback_file'] ?: ($db['callback_file'] ?? '');
            $item['is_active_pc'] = (bool) ($db['is_active_pc'] ?? false);
            $item['is_active_mobile'] = (bool) ($db['is_active_mobile'] ?? false);
            $item['config'] = $db['config'] ?? '{}';

            // 已装模板才去比对最新版本：版本字符串不相等就视为有更新（策略与应用商店一致）
            $latest = $latestVersions[$name] ?? null;
            if ($latest && !empty($latest['version']) && (string) $latest['version'] !== (string) $item['version']) {
                $item['has_update']       = true;
                $item['latest_version']   = (string) $latest['version'];
                $item['latest_file_path'] = (string) ($latest['file_path'] ?? '');
            } else {
                $item['has_update']       = false;
                $item['latest_version']   = '';
                $item['latest_file_path'] = '';
            }
        } else {
            $item['config'] = '{}';
            $item['has_update']       = false;
            $item['latest_version']   = '';
            $item['latest_file_path'] = '';
        }

        $data[] = $item;
    }

    echo json_encode([
        'code' => 0,
        'msg' => '',
        'count' => count($data),
        'data' => $data,
        'csrf_token' => Csrf::token(),
        '_license_error' => $licenseError,
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// 弹窗模式：渲染模板设置页。
if ($isPopup) {
    $templateName = templateManageValidateName((string) Input::get('name', ''));
    $templateInfo = $model->findByName($templateName, $scope);
    if ($templateInfo === null) {
        exit('模板未安装');
    }

    $settingFile = $model->getSettingFilePath($templateName);
    if (!is_file($settingFile)) {
        exit('该模板暂无设置页面');
    }

    $csrfToken = Csrf::token();
    $pageTitle = '模板设置：' . htmlspecialchars((string) $templateInfo['title'], ENT_QUOTES, 'UTF-8');
    include __DIR__ . '/view/popup/header.php';

    include_once $settingFile;
    if (function_exists('template_setting_view')) {
        call_user_func('template_setting_view');
    } elseif (function_exists('plugin_setting_view')) {
        call_user_func('plugin_setting_view');
    } else {
        echo '<div style="padding:16px;color:#6b7280;">该模板未提供可渲染的设置视图。</div>';
    }

    include __DIR__ . '/view/popup/footer.php';
    return;
}

// 普通页面模式：输出模板管理页面。
$csrfToken = Csrf::token();
if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/template.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/template.php';
    require __DIR__ . '/index.php';
}
