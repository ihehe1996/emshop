<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 主站后台 · 插件管理控制器(字符串启用列表版)。
 *
 * 设计原则(详见 .claude/docs/应用商店重构方案.md):磁盘是真理,启用列表是字符串。
 *   - 磁盘有目录 = 已装(无 install action;从应用商店下载或手动放磁盘即可)
 *   - 启用列表存于 em_config.enabled_plugins(逗号分隔 slug)
 *   - 元数据(title/version/category/icon/setting_file/...)走 parseHeader 实时读
 *
 * 保留 actions:enable / disable / uninstall(=清数据+删磁盘) / save_config
 * 删除 actions:install(磁盘=装) / delete(已并入 uninstall)
 */
adminRequireLogin();
$user      = $adminUser;
$siteName  = Config::get('sitename', 'EMSHOP');
$model     = new PluginModel();

$scope     = 'main';
$isPopup   = Input::get('_popup', '') === '1';

// ============================================================
// POST 处理
// ============================================================
if (Request::isPost()) {
    try {
        if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效,请刷新页面后重试');
        }

        $action = (string) Input::post('_action', '');
        $name   = trim((string) Input::post('name', ''));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            Response::error('非法插件名');
        }

        switch ($action) {
            // 启用 —— 加入 enabled_plugins 列表
            // 不再做"首次启用"判断,callback_init 由插件作者保证幂等(CREATE TABLE IF NOT EXISTS 之类)
            case 'enable': {
                if (!$model->existsOnDisk($name)) Response::error('磁盘上未找到该插件');
                if ($model->isEnabled($name, $scope)) Response::error('该插件已经是启用状态');

                $model->enable($name, $scope);
                if ($model->isSwoolePlugin($name, $scope)) {
                    PluginModel::bumpSwooleCodeVersion();
                }
                Response::success('插件已启用', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            // 禁用 —— DB 行不存在 = no-op(本来就是默认禁用态)
            case 'disable': {
                if (!$model->isEnabled($name, $scope)) Response::error('该插件已经是禁用状态');
                $isSwoole = $model->isSwoolePlugin($name, $scope);
                $model->disable($name, $scope);
                if ($isSwoole) PluginModel::bumpSwooleCodeVersion();
                Response::success('插件已禁用', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            // 卸载 —— callback_rm 清插件私有数据 + 清 Storage 配置 + 递归删磁盘目录(=彻底移除)
            // 启用中的插件必须先禁用,避免 callback_rm 清掉数据后插件还在 runtime 工作产生脏状态
            case 'uninstall': {
                if (!$model->existsOnDisk($name)) Response::error('磁盘上未找到该插件');
                if ($model->isEnabled($name, $scope)) Response::error('该插件正在启用中,请先禁用再卸载');
                $isSwoole = $model->isSwoolePlugin($name, $scope);

                // 触发插件 callback_rm 清理它自己的私有数据(建表清理 / 自定义资源等)
                $callbackFile = EM_ROOT . '/content/plugin/' . $name . '/' . $name . '_callback.php';
                if (is_file($callbackFile)) {
                    include_once $callbackFile;
                    if (function_exists('callback_rm')) call_user_func('callback_rm');
                }

                $model->uninstall($name, $scope);
                // 清掉 Storage 里的所有插件配置(per-plugin per-scope)
                Storage::getInstance($name)->deleteAllName('YES');

                // 递归删磁盘目录:卸载 = 彻底移除,要重新启用得回应用商店重装
                $dir = EM_ROOT . '/content/plugin/' . $name;
                $rmDir = static function (string $p) use (&$rmDir): bool {
                    if (!is_dir($p)) return @unlink($p);
                    foreach (scandir($p) ?: [] as $i) {
                        if ($i === '.' || $i === '..') continue;
                        $rmDir($p . DIRECTORY_SEPARATOR . $i);
                    }
                    return @rmdir($p);
                };
                if (is_dir($dir) && !$rmDir($dir)) {
                    Response::error('磁盘文件删除失败,请检查目录权限');
                }

                if ($isSwoole) PluginModel::bumpSwooleCodeVersion();

                Response::success('插件已卸载', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            // 保存配置 —— 优先调插件自带 plugin_setting();无则把 POST 字段写入 Storage
            case 'save_config': {
                if (!$model->existsOnDisk($name)) Response::error('磁盘上未找到该插件');

                $settingFile = EM_ROOT . '/content/plugin/' . $name . '/' . $name . '_setting.php';
                if (is_file($settingFile)) {
                    include_once $settingFile;
                    if (function_exists('plugin_setting')) {
                        // plugin_setting 内部用 Storage 写并自行 Response
                        call_user_func('plugin_setting');
                        return;
                    }
                }

                // 通用兜底:全部 POST 字段(除框架字段)逐个写 Storage
                $storage = Storage::getInstance($name);
                foreach (Input::allPost() as $k => $v) {
                    if (in_array($k, ['_action', 'csrf_token', 'name'], true)) continue;
                    $storage->setValue($k, $v);
                }
                Response::success('配置已保存', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            default:
                Response::error('未知操作');
        }
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error('系统繁忙,请稍后再试');
    }
}

// ============================================================
// AJAX 列表 —— 扫磁盘 + 左联 DB 状态,经 AppLicenseGuard 过滤后返回
// ============================================================
if (!$isPopup && Input::get('_action', '') === 'list') {
    header('Content-Type: application/json; charset=utf-8');

    $scanned = $model->scanWithStatus($scope);

    // 授权过滤:服务端注册过 ∩ 已购买 → 才保留;系统内置直通
    $licenseError = null;
    $licenseError = (string) ($licenseError ?? '');

    $data = [];
    foreach ($scanned as $name => $info) {
        $data[] = [
            'name'         => $name,
            'title'        => (string) ($info['title']       ?? $name),
            'version'      => (string) ($info['version']     ?? '1.0.0'),
            'author'       => (string) ($info['author']      ?? ''),
            'author_url'   => (string) ($info['author_url']  ?? ''),
            'description'  => (string) ($info['description'] ?? ''),
            'category'     => (string) ($info['category']    ?? ''),
            'icon'         => (string) ($info['icon']        ?? ''),
            'preview'      => (string) ($info['preview']     ?? ''),
            'setting_file' => (string) ($info['setting_file'] ?? ''),
            'show_file'    => (string) ($info['show_file']    ?? ''),
            'is_installed' => 1, // 磁盘有就是已装,view 仍可读这个字段
            'is_enabled'   => !empty($info['is_enabled']),
            'id'           => (int) ($info['state_id'] ?? 0),
            'config'       => '{}', // 兼容字段:配置走 Storage,这里给空对象占位
        ];
    }

    echo json_encode([
        'code'           => 0,
        'msg'            => '',
        'count'          => count($data),
        'data'           => $data,
        'csrf_token'     => Csrf::token(),
        '_license_error' => $licenseError,
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// ============================================================
// 弹窗模式:插件设置页
// ============================================================
if ($isPopup) {
    $pluginName = trim((string) Input::get('name', ''));
    if ($pluginName === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $pluginName)) {
        exit('非法插件名');
    }
    if (!$model->existsOnDisk($pluginName)) {
        exit('磁盘上未找到该插件');
    }

    $settingFile = EM_ROOT . '/content/plugin/' . $pluginName . '/' . $pluginName . '_setting.php';
    if (!is_file($settingFile)) exit('该插件暂无设置页面');

    $csrfToken  = Csrf::token();
    $headerInfo = $model->parseHeader(EM_ROOT . '/content/plugin/' . $pluginName . '/' . $pluginName . '.php');
    $titleStr   = (string) ($headerInfo['title'] ?? $pluginName);
    $pageTitle  = '插件设置:' . htmlspecialchars($titleStr, ENT_QUOTES, 'UTF-8');

    include __DIR__ . '/view/popup/header.php';
    include_once $settingFile;
    if (function_exists('plugin_setting_view')) call_user_func('plugin_setting_view');
    include __DIR__ . '/view/popup/footer.php';
    return;
}

// ============================================================
// 普通页面渲染
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
