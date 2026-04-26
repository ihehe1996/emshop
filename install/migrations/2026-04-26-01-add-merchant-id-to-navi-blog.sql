-- ============================================================
-- 给 em_navi / em_blog / em_blog_category / em_blog_tag 加 merchant_id
--
-- 新模型：
--   em_navi —— is_system=1 永远 merchant_id=0（系统导航全站共享，主商都能看）
--             is_system=0 跟 merchant_id 走（0=主站自定义，>0=商户自定义）
--   em_blog / em_blog_category / em_blog_tag —— 完全按 merchant_id 隔离
--             0=主站，>0=商户。商户标签独立池，互不可见
--
-- 旧数据全部留在 merchant_id=0（既有的"主站自定义导航 / 博客 / 分类 / 标签"语义不变）
-- ============================================================

-- 1. em_navi.merchant_id
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__navi'
               AND column_name = 'merchant_id');
SET @sql := IF(@col > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__navi`
       ADD COLUMN `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0
       COMMENT ''0=主站；>0=商户自定义导航；is_system=1 时永远 0''
       AFTER `parent_id`,
       ADD KEY `idx_merchant_status` (`merchant_id`, `status`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. em_blog.merchant_id
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__blog'
               AND column_name = 'merchant_id');
SET @sql := IF(@col > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__blog`
       ADD COLUMN `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0
       COMMENT ''0=主站文章；>0=商户文章''
       AFTER `category_id`,
       ADD KEY `idx_merchant_status` (`merchant_id`, `status`, `deleted_at`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. em_blog_category.merchant_id
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__blog_category'
               AND column_name = 'merchant_id');
SET @sql := IF(@col > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__blog_category`
       ADD COLUMN `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0
       COMMENT ''0=主站；>0=商户''
       AFTER `parent_id`,
       ADD KEY `idx_merchant_status` (`merchant_id`, `status`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. em_blog_tag.merchant_id —— 加 merchant_id 后唯一约束 name 改成 (name, merchant_id)
--    商户和主站可以都有同名标签（独立池）
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__blog_tag'
               AND column_name = 'merchant_id');
SET @sql := IF(@col > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__blog_tag`
       ADD COLUMN `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0
       COMMENT ''0=主站标签池；>0=商户标签池''
       AFTER `id`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 把唯一索引从 (name) 改成 (merchant_id, name)
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__blog_tag'
              AND index_name = 'name');
SET @sql := IF(@ix = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__blog_tag` DROP INDEX `name`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__blog_tag'
              AND index_name = 'uk_merchant_name');
SET @sql := IF(@ix > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__blog_tag` ADD UNIQUE KEY `uk_merchant_name` (`merchant_id`, `name`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
