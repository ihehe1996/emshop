<?php
/**
Plugin Name: EMSHOP共享店铺
Version: 0.1.0
Plugin URL:
Description: 与同系统（EMSHOP）其他站点对接：管理对接站点凭证，后续可同步商品、代下单等。
Author: EMSHOP
Author URL:
Category: 对接插件
*/

defined('EM_ROOT') || exit('Access Denied');

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'EmshopPlugin\\') !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', $class);
    $file = __DIR__ . '/lib/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * 保证插件数据表存在（幂等）。后台设置弹窗在未走前台 init 时也可调用。
 */
function emshop_plugin_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $cb = __DIR__ . '/emshop_callback.php';
    if (is_file($cb)) {
        require_once $cb;
    }
    if (function_exists('callback_init')) {
        callback_init();
    }
}
