-- ============================================================
-- em_blog_tag：删除老的 UNIQUE(`name`) 索引
--
-- 上一个迁移本想删 name 唯一约束，但实际索引名是 uniq_name 不是 name，
-- 导致旧 UNIQUE 残留，主站和商户没法各自有同名标签。
-- 这里按 uniq_name 兜底删一次。
-- ============================================================

SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = '__PREFIX__blog_tag'
              AND index_name = 'uniq_name');
SET @sql := IF(@ix = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__blog_tag` DROP INDEX `uniq_name`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
