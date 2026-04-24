<?php
/**
 * 虚拟商品（自动发货）插件 — 生命周期回调
 *
 * callback_init(): 启用插件时执行，创建卡密库存表
 * callback_rm():   删除插件时执行，清理数据
 * callback_up():   更新插件时执行，处理表结构变更
 */
defined('EM_ROOT') || exit('Access Denied');

// 启用插件时执行：创建所需数据库表
function callback_init()
{
    $sqlFile = __DIR__ . '/install.sql';
    if (!is_file($sqlFile)) {
        return;
    }

    $content = file_get_contents($sqlFile);
    if ($content === false) {
        return;
    }

    // 按分号+换行拆分 SQL 语句，逐条执行
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $content);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) {
            continue;
        }
        // 过滤注释行
        $lines = preg_split('/\r?\n/', $stmt);
        $filtered = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && !str_starts_with($trimmed, '--')) {
                $filtered[] = $line;
            }
        }
        $sql = trim(implode("\n", $filtered));
        if ($sql !== '') {
            Database::statement($sql);
        }
    }
}

// 删除插件时执行：清理插件数据
function callback_rm()
{
    // 删除卡密库存表（谨慎：会丢失所有卡密数据）
    $prefix = Database::prefix();
    Database::statement("DROP TABLE IF EXISTS `{$prefix}goods_virtual_card`");
}

// 更新插件时执行：处理表结构升级
function callback_up()
{
    $prefix = Database::prefix();

    // v1.1: 新增 sell_priority 字段（销售优先级）
    try {
        Database::statement(
            "ALTER TABLE `{$prefix}goods_virtual_card` ADD COLUMN `sell_priority` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售优先级' AFTER `remark`"
        );
    } catch (Throwable $e) {
        // 字段可能已存在，忽略
    }

    // v1.2: card_no / card_pwd 扩容为 TEXT，支持超长卡密
    try {
        Database::statement(
            "ALTER TABLE `{$prefix}goods_virtual_card` MODIFY COLUMN `card_no` TEXT NOT NULL COMMENT '卡号/卡密内容'"
        );
        Database::statement(
            "ALTER TABLE `{$prefix}goods_virtual_card` MODIFY COLUMN `card_pwd` TEXT DEFAULT NULL COMMENT '卡密密码（可选，部分卡密格式需要）'"
        );
    } catch (Throwable $e) {
        // 忽略
    }
}
