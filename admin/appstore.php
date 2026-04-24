<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 应用商店控制器。
 *
 * 提供官方应用/模板/插件的浏览、下载与安装功能。
 */
adminRequireLogin();
$user = $adminUser;
$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();

// AJAX：拉取分类列表（/api/app_categories.php）。走独立 action 保证页面不被外网阻塞
if ((string) Input::get('_action', '') === 'categories') {
    try {
        // 主站后台固定 scope=1，让服务端只统计 app.scope IN (0,1) 的应用
        Response::success('', ['list' => LicenseClient::appCategories(1)]);
    } catch (Throwable $e) {
        Response::error($e->getMessage(), ['list' => []]);
    }
}

// AJAX：拉取已启用的支付方式（/api/pay_methods.php）。购买弹窗用来渲染可选支付通道
if ((string) Input::get('_action', '') === 'pay_methods') {
    try {
        Response::success('', ['list' => LicenseClient::payMethods()]);
    } catch (Throwable $e) {
        Response::error($e->getMessage(), ['list' => []]);
    }
}

// AJAX：为指定应用创建购买订单（/api/app_buy.php），成功返回 pay_url 供前端顶层跳转
// 用 POST + CSRF 校验（和 install/update 一致），避免 CSRF 伪造
if (Request::isPost() && (string) Input::post('_action', '') === 'app_buy') {
    if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
        Response::error('请求已失效，请刷新页面后重试');
    }
    $appId     = (int) Input::post('app_id', 0);
    $payMethod = trim((string) Input::post('pay_method', ''));
    if ($appId <= 0)       Response::error('应用ID不能为空');
    if ($payMethod === '') Response::error('请选择支付方式');

    // emkey + host 从当前激活状态取；未激活直接拦截，防止服务端兜底报错
    $licenseRow = LicenseService::currentLicense();
    $emkey = $licenseRow ? (string) ($licenseRow['license_code'] ?? '') : '';
    if ($emkey === '') {
        Response::error('请先激活正版授权');
    }
    $host = LicenseService::effectiveHost();
    try {
        // 主站购买 member_code 传空串；服务端按 identity=main_site 入库
        $data = LicenseClient::appBuy($emkey, $host, $appId, $payMethod, '');
        Response::success('', $data + ['csrf_token' => Csrf::refresh()]);
    } catch (Throwable $e) {

        Response::error($e->getMessage());
    }
}

// AJAX：按 id 拉取单个应用详情 + 支付方式（/api/app_detail.php），购买弹窗一次拉齐
if ((string) Input::get('_action', '') === 'app_detail') {
    $appId = (int) Input::get('id', 0);
    if ($appId <= 0) {
        Response::error('应用ID不能为空');
    }
    // 复用列表接口的身份注入逻辑（未激活时 emkey 为空，服务端会回退 VIP 价）
    $licenseRow = LicenseService::currentLicense();
    $emkey = $licenseRow ? (string) ($licenseRow['license_code'] ?? '') : '';
    $host  = LicenseService::effectiveHost();
    try {
        $data = LicenseClient::appDetail($appId, $emkey, $host, 1, ''); // 主站：scope=1 / member_code=''
        Response::success('', $data);
    } catch (Throwable $e) {
        Response::error($e->getMessage());
    }
}

// AJAX：拉取应用列表（/api/app_store.php）。由 layui table 分页驱动；失败返回空列表不挂页
if ((string) Input::get('_action', '') === 'list') {
    // 取当前激活码和域名用于服务端计算 my_price（未激活时 emkey 为空，服务端会按 VIP 价返回）
    $licenseRow = LicenseService::currentLicense();
    $emkey = $licenseRow ? (string) ($licenseRow['license_code'] ?? '') : '';
    // 有绑定过主授权域名就用它，否则回退当前 HTTP_HOST（适配未激活场景）
    $host  = LicenseService::effectiveHost();

    $params = [
        'page'        => max(1, (int) Input::get('page', 1)),
        'pageNum'     => min(100, max(1, (int) Input::get('limit', 10))),
        'type'        => (string) Input::get('type', ''),
        'category_id' => (int) Input::get('category_id', 0),
        'keyword'     => (string) Input::get('keyword', ''),
        'scope'       => 1, // 主站后台固定 1
        'emkey'       => $emkey,
        'host'        => $host,
        'member_code' => '', // 主站身份 main_site；与 app_buy 约定一致
    ];
    try {
        $result = LicenseClient::appStoreList($params);

        // 本地已装检测：扫 em_plugin.name / em_template.name 做映射，给每条补 is_installed / installed_version
        // （后续接入 em_appstore_install 后可换成那张表）
        $installedPlugins = [];
        try {
            $rows = Database::query('SELECT `name`, `version` FROM `' . Database::prefix() . 'plugin`');
            foreach ($rows as $r) $installedPlugins[(string) $r['name']] = (string) ($r['version'] ?? '');
        } catch (Throwable $e) {
            // em_plugin 不存在也不影响列表
        }
        $installedThemes = [];
        try {
            // 表名是 em_template（对应 TemplateModel 里的 prefix . 'template'），不是 em_theme
            $rows = Database::query('SELECT `name`, `version` FROM `' . Database::prefix() . 'template`');
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

// 安装 / 更新 应用：下载远端 zip → 解压到 content/plugin|template/{name}/ → 注册到 em_plugin|em_template
if (Request::isPost() && in_array((string) Input::post('_action', ''), ['install', 'update'], true)) {
    try {
        if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $name    = trim((string) Input::post('name', ''));
        $type    = (string) Input::post('type', 'plugin');
        $filePath = trim((string) Input::post('file_path', ''));
        $isUpdate = (string) Input::post('_action', '') === 'update';
        // 主站后台固定 scope='main'；商户后台的应用商店（路线 B 阶段 2）会走另一份控制器传 merchant_{id}
        $scope = 'main';

        if ($name === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            Response::error('非法应用名');
        }
        if (!in_array($type, ['plugin', 'template'], true)) {
            Response::error('未知应用类型');
        }

        $targetRoot = $type === 'template' ? EM_ROOT . '/content/template' : EM_ROOT . '/content/plugin';
        $targetDir  = $targetRoot . '/' . $name;

        // 本地快捷安装：目录已在磁盘上 → 跳过下载，直接走注册流程。
        //   路线 B 后磁盘文件全站共享一份：别的 scope 先装过就会命中这条分支；
        //   当前 scope 此时只在 DB 里新增一行记录即可，不碰物理文件。
        //   更新动作不走这条分支（更新必须重新下载新版文件；此举会让所有持有该应用的 scope
        //   同时拿到新文件，但 DB 版本字段仅更新当前 scope 那行，其它 scope 不同步——可接受）。
        $localAlreadyExists = !$isUpdate && is_dir($targetDir);

        // 非本地快捷安装时才要求 file_path（更新 + 首装都需要下载）
        if (!$localAlreadyExists && $filePath === '') {
            Response::error('缺少下载地址');
        }

        // 统一把 file_path 规范为绝对 URL；始终基于 license_urls[0]，只允许这个主 host
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

        // 本地快捷安装：目录已存在，跳过下载/解压，直接进入 REGISTER
        // 非快捷场景才执行下载解压
        if (!$localAlreadyExists) {
        // 下载 zip 到项目内临时目录（避免 Windows 下跨盘 rename 失败）
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

        // 解压：若 zip 顶层只有一个目录（通常等于 name），则把它内部内容铺平到 targetDir
        if (!class_exists('ZipArchive')) {
            @unlink($tmpZip);
            Response::error('PHP ZipArchive 扩展未启用');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            Response::error('zip 打开失败');
        }

        // 探测顶层
        $topLevel = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $slashPos = strpos($entry, '/');
            $top = $slashPos !== false ? substr($entry, 0, $slashPos) : $entry;
            if ($top !== '') $topLevel[$top] = true;
        }
        $topList = array_keys($topLevel);

        // 更新时先清空旧目录
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
            // zip 被 wrap 在单一顶层目录下：解压到同盘临时目录再移动
            $extractTmp = $tmpRoot . '/x_' . uniqid();
            @mkdir($extractTmp, 0755, true);
            if (!$zip->extractTo($extractTmp)) {
                $zip->close();
                @unlink($tmpZip);
                $rmTree($extractTmp);
                Response::error('解压失败');
            }
            $zip->close();

            // 优先 rename（同盘）；失败则递归拷贝 + 删除（跨盘兜底）
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
        } // end if (!$localAlreadyExists) —— 下载/解压块到此结束

        // 注册到本地数据库
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

            // 首次安装时才调 callback_init；更新跳过，避免重置用户配置/重建表
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

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/appstore.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/appstore.php';
    require __DIR__ . '/index.php';
}
