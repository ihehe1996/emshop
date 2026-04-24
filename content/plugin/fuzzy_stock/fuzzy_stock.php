<?php
/**
Plugin Name: 模糊显示商品库存
Version: 1.0.0
Plugin URL:
Description: 将商品库存数字替换为友好化文字（如"充足""少量""售罄"），支持自定义数量区间与显示文本。
Author: 驳手
Author URL: https://em.ihehe.me/
Category: 商品增强
*/

defined('EM_ROOT') || exit('access denied!');

/**
 * 读取插件配置的区间规则，返回排序后的数组。
 * 每条规则格式：['min' => int, 'max' => int, 'label' => string]
 */
function fuzzy_stock_get_rules(): array
{
    $storage = Storage::getInstance('fuzzy_stock');
    $rules = $storage->getValue('rules');

    if (empty($rules) || !is_array($rules)) {
        // 默认规则
        return [
            ['min' => 0,   'max' => 0,   'label' => '售罄'],
            ['min' => 1,   'max' => 10,  'label' => '少量'],
            ['min' => 11,  'max' => 100, 'label' => '有货'],
            ['min' => 101, 'max' => 9999999,   'label' => '充足'],
        ];
    }

    return $rules;
}

/**
 * 根据库存数量匹配显示文本。
 */
function fuzzy_stock_format(int $stock): string
{
    $rules = fuzzy_stock_get_rules();

    foreach ($rules as $rule) {
        $min = (int) ($rule['min'] ?? 0);
        $max = (int) ($rule['max'] ?? 0);
        $label = trim($rule['label'] ?? '');

        if ($stock < $min) continue;
        if ($stock > $max) continue;

        return $label ?: (string) $stock;
    }

    // 无匹配规则，返回原始数字
    return (string) $stock;
}

// 通过核心过滤器 goods_stock_display 接入：核心在渲染商品列表/详情时
// 统一调用 applyFilter('goods_stock_display', $stock)，本插件把原始整数转成展示文字。
// 未启用插件时核心自动 fallback 为原始数字字符串。
addFilter('goods_stock_display', function ($stock) {
    if (!is_numeric($stock)) {
        // 已经被其他插件处理过，原样放行
        return $stock;
    }
    return fuzzy_stock_format((int) $stock);
});
