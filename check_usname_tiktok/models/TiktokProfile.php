<?php
require_once dirname(__DIR__, 2) . '/check_video_tiktok/php/proxy.php';

class TiktokProfile
{
    public function checkUsername(string $username): array
    {
        $pool = new TiktokFreeProxyPool('username');
        $deadline = microtime(true)
            + max(5, min(60, (int)dvproEnv('USERNAME_TIKTOK_PROXY_TOTAL_TIMEOUT', '30')));
        return $pool->race(
            fn(string $proxy, float $timeout) => $this->profileHandle($username, $proxy, $timeout),
            function ($handle, string $html) use ($username): array {
                if ($html === '') throw new RuntimeException('Không tải được tài khoản TikTok.');
                $httpCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
                if ($httpCode !== 200 && $httpCode !== 404) {
                    throw new RuntimeException('TikTok trả HTTP ' . $httpCode);
                }
                $result = $this->parseProfile($html, $username);
                if ($httpCode === 404 && $result['status'] !== 'DIE') {
                    throw new RuntimeException('Phản hồi tài khoản TikTok không nhất quán.');
                }
                return $result;
            },
            $deadline
        );
    }

    private function profileHandle(string $username, string $proxy, float $timeout)
    {
        $handle = curl_init('https://www.tiktok.com/@' . rawurlencode($username));
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT_MS => max(1, (int)($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)(min($timeout,
                max(1, (int)dvproEnv('USERNAME_TIKTOK_CONNECT_TIMEOUT', '5'))) * 1000)),
            CURLOPT_PROXY => $proxy,
            CURLOPT_NOPROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9,vi;q=0.8',
            ],
        ];
        $caFile = (string)dvproEnv('CURL_CA_BUNDLE', '');
        if ($caFile !== '' && is_file($caFile) && is_readable($caFile)) {
            $options[CURLOPT_CAINFO] = $caFile;
        }
        curl_setopt_array($handle, $options);
        return $handle;
    }

    private function parseProfile(string $html, string $username): array
    {
        if (!preg_match('~<script\b[^>]*\bid=["\x27]__UNIVERSAL_DATA_FOR_REHYDRATION__["\x27][^>]*>(.*?)</script>~s', $html, $match)) {
            throw new RuntimeException('TikTok không trả dữ liệu tài khoản hoặc đang yêu cầu xác minh.');
        }
        $json = json_decode($match[1], true);
        $detail = is_array($json) ? ($json['__DEFAULT_SCOPE__']['webapp.user-detail'] ?? null) : null;
        if (!is_array($detail)) {
            throw new RuntimeException('Không đọc được dữ liệu tài khoản TikTok.');
        }
        $info = $detail['userInfo'] ?? null;
        $user = is_array($info) ? ($info['user'] ?? null) : null;
        $stats = is_array($info) && is_array($info['stats'] ?? null) ? $info['stats'] : [];
        $statsV2 = is_array($info) && is_array($info['statsV2'] ?? null) ? $info['statsV2'] : [];
        $code = $detail['statusCode'] ?? null;
        // Only an explicit user-not-found response is conclusive; other failures use the API.
        if ((int)$code === 10202 && empty($user)) {
            return ['status' => 'DIE', 'username' => $username,
                'message' => 'TikTok xác nhận không tìm thấy tài khoản.', 'source' => 'direct'];
        }
        if ($code === null || (int)$code !== 0 || !is_array($user) || ($stats === [] && $statsV2 === [])
            || empty($user['id']) || !is_string($user['uniqueId'] ?? null)
            || strcasecmp($user['uniqueId'], $username) !== 0) {
            throw new RuntimeException('TikTok chưa trả đủ dữ liệu để kết luận tài khoản.');
        }
        // statsV2 contains precise counts; stats may contain rounded public counts.
        $versions = [$statsV2, $stats];
        $followers = $this->readCount($versions, ['followerCount']);
        $following = $this->readCount($versions, ['followingCount']);
        $videos = $this->readCount($versions, ['videoCount']);
        $likes = $this->readCount($versions, ['heartCount', 'heart']);
        if ($followers === null || $following === null || $videos === null || $likes === null) {
            throw new RuntimeException('TikTok chưa trả đủ thống kê tài khoản.');
        }
        $avatar = (string)($user['avatarLarger'] ?? $user['avatarMedium'] ?? $user['avatarThumb'] ?? '');
        if (!filter_var($avatar, FILTER_VALIDATE_URL) || parse_url($avatar, PHP_URL_SCHEME) !== 'https') {
            $avatar = '';
        }
        return [
            'status' => 'LIVE',
            'username' => $user['uniqueId'],
            'avatar' => $avatar,
            'nickname' => is_string($user['nickname'] ?? null) ? $user['nickname'] : '',
            'followers' => $followers,
            'following' => $following,
            'videos' => $videos,
            'likes' => $likes,
            'private' => (bool)($user['privateAccount'] ?? false),
            'create_time' => is_numeric($user['createTime'] ?? null) ? (int)$user['createTime'] : 0,
            'source' => 'direct',
        ];
    }

    private function readCount(array $versions, array $keys): ?int
    {
        foreach ($versions as $stats) {
            foreach ($keys as $key) {
                $value = $stats[$key] ?? null;
                if (!is_int($value) && !is_string($value)) continue;
                $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($count !== false) return $count;
            }
        }
        return null;
    }

}
