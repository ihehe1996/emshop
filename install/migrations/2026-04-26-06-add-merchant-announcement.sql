-- ============================================================
-- em_merchant 加 announcement 字段（商户独立的店铺公告）
--
-- 主站公告存在 em_config.shop_announcement；商户公告独立存这里，互不干扰：
--   - 主站访客 → em_config.shop_announcement
--   - 商户访客 → em_merchant.announcement（按 MerchantContext::current() 取）
-- 富文本 HTML，longtext 大小够用。
-- ============================================================

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__merchant'
               AND column_name = 'announcement');
SET @sql := IF(@col > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__merchant`
       ADD COLUMN `announcement` LONGTEXT NULL DEFAULT NULL
       COMMENT ''商户独立店铺公告（富文本 HTML）''
       AFTER `description`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
