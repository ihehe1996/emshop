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

    /**
     * 连接店铺
    */
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
        $list = $resp['data'] ?? [];
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        $seenRef = [];
        foreach ($list as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $categoryName = (string) ($cat['name'] ?? '');

            // 兼容不同版本目录结构：children / commodity / items
            $items = [];
            if (!empty($cat['children']) && is_array($cat['children'])) {
                $items = $cat['children'];
            } elseif (!empty($cat['commodity']) && is_array($cat['commodity'])) {
                $items = $cat['commodity'];
            } elseif (!empty($cat['items']) && is_array($cat['items'])) {
                $items = $cat['items'];
            } elseif (!empty($cat['code'])) {
                // 兼容某些实现直接返回平铺商品数组
                $items = [$cat];
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $ref = trim((string) ($item['code'] ?? ''));
                if ($ref === '' || isset($seenRef[$ref])) {
                    continue;
                }
                // 不可售商品过滤：关闭/隐藏/API关闭均不导入
                if (array_key_exists('status', $item) && (int) ($item['status'] ?? 0) !== 1) {
                    continue;
                }
                if (array_key_exists('api_status', $item) && (int) ($item['api_status'] ?? 0) !== 1) {
                    continue;
                }
                if ((int) ($item['hide'] ?? 0) === 1) {
                    continue;
                }
                $seenRef[$ref] = true;
                $out[] = $this->normalizeItem($item, $categoryName);
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
        $payload = ['code' => $ref];
        // V3 多维规格库存查询：透传 sku 数组（如 ['颜色'=>'黑','容量'=>'256G']）
        if (is_array($skuId) && !empty($skuId['sku']) && is_array($skuId['sku'])) {
            $payload['sku'] = $skuId['sku'];
        }
        $resp = $this->call('/?s=/shared/commodity/stock', $payload);
        // print_r($resp); die;
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
        $payload = array_merge([
            'shared_code' => $ref,
            'num'         => $quantity,
            'contact'     => (string) ($extra['contact'] ?? 'emshop@auto'),
            'pay_id'      => (int) ($extra['pay_id'] ?? 1), // 上游支付方式 id；通常 1 = 余额
        ], $extra['sku_fields'] ?? []);
        if (!empty($extra['trade_no'])) {
            $payload['trade_no'] = (string) $extra['trade_no'];
        }
        if (!empty($extra['sku_id'])) {
            // 部分 V3 对接端支持 sku_id / category 其中一种；都透传以提高兼容性
            $payload['sku_id'] = (int) $extra['sku_id'];
            if (!isset($payload['category'])) {
                $payload['category'] = (int) $extra['sku_id'];
            }
        }

        $resp = $this->call('/?s=/shared/commodity/trade', $payload);

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
        $skuRows = $this->parseConfigSkuRows($item);
        return [
            'ref'          => (string) ($item['code'] ?? ''),
            'name'         => (string) ($item['name'] ?? ''),
            'category'     => $categoryName,
            'price'        => (float) ($item['user_price'] ?? $item['price'] ?? 0), // 用户等级价优先
            'stock'        => (int)   ($item['stock'] ?? 0),
            'delivery_way' => (int)   ($item['delivery_way'] ?? 0),  // 0 自动 1 人工
            'config_raw'   => is_array($item['config'] ?? null)
                ? json_encode($item['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) ($item['config'] ?? ''),
            'sku'          => $skuRows,
            'raw'          => $item,
        ];
    }

    /**
     * 解析 V3 config.sku 多维规格矩阵，展开为 SKU 组合列表。
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseConfigSkuRows(array $item): array
    {
        // print_r($item); die;
        $cfg = $item['config'] ?? null;
        if (is_string($cfg) && trim($cfg) !== '') {
            $decoded = json_decode($cfg, true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }
        if (!is_array($cfg) || empty($cfg['sku']) || !is_array($cfg['sku'])) {
            return [];
        }

        $dims = [];
        foreach ($cfg['sku'] as $dimName => $options) {
            if (!is_array($options)) {
                continue;
            }
            $cleanOpts = [];
            foreach ($options as $optName => $premium) {
                $name = trim((string) $optName);
                if ($name === '') {
                    continue;
                }
                $cleanOpts[] = ['name' => $name, 'premium' => (float) $premium];
            }
            if ($cleanOpts !== []) {
                $dims[] = ['name' => (string) $dimName, 'options' => $cleanOpts];
            }
        }
        if ($dims === []) {
            return [];
        }

        $basePrice = (float) ($item['user_price'] ?? $item['price'] ?? 0);
        $stock = max(0, (int) ($item['stock'] ?? 0));
        // print_r($stock); 
        $rows = [];
        $combos = [[]];
        foreach ($dims as $dim) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($dim['options'] as $opt) {
                    $c = $combo;
                    $c[] = [
                        'dim' => (string) ($dim['name'] ?? ''),
                        'name' => (string) ($opt['name'] ?? ''),
                        'premium' => (float) ($opt['premium'] ?? 0),
                    ];
                    $next[] = $c;
                }
            }
            $combos = $next;
        }

        foreach ($combos as $combo) {
            $parts = [];
            $skuPayload = [];
            $premiumSum = 0.0;
            foreach ($combo as $part) {
                $dim = (string) ($part['dim'] ?? '');
                $opt = (string) ($part['name'] ?? '');
                if ($dim === '' || $opt === '') {
                    continue;
                }
                $parts[] = $opt;
                $skuPayload[$dim] = $opt;
                $premiumSum += (float) ($part['premium'] ?? 0);
            }
            if ($skuPayload === []) {
                continue;
            }
            $price = $basePrice + $premiumSum;
            if ($price < 0) {
                $price = 0;
            }
            $rows[] = [
                'sku_id' => null,
                'name'   => implode(' / ', $parts),
                'price'  => $price,
                'stock'  => $stock,
                'sku_fields' => ['sku' => $skuPayload],
            ];
        }
        return $rows;
    }
}
