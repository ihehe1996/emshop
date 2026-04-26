-- ============================================================
-- 清理商户 scope 下"主站统一管理"分类的 em_plugin 行 + 把老分类名同步到新值
--
-- 决策：v1.3 起，支付插件 / 商品类型 / 商品增强 三类由主站统一管理。
-- 商户 scope 下这些分类的旧记录是历史残留，不再会被 init.php 加载（runtime 名单
-- 走主站），但留在 DB 里会让商户的"插件管理"页显示一堆"已装但用不上"的条目，
-- 给运维造成困扰。这里一次性删除。
--
-- 顺带：早期插件 header 用过"商品插件"作为 Category，后来统一成"商品类型"。
-- 老库里 em_plugin.category 还是"商品插件"，需要同步过来才能让 PluginModel
-- 的白名单匹配。
--
-- 不动 main scope 的其它字段，仅修 category。
-- ============================================================

-- 同步老分类名 → 新分类名（先做，确保下面的 DELETE 能命中）
UPDATE `__PREFIX__plugin` SET `category` = '商品类型' WHERE `category` = '商品插件';

-- 清理商户 scope 下三类的残留行
DELETE FROM `__PREFIX__plugin`
 WHERE `scope` LIKE 'merchant\_%'
   AND `category` IN ('支付插件', '商品类型', '商品增强');
