<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 插件管理控制器。
 */
adminRequireLogin();
$user = $adminUser;
$siteName = Config::get('sitename', 'EMSHOP');

$model = new PluginModel();

// 当前作用域：主站后台固定 main；商户后台按 merchant_{id} 走另一份控制器
$scope = 'main';

// 是否为弹窗模式（插件设置页面）
$isPopup = Input::get('_popup', '') === '1';

// ============================================================
// POST 处理
// ============================================================
if (Request::isPost()) {
    try {
        $csrf = (string) Input::post('csrf_token', '');
        if (!Csrf::validate($csrf)) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $action = (string) Input::post('_action', '');

        switch ($action) {
            // 安装插件
            case 'install': {
                $name = trim((string) Input::post('name', ''));
                if ($name === '') {
                    Response::error('插件名称不能为空');
                }

                if ($model->isInstalled($name, $scope)) {
                    Response::error('该插件已安装');
                }

                // 扫描插件目录，解析头信息
                $pluginDir = EM_ROOT . '/content/plugin/' . $name;
                if (!is_dir($pluginDir)) {
                    Response::error('插件目录不存在');
                }

                $mainFile = $pluginDir . '/' . $name . '.php';
                if (!is_file($mainFile)) {
                    Response::error('插件主文件不存在');
                }

                $info = $model->parseHeader($mainFile);
                if ($info === null) {
                    Response::error('无法解析插件信息，请检查插件头部注释');
                }

                $info['name'] = $name;
                $info['main_file'] = $name . '.php';

                // 检查插件是否有设置页面
                $settingFile = $pluginDir . '/' . $name . '_setting.php';
                if (is_file($settingFile)) {
                    $info['setting_file'] = $name . '_setting.php';
                }

                // 检查插件是否有前台展示页面
                $showFile = $pluginDir . '/' . $name . '_show.php';
                if (is_file($showFile)) {
                    $info['show_file'] = $name . '_show.php';
                }

                // 检查插件图标
                $iconFile = $pluginDir . '/icon.png';
                if (is_file($iconFile)) {
                    $info['icon'] = '/content/plugin/' . $name . '/icon.png';
                } else {
                    $iconGif = $pluginDir . '/icon.gif';
                    if (is_file($iconGif)) {
                        $info['icon'] = '/content/plugin/' . $name . '/icon.gif';
                    }
                }

                // 检查预览图
                $previewFile = $pluginDir . '/preview.jpg';
                if (is_file($previewFile)) {
                    $info['preview'] = '/content/plugin/' . $name . '/preview.jpg';
                }

                // 调用插件安装回调
                $callbackFile = $pluginDir . '/' . $name . '_callback.php';
                if (is_file($callbackFile)) {
                    include_once $callbackFile;
                    if (function_exists('callback_init')) {
                        call_user_func('callback_init');
                    }
                }

                $id = $model->install($name, $info, $scope);

                $csrfToken = Csrf::refresh();
                Response::success('插件安装成功', ['csrf_token' => $csrfToken, 'id' => $id]);
                break;
            }

            // 卸载插件
            case 'uninstall': {
                $name = trim((string) Input::post('name', ''));
                if ($name === '') {
                    Response::error('插件名称不能为空');
                }

                if (!$model->isInstalled($name, $scope)) {
                    Response::error('该插件未安装');
                }

                // 调用插件卸载回调
                $callbackFile = EM_ROOT . '/content/plugin/' . $name . '/' . $name . '_callback.php';
                if (is_file($callbackFile)) {
                    include_once $callbackFile;
                    if (function_exists('callback_rm')) {
                        call_user_func('callback_rm');
                    }
                }

                $model->uninstall($name, $scope);
                Response::success('插件卸载成功', ['csrf_token' => $csrfToken]);
                break;
            }

            // 删除插件文件（仅对未安装的插件；彻底移除磁盘上的 content/plugin/{name}/ 目录）
            case 'delete': {
                $name = trim((string) Input::post('name', ''));
                if ($name === '') {
                    Response::error('插件名称不能为空');
                }
                // 防越权/路径穿越：只允许字母数字下划线连字符
                if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
                    Response::error('非法插件名');
                }
                if ($model->isInstalled($name, $scope)) {
                    Response::error('插件处于已安装状态，请先卸载');
                }

                $dir = EM_ROOT . '/content/plugin/' . $name;
                if (!is_dir($dir)) {
                    Response::error('插件目录不存在');
                }
                $rmDir = static function (string $path) use (&$rmDir): bool {
                    if (!is_dir($path)) return @unlink($path);
                    foreach (scandir($path) ?: [] as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $rmDir($path . DIRECTORY_SEPARATOR . $item);
                    }
                    return @rmdir($path);
                };
                if (!$rmDir($dir)) {
                    Response::error('删除失败，请检查文件权限');
                }
                Response::success('插件已删除', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            // 启用插件
            case 'enable': {
                $name = trim((string) Input::post('name', ''));
                if ($name === '') {
                    Response::error('插件名称不能为空');
                }

                if (!$model->isInstalled($name, $scope)) {
                    Response::error('该插件未安装，请先安装');
                }

                if ($model->isEnabled($name, $scope)) {
                    Response::error('该插件已经是启用状态');
                }

                $model->enable($name, $scope);

                $csrfToken = Csrf::refresh();
                Response::success('插件已启用', ['csrf_token' => $csrfToken]);
                break;
            }

            // 禁用插件
            case 'disable': {
                $name = trim((string) Input::post('name', ''));
                if ($name === '') {
                    Response::error('插件名称不能为空');
                }

                if (!$model->isInstalled($name, $scope)) {
                    Response::error('该插件未安装');
                }

                if (!$model->isEnabled($name, $scope)) {
                    Response::error('该插件已经是禁用状态');
                }

                $model->disable($name, $scope);

                $csrfToken = Csrf::refresh();
                Response::success('插件已禁用', ['csrf_token' => $csrfToken]);
                break;
            }

            // 保存插件设置
            case 'save_config': {
                $name = trim((string) Input::post('name', ''));
                if ($name === '') {
                    Response::error('插件名称不能为空');
                }

                if (!$model->isInstalled($name, $scope)) {
                    Response::error('该插件未安装');
                }

                // 调用插件自身的设置保存函数
                $settingFile = EM_ROOT . '/content/plugin/' . $name . '/' . $name . '_setting.php';
                if (is_file($settingFile)) {
                    include_once $settingFile;
                    if (function_exists('plugin_setting')) {
                        // plugin_setting() 会调用 Response/Output 退出，这里不需要处理
                        call_user_func('plugin_setting');
                        return; // plugin_setting 已处理了响应
                    }
                }

                // 如果没有专属设置函数，通用方式保存
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
// AJAX 获取插件列表
// ============================================================
if (!$isPopup && Input::get('_action', '') === 'list') {
    header('Content-Type: application/json; charset=utf-8');

    $scannedPlugins = $model->scanPlugins(); // 磁盘上的插件
    // 授权过滤：中心服务已购 + Custom 自建 + 系统内置插件会保留
    // 中心服务挂了：仍保留直通项，错误通过 $licenseError 出参透给前端
    $licenseError = null;
    $scannedPlugins = AppLicenseGuard::filter($scannedPlugins, '', 1, $licenseError);
    $licenseError = (string) ($licenseError ?? '');
    $installedPlugins = $model->getAllInstalled($scope); // 已安装的插件
    $installedMap = [];
    foreach ($installedPlugins as $p) {
        $installedMap[$p['name']] = $p;
    }

    $data = [];
    foreach ($scannedPlugins as $name => $info) {
        $item = [
            'name' => $name,
            'title' => $info['title'] ?: $name,
            'version' => $info['version'] ?: '1.0.0',
            'author' => $info['author'] ?: '',
            'author_url' => $info['author_url'] ?: '',
            'description' => $info['description'] ?: '',
            'category' => $info['category'] ?: '',
            'icon' => $info['icon'] ?: '',
            'preview' => $info['preview'] ?: '',
            'is_installed' => isset($installedMap[$name]),
            'is_enabled' => false,
            'id' => 0,
        ];

        $settingFileOnDisk = is_file(EM_ROOT . '/content/plugin/' . $name . '/' . $name . '_setting.php') ? $name . '_setting.php' : '';
        $showFileOnDisk = is_file(EM_ROOT . '/content/plugin/' . $name . '/' . $name . '_show.php') ? $name . '_show.php' : '';
        $previewOnDisk = is_file(EM_ROOT . '/content/plugin/' . $name . '/preview.jpg') ? '/content/plugin/' . $name . '/preview.jpg' : '';
        if (isset($installedMap[$name])) {
            $db = $installedMap[$name];
            $item['is_enabled'] = (bool) $db['is_enabled'];
            $item['id'] = (int) $db['id'];
            $item['title'] = $db['title'] ?: $info['title'] ?: $name;
            $item['version'] = $db['version'] ?: $info['version'] ?: '1.0.0';
            $item['author'] = $db['author'] ?: $info['author'] ?: '';
            $item['author_url'] = $db['author_url'] ?: $info['author_url'] ?: '';
            $item['description'] = $db['description'] ?: $info['description'] ?: '';
            $item['category'] = $db['category'] ?: $info['category'] ?: '';
            $item['icon'] = $db['icon'] ?: $info['icon'] ?: '';
            $item['preview'] = $db['preview'] ?: $previewOnDisk ?: '';
            $item['setting_file'] = $settingFileOnDisk ?: ($db['setting_file'] ?: '');
            $item['show_file'] = $db['show_file'] ?: $showFileOnDisk ?: '';
            $item['config'] = $db['config'] ?? '{}';
        } else {
            $item['setting_file'] = $settingFileOnDisk;
            $item['show_file'] = $showFileOnDisk;
            $item['preview'] = $previewOnDisk;
            $item['config'] = '{}';
        }

        $data[] = $item;
    }

    echo json_encode([
        'code' => 0,
        'msg' => '',
        'count' => count($data),
        'data' => $data,
        'csrf_token' => Csrf::token(),
        '_license_error' => $licenseError, // 非空即中心服务不可达，前端要在顶部展示告警
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// ============================================================
// 弹窗模式：插件设置页面
// ============================================================
if ($isPopup) {
    $pluginName = trim((string) Input::get('name', ''));
    if ($pluginName === '') {
        exit('缺少插件名称');
    }

    $pluginInfo = $model->findByName($pluginName, $scope);
    if ($pluginInfo === null) {
        exit('插件未安装');
    }

    $settingFile = EM_ROOT . '/content/plugin/' . $pluginName . '/' . $pluginName . '_setting.php';
    if (!is_file($settingFile)) {
        exit('该插件暂无设置页面');
    }

    $csrfToken = Csrf::token();
    $pageTitle = '插件设置：' . htmlspecialchars($pluginInfo['title'], ENT_QUOTES, 'UTF-8');
    include __DIR__ . '/view/popup/header.php';

    // 加载插件设置视图
    include_once $settingFile;
    if (function_exists('plugin_setting_view')) {
        call_user_func('plugin_setting_view');
    }

    include __DIR__ . '/view/popup/footer.php';
    return;
}

// ============================================================
// 正常页面模式
// ============================================================
$csrfToken = Csrf::token();

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/plugin.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/plugin.php';
    require __DIR__ . '/index.php';
}

//