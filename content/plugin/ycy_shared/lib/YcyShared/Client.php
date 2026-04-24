<?php

declare(strict_types=1);

namespace YcyShared;

use RuntimeException;

/**
 * 上游 API 客户端抽象基类。
 *
 * V3 / V4 实现差异：
 *   V3（异次元）— auth 走 POST body，商品 ID 是 16 位 code，路由前缀 /?s=/shared/commodity/
 *   V4（萌次元）— auth 走 HTTP Header，商品 ID 是数字 id，路由前缀 /plugin/open-api/
 *
 * 共用方法（子类各自实现）：
 *   connect()      验证鉴权 → ['username', 'balance']
 *   fetchItems()   拉目录 → [['ref','name','price','stock','sku','...']...]
 *   fetchStock($ref|$sku)  库存数
 *   placeOrder(...)  代付 → ['trade_no', 'contents']
 *
 * 签名算法（两版一致）：
 *   sign = md5(urldecode(http_build_query(ksort($params_without_sign))) . '&key=' . $app_key)
 *   其中 params 不含 sign 自身，但一般包含 app_id。
 */
abstract class Client
{
    /** @var array<string, mixed> */
    protected array $site;
    protected string $host;
    protected string $appId;
    protected string $appKey;

    public function __construct(array $site)
    {
        if (empty($site['host']) || empty($site['app_id']) || empty($site['app_key'])) {
            throw new RuntimeException('站点未配置完整（host / app_id / app_key 必填）');
        }
        $this->site = $site;
        $this->host = rtrim((string) $site['host'], '/');
        $this->appId = (string) $site['app_id'];
        $this->appKey = (string) $site['app_key'];
    }

    /**
     * 工厂：按站点 version 返回对应实现。
     */
    public static function make(array $site): self
    {
        $version = (string) ($site['version'] ?? 'v3');
        if ($version === 'v4') return new ClientV4($site);
        return new ClientV3($site);
    }

    /**
     * 生成上游签名（两版通用）。
     * @param array<string, mixed> $params
     */
    protected function sign(array $params): string
    {
        unset($params['sign']);
        $filtered = array_filter($params, static fn($v) => $v !== null && $v !== '');
        ksort($filtered);
        $str = urldecode(http_build_query($filtered));
        return md5($str . '&key=' . $this->appKey);
    }

    /**
     * 发送 POST 请求；错误抛 RuntimeException。
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    protected function post(string $path, array $post = [], array $extraHeaders = [], int $timeout = 15): array
    {
        $url = $this->host . $path;
        $headers = array_merge([
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: emshop-ycy-shared/' . EM_VERSION,
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('上游不可达：' . $err);
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new RuntimeException('上游返回非 JSON（HTTP ' . $code . '）：' . mb_substr((string) $body, 0, 200));
        }
        return $json;
    }

    // 子类实现 ------------------------------------------------

    /** 验证鉴权 → ['username' => string, 'balance' => float] */
    abstract public function connect(): array;

    /** 拉取目录（精简版，每项 normalize 成统一结构） */
    abstract public function fetchItems(): array;

    /** 单商品详情（按 ref） */
    abstract public function fetchItem(string $ref): array;

    /** 查询库存 */
    abstract public function fetchStock(string $ref, $skuId = null): int;

    /**
     * 下单代付：回 ['trade_no', 'contents'（卡密）, 'status']
     *
     * @param array $extra 视版本不同传入 contact / sku / card_id 等
     */
    abstract public function placeOrder(string $ref, int $quantity, array $extra = []): array;

    /**
     * 按上游 trade_no 查询已下单订单的状态和发货内容。
     * 用于代付重试前先确认"上次是否已经成功处理过"，防止重复扣款。
     *
     * 返回约定：
     *   - 订单存在且已完成：['found' => true, 'contents' => '卡密', 'status' => 1]
     *   - 订单存在但未发货：['found' => true, 'contents' => '', 'status' => 0]
     *   - 订单不存在：['found' => false]
     *
     * 实现失败时抛 RuntimeException；调用方决定降级策略。
     */
    abstract public function queryOrder(string $tradeNo): array;
}
