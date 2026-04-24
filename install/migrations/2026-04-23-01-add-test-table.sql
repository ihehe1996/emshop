-- ============================================================
-- 升级功能自测 · 文件 01：建测试表 + 塞种子数据
--
-- 测试点：
--   (a) 跨文件顺序：本文件必须先于 02 跑，否则 02 的 ALTER 会找不到表
--   (b) 单文件内多条语句按顺序执行：先 CREATE TABLE，再 CREATE INDEX，再 INSERT
--   (c) 幂等：IF NOT EXISTS / INSERT IGNORE，允许重跑不报错（手动调试时方便）
--
-- 约定：表前缀用 __PREFIX__ 占位，UpdateService 执行前会自动替换为 Database::prefix()
-- ============================================================

CREATE TABLE IF NOT EXISTS `__PREFIX__test_upgrade` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '测试名称',
    `note`       VARCHAR(200) NOT NULL DEFAULT '' COMMENT '备注',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='升级功能自测表';

-- 加个索引（测试同文件内 ALTER 是否按顺序执行）
ALTER TABLE `__PREFIX__test_upgrade` ADD INDEX `idx_created_at` (`created_at`);

-- 种子数据（测试 DML 是否跑）
INSERT IGNORE INTO `__PREFIX__test_upgrade` (`name`, `note`) VALUES
    ('upgrade_test_1', '文件 01 插入的第一行'),
    ('upgrade_test_2', '文件 01 插入的第二行'),
    ('upgrade_test_3', '文件 01 插入的第三行');
