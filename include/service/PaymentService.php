<?php

declare(strict_types=1);

/**
 * 支付服务。
 *
 * 钩子链：
 * 1. payment_methods_register — 各支付插件注册自己的支付方式
 * 2. payment_methods          — 对最终的支付方式列表做二次处理（排序、过滤、修改等）
 */
class PaymentService
{
    /**
     * 获取所有已启用的支付方式（已排序）。
     *
     * @return array<array{code:string, name:string, image:string}>
     */
    public static function getMethods(): array
    {
        $methods = [];

        // 1. 通过钩子收集插件注册的支付方式
        $methods = applyFilter('payment_methods_register', $methods);

        // 余额支付（内置，受后台开关控制，放在最后）
        $balanceEnabled = Config::get('shop_balance_enabled', '1');
        if ($balanceEnabled === '1') {
            // 登录用户：在显示名后拼当前余额，方便用户一眼看到够不够付
            $displayName = '余额支付';
            if (session_status() === PHP_SESSION_NONE) session_start();
            $fu = $_SESSION['em_front_user'] ?? null;
            if (is_array($fu) && isset($fu['money'])) {
                $bal = bcdiv((string) $fu['money'], '1000000', 2);
                $cur = Currency::getInstance()->getPrimary();
                $sym = $cur ? ($cur['symbol'] ?? '¥') : '¥';
                $displayName = '余额支付 ' . $sym . $bal;
            }
            $methods[] = [
                'code'         => 'balance',
                'name'         => '余额支付',
                'display_name' => $displayName,
                'image'        => '/content/static/img/balance.png',
                'channel'      => 'balance',
                'plugin'       => 'built-in',
                'plugin_name'  => '内置',
            ];
        }

        // 2. 通过钩子对支付方式列表做二次处理（排序、过滤、修改等）
        $methods = applyFilter('payment_methods', $methods);

        return $methods;
    }
}
