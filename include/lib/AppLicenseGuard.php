<?php

declare(strict_types=1);

/**
 * 应用授权过滤器。
 *
 * 把"本地扫到的插件/模板列表"按中心服务的购买记录裁剪：
 *   - 本地头部注释标了 Custom: true 的 → 自定义应用，直接放行
 *   - 其余交给 LicenseClient::appPurchased() 实时验证，只保留"已购买或免费可用"的
 *
 * 调用方：
 *   /admin/plugin.php         member_code = '', scope = 1        （主站）
 *   /admin/template.php       member_code = '', scope = 1        （主站）
 *   /user/merchant/plugin.php member_code = {商户主 user.invite_code}, scope = 2
 *   /user/merchant/theme.php  member_code = {商户主 user.invite_code}, scope = 2
 *
 * 失败策略（见用户约定 Q1）：
 *   - 中心服务不可达 / 超时 / 报错 → 抛 RuntimeException，不吃异常
 *   - 调用方要捕获并决定上层展示（通常是返回空列表 + 顶部告警条）
 *   - Custom 应用不受网络影响，永远可见
 */
final class AppLicenseGuard
{
    /**
     * 系统内置应用白名单：不送中心服务校验，所有租户永远可见。
     * 目前只有 "default" 模板 —— 是系统默认模板、非购买产品。
     */
    public const SYSTEM_APPS = ['default'];

    /**
     * 过滤本地"插件/模板"集合，只保留当前租户有权限的。
     *
     * 降级策略：
     *   - Custom / 系统内置（SYSTEM_APPS）永远返回，不依赖网络
     *   - 中心服务校验失败（不可达 / 未激活 emkey / 超时）→ 跳过需校验那批；
     *     错误消息通过 out-param $errorOut 透出，调用方决定要不要展示告警条
     *
     * @param array<string, array<string, mixed>> $localItems  key = name_en，value = 已解析的 header 数组
     * @param string                              $memberCode  主站传 ''；商户传 owner user 的 invite_code
     * @param int                                 $scope       1=主站 / 2=商户；服务端据此过滤 app.scope IN (0, :scope)
     * @param string|null                         $errorOut    中心服务异常消息（出参；null = 无异常）
     * @return array<string, array<string, mixed>>             过滤后的子集，保持 key 不变
     */
    public static function filter(array $localItems, string $memberCode, int $scope, ?string &$errorOut = null): array
    {
        $errorOut = null;
        if ($localItems === []) return [];

        // 1. Custom 本地应用 + 系统内置应用（如 default 模板）直接放行，不参与中心服务校验
        $customItems = [];
        $needVerify  = [];
        $systemSet   = array_flip(self::SYSTEM_APPS);
        foreach ($localItems as $name => $meta) {
            if (!empty($meta['custom']) || isset($systemSet[(string) $name])) {
                $customItems[$name] = $meta;
            } else {
                $needVerify[] = (string) $name;
            }
        }

        // 2. 没有需校验的 → 直接返回直通项
        if ($needVerify === []) {
            return $customItems;
        }

        // 3. 把剩余名字扔给中心服务；失败就只保留直通项 + 上抛错误消息
        try {
            $authorized = LicenseClient::appPurchased($needVerify, $memberCode, $scope);
        } catch (Throwable $e) {
            $errorOut = $e->getMessage();
            return $customItems;
        }
        $authorizedMap = array_flip($authorized);

        // 4. 组装：直通项 + 授权通过的
        $result = $customItems;
        foreach ($needVerify as $name) {
            if (isset($authorizedMap[$name]) && isset($localItems[$name])) {
                $result[$name] = $localItems[$name];
            }
        }
        return $result;
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
