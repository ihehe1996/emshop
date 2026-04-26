-- ============================================================
-- 彻底移除 em_merchant.slug
--
-- slug 历史上是给"目录式分站"准备的（/s/{slug}/），现已统一改成子域名 / 自定义域名
-- 解析（MerchantContext::resolve），slug 不再用于路由识别也不在前端展示。
-- 此处一并删 schema，避免再有代码误用。
-- ============================================================

-- 先删唯一索引 uk_slug（如果存在）
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__merchant'
              AND index_name = 'slug');
SET @sql := IF(@ix = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__merchant` DROP INDEX `slug`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 索引名 uk_slug 也尝试删（不同时期可能有不同命名）
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__merchant'
              AND index_name = 'uk_slug');
SET @sql := IF(@ix = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__merchant` DROP INDEX `uk_slug`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 再删字段
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__merchant'
               AND column_name = 'slug');
SET @sql := IF(@col = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__merchant` DROP COLUMN `slug`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
