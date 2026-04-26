-- ============================================================
-- em_page：加 is_homepage 字段
--
-- 作用：把某个自定义页面（已发布）"提升"为站点首页，优先级高于
-- settings.homepage_mode（mall / goods_list / blog）。
-- 同 scope 内最多一条 is_homepage=1（由应用层 setHomepage() 时清掉同 scope 旧值保证）。
--
--   主站设的页面首页 = WHERE merchant_id=0 AND is_homepage=1 LIMIT 1
--   商户设的页面首页 = WHERE merchant_id={商户id} AND is_homepage=1 LIMIT 1
-- 互不干扰；商户没设时回落到 homepage_mode 配置。
-- ============================================================

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__page'
               AND column_name = 'is_homepage');
SET @sql := IF(@col > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__page`
       ADD COLUMN `is_homepage` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1=该 scope 的站点首页''
       AFTER `status`,
       ADD KEY `idx_merchant_homepage` (`merchant_id`, `is_homepage`)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
