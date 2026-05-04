<?php

declare(strict_types=1);

/**
 * 安装服务。
 *
 * 负责初始化数据库、创建用户表，并写入默认管理员。
 */
final class InstallService
{
    /**
     * 执行安装。
     */
    public function setup(): void
    {
        $config = Database::config();
        $userTable = Database::prefix() . 'user';
        $configTable = Database::prefix() . 'config';

        Database::statement(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $config['dbname']
        ), true);

        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `config_name` VARCHAR(100) NOT NULL COMMENT \'配置项名称\',
                `config_value` TEXT NOT NULL COMMENT \'配置项值\',
                `description` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'配置项描述\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_config_name` (`config_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'系统配置表\'',
            $configTable
        ));

        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `username` VARCHAR(50) NOT NULL COMMENT \'用户名（登录账号）\',
                `email` VARCHAR(120) NOT NULL COMMENT \'邮箱地址\',
                `password` VARCHAR(255) NOT NULL COMMENT \'密码（bcrypt加密）\',
                `nickname` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'昵称\',
                `avatar` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'头像URL\',
                `mobile` VARCHAR(20) NOT NULL DEFAULT \'\' COMMENT \'手机号码\',
                `money` BIGINT NOT NULL DEFAULT 0 COMMENT \'账户余额（实际金额×1000000）\',
                `commission_frozen` BIGINT NOT NULL DEFAULT 0 COMMENT \'冻结佣金 ×1000000（订单完成后、冷却期内不可提）\',
                `commission_available` BIGINT NOT NULL DEFAULT 0 COMMENT \'可提现佣金 ×1000000\',
                `invite_code` VARCHAR(16) DEFAULT NULL COMMENT \'推广邀请码（全站唯一）\',
                `inviter_l1` BIGINT UNSIGNED DEFAULT NULL COMMENT \'直接上级用户ID（一级）\',
                `inviter_l2` BIGINT UNSIGNED DEFAULT NULL COMMENT \'二级上级用户ID\',
                `secret` VARCHAR(64) DEFAULT NULL COMMENT \'API密钥\',
                `role` VARCHAR(20) NOT NULL DEFAULT \'user\' COMMENT \'角色：admin=管理员 user=普通用户\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：1=正常 0=禁用\',
                `last_login_ip` VARCHAR(45) DEFAULT NULL COMMENT \'最后登录IP\',
                `last_login_at` DATETIME DEFAULT NULL COMMENT \'最后登录时间\',
                `remember_token` CHAR(64) DEFAULT NULL COMMENT \'记住我令牌\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'注册时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_username` (`username`),
                UNIQUE KEY `uniq_email` (`email`, `role`),
                UNIQUE KEY `uk_invite_code` (`invite_code`),
                KEY `idx_role_status` (`role`, `status`),
                KEY `idx_remember_token` (`remember_token`),
                KEY `idx_inviter_l1` (`inviter_l1`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户表\'',
            $userTable
        ));

        // 优惠券定义表
        // 金额字段统一 BIGINT ×1000000；
        // value 字段含义因 type 而异：fixed_amount 存金额；percent 存百分比(0-100，如 85 表示 8.5 折)
        $couponTable = Database::prefix() . 'coupon';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `code` VARCHAR(32) NOT NULL COMMENT \'公共兑换码（全站唯一）\',
                `name` VARCHAR(100) NOT NULL COMMENT \'后台识别名称\',
                `title` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'用户展示标题\',
                `description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'使用说明\',
                `type` VARCHAR(20) NOT NULL DEFAULT \'fixed_amount\' COMMENT \'类型：fixed_amount/percent/free_shipping\',
                `value` BIGINT NOT NULL DEFAULT 0 COMMENT \'券值（满减×1000000；打折为整数 0-100）\',
                `min_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'使用门槛 ×1000000\',
                `max_discount` BIGINT NOT NULL DEFAULT 0 COMMENT \'打折最大减免 ×1000000，0 表示无封顶\',
                `apply_scope` VARCHAR(20) NOT NULL DEFAULT \'all\' COMMENT \'适用范围：all/category/goods/goods_type\',
                `apply_ids` TEXT COMMENT \'适用目标 id 列表 JSON\',
                `start_at` DATETIME DEFAULT NULL COMMENT \'生效开始\',
                `end_at` DATETIME DEFAULT NULL COMMENT \'生效结束\',
                `total_usage_limit` INT NOT NULL DEFAULT -1 COMMENT \'总使用次数上限 -1=无限\',
                `used_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'已使用次数缓存\',
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'启用状态\',
                `owner_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'所属者：0=主站，其他=分站\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'排序\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_code` (`code`),
                KEY `idx_end_at` (`end_at`),
                KEY `idx_enabled` (`is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'优惠券定义\'',
            $couponTable
        ));

        // 用户已领取的优惠券
        // (user_id, coupon_id) 唯一 —— 保证同一人同一券只能领一次
        // 过期/失效不落表，查询时 join 动态判断
        $userCouponTable = Database::prefix() . 'user_coupon';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'用户ID\',
                `coupon_id` INT UNSIGNED NOT NULL COMMENT \'优惠券ID\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'unused\' COMMENT \'unused/used\',
                `obtained_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'领取时间\',
                `used_at` DATETIME DEFAULT NULL COMMENT \'使用时间\',
                `order_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'使用该券的订单ID\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_coupon` (`user_id`, `coupon_id`),
                KEY `idx_coupon` (`coupon_id`),
                KEY `idx_user_status` (`user_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户持有的优惠券\'',
            $userCouponTable
        ));

        // 用户余额变动记录表
        $balanceLogTable = Database::prefix() . 'user_balance_log';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'用户ID\',
                `type` VARCHAR(20) NOT NULL COMMENT \'类型：increase=增加 decrease=减少\',
                `amount` BIGINT NOT NULL COMMENT \'变动金额（×1000000）\',
                `before_balance` BIGINT NOT NULL COMMENT \'变动前余额（×1000000）\',
                `after_balance` BIGINT NOT NULL COMMENT \'变动后余额（×1000000）\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'备注\',
                `operator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'操作人ID\',
                `operator_name` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'操作人名称\',
                `ip` VARCHAR(45) NOT NULL DEFAULT \'\' COMMENT \'操作IP\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户余额变动记录\'',
            $balanceLogTable
        ));

        // 佣金流水（返佣明细；status 落表只有 frozen/available/withdrawn/reverted 四种）
        $commissionLogTable = Database::prefix() . 'commission_log';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'佣金归属用户\',
                `order_id` BIGINT UNSIGNED NOT NULL COMMENT \'关联订单\',
                `order_no` VARCHAR(32) NOT NULL COMMENT \'订单号冗余\',
                `from_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'下单用户 0=游客\',
                `level` TINYINT UNSIGNED NOT NULL COMMENT \'分销级别 1/2/3\',
                `amount` BIGINT NOT NULL COMMENT \'佣金金额 ×1000000\',
                `rate` INT NOT NULL COMMENT \'计算时的比例（整数 500=5%%）\',
                `basis_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'计算基数（利润金额）×1000000\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'frozen\' COMMENT \'frozen/available/withdrawn/reverted\',
                `frozen_until` DATETIME DEFAULT NULL COMMENT \'冻结到期时间（NULL=已解冻或其他状态）\',
                `withdraw_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'关联提现记录\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_status` (`user_id`, `status`),
                KEY `idx_order` (`order_id`),
                KEY `idx_frozen_until` (`frozen_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'佣金流水\'',
            $commissionLogTable
        ));

        // 用户钱包充值记录（通过支付插件充值到 user.money）
        $userRechargeTable = Database::prefix() . 'user_recharge';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `order_no` VARCHAR(32) NOT NULL COMMENT \'充值单号，R 开头\',
                `amount` BIGINT NOT NULL COMMENT \'充值金额 ×1000000\',
                `payment_code` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'支付方式 code\',
                `payment_plugin` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'所属支付插件 slug\',
                `trade_no` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'第三方流水号\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\' COMMENT \'pending=待支付 paid=已充值 cancelled=已取消\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `paid_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_user` (`user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'钱包充值订单\'',
            $userRechargeTable
        ));

        // 用户钱包提现申请（用户提交 → 管理员审核 → 通过后线下打款）
        $userWithdrawTable = Database::prefix() . 'user_withdraw';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `amount` BIGINT NOT NULL COMMENT \'提现金额 ×1000000（已从 user.money 扣除）\',
                `channel` VARCHAR(16) NOT NULL COMMENT \'收款方式：alipay/wxpay/bank\',
                `account_name` VARCHAR(50) NOT NULL COMMENT \'收款人姓名\',
                `account_no` VARCHAR(100) NOT NULL COMMENT \'收款账号\',
                `bank_name` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'开户行（仅银行卡）\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\' COMMENT \'pending=待审核 approved=审核通过 paid=已打款 rejected=已驳回\',
                `admin_remark` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'管理员审核备注\',
                `admin_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'最后处理人\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `processed_at` DATETIME NULL DEFAULT NULL COMMENT \'最后处理时间\',
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'钱包提现申请\'',
            $userWithdrawTable
        ));

        // 佣金提现记录（提现 = 从 commission_available 划到 em_user.money）
        $commissionWithdrawTable = Database::prefix() . 'commission_withdraw';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `amount` BIGINT NOT NULL COMMENT \'提现金额 ×1000000\',
                `before_balance` BIGINT NOT NULL DEFAULT 0 COMMENT \'提现前可用佣金\',
                `after_balance` BIGINT NOT NULL DEFAULT 0 COMMENT \'提现后可用佣金\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'done\' COMMENT \'done=已入余额\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'佣金提现记录\'',
            $commissionWithdrawTable
        ));

        // 订单主表
        $orderTable = Database::prefix() . 'order';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_no` VARCHAR(32) NOT NULL,
                `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `guest_token` VARCHAR(64) DEFAULT NULL,
                `owner_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `goods_amount` BIGINT NOT NULL DEFAULT 0,
                `discount_amount` BIGINT NOT NULL DEFAULT 0,
                `pay_amount` BIGINT NOT NULL DEFAULT 0,
                `payment_code` VARCHAR(64) NOT NULL DEFAULT \'\',
                `payment_name` VARCHAR(64) NOT NULL DEFAULT \'\',
                `payment_plugin` VARCHAR(64) NOT NULL DEFAULT \'\',
                `payment_plugin_name` VARCHAR(64) NOT NULL DEFAULT \'\',
                `payment_channel` VARCHAR(64) NOT NULL DEFAULT \'\',
                `status` VARCHAR(20) NOT NULL DEFAULT \'pending\',
                `coupon_code` VARCHAR(32) DEFAULT NULL COMMENT \'使用的优惠券 code\',
                `inviter_l1` BIGINT UNSIGNED DEFAULT NULL COMMENT \'下单时一级上级（快照）\',
                `inviter_l2` BIGINT UNSIGNED DEFAULT NULL COMMENT \'下单时二级上级（快照）\',
                `contact_info` TEXT COMMENT \'下单联系信息：JSON（附加选项）或纯字符串（游客联系方式）\',
                `shipping_address_snapshot` TEXT COMMENT \'收货地址快照 JSON（recipient/mobile/province/city/district/detail）；仅需地址的商品类型有值\',
                `delivery_callback_url` VARCHAR(500) DEFAULT NULL COMMENT \'发货异步回调地址（同系统对接）\',
                `order_password` VARCHAR(255) DEFAULT NULL COMMENT \'游客查单订单密码（明文存储）\',
                `pay_time` DATETIME DEFAULT NULL,
                `delivery_time` DATETIME DEFAULT NULL,
                `complete_time` DATETIME DEFAULT NULL,
                `ip` VARCHAR(45) NOT NULL DEFAULT \'\',
                `source` VARCHAR(16) NOT NULL DEFAULT \'web\',
                `remark` TEXT,
                `admin_remark` TEXT,
                `display_currency_code` VARCHAR(3) NOT NULL DEFAULT \'\' COMMENT \'下单时访客选择的展示货币代码；空=主货币\',
                `display_rate` BIGINT NOT NULL DEFAULT 0 COMMENT \'下单时汇率快照 ×1000000，1 单位展示货币 = rate/1000000 主货币；0=按主货币展示\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_user` (`user_id`),
                KEY `idx_guest_token` (`guest_token`),
                KEY `idx_owner_status` (`owner_id`, `status`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'订单主表\'',
            $orderTable
        ));

        // 订单商品表
        $orderGoodsTable = Database::prefix() . 'order_goods';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `goods_id` INT UNSIGNED NOT NULL,
                `spec_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `goods_title` VARCHAR(255) NOT NULL DEFAULT \'\',
                `spec_name` VARCHAR(255) NOT NULL DEFAULT \'\',
                `cover_image` VARCHAR(512) NOT NULL DEFAULT \'\',
                `price` BIGINT NOT NULL DEFAULT 0,
                `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
                `goods_type` VARCHAR(64) NOT NULL DEFAULT \'\',
                `plugin_data` TEXT,
                `delivery_content` TEXT,
                `delivery_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`),
                KEY `idx_goods` (`goods_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'订单商品表\'',
            $orderGoodsTable
        ));

        // 订单支付记录表
        $orderPaymentTable = Database::prefix() . 'order_payment';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `payment_code` VARCHAR(64) NOT NULL DEFAULT \'\',
                `payment_plugin` VARCHAR(64) NOT NULL DEFAULT \'\',
                `trade_no` VARCHAR(128) DEFAULT NULL,
                `amount` BIGINT NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `paid_at` DATETIME DEFAULT NULL,
                `extra` TEXT,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`),
                KEY `idx_trade_no` (`trade_no`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'订单支付记录\'',
            $orderPaymentTable
        ));

        $langTable = Database::prefix() . 'language';
        // 发货队列任务表
        $queueTable = Database::prefix() . 'delivery_queue';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `order_goods_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `task_type` VARCHAR(32) NOT NULL DEFAULT \'delivery\',
                `goods_type` VARCHAR(64) NOT NULL DEFAULT \'\',
                `payload` TEXT,
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,
                `last_error` TEXT,
                `callback_token` VARCHAR(64) DEFAULT NULL,
                `next_retry_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `completed_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`),
                KEY `idx_status` (`status`, `next_retry_at`),
                KEY `idx_callback` (`callback_token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'发货队列任务表\'',
            $queueTable
        ));

        $transTable = Database::prefix() . 'lang';

        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `name` VARCHAR(50) NOT NULL COMMENT \'语言名称，如：简体中文\',
                `code` VARCHAR(20) NOT NULL COMMENT \'浏览器语言码，如：zh-CN\',
                `icon` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'语言图标/国旗URL\',
                `is_default` CHAR(1) NOT NULL DEFAULT \'n\' COMMENT \'是否默认语言：y=是 n=否\',
                `enabled` CHAR(1) NOT NULL DEFAULT \'y\' COMMENT \'是否启用：y=启用 n=禁用\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'语言表\'',
            $langTable
        ));

        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `lang_id` BIGINT UNSIGNED NOT NULL COMMENT \'关联语言ID\',
                `translate` VARCHAR(200) NOT NULL COMMENT \'翻译语句原文\',
                `content` TEXT NOT NULL COMMENT \'翻译内容\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_lang_translate` (`lang_id`, `translate`),
                KEY `idx_lang_id` (`lang_id`),
                CONSTRAINT `fk_lang_id` FOREIGN KEY (`lang_id`) REFERENCES `%s`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'翻译词条表\'',
            $transTable,
            $langTable
        ));

        $goodsCatTable = Database::prefix() . 'goods_category';
        $blogCatTable = Database::prefix() . 'blog_category';
        $naviTable = Database::prefix() . 'navi';

        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'父级ID，0=顶级\',
                `name` VARCHAR(100) NOT NULL COMMENT \'分类名称\',
                `slug` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'URL别名\',
                `description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'分类描述\',
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'图标\',
                `cover_image` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'封面图片\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'排序值，越小越靠前\',
                `seo_title` VARCHAR(200) NOT NULL DEFAULT \'\' COMMENT \'SEO标题\',
                `seo_keywords` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'SEO关键词\',
                `seo_description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'SEO描述\',
                `rebate_config` TEXT NULL COMMENT \'分类级返佣配置 JSON：{"l1":500,"l2":300}（整数 rate/10000 = 百分比）\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：1=启用 0=禁用\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                KEY `idx_parent_id` (`parent_id`),
                KEY `idx_slug` (`slug`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商品分类表\'',
            $goodsCatTable
        ));

        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'父级ID，0=顶级\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站；>0=商户\',
                `name` VARCHAR(100) NOT NULL COMMENT \'分类名称\',
                `slug` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'URL别名\',
                `description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'分类描述\',
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'图标\',
                `cover_image` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'封面图片\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'排序值，越小越靠前\',
                `seo_title` VARCHAR(200) NOT NULL DEFAULT \'\' COMMENT \'SEO标题\',
                `seo_keywords` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'SEO关键词\',
                `seo_description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'SEO描述\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：1=启用 0=禁用\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                KEY `idx_parent_id` (`parent_id`),
                KEY `idx_slug` (`slug`),
                KEY `idx_status` (`status`),
                KEY `idx_merchant_status` (`merchant_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'文章分类表\'',
            $blogCatTable
        ));

        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'父级ID，0=顶级\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站；>0=商户自定义导航；is_system=1 时永远 0\',
                `name` VARCHAR(100) NOT NULL COMMENT \'导航名称\',
                `type` VARCHAR(20) NOT NULL DEFAULT \'custom\' COMMENT \'类型：system/custom/goods_cat/blog_cat\',
                `type_ref_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'关联ID\',
                `link` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'链接地址\',
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'图标\',
                `target` VARCHAR(20) NOT NULL DEFAULT \'_self\' COMMENT \'打开方式\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'排序值\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'1=启用 0=禁用\',
                `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1=系统导航不可删除\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_parent_id` (`parent_id`),
                KEY `idx_sort` (`sort`),
                KEY `idx_status` (`status`),
                KEY `idx_merchant_status` (`merchant_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'导航表\'',
            $naviTable
        ));

        // 文章表（多租户：merchant_id=0 主站，>0 商户）
        $blogTable = Database::prefix() . 'blog';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'分类ID\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站文章；>0=商户文章\',
                `title` VARCHAR(255) NOT NULL COMMENT \'文章标题\',
                `slug` VARCHAR(255) DEFAULT NULL COMMENT \'URL别名\',
                `excerpt` TEXT COMMENT \'摘要\',
                `content` LONGTEXT COMMENT \'正文内容\',
                `cover_image` VARCHAR(500) DEFAULT NULL COMMENT \'封面图\',
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'作者用户ID\',
                `views_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'阅读量\',
                `is_top` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'是否置顶\',
                `sort` INT NOT NULL DEFAULT 0 COMMENT \'排序（越小越靠前）\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：1=发布 0=草稿\',
                `created_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'创建人ID\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL COMMENT \'软删除时间，NULL表示未删除\',
                PRIMARY KEY (`id`),
                KEY `idx_category` (`category_id`),
                KEY `idx_status_sort` (`status`, `is_top`, `sort`, `id`),
                KEY `idx_views` (`views_count`),
                KEY `idx_deleted` (`deleted_at`),
                KEY `idx_merchant_status` (`merchant_id`, `status`, `deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'文章表\'',
            $blogTable
        ));

        // 文章标签：商户和主站独立池（uk_merchant_name 允许同名）
        $blogTagTable = Database::prefix() . 'blog_tag';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站标签池；>0=商户标签池\',
                `name` VARCHAR(50) NOT NULL COMMENT \'标签名称\',
                `slug` VARCHAR(50) DEFAULT \'\' COMMENT \'URL别名\',
                `color` VARCHAR(20) DEFAULT \'\' COMMENT \'标签颜色（可选）\',
                `sort` INT NOT NULL DEFAULT 0 COMMENT \'排序\',
                `article_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'文章数量（冗余计数）\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_name` (`merchant_id`, `name`),
                KEY `idx_sort` (`sort`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'博客标签表\'',
            $blogTagTable
        ));

        // 默认系统导航：仅当表内还没有同名系统导航时才插入，避免重复执行 install 脚本生成重复数据
        $defaultNavi = [
            ['name' => '首页', 'type' => 'system', 'link' => '?',             'sort' => 1, 'is_system' => 1],
            ['name' => '商城', 'type' => 'system', 'link' => '?c=goods_list', 'sort' => 2, 'is_system' => 1],
            ['name' => '博客', 'type' => 'system', 'link' => '?c=blog_list',  'sort' => 3, 'is_system' => 1],
        ];
        foreach ($defaultNavi as $row) {
            $exists = Database::fetchOne(
                'SELECT `id` FROM `' . $naviTable . '` WHERE `name` = ? AND `is_system` = 1 LIMIT 1',
                [$row['name']]
            );
            if ($exists === null) {
                Database::insert('navi', $row);
            }
        }

        $this->executeSqlFile(EM_ROOT . '/install/goods_module.sql');

        // ============ 正版授权 ============
        // 站点级授权：emkey / emkey_type / main_host / alias_hosts 全部存 em_config。
        // 详见 LicenseService。

        // ============ 商户（分站）模块 ============
        // 参考：a 系统文档/分站功能方案.md v1.1
        $this->setupMerchantModule();

        // 插入默认语言
        Database::execute(sprintf(
            'INSERT IGNORE INTO `%s` (`id`, `name`, `code`, `is_default`, `enabled`) VALUES (1, \'简体中文\', \'zh-cn\', \'y\', \'y\')',
            $langTable
        ), []);

        $siteConfigSql = sprintf(
            'INSERT INTO `%s` (`config_name`, `config_value`, `description`)
             VALUES (:config_name, :config_value, :description)
             ON DUPLICATE KEY UPDATE
                `config_value` = VALUES(`config_value`),
                `description` = VALUES(`description`)',
            $configTable
        );

        Database::execute($siteConfigSql, [
            'config_name' => 'sitename',
            'config_value' => 'EMSHOP',
            'description' => '站点名称',
        ]);

        $hasher = new PasswordHash(8, true);
        $hash = $hasher->HashPassword('123456');

        $sql = sprintf(
            'INSERT INTO `%s` (`username`, `email`, `password`, `nickname`, `avatar`, `role`, `status`)
             VALUES (:username, :email, :password, :nickname, :avatar, :role, :status)
             ON DUPLICATE KEY UPDATE
                `email` = VALUES(`email`),
                `password` = VALUES(`password`),
                `nickname` = VALUES(`nickname`),
                `avatar` = VALUES(`avatar`),
                `role` = VALUES(`role`),
                `status` = VALUES(`status`),
                `updated_at` = CURRENT_TIMESTAMP',
            $userTable
        );

        Database::execute($sql, [
            'username' => 'admin',
            'email' => '10220739@qq.com',
            'password' => $hash,
            'nickname' => '管理员',
            'avatar' => '',
            'role' => 'admin',
            'status' => 1,
        ]);
    }

    /**
     * 建立商户（分站）模块相关数据结构。
     * 详见 a 系统文档/分站功能方案.md v1.1。
     */
    private function setupMerchantModule(): void
    {
        $prefix = Database::prefix();

        // 商户等级：不含拿货折扣率（折扣率来自 user_levels.discount），
        // 只存费率 + 功能开关 + 访问方式开关
        $merchantLevelTable = $prefix . 'merchant_level';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(64) NOT NULL COMMENT \'等级名称\',
                `price` BIGINT NOT NULL DEFAULT 0 COMMENT \'自助开通价 ×1000000；0=不允许自助开通\',
                `self_goods_fee_rate` INT NOT NULL DEFAULT 0 COMMENT \'自建商品手续费率（万分位，500=5%%）\',
                `withdraw_fee_rate` INT NOT NULL DEFAULT 0 COMMENT \'提现手续费率（万分位）\',
                `allow_subdomain` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'允许二级域名\',
                `allow_custom_domain` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'允许自定义顶级域名\',
                `allow_self_goods` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'允许上架自建商品\',
                `sort` INT NOT NULL DEFAULT 100,
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_name` (`name`),
                KEY `idx_enabled` (`is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户等级\'',
            $merchantLevelTable
        ));

        // 商户主数据
        $merchantTable = $prefix . 'merchant';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'商户主 user_id\',
                `parent_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'上级商户 id（一层）\',
                `level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'商户等级 id\',
                `name` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'店铺名\',
                `logo` VARCHAR(500) NOT NULL DEFAULT \'\',
                `slogan` VARCHAR(255) NOT NULL DEFAULT \'\',
                `description` TEXT,
                `icp` VARCHAR(100) NOT NULL DEFAULT \'\',
                `subdomain` VARCHAR(64) DEFAULT NULL COMMENT \'二级域名前缀\',
                `custom_domain` VARCHAR(200) DEFAULT NULL COMMENT \'自定义域名\',
                `domain_verified` TINYINT(1) NOT NULL DEFAULT 0,
                `theme` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'模板名（v1 不生效）\',
                `default_markup_rate` INT NOT NULL DEFAULT 1000 COMMENT \'默认加价率（万分位；1000=10%）；无商品级覆盖时采用\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'1=正常 0=禁用\',
                `opened_at` DATETIME DEFAULT NULL,
                `opened_via` VARCHAR(16) NOT NULL DEFAULT \'admin\' COMMENT \'admin=后台手动 self=自助\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_id` (`user_id`),
                UNIQUE KEY `uk_subdomain` (`subdomain`),
                UNIQUE KEY `uk_custom_domain` (`custom_domain`),
                KEY `idx_parent` (`parent_id`),
                KEY `idx_level` (`level_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户主数据\'',
            $merchantTable
        ));

        // 主站商品 -> 商户的引用关系（"已推送到本店铺"）
        $merchantGoodsRefTable = $prefix . 'goods_merchant_ref';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `goods_id` INT UNSIGNED NOT NULL COMMENT \'主站商品 id\',
                `markup_rate` INT NOT NULL DEFAULT 0 COMMENT \'加价百分比（万分位；0=不加价）\',
                `merchant_category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'商户自定义分类 id；0=沿用主站分类\',
                `is_on_sale` TINYINT(1) NOT NULL DEFAULT 1,
                `is_recommended` TINYINT(1) NULL DEFAULT NULL COMMENT \'推荐覆盖：NULL=跟随主站，1=推荐，0=不推荐\',
                `sort` INT NOT NULL DEFAULT 100,
                `pushed_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_goods` (`merchant_id`, `goods_id`),
                KEY `idx_goods` (`goods_id`),
                KEY `idx_merchant_sale` (`merchant_id`, `is_on_sale`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户引用主站商品\'',
            $merchantGoodsRefTable
        ));

        // 商户自定义分类（支持二级）
        $merchantCategoryTable = $prefix . 'merchant_category';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(100) NOT NULL,
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\',
                `sort` INT NOT NULL DEFAULT 100,
                `status` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_merchant_parent` (`merchant_id`, `parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户自定义分类\'',
            $merchantCategoryTable
        ));

        // 主站分类在商户店的重命名映射
        $merchantCategoryMapTable = $prefix . 'merchant_category_map';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `master_category_id` INT UNSIGNED NOT NULL COMMENT \'主站 goods_category.id\',
                `alias_name` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'商户侧显示名（空=跟随主站名）\',
                `is_hidden` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1=在本店隐藏该主站分类\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_master` (`merchant_id`, `master_category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户分类重命名/隐藏映射\'',
            $merchantCategoryMapTable
        ));

        // 店铺余额变动流水
        $merchantBalanceLogTable = $prefix . 'merchant_balance_log';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'商户主 user_id（冗余便查）\',
                `type` VARCHAR(20) NOT NULL COMMENT \'increase/decrease/refund/withdraw/withdraw_fee/sub_rebate/adjust\',
                `amount` BIGINT NOT NULL COMMENT \'变动金额 ×1000000 正数\',
                `before_balance` BIGINT NOT NULL,
                `after_balance` BIGINT NOT NULL,
                `order_id` BIGINT UNSIGNED DEFAULT NULL,
                `withdraw_id` BIGINT UNSIGNED DEFAULT NULL,
                `source_merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'sub_rebate 类型下的来源子商户\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\',
                `operator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=系统自动\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_merchant_time` (`merchant_id`, `created_at`),
                KEY `idx_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户店铺余额流水\'',
            $merchantBalanceLogTable
        ));

        // 店铺余额提现
        $merchantWithdrawTable = $prefix . 'merchant_withdraw';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `amount` BIGINT NOT NULL COMMENT \'提现毛额 ×1000000\',
                `fee_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'手续费 ×1000000\',
                `net_amount` BIGINT NOT NULL COMMENT \'实到账 = amount - fee_amount\',
                `before_balance` BIGINT NOT NULL,
                `after_balance` BIGINT NOT NULL,
                `target` VARCHAR(16) NOT NULL DEFAULT \'money\' COMMENT \'money=提现到消费余额\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'done\' COMMENT \'pending/done/rejected\',
                `audit_remark` VARCHAR(255) NOT NULL DEFAULT \'\',
                `audited_at` DATETIME DEFAULT NULL,
                `audited_by` BIGINT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_merchant_status` (`merchant_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户提现记录\'',
            $merchantWithdrawTable
        ));

        // 自定义页面表（WordPress 式 Pages：静态页，可挂到导航）—— 多租户隔离
        $pageTable = $prefix . 'page';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站；>0=商户\',
                `title` VARCHAR(200) NOT NULL COMMENT \'页面标题\',
                `slug` VARCHAR(100) NOT NULL COMMENT \'URL 别名，访问 /p/{slug}\',
                `content` LONGTEXT COMMENT \'页面正文 HTML（富文本）\',
                `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'0=草稿, 1=已发布\',
                `is_homepage` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1=该 scope 的站点首页（同 merchant_id 最多一条）\',
                `template_name` VARCHAR(50) NOT NULL DEFAULT \'\' COMMENT \'指定主题模板文件名（不含 page- 前缀和扩展名），空=用通用 page.php\',
                `seo_title` VARCHAR(200) NOT NULL DEFAULT \'\',
                `seo_keywords` VARCHAR(500) NOT NULL DEFAULT \'\',
                `seo_description` VARCHAR(500) NOT NULL DEFAULT \'\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'数值越小越靠前\',
                `views_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME NULL DEFAULT NULL COMMENT \'软删除时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_slug` (`merchant_id`, `slug`),
                KEY `idx_status` (`status`),
                KEY `idx_deleted` (`deleted_at`),
                KEY `idx_merchant_status` (`merchant_id`, `status`, `deleted_at`),
                KEY `idx_merchant_homepage` (`merchant_id`, `is_homepage`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'自定义页面（WordPress 式 Pages）\'',
            $pageTable
        ));

        // 商户隐藏的系统导航
        $merchantNaviHiddenTable = $prefix . 'merchant_navi_hidden';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `merchant_id` INT UNSIGNED NOT NULL COMMENT \'商户 ID\',
                `navi_id` BIGINT UNSIGNED NOT NULL COMMENT \'导航 ID（必须 is_system=1）\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`merchant_id`, `navi_id`),
                KEY `idx_navi` (`navi_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'商户隐藏的系统导航\'',
            $merchantNaviHiddenTable
        ));

        // 货币表（方案 A 多币种：主货币 ×1000000 存，其他货币仅用于前台展示换算）
        $currencyTable = $prefix . 'currency';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(3) NOT NULL COMMENT \'ISO 4217 三字母代码\',
                `name` VARCHAR(30) NOT NULL COMMENT \'货币中文名\',
                `symbol` VARCHAR(10) NOT NULL DEFAULT \'\' COMMENT \'货币符号\',
                `rate` BIGINT NOT NULL DEFAULT 0 COMMENT \'×1000000，含义：1 单位该货币 = rate/1000000 主货币\',
                `is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1=主货币（一旦设定不可再改）\',
                `is_frontend_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1=前台默认：访客首次访问且未选过币种时展示该币；全站唯一\',
                `enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'1=启用\',
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
                `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'货币表（多币种：主货币 + 展示货币）\'',
            $currencyTable
        ));

        // 收货地址表（用户级通用数据，供后续实物商品插件使用）
        // 省市区以文本快照存储（避免后续行政区划表更新时历史地址错位）
        // is_default 每个 user 仅能有一条为 1，由 UserAddressModel::setDefault 事务保证
        $userAddressTable = $prefix . 'user_address';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL COMMENT \'em_user.id\',
                `recipient` VARCHAR(50) NOT NULL DEFAULT \'\' COMMENT \'收件人姓名\',
                `mobile` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'收件人手机\',
                `province` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'省/直辖市快照\',
                `city` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'市快照\',
                `district` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'区/县快照\',
                `detail` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'详细地址（街道门牌）\',
                `is_default` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'1=默认地址\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_user_default` (`user_id`, `is_default`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户收货地址（核心通用数据）\'',
            $userAddressTable
        ));

        // 插件安装记录：物理文件全站共享一份，DB 行按 scope（main / merchant_X）隔离
        // 唯一索引必须 (name, scope) —— 同一个 epay 可以同时安装在主站和商户两套独立配置
        $pluginTable = $prefix . 'plugin';
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(64) NOT NULL COMMENT \'插件目录名/标识\',
                `scope` VARCHAR(64) NOT NULL DEFAULT \'main\' COMMENT \'作用域：main=主站 / merchant_{id}=商户独立安装\',
                `title` VARCHAR(128) NOT NULL COMMENT \'插件显示名称\',
                `version` VARCHAR(32) NOT NULL DEFAULT \'1.0.0\' COMMENT \'插件版本\',
                `author` VARCHAR(128) NOT NULL DEFAULT \'\' COMMENT \'插件作者\',
                `author_url` VARCHAR(512) NOT NULL DEFAULT \'\' COMMENT \'作者主页\',
                `description` TEXT NOT NULL COMMENT \'插件描述\',
                `category` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'插件分类（如：支付插件 / 商品插件 / 系统扩展）\',
                `icon` VARCHAR(512) NOT NULL DEFAULT \'\' COMMENT \'插件图标\',
                `preview` VARCHAR(512) NOT NULL DEFAULT \'\' COMMENT \'预览图\',
                `main_file` VARCHAR(128) NOT NULL DEFAULT \'\' COMMENT \'主入口文件\',
                `setting_file` VARCHAR(128) NOT NULL DEFAULT \'\' COMMENT \'设置页文件\',
                `show_file` VARCHAR(128) NOT NULL DEFAULT \'\' COMMENT \'前台展示文件\',
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'是否已启用\',
                `installed_at` DATETIME DEFAULT NULL COMMENT \'安装时间\',
                `updated_at` DATETIME DEFAULT NULL COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_name_scope` (`name`, `scope`),
                KEY `idx_scope_enabled` (`scope`, `is_enabled`),
                KEY `idx_category` (`category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'插件安装记录\'',
            $pluginTable
        ));

        // ============ 默认商户等级 ============
        // "普通商户"：仅开自建商品；不允许独立收款、自定义域名
        $hasLevel = Database::fetchOne(sprintf('SELECT `id` FROM `%s` LIMIT 1', $merchantLevelTable));
        if ($hasLevel === null) {
            Database::insert('merchant_level', [
                'name' => '普通商户',
                'price' => 0,
                'self_goods_fee_rate' => 500,
                'withdraw_fee_rate' => 0,
                'allow_subdomain' => 0,
                'allow_custom_domain' => 0,
                'allow_self_goods' => 1,
                'sort' => 100,
                'is_enabled' => 1,
            ]);
        }

        // ============ 默认主货币（CNY） ============
        // 数据库里所有金额都以主货币 ×1000000 存储；装完后主货币锁死不可切换
        $hasCurrency = Database::fetchOne(sprintf('SELECT `id` FROM `%s` LIMIT 1', $currencyTable));
        if ($hasCurrency === null) {
            $now = time();
            Database::insert('currency', [
                'code' => 'CNY',
                'name' => '人民币',
                'symbol' => '¥',
                'rate' => 1000000,
                'is_primary' => 1,
                'is_frontend_default' => 1, // 初装时主货币同时作为前台默认；后续可在后台切到其他货币
                'enabled' => 1,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * 执行 SQL 文件中的多条建表语句。
     */
    private function executeSqlFile(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        $statements = preg_split('/;\s*(?:\r?\n|$)/', $content);
        if (!is_array($statements)) {
            return;
        }

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }

            $lines = preg_split('/\r?\n/', $statement);
            if (!is_array($lines)) {
                continue;
            }

            $filteredLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }
                $filteredLines[] = $line;
            }

            $sql = trim(implode("\n", $filteredLines));
            if ($sql === '') {
                continue;
            }

            Database::statement($sql);
        }
    }
}
