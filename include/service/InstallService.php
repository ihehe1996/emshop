<?php
declare(strict_types=1);
final class InstallService
{
    public function setup(array $options = []): void
    {
        $config = Database::config();
        $prefix = Database::prefix();

        Database::statement(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $config['dbname']
        ), true);

        // 系统配置表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `config_name` VARCHAR(100) NOT NULL COMMENT \'配置项名称\',
                `config_value` TEXT NOT NULL COMMENT \'配置项值\',
                `description` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'配置项描述\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_config_name` (`config_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'系统配置表\'',
            $prefix . 'config'
        ));
        $siteConfigSql = sprintf('INSERT INTO `%s` (`config_name`, `config_value`, `description`) VALUES (:config_name, :config_value, :description) ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`), `description` = VALUES(`description`)', $prefix . 'config');
        $defaultConfigs = [
            ['config_name' => 'sitename', 'config_value' => 'EMSHOP', 'description' => '站点名称'],
            ['config_name' => 'site_enabled', 'config_value' => '1', 'description' => '站点开启'],
            ['config_name' => 'site_logo_type', 'config_value' => 'text', 'description' => 'Logo 显示方式'],
            ['config_name' => 'homepage_mode', 'config_value' => 'mall', 'description' => '首页入口'],
            ['config_name' => 'site_timezone', 'config_value' => 'Asia/Shanghai', 'description' => '服务器时区'],
            ['config_name' => 'swoole_api_url', 'config_value' => 'http://127.0.0.1:9601', 'description' => 'Swoole API 地址'],
            ['config_name' => 'url_format', 'config_value' => 'default', 'description' => '链接格式'],
            ['config_name' => 'user_register', 'config_value' => '1', 'description' => '开放注册'],
            ['config_name' => 'shop_balance_enabled', 'config_value' => '1', 'description' => '余额购买'],
            ['config_name' => 'shop_guest_balance_enabled', 'config_value' => '1', 'description' => '游客余额购买'],
            ['config_name' => 'shop_min_recharge', 'config_value' => '10000000', 'description' => '单次最低充值'],
            ['config_name' => 'shop_max_recharge', 'config_value' => '500000000', 'description' => '单次最高充值'],
            ['config_name' => 'shop_withdraw_min', 'config_value' => '10000000', 'description' => '最低提现额'],
            ['config_name' => 'shop_withdraw_max', 'config_value' => '2000000000', 'description' => '最高提现额'],
            ['config_name' => 'shop_order_expire_minutes', 'config_value' => '10', 'description' => '订单超时时间（分钟）'],
            ['config_name' => 'shop_enable_coupon', 'config_value' => '1', 'description' => '启用优惠券'],
            ['config_name' => 'shop_announcement', 'config_value' => '<p><span style="color: rgb(207, 19, 34);">这里是系统自带的默认公告。如需修改，请前往后台管理面板 - 基础设置 - 商城设置页面处更改</span></p><p><span style="color: rgb(56, 158, 13);">注意本系统遵循</span><span style="color: rgb(56, 158, 13); background-color: rgb(255, 255, 255); font-size: 13px;">GPLv3开源协议发布，使用者造成的一切法律后果与作者无关</span></p>', 'description' => '商城公告内容'],
            ['config_name' => 'shop_announcement_positions', 'config_value' => 'home,goods_list', 'description' => '商城公告显示位置'],
            ['config_name' => 'guest_find_contact_enabled', 'config_value' => '0', 'description' => '联系方式查单开关'],
            ['config_name' => 'guest_find_contact_type', 'config_value' => 'any', 'description' => '联系方式查单类型'],
            ['config_name' => 'guest_find_contact_checkout_placeholder', 'config_value' => '请输入您的联系方式', 'description' => '联系方式下单页占位提示'],
            ['config_name' => 'guest_find_contact_lookup_placeholder', 'config_value' => '请输入您下单时填写的联系方式', 'description' => '联系方式查单页占位提示'],
            ['config_name' => 'guest_find_password_enabled', 'config_value' => '1', 'description' => '订单密码查单开关'],
            ['config_name' => 'guest_find_password_checkout_placeholder', 'config_value' => '请设置您的订单密码', 'description' => '订单密码下单页占位提示'],
            ['config_name' => 'guest_find_password_lookup_placeholder', 'config_value' => '请输入您的订单密码', 'description' => '订单密码查单页占位提示'],
            ['config_name' => 'shop_enable_rebate', 'config_value' => '0', 'description' => '推广返佣总开关'],
            ['config_name' => 'rebate_level1_rate', 'config_value' => '1000', 'description' => '一级佣金比例（万分位）'],
            ['config_name' => 'rebate_level2_rate', 'config_value' => '300', 'description' => '二级佣金比例（万分位）'],
            ['config_name' => 'rebate_calculate_mode', 'config_value' => 'amount', 'description' => '推广返佣计算方式'],
            ['config_name' => 'rebate_freeze_days', 'config_value' => '3', 'description' => '推广返佣冷却天数'],
            ['config_name' => 'blog_article_per_page', 'config_value' => '10', 'description' => '博客每页文章数'],
            ['config_name' => 'blog_comment_need_verify', 'config_value' => '1', 'description' => '博客评论需审核'],
            ['config_name' => 'blog_show_author', 'config_value' => '1', 'description' => '博客显示作者'],
            ['config_name' => 'blog_show_views', 'config_value' => '1', 'description' => '博客显示阅读量'],
            ['config_name' => 'blog_rss_enabled', 'config_value' => '0', 'description' => '博客 RSS 开关'],
            ['config_name' => 'mail_port', 'config_value' => '465', 'description' => '邮箱端口'],
            ['config_name' => 'substation_enabled', 'config_value' => '1', 'description' => '启用商户'],
            ['config_name' => 'merchant_enable_self_open', 'config_value' => '1', 'description' => '允许自动开通商户'],
            ['config_name' => 'merchant_default_theme', 'config_value' => 'default', 'description' => '分站默认模板'],
            ['config_name' => 'active_template_pc', 'config_value' => 'default', 'description' => '主站 PC 启用模板'],
            ['config_name' => 'active_template_mobile', 'config_value' => 'default', 'description' => '主站手机启用模板'],
            ['config_name' => 'enabled_plugins', 'config_value' => 'tips,virtual_card', 'description' => '主站默认启用插件'],
        ];
        foreach ($defaultConfigs as $configRow) Database::execute($siteConfigSql, $configRow);

        // 用户表
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
            $prefix . 'user'
        ));
        $admin = isset($options['admin']) && is_array($options['admin']) ? $options['admin'] : [];
        $adminUsername = trim((string) ($admin['username'] ?? ''));
        $adminEmail = trim((string) ($admin['email'] ?? ''));
        $adminPassword = (string) ($admin['password'] ?? '');
        if ($adminUsername === '' || $adminEmail === '' || $adminPassword === '') throw new InvalidArgumentException('管理员账号信息不完整');
        $hasher = new PasswordHash(8, true);
        $hash = $hasher->HashPassword($adminPassword);
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
            $prefix . 'user'
        );
        Database::execute($sql, [
            'username' => $adminUsername,
            'email' => $adminEmail,
            'password' => $hash,
            'nickname' => '管理员',
            'avatar' => '',
            'role' => 'admin',
            'status' => 1,
        ]);

        // 优惠券定义表
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
            $prefix . 'coupon'
        ));

        // 用户优惠券表
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
            $prefix . 'user_coupon'
        ));

        // 用户余额流水表
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
            $prefix . 'user_balance_log'
        ));

        // 佣金流水表
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
            $prefix . 'commission_log'
        ));

        // 用户充值订单表
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
            $prefix . 'user_recharge'
        ));

        // 用户提现申请表
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
            $prefix . 'user_withdraw'
        ));

        // 佣金提现记录表
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
            $prefix . 'commission_withdraw'
        ));

        // 订单主表
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
            $prefix . 'order'
        ));

        // 订单商品表
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
            $prefix . 'order_goods'
        ));

        // 订单支付记录表
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
            $prefix . 'order_payment'
        ));

        // 语言表

        // 发货队列表
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
            $prefix . 'delivery_queue'
        ));

        // 翻译词条表
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
            $prefix . 'language'
        ));
        Database::execute(sprintf(
            'INSERT IGNORE INTO `%s` (`id`, `name`, `code`, `is_default`, `enabled`) VALUES (1, \'简体中文\', \'zh-cn\', \'y\', \'y\')',
            $prefix . 'language'
        ), []);
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
            $prefix . 'lang',
            $prefix . 'language'
        ));

        // 商品分类表

        // 文章分类表

        // 导航表
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
            $prefix . 'goods_category'
        ));
        Database::insert('goods_category', ['parent_id' => 0, 'name' => '演示分类', 'slug' => 'demo-category', 'description' => '默认演示商品分类', 'sort' => 100, 'status' => 1]);
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
            $prefix . 'blog_category'
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
            $prefix . 'navi'
        ));
        $defaultNavi = [
            ['name' => '首页', 'type' => 'system', 'link' => '?',             'sort' => 1, 'is_system' => 1],
            ['name' => '商城', 'type' => 'system', 'link' => '?c=goods_list', 'sort' => 2, 'is_system' => 1],
            ['name' => '博客', 'type' => 'system', 'link' => '?c=blog_list',  'sort' => 3, 'is_system' => 1],
        ];
        foreach ($defaultNavi as $row) {
            $exists = Database::fetchOne(
                'SELECT `id` FROM `' . $prefix . 'navi' . '` WHERE `name` = ? AND `is_system` = 1 LIMIT 1',
                [$row['name']]
            );
            if ($exists === null) {
                Database::insert('navi', $row);
            }
        }

        // 文章表
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
            $prefix . 'blog'
        ));

        // 博客标签表
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
            $prefix . 'blog_tag'
        ));
        $this->setupCoreSupportTables();
        $this->setupGoodsModule();

        // 商户等级表
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
            $prefix . 'merchant_level'
        ));
        $hasLevel = Database::fetchOne(sprintf('SELECT `id` FROM `%s` LIMIT 1', $prefix . 'merchant_level'));
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

        // 商户主表
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
                `default_markup_rate` INT NOT NULL DEFAULT 1000 COMMENT \'默认加价率（万分位；1000=10%%）；无商品级覆盖时采用\',
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
            $prefix . 'merchant'
        ));

        // 商户商品引用表
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
            $prefix . 'goods_merchant_ref'
        ));

        // 商户分类表
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
            $prefix . 'merchant_category'
        ));

        // 商户分类映射表
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
            $prefix . 'merchant_category_map'
        ));

        // 商户余额流水表
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
            $prefix . 'merchant_balance_log'
        ));

        // 商户提现记录表
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
            $prefix . 'merchant_withdraw'
        ));

        // 页面表
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
            $prefix . 'page'
        ));

        // 商户隐藏导航表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `merchant_id` INT UNSIGNED NOT NULL COMMENT \'商户 ID\',
                `navi_id` BIGINT UNSIGNED NOT NULL COMMENT \'导航 ID（必须 is_system=1）\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`merchant_id`, `navi_id`),
                KEY `idx_navi` (`navi_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'商户隐藏的系统导航\'',
            $prefix . 'merchant_navi_hidden'
        ));

        // 货币表
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
            $prefix . 'currency'
        ));
        $hasCurrency = Database::fetchOne(sprintf('SELECT `id` FROM `%s` LIMIT 1', $prefix . 'currency'));
        if ($hasCurrency === null) {
            $now = time();
            Database::insert('currency', [
                'code' => 'CNY',
                'name' => '人民币',
                'symbol' => '¥',
                'rate' => 1000000,
                'is_primary' => 1,
                'is_frontend_default' => 1,
                'enabled' => 1,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 用户地址表
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
            $prefix . 'user_address'
        ));

        // 插件安装记录表
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
            $prefix . 'plugin'
        ));
    }
    private function setupCoreSupportTables(): void
    {
        $prefix = Database::prefix();

        // 应用市场表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `remote_app_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'授权端应用ID\',
                `app_code` VARCHAR(64) NOT NULL COMMENT \'应用编码（插件名/模板名）\',
                `type` VARCHAR(20) NOT NULL DEFAULT \'plugin\' COMMENT \'plugin/template\',
                `title` VARCHAR(150) NOT NULL DEFAULT \'\' COMMENT \'应用标题\',
                `version` VARCHAR(50) NOT NULL DEFAULT \'\' COMMENT \'版本号\',
                `category` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'分类\',
                `cover` VARCHAR(512) NOT NULL DEFAULT \'\' COMMENT \'封面图\',
                `description` TEXT COMMENT \'应用描述\',
                `cost_price` BIGINT NOT NULL DEFAULT 0 COMMENT \'最近采购单价 ×1000000\',
                `retail_price` BIGINT NOT NULL DEFAULT 0 COMMENT \'分站售价 ×1000000\',
                `total_quota` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'累计采购配额\',
                `consumed_quota` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'已售配额\',
                `is_listed` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'1=上架 0=下架\',
                `remote_payload` MEDIUMTEXT COMMENT \'授权端返回原始数据 JSON\',
                `last_purchased_at` DATETIME DEFAULT NULL COMMENT \'最后采购时间\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_app_code_type` (`app_code`, `type`),
                KEY `idx_type_listed` (`type`, `is_listed`),
                KEY `idx_updated_at` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'应用商店上架清单\'',
            $prefix . 'app_market'
        ));

        // 应用市场流水表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `market_id` INT UNSIGNED NOT NULL COMMENT \'app_market.id\',
                `app_code` VARCHAR(64) NOT NULL COMMENT \'应用编码\',
                `type` VARCHAR(20) NOT NULL DEFAULT \'plugin\' COMMENT \'plugin/template\',
                `purchase_qty` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT \'本次采购配额数量\',
                `cost_per_unit` BIGINT NOT NULL DEFAULT 0 COMMENT \'本次采购单价 ×1000000\',
                `total_cost` BIGINT NOT NULL DEFAULT 0 COMMENT \'本次采购总价 ×1000000\',
                `remote_order_no` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'授权端订单号\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'备注\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_market_id` (`market_id`),
                KEY `idx_app_code_type` (`app_code`, `type`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'应用商店采购流水\'',
            $prefix . 'app_market_log'
        ));

        // 应用购买记录表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL COMMENT \'购买方商户ID\',
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'购买用户ID\',
                `app_code` VARCHAR(64) NOT NULL COMMENT \'应用编码\',
                `type` VARCHAR(20) NOT NULL DEFAULT \'plugin\' COMMENT \'plugin/template\',
                `market_id` INT UNSIGNED DEFAULT NULL COMMENT \'来源 app_market.id\',
                `paid_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'实付金额 ×1000000\',
                `balance_log_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'用户余额流水ID\',
                `purchased_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'购买时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_app_type` (`merchant_id`, `app_code`, `type`),
                KEY `idx_market_id` (`market_id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户已购应用记录\'',
            $prefix . 'app_purchase'
        ));

        // 应用订单表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_no` VARCHAR(32) NOT NULL COMMENT \'应用订单号\',
                `merchant_id` INT UNSIGNED NOT NULL COMMENT \'商户ID\',
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'购买用户ID\',
                `market_id` INT UNSIGNED NOT NULL COMMENT \'app_market.id\',
                `app_code` VARCHAR(64) NOT NULL COMMENT \'应用编码\',
                `type` VARCHAR(20) NOT NULL DEFAULT \'plugin\' COMMENT \'plugin/template\',
                `app_title` VARCHAR(150) NOT NULL DEFAULT \'\' COMMENT \'应用标题快照\',
                `amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'订单金额 ×1000000\',
                `pay_method` VARCHAR(20) NOT NULL DEFAULT \'balance\' COMMENT \'支付方式\',
                `status` VARCHAR(20) NOT NULL DEFAULT \'paid\' COMMENT \'订单状态\',
                `balance_log_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'关联余额流水ID\',
                `before_balance` BIGINT NOT NULL DEFAULT 0 COMMENT \'扣款前余额 ×1000000\',
                `after_balance` BIGINT NOT NULL DEFAULT 0 COMMENT \'扣款后余额 ×1000000\',
                `note` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'备注\',
                `paid_at` DATETIME DEFAULT NULL COMMENT \'支付时间\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_merchant_time` (`merchant_id`, `created_at`),
                KEY `idx_user_time` (`user_id`, `created_at`),
                KEY `idx_market_id` (`market_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'应用订单\'',
            $prefix . 'app_order'
        ));

        // 附件表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'上传用户ID\',
                `file_name` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'原始文件名\',
                `file_path` VARCHAR(512) NOT NULL DEFAULT \'\' COMMENT \'相对路径\',
                `file_url` VARCHAR(512) NOT NULL DEFAULT \'\' COMMENT \'访问URL\',
                `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'文件大小（字节）\',
                `file_ext` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'扩展名\',
                `mime_type` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'MIME 类型\',
                `md5` CHAR(32) DEFAULT NULL COMMENT \'文件MD5（用于去重）\',
                `driver` VARCHAR(32) NOT NULL DEFAULT \'local\' COMMENT \'存储驱动\',
                `context` VARCHAR(64) NOT NULL DEFAULT \'default\' COMMENT \'业务场景\',
                `context_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'场景关联ID\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_context` (`user_id`, `context`),
                KEY `idx_context` (`context`, `context_id`),
                KEY `idx_md5` (`md5`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'附件库\'',
            $prefix . 'attachment'
        ));

        // 博客评论表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `blog_id` INT UNSIGNED NOT NULL COMMENT \'文章ID\',
                `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'评论用户ID\',
                `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'父评论ID，0=顶级评论\',
                `reply_user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'被回复用户ID\',
                `content` TEXT NOT NULL COMMENT \'评论内容\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'0=待审 1=通过 2=拒绝\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL COMMENT \'软删除时间\',
                PRIMARY KEY (`id`),
                KEY `idx_blog_parent_status` (`blog_id`, `parent_id`, `status`),
                KEY `idx_parent_status` (`parent_id`, `status`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_deleted_at` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'博客评论\'',
            $prefix . 'blog_comment'
        ));

        // 博客标签关联表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `blog_id` INT UNSIGNED NOT NULL COMMENT \'文章ID\',
                `tag_id` INT UNSIGNED NOT NULL COMMENT \'标签ID\',
                PRIMARY KEY (`blog_id`, `tag_id`),
                KEY `idx_tag_id` (`tag_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'博客文章-标签关联\'',
            $prefix . 'blog_tag_relation'
        ));

        // 友情链接表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(100) NOT NULL COMMENT \'链接名称\',
                `url` VARCHAR(500) NOT NULL COMMENT \'链接地址\',
                `image` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'Logo 图片\',
                `enabled` CHAR(1) NOT NULL DEFAULT \'y\' COMMENT \'y=启用 n=禁用\',
                `expire_time` DATETIME DEFAULT NULL COMMENT \'过期时间\',
                `description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'描述\',
                `sort` INT NOT NULL DEFAULT 0 COMMENT \'排序\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_enabled_expire` (`enabled`, `expire_time`),
                KEY `idx_sort` (`sort`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'友情链接\'',
            $prefix . 'friend_link'
        ));

        // 迁移记录表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `filename` VARCHAR(255) NOT NULL COMMENT \'迁移文件名\',
                `batch` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'迁移批次\',
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'执行时间\',
                `checksum` CHAR(64) NOT NULL DEFAULT \'\' COMMENT \'文件校验\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_filename` (`filename`),
                KEY `idx_batch` (`batch`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'数据库迁移追踪表\'',
            $prefix . 'migrations'
        ));

        // 配置项表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type` VARCHAR(32) NOT NULL DEFAULT \'plugin\' COMMENT \'plugin/template 等\',
                `title` VARCHAR(120) NOT NULL DEFAULT \'\' COMMENT \'插件名/模板名\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'作用域商户ID，0=主站\',
                `name` VARCHAR(120) NOT NULL COMMENT \'配置键\',
                `content` MEDIUMTEXT COMMENT \'配置值\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_scope_name` (`type`, `title`, `merchant_id`, `name`),
                KEY `idx_type_title` (`type`, `title`),
                KEY `idx_merchant` (`merchant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'插件/模板配置存储\'',
            $prefix . 'options'
        ));

        // 系统日志表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `level` VARCHAR(16) NOT NULL DEFAULT \'info\' COMMENT \'info/warning/error\',
                `type` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'业务类型\',
                `action` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'动作\',
                `message` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'日志摘要\',
                `detail` TEXT COMMENT \'详细信息（JSON）\',
                `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'操作用户ID\',
                `username` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'操作用户名\',
                `ip` VARCHAR(45) NOT NULL DEFAULT \'\' COMMENT \'请求IP\',
                `user_agent` VARCHAR(512) NOT NULL DEFAULT \'\' COMMENT \'客户端UA\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_level` (`level`),
                KEY `idx_type` (`type`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'系统日志\'',
            $prefix . 'system_log'
        ));

        // 用户等级表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(50) NOT NULL COMMENT \'等级名称\',
                `level` INT UNSIGNED NOT NULL COMMENT \'等级数值\',
                `discount` BIGINT NOT NULL DEFAULT 10000000 COMMENT \'折扣（展示值×1000000）\',
                `self_open_price` BIGINT NOT NULL DEFAULT 0 COMMENT \'自助开通价（展示值×1000000）\',
                `unlock_exp` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'解锁经验\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'备注\',
                `enabled` CHAR(1) NOT NULL DEFAULT \'y\' COMMENT \'y=启用 n=禁用\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_name` (`name`),
                UNIQUE KEY `uk_level` (`level`),
                KEY `idx_enabled` (`enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户等级\'',
            $prefix . 'user_levels'
        ));
    }
    private function setupGoodsModule(): void
    {
        $prefix = Database::prefix();

        // 商品主表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `owner_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=主站，>0=商户自建（owner user_id）',
            `category_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `category_source` VARCHAR(16) NOT NULL DEFAULT 'main' COMMENT 'main/merchant',
            `title` VARCHAR(255) NOT NULL DEFAULT '',
            `code` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '商品唯一编码',
            `intro` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '简介',
            `cover_images` TEXT COMMENT 'JSON 数组',
            `min_price` BIGINT NOT NULL DEFAULT 0 COMMENT '最低价 ×1000000（缓存）',
            `max_price` BIGINT NOT NULL DEFAULT 0 COMMENT '最高价 ×1000000（缓存）',
            `total_stock` INT NOT NULL DEFAULT 0 COMMENT '总库存缓存',
            `views_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_on_sale` TINYINT(1) NOT NULL DEFAULT 1,
            `is_recommended` TINYINT(1) NOT NULL DEFAULT 0,
            `is_top_category` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '分类置顶',
            `api_enabled` TINYINT(1) DEFAULT NULL COMMENT 'NULL/1=允许API下单 0=禁止',
            `jump_url` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '外链（可选）',
            `goods_type` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '商品类型（插件注册）',
            `plugin_data` MEDIUMTEXT COMMENT '插件数据 JSON',
            `configs` MEDIUMTEXT COMMENT '配置 JSON',
            `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=正常 0=禁用',
            `sort` INT NOT NULL DEFAULT 100,
            `deleted_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_owner_sale` (`owner_id`, `is_on_sale`, `deleted_at`),
            KEY `idx_category` (`category_id`),
            KEY `idx_goods_type` (`goods_type`),
            KEY `idx_deleted` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品表'");

        // 商品规格表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_spec` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `goods_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '规格名（单规格可为空）',
            `price` BIGINT NOT NULL DEFAULT 0 COMMENT '售价 ×1000000',
            `cost_price` BIGINT NOT NULL DEFAULT 0 COMMENT '成本价 ×1000000（可选）',
            `market_price` BIGINT NOT NULL DEFAULT 0 COMMENT '划线价 ×1000000（可选）',
            `stock` INT NOT NULL DEFAULT 0,
            `sold_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `spec_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '规格编号',
            `tags` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '标签（逗号分隔）',
            `min_buy` INT UNSIGNED NOT NULL DEFAULT 1,
            `max_buy` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=不限',
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用',
            `sort` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_goods` (`goods_id`, `status`),
            KEY `idx_default` (`goods_id`, `is_default`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品规格'");

        // 商品规格维度表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_spec_dim` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `goods_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(50) NOT NULL DEFAULT '',
            `sort` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_goods_sort` (`goods_id`, `sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='规格维度'");

        // 商品规格值表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_spec_value` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dim_id` INT UNSIGNED NOT NULL,
            `goods_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(50) NOT NULL DEFAULT '',
            `cover_image` VARCHAR(512) NOT NULL DEFAULT '',
            `sort` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_dim_sort` (`dim_id`, `sort`),
            KEY `idx_goods` (`goods_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='规格维度值'");

        // 商品规格组合表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_spec_combo` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `goods_id` INT UNSIGNED NOT NULL,
            `spec_id` INT UNSIGNED NOT NULL,
            `combo_hash` CHAR(32) NOT NULL DEFAULT '' COMMENT 'value_ids 的 md5',
            `combo_text` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '展示文本（如 红/64G）',
            `value_ids` TEXT COMMENT 'JSON 数组（维度值 id 列表）',
            `stock` INT NOT NULL DEFAULT 0 COMMENT '冗余库存（可选）',
            `sort` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_goods_hash` (`goods_id`, `combo_hash`),
            KEY `idx_goods_sort` (`goods_id`, `sort`),
            KEY `idx_spec` (`spec_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SKU 组合映射'");

        // 商品标签表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_tag` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(50) NOT NULL DEFAULT '',
            `color` VARCHAR(20) NOT NULL DEFAULT '',
            `sort` INT NOT NULL DEFAULT 0,
            `goods_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '冗余计数',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_name` (`name`),
            KEY `idx_sort` (`sort`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品标签'");

        // 商品标签关联表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_tag_relation` (
            `goods_id` INT UNSIGNED NOT NULL,
            `tag_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`goods_id`, `tag_id`),
            KEY `idx_tag` (`tag_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品标签关联'");

        // 商品会员等级价格表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_price_level` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `spec_id` INT UNSIGNED NOT NULL,
            `level_id` INT UNSIGNED NOT NULL,
            `price` BIGINT NOT NULL DEFAULT 0 COMMENT '售价 ×1000000',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_spec_level` (`spec_id`, `level_id`),
            KEY `idx_level` (`level_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='规格会员等级价'");

        // 商品指定用户价格表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_price_user` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `spec_id` INT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `price` BIGINT NOT NULL DEFAULT 0 COMMENT '售价 ×1000000',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_spec_user` (`spec_id`, `user_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='规格指定用户价'");

        // 虚拟商品卡密表
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_virtual_card` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `goods_id` INT UNSIGNED NOT NULL COMMENT '所属商品ID',
            `spec_id` INT UNSIGNED DEFAULT NULL COMMENT '所属规格ID（可选，关联 goods_spec.id）',
            `card_no` TEXT NOT NULL COMMENT '卡号/卡密内容',
            `card_pwd` TEXT DEFAULT NULL COMMENT '卡密密码（可选）',
            `price` DECIMAL(10,2) DEFAULT NULL COMMENT '采购价格（可选）',
            `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态：1=可用，0=已售出，2=已作废',
            `order_id` INT UNSIGNED DEFAULT NULL COMMENT '关联订单ID',
            `order_goods_id` INT UNSIGNED DEFAULT NULL COMMENT '关联订单商品记录ID',
            `sold_at` DATETIME DEFAULT NULL COMMENT '售出时间',
            `remark` VARCHAR(255) DEFAULT NULL COMMENT '备注',
            `sell_priority` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售优先级（值越大越优先）',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_goods` (`goods_id`),
            KEY `idx_goods_status` (`goods_id`, `status`),
            KEY `idx_spec` (`spec_id`),
            KEY `idx_status` (`status`),
            KEY `idx_order` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='虚拟商品（卡密）库存表'");
    }
}
