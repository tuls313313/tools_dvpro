<?php
require_once dirname(__DIR__, 2) . '/env.php';
secureSessionStart();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { dvproRequireCsrf(); }
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
// ===========================
// File: /tools/check-tiktok/php/logic.php
// URL gọi từ JS: ./php/logic.php?action=check_tiktok
// Trả NDJSON stream: mỗi dòng 1 JSON object
// ===========================

require_once __DIR__ . '/VideoProxyPool.php';

class TiktokVideo {
    private $curl;
    private string $proxy = '';
    private string $proxyMode;
    private float $deadline = 0;

    private array $userAgents = [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Edg/122.0.0.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
    ];

    private array $acceptLanguages = [
        'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
        'en-US,en;q=0.9,vi;q=0.8',
        'vi,en-US;q=0.9,en;q=0.8',
        'en-GB,en;q=0.9,vi;q=0.7',
    ];

    public function __construct() {
        $this->proxyMode = strtolower((string)dvproEnv('VIDEO_TIKTOK_PROXY_MODE', 'auto'));
        if (!in_array($this->proxyMode, ['auto', 'fixed', 'direct'], true)) {
            throw new InvalidArgumentException('Cấu hình chế độ proxy trên server không hợp lệ.');
        }
        $this->proxy = $this->proxyMode === 'fixed'
            ? trim((string)dvproEnv('VIDEO_TIKTOK_PROXY', '')) : '';
        if ($this->proxyMode === 'fixed' && $this->proxy === '') {
            throw new InvalidArgumentException('Server chưa cấu hình proxy mặc định.');
        }
        $this->curl = curl_init();

        // set chung
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, (int)dvproEnv('VIDEO_TIKTOK_HTTP_TIMEOUT', '20')),
            CURLOPT_CONNECTTIMEOUT => max(1, (int)dvproEnv('VIDEO_TIKTOK_CONNECT_TIMEOUT', '10')),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
        ]);

        $caFile = (string)dvproEnv('CURL_CA_BUNDLE', '');
        if ($caFile !== '' && is_file($caFile) && is_readable($caFile)) {
            curl_setopt($this->curl, CURLOPT_CAINFO, $caFile);
        }

        if ($this->proxyMode === 'fixed') {
            $this->applyProxy($this->proxy);
        } elseif ($this->proxyMode === 'direct') {
            curl_setopt($this->curl, CURLOPT_PROXY, '');
        }
    }

    private function applyProxy(string $proxy, $handle = null): void {
        $proxyConfig = self::parseProxy($proxy);
        if ($proxyConfig === null) {
            throw new InvalidArgumentException('Proxy trong cấu hình server không hợp lệ.');
        }
        curl_setopt_array($handle ?? $this->curl, [
            CURLOPT_PROXY => $proxyConfig['host'],
            CURLOPT_PROXYPORT => $proxyConfig['port'],
            CURLOPT_PROXYTYPE => $proxyConfig['type'],
            CURLOPT_PROXYUSERPWD => $proxyConfig['userpwd'],
            CURLOPT_NOPROXY => '',
        ]);
    }

    private static function parseProxy(string $proxy): ?array {
        if ($proxy === '' || strlen($proxy) > 512) return null;
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $proxy)) {
            $proxy = 'http://' . $proxy;
        }
        $parts = parse_url($proxy);
        if (!is_array($parts) || empty($parts['host'])) return null;
        $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
        $types = [
            'http' => CURLPROXY_HTTP,
            'https' => CURLPROXY_HTTPS,
            'socks5' => CURLPROXY_SOCKS5,
            'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
        ];
        if (!isset($types[$scheme])) return null;
        $port = (int)($parts['port'] ?? 0);
        if ($port < 1 || $port > 65535) return null;
        $userpwd = '';
        if (isset($parts['user']) || isset($parts['pass'])) {
            $userpwd = rawurldecode((string)($parts['user'] ?? '')) . ':' . rawurldecode((string)($parts['pass'] ?? ''));
        }
        return ['host' => (string)$parts['host'], 'port' => $port, 'type' => $types[$scheme], 'userpwd' => $userpwd];
    }

    public function __destruct() {
        if ($this->curl) curl_close($this->curl);
    }

    private function randPick(array $arr) {
        return $arr[random_int(0, count($arr) - 1)];
    }

    private function applyRandomHeaders(): void {
        $ua = $this->randPick($this->userAgents);
        $lang = $this->randPick($this->acceptLanguages);

        // Referer random nhẹ
        $referers = [
            'https://www.tiktok.com/',
            'https://www.google.com/',
            'https://www.bing.com/',
        ];
        $ref = $this->randPick($referers);

        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: ' . $lang,
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Upgrade-Insecure-Requests: 1',
            'Referer: ' . $ref,
        ];

        // set UA + headers cho request hiện tại
        curl_setopt($this->curl, CURLOPT_USERAGENT, $ua);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
    }

    public static function isTiktokUrl(string $url): bool {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');
        return in_array($scheme, ['http', 'https'], true)
            && ($host === 'tiktok.com' || str_ends_with($host, '.tiktok.com'))
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['port']) || $parts['port'] === ($scheme === 'https' ? 443 : 80));
    }

    public function getVideoStats(string $url, ?string $proxyBatch = null): array {
        if (!self::isTiktokUrl($url)) return ['error' => 'Link TikTok không hợp lệ'];
        $this->deadline = microtime(true)
            + max(10, min(90, (int)dvproEnv('VIDEO_TIKTOK_PROXY_TOTAL_TIMEOUT', '45')));
        if ($this->proxyMode !== 'auto') {
            return $this->requestVideoStats($url);
        }

        $pool = new TiktokVideoProxyPool($proxyBatch ?? bin2hex(random_bytes(16)));
        $this->applyRandomHeaders();
        try {
            return $pool->race(
                function (string $proxy, float $timeout) use ($url) {
                    $handle = curl_copy_handle($this->curl);
                    $this->applyProxy($proxy, $handle);
                    curl_setopt($handle, CURLOPT_URL, $url);
                    return $handle;
                },
                function ($handle, string $html, int $followups): array|string {
                    $next = $followups === 0 ? $this->photoVideoUrl($html,
                        (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL)) : null;
                    return $next ?? $this->parseVideoStats($html);
                },
                $this->deadline
            );
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function fetchPage(string $url) {
        $remainingMs = (int)(($this->deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) return false;
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT_MS => min($remainingMs, max(1, (int)dvproEnv('VIDEO_TIKTOK_HTTP_TIMEOUT', '20')) * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => min($remainingMs, max(1, (int)dvproEnv('VIDEO_TIKTOK_CONNECT_TIMEOUT', '10')) * 1000),
        ]);
        return curl_exec($this->curl);
    }

    private function requestVideoStats(string $url): array {
        $this->applyRandomHeaders();
        $html = $this->fetchPage($url);

        $next = $html !== false ? $this->photoVideoUrl($html,
            (string)curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL)) : null;
        if ($next !== null) $html = $this->fetchPage($next);

        if ($html === false || $html === '') {
            $err = curl_error($this->curl);
            return ['error' => $err ? ('cURL error: ' . $err) : 'Không tải được trang TikTok'];
        }

        try {
            return $this->parseVideoStats($html);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function photoVideoUrl(string $html, string $effectiveUrl): ?string {
        $host = strtolower((string)parse_url($effectiveUrl, PHP_URL_HOST));
        $path = (string)parse_url($effectiveUrl, PHP_URL_PATH);
        if ($html !== '' && ($host === 'tiktok.com' || str_ends_with($host, '.tiktok.com'))
            && preg_match('~^/(@[^/]+)/photo/(\d+)/?$~', $path, $photo)) {
            return 'https://www.tiktok.com/' . $photo[1] . '/video/' . $photo[2];
        }
        return null;
    }

    private function parseVideoStats(string $html): array {
        // Nếu gặp trang challenge (waf), thường có "Please wait..." hoặc các script waf
        // Không phải bypass gì cả, chỉ báo lỗi rõ ràng để bạn xử lý UI.
        if (stripos($html, 'Please wait') !== false && stripos($html, 'challenge') !== false) {
            throw new RuntimeException('TikTok đang chặn/Challenge (WAF). Thử lại sau hoặc đổi IP');
        }

        if (!preg_match(
            '/<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__"[^>]*>(.*?)<\/script>/s',
            $html,
            $m
        )) {
            throw new RuntimeException('Không tìm thấy JSON TikTok');
        }

        $json = json_decode($m[1], true);
        if (!$json) {
            throw new RuntimeException('Parse JSON lỗi');
        }

        $item = $json['__DEFAULT_SCOPE__']['webapp.video-detail']['itemInfo']['itemStruct'] ?? null;

        if (!$item || empty($item['stats'])) {
            throw new RuntimeException('TikTok không trả về thống kê của bài này; có thể bài bị giới hạn hoặc không công khai');
        }

        return [
            'item_id'        => $item['id'] ?? null,
            'digg_count'     => $item['stats']['diggCount'] ?? 0,
            'comment_count'  => $item['stats']['commentCount'] ?? 0,
            'share_count'    => $item['stats']['shareCount'] ?? 0,
            'play_count'     => $item['stats']['playCount'] ?? 0,
            'collect_count'  => $item['stats']['collectCount'] ?? 0,
        ];
    }
}

/* ===== ROUTER ===== */
if (!isset($_GET['action']) || $_GET['action'] !== 'check_tiktok') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
    exit;
}

$force = ($_POST['force'] ?? '') === '1';
$batchInput = $_POST['proxy_batch'] ?? '';
if (!is_string($batchInput) || !preg_match('/^[a-zA-Z0-9_-]{16,80}$/', $batchInput)) {
    $batchInput = bin2hex(random_bytes(16));
}
$proxyBatch = hash('sha256', session_id() . '|' . $batchInput);
require_once __DIR__ . '/CacheMaintenance.php';
TiktokCacheMaintenance::run();

$cacheTtl = max(1, (int)dvproEnv('VIDEO_TIKTOK_CACHE_TTL', '15'));
$urlsRaw = trim($_POST['video_url'] ?? '');
if ($urlsRaw === '') {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Vui lòng nhập link TikTok'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $tiktok = new TiktokVideo();
} catch (InvalidArgumentException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== STREAM HEADERS =====
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@ob_implicit_flush(true);

// xả hết buffer nếu có
while (ob_get_level() > 0) { @ob_end_flush(); }

header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no'); // nginx: tắt buffer để stream được

// parse links
$urls = [];
foreach (preg_split('/\R/', $urlsRaw) as $u) {
    $u = trim($u);
    if ($u !== '') $urls[] = $u;
}

$total = count($urls);

// meta (optional)
echo json_encode([
    'type' => 'meta',
    'total' => $total,
    'started_at' => date('d/m/Y H:i:s'),
], JSON_UNESCAPED_UNICODE) . "\n";
flush();

$idx = 0;
foreach ($urls as $url) {
    $idx++;

    $row = [
        'type'       => 'row',
        'index'      => $idx,
        'total'      => $total,
        'video_url'  => $url,
        'checked_at' => date('d/m/Y H:i:s'),
        'status'     => 'ERROR',
    ];

    if (!TiktokVideo::isTiktokUrl($url)) {
        $row['error'] = 'Link TikTok không hợp lệ';
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
        continue;
    }

    $cacheKey = 'tiktok:video:result:' . hash('sha256', $url);
    $data = null;
    if (!$force) {
        try {
            $cached = dvproCacheGet($cacheKey);
            if (is_array($cached) && is_array($cached['data'] ?? null)
                && !isset($cached['data']['error']) && !empty($cached['data']['item_id'])
                && is_string($cached['checked_at'] ?? null)) {
                $data = $cached['data'];
                $row['checked_at'] = $cached['checked_at'];
            }
        } catch (Throwable $e) {
            // Cache is optional; keep checking TikTok if storage is unavailable.
        }
    }
    if ($data === null) {
        $data = $tiktok->getVideoStats($url, $proxyBatch);
        if (!isset($data['error']) && !empty($data['item_id'])) {
            try {
                dvproCacheSet($cacheKey, [
                    'data' => $data,
                    'checked_at' => $row['checked_at'],
                ], $cacheTtl);
            } catch (Throwable $e) {
                // A successful TikTok result must still be returned if caching fails.
            }
        }
    }

    if (isset($data['error'])) {
        $row['error'] = $data['error'];
    } else {
        $row['status'] = 'SUCCESS';
        $row = array_merge($row, $data);
    }

    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    flush();

    // Keep pacing between links, without delaying the final result.
    if ($idx < $total) {
        $minDelay = max(0, (int)dvproEnv('VIDEO_TIKTOK_DELAY_MIN_US', '250000'));
        $maxDelay = max($minDelay, (int)dvproEnv('VIDEO_TIKTOK_DELAY_MAX_US', '750000'));
        usleep(random_int($minDelay, $maxDelay));
    }
}

// done (optional)
echo json_encode([
    'type' => 'done',
    'finished_at' => date('d/m/Y H:i:s'),
], JSON_UNESCAPED_UNICODE) . "\n";
flush();
exit;
