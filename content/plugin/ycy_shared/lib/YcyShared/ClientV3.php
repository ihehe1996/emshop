<?php

declare(strict_types=1);

namespace YcyShared;

use RuntimeException;

/**
 * V3（异次元 / ACG-FAKA 3.x）协议客户端。
 *
 * 约定：
 *   - 鉴权：app_id + app_key + sign 走 POST body
 *   - 路由：/?s=/shared/{group}/{action}
 *   - 商品主键：16 位 code
 *   - 响应：{code, message, data}（data 为数组/对象/数字/null，按接口不同）
 */
final class ClientV3 extends Client
{
    /**
     * 封装带签的 POST。
     */
    private function call(string $path, array $payload = []): array
    {
        $payload['app_id'] = $this->appId;
        $payload['sign']   = $this->sign($payload);

        $resp = $this->post($path, $payload);

        $code = (int) ($resp['code'] ?? -1);
        if ($code !== 200 && $code !== 1 && $code !== 0) {
            // 不同接口成功 code 可能 200 / 1 / 0，这里放宽（按 success 字段兜底）
            if (empty($resp['success']) && empty($resp['data'])) {
                throw new RuntimeException((string) ($resp['message'] ?? '上游请求失败'));
            }
        }
        return $resp;
    }

    public function connect(): array
    {
        $resp = $this->call('/?s=/shared/authentication/connect');
        $data = $resp['data'] ?? [];
        return [
            'username' => (string) ($data['shopName'] ?? $data['username'] ?? ''),
            'balance'  => (float)  ($data['balance'] ?? 0),
        ];
    }

    public function fetchItems(): array
    {
        $resp = $this->call('/?s=/shared/commodity/items');
        // print_r($resp); die;
        $list = $resp['data'] ?? [];
        $out  = [];
        foreach ($list as $cat) {
            foreach (($cat['commodity'] ?? $cat['items'] ?? []) as $item) {
                $out[] = $this->normalizeItem($item, (string) ($cat['name'] ?? ''));
            }
        }
        return $out;
    }

    public function fetchItem(string $ref): array
    {
        $resp = $this->call('/?s=/shared/commodity/item', ['code' => $ref]);
        return $this->normalizeItem($resp['data'] ?? [], '');
    }

    public function fetchStock(string $ref, $skuId = null): int
    {
        $resp = $this->call('/?s=/shared/commodity/stock', ['code' => $ref]);
        return (int) (($resp['data']['stock'] ?? 0) ?: 0);
    }

    public function queryOrder(string $tradeNo): array
    {
        if ($tradeNo === '') return ['found' => false];
        try {
            $resp = $this->call('/?s=/shared/commodity/query', ['tradeNo' => $tradeNo]);
        } catch (RuntimeException $e) {
            // 接口常在"订单不存在"时走异常分支
            if (mb_stripos($e->getMessage(), '不存在') !== false) return ['found' => false];
            throw $e;
        }
        $data = $resp['data'] ?? [];
        if (empty($data)) return ['found' => false];
        return [
            'found'    => true,
            'contents' => (string) ($data['secret'] ?? $data['contents'] ?? ''),
            'status'   => (int)    ($data['status'] ?? 0),
            'raw'      => $resp,
        ];
    }

    public function placeOrder(string $ref, int $quantity, array $extra = []): array
    {
        $resp = $this->call('/?s=/shared/commodity/trade', array_merge([
            'shared_code' => $ref,
            'num'         => $quantity,
            'contact'     => (string) ($extra['contact'] ?? 'emshop@auto'),
            'pay_id'      => (int) ($extra['pay_id'] ?? 1), // 上游支付方式 id；通常 1 = 余额
        ], $extra['sku_fields'] ?? []));

        $data = $resp['data'] ?? [];
        return [
            'trade_no' => (string) ($data['trade_no'] ?? ''),
            'contents' => (string) ($data['secret'] ?? ''),
            'status'   => (int)    ($data['status'] ?? 0),
            'raw'      => $resp,
        ];
    }

    /**
     * 归一化 V3 item 结构。
     */
    private function normalizeItem(array $item, string $categoryName): array
    {
        return [
            'ref'          => (string) ($item['code'] ?? ''),
            'name'         => (string) ($item['name'] ?? ''),
            'category'     => $categoryName,
            'price'        => (float) ($item['user_price'] ?? $item['price'] ?? 0), // 用户等级价优先
            'stock'        => (int)   ($item['stock'] ?? 0),
            'delivery_way' => (int)   ($item['delivery_way'] ?? 0),  // 0 自动 1 人工
            'config_raw'   => (string) ($item['config'] ?? ''),     // INI 格式 SKU 配置
            'sku'          => [], // V3 的 SKU 需要解析 config，解析交给 SyncService
            'raw'          => $item,
        ];
    }
}
