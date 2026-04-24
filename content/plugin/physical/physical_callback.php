<?php
/**
 * 实物商品插件 — 生命周期回调
 *
 * callback_init(): 启用插件时执行
 * callback_rm():   删除插件时执行
 * callback_up():   更新插件时执行
 */
defined('EM_ROOT') || exit('Access Denied');

// 启用插件时执行
function callback_init()
{
    // 实物商品插件不需要额外建表
}

// 删除插件时执行
function callback_rm()
{
    // 清理实物商品的 plugin_data（可选，避免残留配置）
    // 注意：不清理商品本身，只清理插件专属数据
}

// 更新插件时执行
function callback_up()
{
    // 预留
}
