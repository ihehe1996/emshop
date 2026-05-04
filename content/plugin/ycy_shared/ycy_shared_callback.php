<?php
/**
 * 异次元共享店铺 - 安装/卸载回调。
 *
 * 插件安装时由 admin/plugin.php 调用 callback_init 创建表结构。
 * 插件卸载时调用 callback_rm（按需清理，v1 保留数据）。
 */

defined('EM_ROOT') || exit('Access Denied');

/**
 * 安装回调：建表 em_ycy_site / em_ycy_goods / em_ycy_trade。
 */
function callback_init(): void
{
    $prefix = Database::prefix();

    // 站点表：上游 YCY/MCY 站点（支持多站点）
    Database::execute("CREATE TABLE IF NOT EXISTS `{$prefix}ycy_site` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`          VARCHAR(100) NOT NULL DEFAULT '' COMMENT '站点显示名',
        `version`       VARCHAR(8) NOT NULL DEFAULT 'v3' COMMENT '协议版本：v3 / v4',
        `host`          VARCHAR(500) NOT NULL DEFAULT '' COMMENT '站点根地址，如 https://ycy.xxx.com',
        `app_id`        VARCHAR(128) NOT NULL DEFAULT '' COMMENT '上游 app_id',
        `app_key`       VARCHAR(256) NOT NULL DEFAULT '' COMMENT '上游 app_key',
        `markup_ratio`  DECIMAL(6,3) NOT NULL DEFAULT 1.200 COMMENT '默认加价系数（>=1.000，商品级可覆盖）',
        `min_markup`    DECIMAL(6,3) NOT NULL DEFAULT 1.050 COMMENT '最低加价系数（兜底防亏本）',
        `enabled`       TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 启用 0 停用',
        `last_synced_at` DATETIME DEFAULT NULL COMMENT '最近一次商品列表同步时间',
        `remark`        VARCHAR(255) NOT NULL DEFAULT '',
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_enabled` (`enabled`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='异次元共享店铺 - 上游站点'");

    // 商品映射表：本地 goods_id ↔ 上游商品
    Database::execute("CREATE TABLE IF NOT EXISTS `{$prefix}ycy_goods` (
        `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `site_id`          INT UNSIGNED NOT NULL,
        `goods_id`         INT UNSIGNED NOT NULL COMMENT '本地 em_goods.id',
        `upstream_ref`     VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'v3: code(16位) / v4: 数字id',
        `upstream_name`    VARCHAR(200) NOT NULL DEFAULT '',
        `sku_map`          MEDIUMTEXT COMMENT 'JSON: [{local_spec_id, upstream_sku_id/category, price, stock}, ...]',
        `markup_ratio`     DECIMAL(6,3) DEFAULT NULL COMMENT '商品级加价系数（覆盖站点默认）',
        `last_stock`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上次同步的总库存',
        `last_price_raw`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上次同步的基础价 ×1000000',
        `last_stock_synced_at` DATETIME DEFAULT NULL,
        `last_catalog_synced_at` DATETIME DEFAULT NULL,
        `next_stock_sync_at` DATETIME DEFAULT NULL COMMENT '下次库存同步时间（任务化分片）',
        `next_price_sync_at` DATETIME DEFAULT NULL COMMENT '下次价格同步时间（任务化分片）',
        `stock_fail_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '库存同步连续失败次数',
        `price_fail_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '价格同步连续失败次数',
        `last_stock_error` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最近一次库存同步错误',
        `last_price_error` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最近一次价格同步错误',
        `sync_lock_token` VARCHAR(64) DEFAULT NULL COMMENT '同步锁 token',
        `sync_lock_until` DATETIME DEFAULT NULL COMMENT '同步锁过期时间',
        `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_site_ref` (`site_id`, `upstream_ref`),
        KEY `idx_goods_id` (`goods_id`),
        KEY `idx_stock_due` (`next_stock_sync_at`),
        KEY `idx_price_due` (`next_price_sync_at`),
        KEY `idx_sync_lock` (`sync_lock_until`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='异次元共享店铺 - 商品映射'");

    // 代付上游单流水表：本地订单行 ↔ 上游 trade_no
    Database::execute("CREATE TABLE IF NOT EXISTS `{$prefix}ycy_trade` (
        `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `order_goods_id`  BIGINT UNSIGNED NOT NULL COMMENT '本地 em_order_goods.id',
        `site_id`         INT UNSIGNED NOT NULL,
        `upstream_ref`    VARCHAR(128) NOT NULL DEFAULT '',
        `upstream_trade_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'v4: 客户端生成 / v3: 服务端返回',
        `quantity`        INT UNSIGNED NOT NULL DEFAULT 1,
        `cost_amount_raw` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上游实付金额 ×1000000',
        `status`          VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending / success / failed',
        `next_poll_at`    DATETIME DEFAULT NULL COMMENT '下次轮询时间',
        `poll_attempts`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '轮询次数',
        `last_poll_error` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '最近一次轮询错误',
        `response`        MEDIUMTEXT COMMENT '上游返回原始内容',
        `error_message`   VARCHAR(500) NOT NULL DEFAULT '',
        `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_order_goods` (`order_goods_id`),
        KEY `idx_status` (`status`),
        KEY `idx_trade_poll` (`status`, `next_poll_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='异次元共享店铺 - 代付流水'");

}

/**
 * 卸载回调：v1 版本保留数据（商品/订单记录有历史价值）。
 * 如需彻底清理，用户可手动 DROP 三张表。
 */
function callback_rm(): void
{
    // 故意空：保留数据
}
