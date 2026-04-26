-- ============================================================
-- em_merchant_navi_hidden：记录"哪些系统导航在某商户站被隐藏"
--
-- 系统导航（is_system=1）全站共享，所有商户都能看到。
-- 但商户偶尔想"在自己店里把这条隐掉"——既不删除（删了别店也没了），
-- 也不需要主站知情。所以单独建张表记录"商户 X 不想显示导航 Y"。
--
-- 主站不写这张表（主站本来就能直接控制 status）。
-- ============================================================

CREATE TABLE IF NOT EXISTS `__PREFIX__merchant_navi_hidden` (
    `merchant_id` INT UNSIGNED NOT NULL COMMENT '商户 ID',
    `navi_id` BIGINT UNSIGNED NOT NULL COMMENT '导航 ID（必须 is_system=1）',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`merchant_id`, `navi_id`),
    KEY `idx_navi` (`navi_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户隐藏的系统导航';
