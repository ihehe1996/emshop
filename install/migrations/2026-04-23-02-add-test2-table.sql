-- ============================================================
-- 升级功能自测 · 文件 02：在 01 建好的表上加字段 + 建一张联动表
--
-- 测试点：
--   (a) 跨文件顺序：必须 01 先跑，ALTER 才能找到 __PREFIX__test_upgrade 表
--       —— 如果升级系统顺序反了，这里会直接炸 "Table doesn't exist"
--   (b) 跨文件事务隔离：每个文件独立成一个迁移单位，各自记入 __PREFIX__migrations
--   (c) 同文件内多条语句：ALTER → ALTER → CREATE TABLE → INSERT，四条按序执行
--
-- 约定：表前缀用 __PREFIX__ 占位，UpdateService 执行前会自动替换为 Database::prefix()
-- ============================================================

-- 给 01 建的表加字段（这条如果失败说明文件没按顺序跑）
ALTER TABLE `__PREFIX__test_upgrade` ADD COLUMN `score` INT NOT NULL DEFAULT 0 COMMENT '测试分数' AFTER `note`;
ALTER TABLE `__PREFIX__test_upgrade` ADD COLUMN `tag`   VARCHAR(30) NOT NULL DEFAULT '' COMMENT '测试标签' AFTER `score`;

-- 再建一张子表，靠外键关联到 01 的表（测试表间依赖）
CREATE TABLE IF NOT EXISTS `__PREFIX__test_upgrade_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `test_id`    INT UNSIGNED NOT NULL COMMENT '对应 __PREFIX__test_upgrade.id',
    `action`     VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '操作描述',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_test_id` (`test_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='升级功能自测日志表';

-- 塞数据到新加字段和新建的表里
UPDATE `__PREFIX__test_upgrade` SET `score` = 100, `tag` = 'alpha' WHERE `name` = 'upgrade_test_1';
UPDATE `__PREFIX__test_upgrade` SET `score` = 200, `tag` = 'beta'  WHERE `name` = 'upgrade_test_2';
UPDATE `__PREFIX__test_upgrade` SET `score` = 300, `tag` = 'gamma' WHERE `name` = 'upgrade_test_3';

INSERT IGNORE INTO `__PREFIX__test_upgrade_log` (`test_id`, `action`) VALUES
    (1, '文件 02 写入 log：第一行'),
    (2, '文件 02 写入 log：第二行'),
    (3, '文件 02 写入 log：第三行');
