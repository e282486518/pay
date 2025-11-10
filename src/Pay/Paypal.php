<?php
/**
 * @Author: [FENG] <1161634940@qq.com>
 * @Date:   2025-06-23 22:23:01
 * @Last Modified by:   [FENG] <1161634940@qq.com>
 * @Last Modified time: 2025-08-23 12:23:45
 */
namespace fengkui\Pay;

use fengkui\Supports\Http;

/**
 * PayPal 支付 - PC网页支付，原生PHP实现
 */
class Paypal
{
    private static $sandboxUrl = 'https://api-m.sandbox.paypal.com';
    private static $apiUrl     = 'https://api-m.paypal.com';
    private static $baseUrl;

    private static $config = [
        'client_id'     => '',
        'client_secret' => '',
        'webhook_id'    => '',
        'is_sandbox'    => true,
        'notify_url'    => '',
        'return_url'    => '',
        'cancel_url'    => '',
        'encrypt_key'   => '', // 用于加密解密的数据密钥（base64编码的对称密钥）
    ];

    public function __construct($config = NULL)
    {
        $config && self::$config = array_merge(self::$config, $config);
        self::$baseUrl = !empty(self::$config['is_sandbox']) ? self::$sandboxUrl : self::$apiUrl;
    }

    // 获取access_token
    protected static function getAccessToken()
    {
        $auth = base64_encode(self::$config['client_id'] . ':' . self::$config['client_secret']);
        $headers = [
            "Authorization: Basic $auth",
            "Content-Type: application/x-www-form-urlencoded"
        ];
        $body = "grant_type=client_credentials";
        $response = Http::post(self::$baseUrl . '/v1/oauth2/token', $body, $headers, false);
        $json = is_string($response) ? json_decode($response, true) : $response;
        return $json['access_token'] ?? null;
    }

    // 下单，返回PayPal跳转链接
    public static function unifiedOrder($order)
    {
        $accessToken = self::getAccessToken();
        $url = self::$baseUrl . '/v2/checkout/orders';
        $headers = [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ];
        $data = [
            "intent" => "CAPTURE",
            "purchase_units" => [[
                "amount" => [
                    "currency_code" => $order['currency'] ?? 'USD',
                    "value" => $order['amount'],
                ],
                "description" => $order['description'] ?? '',
            ]],
            "application_context" => [
                "return_url" => $order['return_url'] ?? self::$config['return_url'],
                "cancel_url" => $order['cancel_url'] ?? self::$config['cancel_url'],
            ]
        ];
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);
        $res = Http::post($url, $body, $headers, false);
        $resArr = is_string($res) ? json_decode($res, true) : $res;
        if (!empty($resArr['links'])) {
            foreach ($resArr['links'] as $link) {
                if ($link['rel'] === 'approve') {
                    return $link['href'];
                }
            }
        }
        return null;
    }

    // 支付捕获（回调后确认支付）
    public static function capture($orderId)
    {
        $accessToken = self::getAccessToken();
        $url = self::$baseUrl . "/v2/checkout/orders/{$orderId}/capture";
        $headers = [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ];
        $res = Http::post($url, '', $headers, false);
        return is_string($res) ? json_decode($res, true) : $res;
    }

    // 查询订单
    public static function query($orderId)
    {
        $accessToken = self::getAccessToken();
        $url = self::$baseUrl . "/v2/checkout/orders/{$orderId}";
        $headers = [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ];
        $res = Http::get($url, [], $headers);
        return is_string($res) ? json_decode($res, true) : $res;
    }

    // 退款
    public static function refund($captureId, $amount, $currency = 'USD')
    {
        $accessToken = self::getAccessToken();
        $url = self::$baseUrl . "/v2/payments/captures/{$captureId}/refund";
        $headers = [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ];
        $data = [
            "amount" => [
                "value" => $amount,
                "currency_code" => $currency
            ]
        ];
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);
        $res = Http::post($url, $body, $headers, false);
        return is_string($res) ? json_decode($res, true) : $res;
    }

    /**
     * PayPal 回调验签（风格参考 wechat.php）
     * @param string $body   回调原始内容
     * @param array  $headers HTTP头（区分大小写，推荐 getallheaders() 直接传入）
     * @return bool
     */
    public static function verifyNotify($body, $headers)
    {
        $webhook_id = self::$config['webhook_id'] ?? '';
        if (!$webhook_id) {
            return false;
        }

        $accessToken = self::getAccessToken();
        if (!$accessToken) {
            return false;
        }

        $url = self::$baseUrl . '/v1/notifications/verify-webhook-signature';

        $verifyData = [
            'auth_algo'         => $headers['PAYPAL-AUTH-ALGO'] ?? $headers['Paypal-Auth-Algo'] ?? '',
            'cert_url'          => $headers['PAYPAL-CERT-URL'] ?? $headers['Paypal-Cert-Url'] ?? '',
            'transmission_id'   => $headers['PAYPAL-TRANSMISSION-ID'] ?? $headers['Paypal-Transmission-Id'] ?? '',
            'transmission_sig'  => $headers['PAYPAL-TRANSMISSION-SIG'] ?? $headers['Paypal-Transmission-Sig'] ?? '',
            'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? $headers['Paypal-Transmission-Time'] ?? '',
            'webhook_id'        => $webhook_id,
            'webhook_event'     => is_string($body) ? json_decode($body, true) : $body
        ];

        // webhook_event 解析失败, 直接用原始字符串
        if (empty($verifyData['webhook_event'])) {
            $verifyData['webhook_event'] = $body;
        }

        $verifyHeaders = [
            "Content-Type: application/json",
            "Authorization: Bearer {$accessToken}"
        ];

        $res = Http::post($url, json_encode($verifyData, JSON_UNESCAPED_UNICODE), $verifyHeaders, false);
        $resArr = is_string($res) ? json_decode($res, true) : $res;
        return isset($resArr['verification_status']) && $resArr['verification_status'] === 'SUCCESS';
    }

    /**
     * 解密回调中的加密信息
     * @param string $encryptedData  base64编码的密文
     * @param string $iv            base64编码的初始向量（如有）
     * @param string $aad           附加认证数据（如有）
     * @param string $tag           base64编码的认证标签（如有）
     * @return string|false         解密后的明文，失败返回 false
     */
    public static function decryptResource($encryptedData, $iv = '', $aad = '', $tag = '')
    {
        // 兼容你的配置方式，建议密钥为32字节的base64字符串
        $key = self::$config['encrypt_key'] ?? '';
        if (!$key) return false;

        $key = base64_decode($key);
        $cipher = 'aes-256-gcm';

        // PayPal 回调一般使用 AES-256-GCM 加密
        $ciphertext = base64_decode($encryptedData);
        $iv         = base64_decode($iv);
        $tag        = base64_decode($tag);

        // openssl_decrypt 支持 AAD（附加认证数据），仅在传入时使用
        $plaintext = openssl_decrypt(
            $ciphertext,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad ? $aad : ""
        );

        return $plaintext === false ? false : $plaintext;
    }
}