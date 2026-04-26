-- ============================================================
-- em_plugin: 把唯一索引从 (name) 修正成 (name, scope)
--
-- 旧版（早期 install 脚本生成）只锁 name —— 商户站想再装一份主站已装的插件
-- （比如 epay）会被这个唯一约束挡住，报 Duplicate entry 'epay' for key 'uk_name'。
-- 实际上 PluginModel 的所有读写都按 (name, scope) 走，索引开错了。
--
-- 兼容已修复 / 已是新结构的库：用 information_schema + 动态 SQL 做存在性判断，
-- 旧索引不存在 / 新索引已存在时直接跳过，不抛错。
-- ============================================================

-- 1. 旧索引 uk_name 存在 → 删
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__plugin'
              AND index_name = 'uk_name');
SET @sql := IF(@ix = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__plugin` DROP INDEX `uk_name`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. 新索引 uk_name_scope 不存在 → 加
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__plugin'
              AND index_name = 'uk_name_scope');
SET @sql := IF(@ix > 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__plugin` ADD UNIQUE KEY `uk_name_scope` (`name`, `scope`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. 顺手补两个常用查询索引（有就跳过）
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__plugin'
              AND index_name = 'idx_scope_enabled');
SET @sql := IF(@ix > 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__plugin` ADD INDEX `idx_scope_enabled` (`scope`, `is_enabled`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__plugin'
              AND index_name = 'idx_category');
SET @sql := IF(@ix > 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__plugin` ADD INDEX `idx_category` (`category`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
