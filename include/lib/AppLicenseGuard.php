<?php

declare(strict_types=1);

/**
 * 应用授权过滤器。
 *
 * 把"本地扫到的插件/模板列表"按中心服务的购买/注册记录裁剪：
 *   - 系统内置（SYSTEM_APPS，如 default 模板）→ 直接放行
 *   - 主站（scope=1）：只走 /api/app_purchased.php
 *       服务端会把"未在 app 表里注册的应用名"也归类为"已购买"返回 —— 这是有意设计的
 *       fallback：让站长本地自定义/内部应用也能跑。主站维持这一行为。
 *   - 商户（scope=2）：appPurchased ∩ appLatestVersions（服务端 app 表真实注册过的）
 *       商户站不允许"本地随便放一个插件目录就跑"，必须中心服务认可的应用才上架。
 *
 * 调用方：
 *   /admin/plugin.php          member_code = '', scope = 1, type = 'plugin'
 *   /admin/template.php        member_code = '', scope = 1, type = 'template'
 *   /user/merchant/plugin.php  member_code = {商户主 user.invite_code}, scope = 2, type = 'plugin'
 *   /user/merchant/theme.php   member_code = {商户主 user.invite_code}, scope = 2, type = 'template'
 *
 * 失败策略：
 *   - 中心服务不可达 / 超时 / 报错 → 不吃异常，错误消息通过 out-param $errorOut 透出；
 *     此时除 SYSTEM_APPS 外都暂不放行，避免误把未授权的应用露出来
 *   - 调用方决定上层展示（通常是返回空列表 + 顶部告警条）
 */
final class AppLicenseGuard
{
    /**
     * 系统内置应用白名单：不送中心服务校验，所有租户永远可见。
     * 目前只有 "default" 模板 —— 是系统默认模板、非购买产品。
     */
    public const SYSTEM_APPS = ['default'];

    /**
     * appLatestVersions 单次请求最多 50 个 name，超出需分批。
     */
    private const VERSIONS_BATCH_SIZE = 50;

    /**
     * 过滤本地"插件/模板"集合，只保留：服务端已注册 + 通过购买/scope 校验的。
     *
     * @param array<string, array<string, mixed>> $localItems  key = name_en，value = 已解析的 header 数组
     * @param string                              $memberCode  主站传 ''；商户传 owner user 的 invite_code
     * @param int                                 $scope       1=主站 / 2=商户
     * @param string                              $type        'plugin' / 'template'，用于服务端注册性查询
     * @param string|null                         $errorOut    中心服务异常消息（出参；null = 无异常）
     * @return array<string, array<string, mixed>>             过滤后的子集，保持 key 不变
     */
    public static function filter(array $localItems, string $memberCode, int $scope, string $type, ?string &$errorOut = null): array
    {
        $errorOut = null;
        if ($localItems === []) return [];

        // 1. SYSTEM_APPS 直通（default 模板等系统内置，不走购买流程）
        $bypassItems = [];
        $needVerify  = [];
        $systemSet   = array_flip(self::SYSTEM_APPS);
        foreach ($localItems as $name => $meta) {
            if (isset($systemSet[(string) $name])) {
                $bypassItems[$name] = $meta;
            } else {
                $needVerify[] = (string) $name;
            }
        }
        if ($needVerify === []) {
            return $bypassItems;
        }

        // 2. 按 scope 分流：
        //    - 主站（scope=1）只跑 appPurchased，保留服务端"未注册应用 = 已购买"的 fallback，
        //      让站长本地自定义/内部应用能跑
        //    - 商户（scope=2）需要 appPurchased ∩ appLatestVersions，强制走中心服务白名单
        //    任一接口出错 → 不吃异常，仅保留 SYSTEM_APPS，把错误消息透出去
        try {
            $purchased = LicenseClient::appPurchased($needVerify, $memberCode, $scope);
            $registered = ($scope === 2) ? self::queryRegisteredNames($needVerify, $type) : null;
        } catch (Throwable $e) {
            $errorOut = $e->getMessage();
            return $bypassItems;
        }

        $purchasedMap  = array_flip($purchased);
        $registeredMap = $registered === null ? null : array_flip($registered);

        // 3. 命中规则：主站只看 purchased；商户两边都要命中
        $result = $bypassItems;
        foreach ($needVerify as $name) {
            if (!isset($purchasedMap[$name]) || !isset($localItems[$name])) {
                continue;
            }
            if ($registeredMap !== null && !isset($registeredMap[$name])) {
                // 商户：服务端没注册过 → 拒绝
                continue;
            }
            $result[$name] = $localItems[$name];
        }
        return $result;
    }

    /**
     * 调 appLatestVersions 拿"服务端注册过的应用名集合"。超过 50 个分批，结果合并。
     *
     * @param array<int,string> $names
     * @param string $type 'plugin' / 'template'
     * @return array<int,string> 注册过的 name_en 列表（未注册的不会出现）
     */
    private static function queryRegisteredNames(array $names, string $type): array
    {
        if (!in_array($type, ['plugin', 'template'], true)) {
            // 类型非法直接当全不存在，避免误放行
            return [];
        }
        $registered = [];
        foreach (array_chunk($names, self::VERSIONS_BATCH_SIZE) as $batch) {
            $map = LicenseClient::appLatestVersions($batch, $type);
            foreach ($map as $name => $_meta) {
                $registered[] = (string) $name;
            }
        }
        return $registered;
    }

    /**
     * 给定一个商户行，返回其对应的 member_code（= 商户主用户 invite_code）。
     * 找不到时返回空串（调用方应按"未授权"处理，避免泄漏主站应用给非法商户）。
     *
     * @param array<string, mixed> $merchant   em_merchant 行
     */
    public static function memberCodeForMerchant(array $merchant): string
    {
        $userId = (int) ($merchant['user_id'] ?? 0);
        if ($userId <= 0) return '';
        $row = Database::fetchOne(
            'SELECT `invite_code` FROM `' . Database::prefix() . 'user` WHERE `id` = ? LIMIT 1',
            [$userId]
        );
        return $row ? (string) ($row['invite_code'] ?? '') : '';
    }
}
