<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 商户后台 - 应用商店控制器。
 *
 * 与主站 admin/appstore.php 结构对齐，关键差异：
 *   - 身份：merchantRequireLogin + 当前商户上下文
 *   - scope：中心接口传 2，本地 Model 传 'merchant_{id}'
 *   - member_code：商户主用户 invite_code（AppLicenseGuard::memberCodeForMerchant）
 *   - 视图：商户侧 layout，PJAX 容器 #merchantContent
 *
 * 物理文件（content/plugin|template/xxx）全站共享一份；当前 scope 只写入自己的 DB 行。
 */
merchantRequireLogin();

$csrfToken = Csrf::token();

// 作用域与身份常量（全函数复用）
$merchantId   = (int) ($currentMerchant['id'] ?? 0);
$scope        = 'merchant_' . $merchantId;
$memberCode   = AppLicenseGuard::memberCodeForMerchant($currentMerchant);

// AJAX：分类列表（scope=2 让服务端按商户维度算 count）
if ((string) Input::get('_action', '') === 'categories') {
    try {
        Response::success('', ['list' => LicenseClient::appCategories(2)]);
    } catch (Throwable $e) {
        Response::error($e->getMessage(), ['list' => []]);
    }
}

// AJAX：支付方式列表
if ((string) Input::get('_action', '') === 'pay_methods') {
    try {
        Response::success('', ['list' => LicenseClient::payMethods()]);
    } catch (Throwable $e) {
        Response::error($e->getMessage(), ['list' => []]);
    }
}

// AJAX：创建购买订单，返回 pay_url 供前端顶层跳转
if (Request::isPost() && (string) Input::post('_action', '') === 'app_buy') {
    if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
        Response::error('请求已失效，请刷新页面后重试');
    }
    $appId     = (int) Input::post('app_id', 0);
    $payMethod = trim((string) Input::post('pay_method', ''));
    if ($appId <= 0)       Response::error('应用ID不能为空');
    if ($payMethod === '') Response::error('请选择支付方式');
    if ($memberCode === '') Response::error('当前商户身份无效（未获取到 invite_code）');

    $licenseRow = LicenseService::currentLicense();
    $emkey = $licenseRow ? (string) ($licenseRow['license_code'] ?? '') : '';
    if ($emkey === '') {
        Response::error('主站尚未激活正版授权，无法发起购买');
    }
    $host = LicenseService::effectiveHost();
    try {
        // 商户购买：member_code 非空，服务端按 identity=member_code 入库
        $data = LicenseClient::appBuy($emkey, $host, $appId, $payMethod, $memberCode);
        Response::success('', $data + ['csrf_token' => Csrf::refresh()]);
    } catch (Throwable $e) {
        Response::error($e->getMessage());
    }
}

// AJAX：应用详情 + 支付方式（购买弹窗一次拉齐；scope=2）
if ((string) Input::get('_action', '') === 'app_detail') {
    $appId = (int) Input::get('id', 0);
    if ($appId <= 0) Response::error('应用ID不能为空');
    $licenseRow = LicenseService::currentLicense();
    $emkey = $licenseRow ? (string) ($licenseRow['license_code'] ?? '') : '';
    $host  = LicenseService::effectiveHost();
    try {
        $data = LicenseClient::appDetail($appId, $emkey, $host, 2, $memberCode);
        Response::success('', $data);
    } catch (Throwable $e) {
        Response::error($e->getMessage());
    }
}

// AJAX：应用列表（layui table 分页驱动，scope=2）
if ((string) Input::get('_action', '') === 'list') {
    $licenseRow = LicenseService::currentLicense();
    $emkey = $licenseRow ? (string) ($licenseRow['license_code'] ?? '') : '';
    $host  = LicenseService::effectiveHost();

    $params = [
        'page'        => max(1, (int) Input::get('page', 1)),
        'pageNum'     => min(100, max(1, (int) Input::get('limit', 10))),
        'type'        => (string) Input::get('type', ''),
        'category_id' => (int) Input::get('category_id', 0),
        'keyword'     => (string) Input::get('keyword', ''),
        'scope'       => 2, // 商户后台固定 2
        'emkey'       => $emkey,
        'host'        => $host,
        'member_code' => $memberCode, // 商户身份，服务端用 emkey + member_code 精确定位购买记录
    ];
    try {
        $result = LicenseClient::appStoreList($params);

        // 本地已装检测：限定 scope = 当前商户
        $installedPlugins = [];
        try {
            $rows = Database::query(
                'SELECT `name`, `version` FROM `' . Database::prefix() . 'plugin` WHERE `scope` = ?',
                [$scope]
            );
            foreach ($rows as $r) $installedPlugins[(string) $r['name']] = (string) ($r['version'] ?? '');
        } catch (Throwable $e) {
            // em_plugin 不存在或查询失败，不影响列表展示
        }
        $installedThemes = [];
        try {
            $rows = Database::query(
                'SELECT `name`, `version` FROM `' . Database::prefix() . 'template` WHERE `scope` = ?',
                [$scope]
            );
            foreach ($rows as $r) $installedThemes[(string) $r['name']] = (string) ($r['version'] ?? '');
        } catch (Throwable $e) {
        }

        foreach ($result['list'] as &$app) {
            $slug = (string) ($app['name_en'] ?? '');
            $type = (string) ($app['type'] ?? '');
            $map = $type === 'template' ? $installedThemes : $installedPlugins;
            if ($slug !== '' && isset($map[$slug])) {
                $app['is_installed']      = 1;
                $app['installed_version'] = $map[$slug];
            } else {
                $app['is_installed']      = 0;
                $app['installed_version'] = '';
            }
        }
        unset($app);

        Response::success('', $result);
    } catch (Throwable $e) {
        Response::success($e->getMessage(), [
            'list' => [], 'count' => 0,
            'page' => $params['page'], 'pageNum' => $params['pageNum'],
        ]);
    }
}

// 安装 / 更新：下载 zip → 解压到 content/plugin|template/{name}/ → 注册到 em_plugin|em_template
// 整体流程与主站一致；唯一差异是 Model 写入时 scope 使用 'merchant_{id}'
if (Request::isPost() && in_array((string) Input::post('_action', ''), ['install', 'update'], true)) {
    try {
        if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $name     = trim((string) Input::post('name', ''));
        $type     = (string) Input::post('type', 'plugin');
        $filePath = trim((string) Input::post('file_path', ''));
        $isUpdate = (string) Input::post('_action', '') === 'update';

        if ($name === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            Response::error('非法应用名');
        }
        if (!in_array($type, ['plugin', 'template'], true)) {
            Response::error('未知应用类型');
        }

        $targetRoot = $type === 'template' ? EM_ROOT . '/content/template' : EM_ROOT . '/content/plugin';
        $targetDir  = $targetRoot . '/' . $name;

        // 本地快捷安装：目录已在磁盘上 → 跳过下载直接注册（通常是主站或其它商户先装过）
        // 更新动作不走这条分支（会重新下载新版文件，所有持有该 name 的 scope 会同时拿到新文件）
        $localAlreadyExists = !$isUpdate && is_dir($targetDir);

        if (!$localAlreadyExists && $filePath === '') {
            Response::error('缺少下载地址');
        }

        // 规范化 file_path 为绝对 URL；始终基于 license_urls[0]，只允许主 host
        $downloadUrl = '';
        if (!$localAlreadyExists) {
            $lines = LicenseClient::lines();
            if (!$lines) Response::error('未配置授权服务器地址');
            $baseHost = rtrim($lines[0]['url'], '/');
            if (stripos($filePath, 'http://') === 0 || stripos($filePath, 'https://') === 0) {
                if (strpos($filePath, $baseHost) !== 0) {
                    Response::error('下载地址非法（host 不匹配）');
                }
                $downloadUrl = $filePath;
            } else {
                $downloadUrl = $baseHost . '/' . ltrim($filePath, '/');
            }
        }

        if (!$localAlreadyExists) {
        $tmpRoot = EM_ROOT . '/content/uploads/.appstore_tmp';
        if (!is_dir($tmpRoot)) @mkdir($tmpRoot, 0755, true);
        $tmpZip = $tmpRoot . '/zip_' . uniqid() . '.zip';
        $fp = fopen($tmpZip, 'wb');
        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fp,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_TIMEOUT         => 120,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_USERAGENT       => 'emshop-' . EM_VERSION,
        ]);
        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $httpCode !== 200 || filesize($tmpZip) < 16) {
            @unlink($tmpZip);
            Response::error('下载失败：' . ($curlErr !== '' ? $curlErr : 'HTTP ' . $httpCode));
        }

        if (!class_exists('ZipArchive')) {
            @unlink($tmpZip);
            Response::error('PHP ZipArchive 扩展未启用');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            Response::error('zip 打开失败');
        }

        $topLevel = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $slashPos = strpos($entry, '/');
            $top = $slashPos !== false ? substr($entry, 0, $slashPos) : $entry;
            if ($top !== '') $topLevel[$top] = true;
        }
        $topList = array_keys($topLevel);

        $rmTree = static function (string $path) use (&$rmTree): bool {
            if (!file_exists($path)) return true;
            if (!is_dir($path)) return @unlink($path);
            foreach (scandir($path) ?: [] as $item) {
                if ($item === '.' || $item === '..') continue;
                $rmTree($path . DIRECTORY_SEPARATOR . $item);
            }
            return @rmdir($path);
        };
        if ($isUpdate && is_dir($targetDir)) {
            $rmTree($targetDir);
        }
        if (!is_dir($targetRoot)) @mkdir($targetRoot, 0755, true);

        if (count($topList) === 1 && strpos($topList[0], '.') === false) {
            $extractTmp = $tmpRoot . '/x_' . uniqid();
            @mkdir($extractTmp, 0755, true);
            if (!$zip->extractTo($extractTmp)) {
                $zip->close();
                @unlink($tmpZip);
                $rmTree($extractTmp);
                Response::error('解压失败');
            }
            $zip->close();

            $src = $extractTmp . '/' . $topList[0];
            $moved = @rename($src, $targetDir);
            if (!$moved) {
                $copyTree = static function (string $from, string $to) use (&$copyTree): bool {
                    if (!is_dir($from)) return @copy($from, $to);
                    if (!is_dir($to) && !@mkdir($to, 0755, true)) return false;
                    foreach (scandir($from) ?: [] as $item) {
                        if ($item === '.' || $item === '..') continue;
                        if (!$copyTree($from . DIRECTORY_SEPARATOR . $item, $to . DIRECTORY_SEPARATOR . $item)) return false;
                    }
                    return true;
                };
                $moved = $copyTree($src, $targetDir);
            }
            $rmTree($extractTmp);
            if (!$moved) {
                @unlink($tmpZip);
                Response::error('移动解压文件到目标目录失败（请检查 content/plugin 或 content/template 目录写权限）');
            }
        } else {
            @mkdir($targetDir, 0755, true);
            if (!$zip->extractTo($targetDir)) {
                $zip->close();
                @unlink($tmpZip);
                Response::error('解压失败');
            }
            $zip->close();
        }
        @unlink($tmpZip);
        } // end if (!$localAlreadyExists)

        // 注册到本地数据库（scope = merchant_{id}）
        if ($type === 'plugin') {
            $mainFile = $targetDir . '/' . $name . '.php';
            if (!is_file($mainFile)) {
                Response::error('插件主文件缺失：' . $name . '.php');
            }
            $model = new PluginModel();
            $info = $model->parseHeader($mainFile);
            if ($info === null) {
                Response::error('无法解析插件头部注释');
            }
            $info['name']       = $name;
            $info['main_file']  = $name . '.php';
            foreach ([
                'setting_file' => $name . '_setting.php',
                'show_file'    => $name . '_show.php',
            ] as $k => $file) {
                if (is_file($targetDir . '/' . $file)) $info[$k] = $file;
            }
            if (is_file($targetDir . '/icon.png')) $info['icon'] = '/content/plugin/' . $name . '/icon.png';
            elseif (is_file($targetDir . '/icon.gif')) $info['icon'] = '/content/plugin/' . $name . '/icon.gif';
            if (is_file($targetDir . '/preview.jpg')) $info['preview'] = '/content/plugin/' . $name . '/preview.jpg';

            // 首次安装时才调 callback_init；更新跳过
            if (!$isUpdate && !$model->isInstalled($name, $scope)) {
                $callbackFile = $targetDir . '/' . $name . '_callback.php';
                if (is_file($callbackFile)) {
                    include_once $callbackFile;
                    if (function_exists('callback_init')) call_user_func('callback_init');
                }
            }

            if ($isUpdate && $model->isInstalled($name, $scope)) {
                $model->update($name, $info, $scope);
            } else {
                if ($model->isInstalled($name, $scope)) {
                    $model->update($name, $info, $scope);
                } else {
                    $model->install($name, $info, $scope);
                }
            }
            Response::success($isUpdate ? '插件已更新' : '插件安装成功', ['csrf_token' => Csrf::refresh()]);
        } else {
            $model = new TemplateModel();
            $scanned = $model->scanTemplates();
            if (!isset($scanned[$name])) {
                Response::error('模板目录扫描失败');
            }
            $info = $scanned[$name];
            if ($isUpdate && $model->isInstalled($name, $scope)) {
                $model->update($name, $info, $scope);
            } else {
                if ($model->isInstalled($name, $scope)) {
                    $model->update($name, $info, $scope);
                } else {
                    $model->install($name, $info, $scope);
                }
            }
            Response::success($isUpdate ? '模板已更新' : '模板安装成功', ['csrf_token' => Csrf::refresh()]);
        }
    } catch (Throwable $e) {
        Response::error('安装异常：' . $e->getMessage());
    }
}

// 页面渲染：商户侧布局
merchantRenderPage(__DIR__ . '/view/appstore.php', [
    'csrfToken' => $csrfToken,
]);
