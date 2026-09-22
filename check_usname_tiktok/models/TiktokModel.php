<?php

class TiktokModel
{
    private string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = (string)dvproEnv('TIKTOK_API_URL', 'https://api.dvpro.vn/tiktok/check');
    }

    public function checkUsername(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new InvalidArgumentException("Username rỗng");
        }

        $ch = curl_init($this->apiUrl);

        $postFields = http_build_query([
            "username" => $username
        ]);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,

    CURLOPT_CONNECTTIMEOUT => (int)dvproEnv('TIKTOK_CONNECT_TIMEOUT', '2'),
    CURLOPT_TIMEOUT        => (int)dvproEnv('TIKTOK_HTTP_TIMEOUT', '4'),

    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
    CURLOPT_TCP_KEEPALIVE  => 1,

    CURLOPT_DNS_CACHE_TIMEOUT => (int)dvproEnv('TIKTOK_DNS_CACHE_TIMEOUT', '300'),

    CURLOPT_USERAGENT      => "Mozilla/5.0 (TikTokCheck/1.0)",

    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,

    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/x-www-form-urlencoded",
        "Accept: application/json",
    ],
]);

        $res = curl_exec($ch);

        if ($res === false) {
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            curl_close($ch);

            // Timeout thường là 28
            throw new RuntimeException("cURL error {$errno}: {$err}");
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Nếu API trả lỗi HTTP
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("API HTTP {$httpCode}");
        }

        $data = json_decode($res, true);

        if (!is_array($data)) {
            throw new RuntimeException("API trả về JSON không hợp lệ");
        }

        // Chuẩn hóa avatar: chỉ cần xử lý "\/" nếu có
        if (!empty($data['avatar']) && is_string($data['avatar'])) {
            $data['avatar'] = str_replace('\\/', '/', $data['avatar']);
        }

        return $data;
    }
}
