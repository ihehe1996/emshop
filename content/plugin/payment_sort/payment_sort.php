<?php
/**
Plugin Name: 支付方式排序
Version: 1.0.0
Plugin URL:
Description: 管理支付方式的显示顺序，支持拖拽排序。
Author: EMSHOP
Author URL:
Category: 系统插件
*/

defined('EM_ROOT') || exit('Access Denied');

// 监听 payment_methods 钩子，根据保存的顺序重排支付方式
addFilter('payment_methods', function (array $methods): array {
    $storage = Storage::getInstance('payment_sort');
    $sortOrder = $storage->getValue('sort_order') ?: [];

    if (empty($sortOrder)) {
        return $methods;
    }

    $sortMap = array_flip($sortOrder);
    usort($methods, function ($a, $b) use ($sortMap) {
        return ($sortMap[$a['code']] ?? 999) - ($sortMap[$b['code']] ?? 999);
    });

    return $methods;
});
