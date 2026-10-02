<?php

declare(strict_types=1);

namespace App\Support\Payment;

use App\Support\Logger;
use App\Support\Setting;
use RuntimeException;
use Throwable;

/**
 * 易支付（彩虹易支付通用协议）网关
 *
 * 协议要点（全部参数均来自后台系统设置，不硬编码）：
 *  - 页面跳转：POST {api_url}/submit.php（最通用，浏览器表单自动提交）
 *  - API 下单：POST {api_url}/mapi.php（返回 JSON，预留）
 *  - 异步通知：GET/POST 回调，trade_status=TRADE_SUCCESS 视为支付成功，必须返回字符串 success
 *  - 查单：GET {api_url}/api.php?act=order&pid=&key=&out_trade_no=
 *  - 退款：POST {api_url}/api.php?act=refund
 *  - 签名：参数按名称 ASCII 升序排列，剔除 sign / sign_type 与空值，
 *          拼成 a=b&c=d 后追加商户密钥，取 MD5 小写
 */
final class EpayGateway implements PaymentGateway
{
    /** HTTP 请求超时（秒） */
    private const TIMEOUT = 15;

    private string $apiUrl;

    private string $pid;

    private string $key;

    private string $signType;

    public function __construct()
    {
        $this->apiUrl   = rtrim(Setting::string('epay_api_url', ''), '/');
        $this->pid      = Setting::string('epay_pid', '');
        $this->key      = Setting::string('epay_key', '');
        $this->signType = strtoupper(Setting::string('epay_sign_type', 'MD5')) ?: 'MD5';
    }

    public function name(): string
    {
        return 'epay';
    }

    public function enabled(): bool
    {
        return Setting::bool('epay_enabled', false)
            && $this->apiUrl !== ''
            && $this->pid !== ''
            && $this->key !== '';
    }

    /**
     * 可用的支付方式（受后台开关控制，微信与支付宝可独立启停）
     *
     * @return array<string, string>
     */
    public function methods(): array
    {
        $methods = [];

        if (Setting::bool('epay_wxpay_enabled', true)) {
            $methods['wxpay'] = '微信支付';
        }

        if (Setting::bool('epay_alipay_enabled', true)) {
            $methods['alipay'] = '支付宝';
        }

        return $methods;
    }

    /**
     * @param  array<string, mixed> $order
     * @return array{mode: string, url: string, params: array<string, string>}
     */
    public function createPayment(array $order, string $payType, string $notifyUrl, string $returnUrl): array
    {
        $params = [
            'pid'          => $this->pid,
            'type'         => $payType,
            'out_trade_no' => (string) $order['order_no'],
            'notify_url'   => $notifyUrl,
            'return_url'   => $returnUrl,
            'name'         => mb_substr((string) ($order['course_title'] ?? '课程'), 0, 64),
            // 易支付金额单位为「元」，保留两位小数
            'money'        => format_money((int) $order['amount']),
            'sitename'     => Setting::string('site_name', 'CourseShop'),
        ];

        $params['sign']      = $this->sign($params);
        $params['sign_type'] = $this->signType;

        return [
            'mode'   => 'submit',
            'url'    => $this->apiUrl . '/submit.php',
            'params' => $params,
        ];
    }

    /**
     * 校验回调签名
     *
     * @param array<string, mixed> $params
     */
    public function verifySign(array $params): bool
    {
        $received = $params['sign'] ?? '';

        if (!is_string($received) || $received === '' || $this->key === '') {
            return false;
        }

        return hash_equals(strtolower($this->sign($params)), strtolower($received));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function isPaidNotify(array $params): bool
    {
        return (string) ($params['trade_status'] ?? '') === 'TRADE_SUCCESS' && $this->verifySign($params);
    }

    /**
     * 异步通知来源 IP 校验
     */
    public function notifyIpAllowed(?string $ip): bool
    {
        $whitelist = trim(Setting::string('epay_notify_ip_whitelist', ''));

        if ($whitelist === '') {
            // 未配置白名单：不拒绝（回调真实性仍由签名与金额校验保证），但记录告警提醒运维加固
            Logger::warning('易支付回调未配置 IP 白名单（epay_notify_ip_whitelist），本次仅依赖签名校验。');

            return true;
        }

        if ($ip === null || $ip === '') {
            return false;
        }

        $allowed = array_filter(array_map('trim', preg_split('/[\s,]+/', $whitelist) ?: []));

        return in_array($ip, $allowed, true);
    }

    /**
     * 主动查单
     *
     * @return array{status: string, trade_no: string, raw: array<string, mixed>}
     */
    public function queryOrder(string $orderNo): array
    {
        $url = $this->apiUrl . '/api.php?' . http_build_query([
            'act'          => 'order',
            'pid'          => $this->pid,
            'key'          => $this->key,
            'out_trade_no' => $orderNo,
        ]);

        $raw = $this->request('GET', $url);

        // code=1 表示查单成功，status=1 表示已支付
        $paid = (int) ($raw['code'] ?? 0) === 1 && (int) ($raw['status'] ?? 0) === 1;

        return [
            'status'   => $paid ? 'paid' : 'pending',
            'trade_no' => (string) ($raw['trade_no'] ?? ''),
            'raw'      => $raw,
        ];
    }

    /**
     * 申请退款
     *
     * @return array{ok: bool, message: string, raw: array<string, mixed>}
     */
    public function refund(string $orderNo, int $amount, string $reason = ''): array
    {
        $url = $this->apiUrl . '/api.php?act=refund';

        $raw = $this->request('POST', $url, [
            'pid'          => $this->pid,
            'key'          => $this->key,
            'out_trade_no' => $orderNo,
            'money'        => format_money($amount),
        ]);

        $ok = (int) ($raw['code'] ?? 0) === 1;

        return [
            'ok'      => $ok,
            'message' => (string) ($raw['msg'] ?? ($ok ? '退款申请已提交' : '退款失败')),
            'raw'     => $raw,
        ];
    }

    /**
     * 按易支付规则生成签名
     *
     * @param array<string, mixed> $params
     */
    private function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);

        // 剔除空值
        $params = array_filter($params, static fn ($value): bool => $value !== '' && $value !== null);

        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return md5(implode('&', $pairs) . $this->key);
    }

    /**
     * 发起 HTTP 请求并解析返回
     *
     * 易支付接口多数返回 JSON；无法解析为 JSON 时返回空数组并记录日志。
     *
     * @param  array<string, string> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $payload = []): array
    {
        $body = null;

        if ($method === 'POST') {
            $body = http_build_query($payload);
        }

        try {
            $response = $this->http($method, $url, $body);
        } catch (Throwable $e) {
            Logger::error('易支付请求失败：' . $e->getMessage());

            return [];
        }

        $decoded = json_decode($response, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // 部分站点在异常时返回纯文本，兜底为 msg
        return $response === '' ? [] : ['msg' => trim($response)];
    }

    /**
     * 底层 HTTP 调用：优先 cURL，未安装时回退到 stream
     */
    private function http(string $method, string $url, ?string $body): string
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            if ($handle === false) {
                throw new RuntimeException('无法初始化 cURL');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                // 校验对端证书，防止支付请求 / 回调被中间人劫持或篡改
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ];

            if ($method === 'POST') {
                $options[CURLOPT_POST]       = true;
                $options[CURLOPT_POSTFIELDS] = $body ?? '';
            }

            curl_setopt_array($handle, $options);

            $result = curl_exec($handle);
            $error  = curl_error($handle);

            if ($result === false) {
                throw new RuntimeException('cURL 错误：' . $error);
            }

            return (string) $result;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $method === 'POST' ? ($body ?? '') : '',
            ],
            'ssl' => [
                // 校验对端证书，防止支付请求 / 回调被中间人劫持或篡改
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            throw new RuntimeException('HTTP 请求失败：' . $url);
        }

        return $result;
    }
}
