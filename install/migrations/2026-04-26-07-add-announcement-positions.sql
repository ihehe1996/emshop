-- ============================================================
-- 公告"显示位置"字段：主站 em_config + 商户 em_merchant 各自独立维护
--
-- 存逗号分隔字符串：'home', 'goods_list', 或 'home,goods_list'，空字符串=不显示
-- 选这种简单格式而不是 JSON：未来加位置只需多个值，前端拆分后判断 in_array 即可
-- ============================================================

-- em_merchant 加 announcement_positions
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__merchant'
               AND column_name = 'announcement_positions');
SET @sql := IF(@col > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__merchant`
       ADD COLUMN `announcement_positions` VARCHAR(64) NOT NULL DEFAULT ''''
       COMMENT ''公告显示位置（逗号分隔：home/goods_list）''
       AFTER `announcement`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 主站位置存 em_config 表 key='shop_announcement_positions'，无需 schema 改动；
-- 这里只占一个迁移记录，避免 InstallService 重复 schema 维护
