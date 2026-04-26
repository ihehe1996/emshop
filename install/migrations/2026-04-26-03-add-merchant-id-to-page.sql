-- ============================================================
-- em_page：加 merchant_id；slug 改成 (merchant_id, slug) 唯一
--
-- 主站和商户都可以有同 slug 的页面（互不可见，URL 也对各自店铺独立解析）
-- ============================================================

-- 1. merchant_id 列
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__page'
               AND column_name = 'merchant_id');
SET @sql := IF(@col > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__page`
       ADD COLUMN `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0
       COMMENT ''0=主站；>0=商户''
       AFTER `id`,
       ADD KEY `idx_merchant_status` (`merchant_id`, `status`, `deleted_at`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. 删掉旧的 uk_slug
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__page'
              AND index_name = 'uk_slug');
SET @sql := IF(@ix = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__page` DROP INDEX `uk_slug`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. 加新唯一约束 (merchant_id, slug)
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__page'
              AND index_name = 'uk_merchant_slug');
SET @sql := IF(@ix > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__page` ADD UNIQUE KEY `uk_merchant_slug` (`merchant_id`, `slug`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
