<?php

declare(strict_types=1);

namespace EmshopPlugin;

use Database;
use Config;
use GoodsModel;
use PermanentDeliveryException;
use RuntimeException;
use Throwable;

/**
 * emshop_remote 发货服务：根据导入时写入的 fulfillment_mode 分流。
 */
final class DeliveryService
{
    public static function handle(int $orderId, int $orderGoodsId, string $payloadJson): void
    {
        $prefix = Database::prefix();
        $og = Database::fetchOne(
            "SELECT `id`, `order_id`, `goods_id`, `spec_id`, `quantity`, `delivery_content`
               FROM `{$prefix}order_goods` WHERE `id` = ? LIMIT 1",
            [$orderGoodsId]
        );
        if (!$og) {
            throw new RuntimeException('[emshop_remote] 订单商品不存在 #' . $orderGoodsId);
        }
        if (!empty($og['delivery_content'])) {
            return; // 幂等
        }

        $goodsId = (int) ($og['goods_id'] ?? 0);
        $specId = (int) ($og['spec_id'] ?? 0);
        $qty = max(1, (int) ($og['quantity'] ?? 1));
        if ($goodsId <= 0) {
            throw new RuntimeException('[emshop_remote] 商品数据异常');
        }

        $goods = Database::fetchOne(
            "SELECT `title`, `configs`, `source_id` FROM `{$prefix}goods` WHERE `id` = ? LIMIT 1",
            [$goodsId]
        );
        if (!$goods) {
            throw new RuntimeException('[emshop_remote] 商品不存在 #' . $goodsId);
        }

        $cfg = json_decode((string) ($goods['configs'] ?? '{}'), true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $imp = $cfg['emshop_import'] ?? [];
        if (!is_array($imp)) {
            $imp = [];
        }
        $mode = (string) ($imp['fulfillment_mode'] ?? 'manual');
        if ($mode !== 'upstream_auto') {
            self::markSold($specId, $qty);
            return;
        }

        [$siteIdBySource, $remoteGoodsBySource] = self::parseSourceId((string) ($goods['source_id'] ?? ''));
        $siteId = (int) ($imp['remote_site_id'] ?? $siteIdBySource);
        $remoteGoodsId = (int) ($imp['remote_goods_id'] ?? $remoteGoodsBySource);
        if ($siteId <= 0 || $remoteGoodsId <= 0) {
            throw new PermanentDeliveryException('[emshop_remote] 导入映射缺失（remote_site_id / remote_goods_id）');
        }

        $site = RemoteSiteModel::find($siteId);
        if ($site === null || (int) ($site['enabled'] ?? 0) !== 1) {
            throw new PermanentDeliveryException('[emshop_remote] 对接站点不存在或已停用');
        }

        $remoteSpecId = self::resolveRemoteSpecId($specId);

        $createPayload = [
            'goods_id'  => $remoteGoodsId,
            'quantity'  => $qty,
        ];
        if ($remoteSpecId > 0) {
            $createPayload['spec_id'] = $remoteSpecId;
        }

        $callbackToken = bin2hex(random_bytes(16));
        $createPayload['delivery_callback_url'] = self::buildDeliveryCallbackUrl($orderGoodsId, $callbackToken);

        $order = Database::fetchOne(
            "SELECT `contact_info`, `address_info` FROM `{$prefix}order` WHERE `id` = ? LIMIT 1",
            [$orderId]
        );
        if ($order) {
            $contact = self::extractContact($order['contact_info'] ?? '');
            if ($contact !== '') {
                $createPayload['contact'] = $contact;
            }
            $address = self::extractAddress($order['address_info'] ?? '');
            if ($address !== null) {
                $createPayload['address_json'] = json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        $baseUrl = (string) ($site['base_url'] ?? '');
        $appid = (string) ($site['appid'] ?? '');
        $secret = (string) ($site['secret'] ?? '');
        if ($baseUrl === '' || $appid === '' || $secret === '') {
            throw new PermanentDeliveryException('[emshop_remote] 对接站点凭证不完整');
        }

        $resp = RemoteApiClient::createOrder($baseUrl, $appid, $secret, $createPayload);
        $upstreamOrderNo = trim((string) ($resp['order_no'] ?? ''));
        if ($upstreamOrderNo === '') {
            throw new RuntimeException('[emshop_remote] 上游未返回订单号');
        }

        $upStatus = '';
        try {
            $q = RemoteApiClient::queryOrder($baseUrl, $appid, $secret, $upstreamOrderNo);
            $upStatus = trim((string) ($q['status_name'] ?? $q['status'] ?? ''));
        } catch (Throwable $e) {
            // 查询失败不阻断主流程，避免因查询偶发错误导致反复重下
        }
        $pluginData = [
            'emshop_remote' => [
                'fulfillment_mode' => 'upstream_auto',
                'remote_site_id'   => $siteId,
                'remote_goods_id'  => $remoteGoodsId,
                'remote_spec_id'   => $remoteSpecId,
                'upstream_order_no'=> $upstreamOrderNo,
                'upstream_status'  => $upStatus,
                'callback_token'   => $callbackToken,
            ],
        ];

        Database::execute(
            "UPDATE `{$prefix}order_goods` SET `plugin_data` = ? WHERE `id` = ?",
            [json_encode($pluginData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $orderGoodsId]
        );
    }

    private static function markSold(int $specId, int $qty): void
    {
        if ($specId > 0 && $qty > 0) {
            GoodsModel::incrementSoldCount($specId, $qty);
        }
    }

    /** @return array{0:int,1:int} */
    private static function parseSourceId(string $sourceId): array
    {
        $parts = explode(':', $sourceId, 2);
        return [
            (int) ($parts[0] ?? 0),
            (int) ($parts[1] ?? 0),
        ];
    }

    private static function resolveRemoteSpecId(int $localSpecId): int
    {
        if ($localSpecId <= 0) {
            return 0;
        }
        $prefix = Database::prefix();
        $row = Database::fetchOne(
            "SELECT `configs` FROM `{$prefix}goods_spec` WHERE `id` = ? LIMIT 1",
            [$localSpecId]
        );
        if (!$row) {
            return 0;
        }
        $cfg = json_decode((string) ($row['configs'] ?? '{}'), true);
        if (!is_array($cfg)) {
            return 0;
        }
        $imp = $cfg['emshop_import'] ?? [];
        if (!is_array($imp)) {
            return 0;
        }
        return (int) ($imp['upstream_spec_id'] ?? 0);
    }

    private static function extractContact($raw): string
    {
        if (is_string($raw)) {
            $s = trim($raw);
            if ($s === '') {
                return '';
            }
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                $name = trim((string) ($decoded['name'] ?? ''));
                $phone = trim((string) ($decoded['phone'] ?? ''));
                return trim($name . ' ' . $phone);
            }
            return $s;
        }
        return '';
    }

    /** @return array<string,string>|null */
    private static function extractAddress($raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return null;
        }
        $out = [
            'recipient' => trim((string) ($d['recipient'] ?? $d['name'] ?? '')),
            'mobile'    => trim((string) ($d['mobile'] ?? $d['phone'] ?? '')),
            'province'  => trim((string) ($d['province'] ?? '')),
            'city'      => trim((string) ($d['city'] ?? '')),
            'district'  => trim((string) ($d['district'] ?? '')),
            'detail'    => trim((string) ($d['detail'] ?? '')),
        ];
        if ($out['recipient'] === '' && $out['mobile'] === '' && $out['detail'] === '') {
            return null;
        }
        return $out;
    }

    private static function buildDeliveryCallbackUrl(int $localOrderGoodsId, string $token): string
    {
        $host = self::detectCurrentHost();
        $scheme = self::detectCurrentScheme();
        if ($host === '') {
            $host = (string) (Config::get('main_domain') ?? '');
        }
        $base = rtrim($scheme . '://' . $host, '/');
        return $base . '/?c=api&act=delivery_callback'
            . '&local_order_goods_id=' . rawurlencode((string) $localOrderGoodsId)
            . '&callback_token=' . rawurlencode($token);
    }

    private static function detectCurrentHost(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') return '';
        $pos = strpos($host, ':');
        if ($pos !== false) {
            $host = substr($host, 0, $pos);
        }
        return trim($host);
    }

    private static function detectCurrentScheme(): string
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') return 'https';
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') return 'https';
        return 'http';
    }
}

