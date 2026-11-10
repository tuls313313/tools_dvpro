<?php
require_once __DIR__ . '/TiktokProfile.php';

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

        $maxLength = max(2, min(64, (int)dvproEnv('TIKTOK_USERNAME_MAX_LENGTH', '24')));
        if (!preg_match('/^[a-zA-Z0-9._]{2,' . $maxLength . '}$/', $username)) {
            throw new InvalidArgumentException('Username không hợp lệ.');
        }
        if (dvproEnvBool('USERNAME_TIKTOK_DIRECT_ENABLED', true)) {
            try {
                return (new TiktokProfile())->checkUsername($username);
            } catch (RuntimeException $e) {
                // Use the complete API result only when direct lookup fails.
            }
        }
        return $this->checkViaApi($username);
    }

    private function checkViaApi(string $username): array
    {
        $ch = curl_init($this->apiUrl);

        $postFields = http_build_query([
            "username" => $username
        ]);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,

    CURLOPT_CONNECTTIMEOUT => max(1, (int)dvproEnv('TIKTOK_CONNECT_TIMEOUT', '2')),
    CURLOPT_TIMEOUT        => max(1, (int)dvproEnv('TIKTOK_HTTP_TIMEOUT', '4')),

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

        if (!is_array($data) || !is_string($data['status'] ?? null)) {
            throw new RuntimeException("API trả về kết quả không hợp lệ");
        }
        $data['status'] = strtoupper($data['status']);
        if (!in_array($data['status'], ['LIVE', 'DIE'], true) || !empty($data['blocked'])) {
            throw new UnexpectedValueException('API chưa xác định được trạng thái tài khoản. Vui lòng Recheck.');
        }
        if (isset($data['username']) && (!is_string($data['username'])
            || strcasecmp(ltrim($data['username'], '@'), $username) !== 0)) {
            throw new RuntimeException('API trả kết quả của username khác.');
        }
        if ($data['status'] === 'DIE'
            && !in_array($data['tiktok_status_code'] ?? null, [10202, '10202'], true)) {
            throw new UnexpectedValueException('API chưa có xác nhận từ TikTok để kết luận DIE. Vui lòng Recheck.');
        }
        $data['username'] = $username;

        // Chuẩn hóa avatar: chỉ cần xử lý "\/" nếu có
        if (!empty($data['avatar']) && is_string($data['avatar'])) {
            $data['avatar'] = str_replace('\\/', '/', $data['avatar']);
        }

        $data['source'] = 'api';
        return $data;
    }
}
