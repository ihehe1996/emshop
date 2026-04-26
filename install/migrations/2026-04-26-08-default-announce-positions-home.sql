-- ============================================================
-- 公告显示位置默认值改为 'home'：让"开箱即用"——
-- 用户从未配置时默认在商城首页展示公告，避免写了公告但前台不显示的困惑。
--
-- 1. em_merchant.announcement_positions 默认值 '' → 'home'
-- 2. 现有"从未配置"的行（'' 空字符串）一并升级为 'home'；
--    用户主动保存"全部取消勾选"会得到 ''（保留这个语义），但今天的老数据
--    全部是创建时的空默认，刷成 home 不会丢失用户已表达的意图。
-- ============================================================

ALTER TABLE `__PREFIX__merchant`
  MODIFY `announcement_positions` VARCHAR(64) NOT NULL DEFAULT 'home'
  COMMENT '公告显示位置（逗号分隔：home/goods_list）';

UPDATE `__PREFIX__merchant`
   SET `announcement_positions` = 'home'
 WHERE `announcement_positions` = '';
