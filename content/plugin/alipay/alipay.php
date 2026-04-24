<?php
/**
Plugin Name: 支付宝支付
Version: 1.0.0
Plugin URL:
Description: 支付宝在线支付插件，支持扫码支付、H5支付。
Author: EMSHOP
Author URL:
Category: 支付插件
*/

defined('EM_ROOT') || exit('Access Denied');

// 注册支付方式
addFilter('payment_methods_register', function (array $methods): array {
    $storage = Storage::getInstance('alipay');
    $displayName = $storage->getValue('display_name') ?: '支付宝';

    $methods[] = [
        'code'         => 'alipay',
        'name'         => '支付宝',
        'display_name' => $displayName,
        'image'        => '/content/plugin/alipay/alipay.png',
        'channel'      => 'alipay',
        'plugin'       => 'alipay',
        'plugin_name'  => '支付宝支付',
    ];
    return $methods;
});
