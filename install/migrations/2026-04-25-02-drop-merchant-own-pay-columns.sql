-- ============================================================
-- 移除"商户独立收款"功能相关的字段
--
-- 决策：v1.3 起统一由主站收款。商户的"独立收款"概念整体下线，
-- 商户卖出商品后的收益继续走分账流水（merchant_balance_log → withdraw 到 user.money）。
--
-- 影响字段：
--   em_merchant_level.allow_own_pay   —— 等级是否允许独立收款（开关上游）
--   em_merchant.own_pay_enabled       —— 该商户是否实际开启了独立收款
--   em_merchant.pay_channel_config    —— 商户独立收款的通道配置 JSON
--   em_order.pay_channel              —— 订单标记的实际收款通道（main/merchant）
--
-- 兼容已删除字段的库：用 information_schema 判断字段是否存在，存在才 DROP，
-- 不存在静默跳过，允许迁移文件重复执行不报错。
-- ============================================================

-- 1. em_merchant_level.allow_own_pay
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__merchant_level'
               AND column_name = 'allow_own_pay');
SET @sql := IF(@col = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__merchant_level` DROP COLUMN `allow_own_pay`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. em_merchant.own_pay_enabled
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__merchant'
               AND column_name = 'own_pay_enabled');
SET @sql := IF(@col = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__merchant` DROP COLUMN `own_pay_enabled`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. em_merchant.pay_channel_config
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__merchant'
               AND column_name = 'pay_channel_config');
SET @sql := IF(@col = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__merchant` DROP COLUMN `pay_channel_config`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. em_order.pay_channel
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__order'
               AND column_name = 'pay_channel');
SET @sql := IF(@col = 0, 'SELECT 1', 'ALTER TABLE `__PREFIX__order` DROP COLUMN `pay_channel`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
