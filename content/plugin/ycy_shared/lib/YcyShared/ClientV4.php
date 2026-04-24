<?php

declare(strict_types=1);

namespace YcyShared;

use RuntimeException;

/**
 * V4（萌次元）协议客户端。
 *
 * 约定：
 *   - 鉴权：Api-Id / Api-Signature 走 HTTP Header
 *   - 路由：/plugin/open-api/{action}
 *   - 商品主键：数字 id；原生 SKU 数组
 *   - 订单 trade_no：24 位客户端生成
 */
final class ClientV4 extends Client
{
    /**
     * 生成 24 位本地 trade_no（时间戳 + 随机）。
     */
    public function genTradeNo(): string
    {
        return date('YmdHis') . mt_rand(100000, 999999) . mt_rand(1000, 9999);
    }

    private function call(string $path, array $payload = []): array
    {
        $sig = $this->sign($payload);
        $headers = [
            'Api-Id: ' . $this->appId,
            'Api-Signature: ' . $sig,
        ];
        $resp = $this->post($path, $payload, $headers);

        $code = (int) ($resp['code'] ?? -1);
        if ($code !== 200 && $code !== 1 && $code !== 0) {
            if (empty($resp['success']) && empty($resp['data'])) {
                throw new RuntimeException((string) ($resp['message'] ?? '上游请求失败'));
            }
        }
        return $resp;
    }

    public function connect(): array
    {
        $resp = $this->call('/plugin/open-api/connect');
        $data = $resp['data'] ?? [];
        return [
            'username' => (string) ($data['username'] ?? ''),
            'balance'  => (float)  ($data['balance'] ?? 0),
        ];
    }

    public function fetchItems(): array
    {
        $resp = $this->call('/plugin/open-api/items');
        $list = $resp['data'] ?? [];
        // V4 items 可能直接是平铺数组，也可能按类目分组；统一铺平
        $out = [];
        foreach ($list as $row) {
            if (isset($row['commodity']) || isset($row['items'])) {
                $cat = (string) ($row['name'] ?? '');
                foreach (($row['commodity'] ?? $row['items'] ?? []) as $item) {
                    $out[] = $this->normalizeItem($item, $cat);
                }
            } else {
                $out[] = $this->normalizeItem($row, '');
            }
        }
        return $out;
    }

    public function fetchItem(string $ref): array
    {
        $resp = $this->call('/plugin/open-api/item', ['id' => (int) $ref]);
        return $this->normalizeItem($resp['data'] ?? [], '');
    }

    public function fetchStock(string $ref, $skuId = null): int
    {
        // V4 库存按 SKU 级查询；若未传 skuId，回落到商品主 id（部分接口实现支持）
        $payload = $skuId !== null && $skuId !== '' ? ['sku_id' => (int) $skuId] : ['id' => (int) $ref];
        $resp = $this->call('/plugin/open-api/sku/stock', $payload);
        return (int) (($resp['data']['stock'] ?? 0) ?: 0);
    }

    public function queryOrder(string $tradeNo): array
    {
        // V4 接口文档里未提供独立的订单查询端点；
        // 客户端 trade_no 是幂等键，重试 placeOrder 时上游会返回原订单数据，
        // 所以这里直接返回 "未查到"，让上层调 placeOrder 走幂等重下。
        return ['found' => false];
    }

    public function placeOrder(string $ref, int $quantity, array $extra = []): array
    {
        $tradeNo = (string) ($extra['trade_no'] ?? $this->genTradeNo());
        $payload = [
            'sku_id'   => (int) ($extra['sku_id'] ?? 0),
            'quantity' => $quantity,
            'trade_no' => $tradeNo,
        ];
        if ($payload['sku_id'] <= 0) {
            throw new RuntimeException('V4 下单必须指定 sku_id');
        }
        $resp = $this->call('/plugin/open-api/trade', $payload);
        $data = $resp['data'] ?? [];
        return [
            'trade_no' => $tradeNo,
            'contents' => (string) ($data['contents'] ?? ''),
            'status'   => 1,
            'raw'      => $resp,
        ];
    }

    private function normalizeItem(array $item, string $categoryName): array
    {
        $sku = [];
        foreach (($item['sku'] ?? []) as $s) {
            $sku[] = [
                'sku_id' => (int)   ($s['id'] ?? 0),
                'name'   => (string) ($s['name'] ?? ''),
                'price'  => (float)  ($s['stock_price'] ?? $s['price'] ?? 0),
                'stock'  => (int)    ($s['stock'] ?? 0),
                'image'  => (string) ($s['picture_url'] ?? ''),
            ];
        }
        return [
            'ref'          => (string) ($item['id'] ?? ''),
            'name'         => (string) ($item['name'] ?? ''),
            'category'     => $categoryName,
            'price'        => (float) ($item['price'] ?? ($sku[0]['price'] ?? 0)),
            'stock'        => (int)   array_sum(array_column($sku, 'stock')) ?: (int) ($item['stock'] ?? 0),
            'delivery_way' => 0,
            'image'        => (string) ($item['picture_url'] ?? ''),
            'introduce'    => (string) ($item['introduce'] ?? ''),
            'sku'          => $sku,
            'raw'          => $item,
        ];
    }
}
