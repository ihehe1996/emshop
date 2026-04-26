-- ============================================================
-- em_merchant_category_map：补上"隐藏"开关 + alias_name 默认空
--
-- 商户后台分类管理希望支持三种行为：
--   1. 跟随主站（无 map 行 / alias_name='' / is_hidden=0）
--   2. 重命名（alias_name 非空）
--   3. 隐藏（is_hidden=1，整条主站分类不在本店出现）
--
-- 老版只有 alias_name，没法表达"隐藏"。
-- ============================================================

-- 1. is_hidden 列
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = '__PREFIX__merchant_category_map'
               AND column_name = 'is_hidden');
SET @sql := IF(@col > 0, 'SELECT 1',
    'ALTER TABLE `__PREFIX__merchant_category_map`
       ADD COLUMN `is_hidden` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1=在本店隐藏该主站分类'' AFTER `alias_name`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. alias_name 改为默认空（旧版 NOT NULL 没默认值，新增"仅隐藏不改名"行时入库会报错）
ALTER TABLE `__PREFIX__merchant_category_map`
  MODIFY `alias_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '商户侧显示名（空=跟随主站名）';
