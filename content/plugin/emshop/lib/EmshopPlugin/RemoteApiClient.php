<?php

declare(strict_types=1);

namespace EmshopPlugin;

/**
 * 调用对方 EMSHOP 前台 API（与 ApiController 签名规则一致）。
 *
 * HTTPS 不校验对端证书（便于内网、自签名、开发环境）；生产环境请尽量使用正规证书。
 */
final class RemoteApiClient
{
    /**
     * 与 ApiController::sign 相同：排除 sign/sign_type/c/a/act，空值不参与，末尾追加 SECRET。
     *
     * @param array<string, mixed> $params
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign'], $params['sign_type'], $params['c'], $params['a'], $params['act']);
        ksort($params, SORT_STRING);

        $parts = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $parts[] = $k . '=' . (string) $v;
        }

        return strtolower(md5(implode('&', $parts) . $secret));
    }

    /**
     * 请求对方 `act=base_info`，用于保存对接站点时自动取站点名等。
     *
     * @return array<string, mixed> data 段：site_name / account / email / mobile / balance
     */
    public static function fetchBaseInfo(string $baseUrl, string $appid, string $secret): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/') . '/';
        $appidInt = (int) preg_replace('/\D/', '', $appid);
        if ($appidInt <= 0) {
            throw new \RuntimeException('appid 无效');
        }
        $secret = trim($secret);
        if ($secret === '') {
            throw new \RuntimeException('SECRET 不能为空');
        }

        $params = [
            'appid'     => $appidInt,
            'timestamp' => time(),
            'sign_type' => 'MD5',
        ];
        $params['sign'] = self::sign($params, $secret);

        $url = $baseUrl . '?c=api&act=base_info';
        $body = http_build_query($params);
        $raw = self::httpPost($url, $body);
        if ($raw === '' || $raw === false) {
            throw new \RuntimeException('对方接口无响应或网络失败');
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('对方返回非 JSON');
        }
        $code = (int) ($json['code'] ?? 0);
        if ($code !== 200) {
            $msg = trim((string) ($json['msg'] ?? '请求失败'));
            throw new \RuntimeException($msg !== '' ? $msg : '对方接口返回错误');
        }
        $data = $json['data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException('对方返回数据格式异常');
        }

        return $data;
    }

    private static function httpPost(string $url, string $body): string|false
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $out = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0 || !is_string($out)) {
                return false;
            }
            return $out;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 15.0,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $out = @file_get_contents($url, false, $ctx);
        return is_string($out) ? $out : false;
    }
}
