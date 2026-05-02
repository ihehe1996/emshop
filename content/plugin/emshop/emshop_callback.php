<?php
/**
 * EMSHOP共享店铺 - 安装/卸载回调。
 */

defined('EM_ROOT') || exit('Access Denied');

/**
 * 启用插件时建表：对接站点（对方 API 接口地址 + appid + secret）。
 */
function callback_init(): void
{
    $prefix = Database::prefix();

    Database::execute("CREATE TABLE IF NOT EXISTS `{$prefix}emshop_remote_site` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '站点显示名',
        `base_url`    VARCHAR(500) NOT NULL DEFAULT '' COMMENT '对方 API 接口地址，保存时以 / 结尾，如 https://shop.example.com/',
        `appid`       VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '对方 API appid（用户ID）',
        `secret`      VARCHAR(256) NOT NULL DEFAULT '' COMMENT '对方 API SECRET',
        `enabled`     TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 启用 0 停用',
        `remark`      VARCHAR(500) NOT NULL DEFAULT '',
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_enabled` (`enabled`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='EMSHOP共享店铺 - 对接站点'");
}

/**
 * 卸载时保留表数据（与 ycy_shared 一致）；彻底删除可手工 DROP 表。
 */
function callback_rm(): void
{
}
