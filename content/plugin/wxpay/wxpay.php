<?php
/**
Plugin Name: 微信支付
Version: 1.0.0
Plugin URL:
Description: 微信在线支付插件，支持扫码支付、JSAPI支付、H5支付。
Author: EMSHOP
Author URL:
Category: 支付插件
*/

defined('EM_ROOT') || exit('Access Denied');

// 注册支付方式
addFilter('payment_methods_register', function (array $methods): array {
    $storage = Storage::getInstance('wxpay');
    $displayName = $storage->getValue('display_name') ?: '微信支付';

    $methods[] = [
        'code'         => 'wxpay',
        'name'         => '微信支付',
        'display_name' => $displayName,
        'image'        => '/content/plugin/wxpay/wxpay.png',
        'channel'      => 'wxpay',
        'plugin'       => 'wxpay',
        'plugin_name'  => '微信支付',
    ];
    return $methods;
});
