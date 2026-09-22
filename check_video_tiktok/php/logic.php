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

class TiktokVideo {
    private $curl;

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

    public function getVideoStats(string $url): array {
        $this->applyRandomHeaders();

        curl_setopt($this->curl, CURLOPT_URL, $url);
        $html = curl_exec($this->curl);

        if ($html === false || $html === '') {
            $err = curl_error($this->curl);
            return ['error' => $err ? ('cURL error: ' . $err) : 'Không tải được trang TikTok'];
        }

        // Nếu gặp trang challenge (waf), thường có "Please wait..." hoặc các script waf
        // Không phải bypass gì cả, chỉ báo lỗi rõ ràng để bạn xử lý UI.
        if (stripos($html, 'Please wait') !== false && stripos($html, 'challenge') !== false) {
            return ['error' => 'TikTok đang chặn/Challenge (WAF). Thử lại sau hoặc đổi IP'];
        }

        if (!preg_match(
            '/<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__"[^>]*>(.*?)<\/script>/s',
            $html,
            $m
        )) {
            return ['error' => 'Không tìm thấy JSON TikTok'];
        }

        $json = json_decode($m[1], true);
        if (!$json) {
            return ['error' => 'Parse JSON lỗi'];
        }

        $item = $json['__DEFAULT_SCOPE__']['webapp.video-detail']['itemInfo']['itemStruct'] ?? null;

        if (!$item || empty($item['stats'])) {
            return ['error' => 'Video private / bị xoá'];
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

$urlsRaw = trim($_POST['video_url'] ?? '');
if ($urlsRaw === '') {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Vui lòng nhập link TikTok'], JSON_UNESCAPED_UNICODE);
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
$tiktok = new TiktokVideo();

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

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $row['error'] = 'Link không hợp lệ';
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
        continue;
    }

    $data = $tiktok->getVideoStats($url);

    if (isset($data['error'])) {
        $row['error'] = $data['error'];
    } else {
        $row['status'] = 'SUCCESS';
        $row = array_merge($row, $data);
    }

    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    flush();

    // chống spam / block (random nhẹ 0.25s - 0.75s)
    $minDelay = max(0, (int)dvproEnv('VIDEO_TIKTOK_DELAY_MIN_US', '250000'));
    $maxDelay = max($minDelay, (int)dvproEnv('VIDEO_TIKTOK_DELAY_MAX_US', '750000'));
    usleep(random_int($minDelay, $maxDelay));
}

// done (optional)
echo json_encode([
    'type' => 'done',
    'finished_at' => date('d/m/Y H:i:s'),
], JSON_UNESCAPED_UNICODE) . "\n";
flush();
exit;
