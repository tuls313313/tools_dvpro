<?php
require_once dirname(__DIR__) . '/env.php';
secureSessionStart();
date_default_timezone_set((string) dvproEnv('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));
require_once __DIR__ . '/models/TiktokModel.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['status' => false, 'message' => 'Method not allowed']);
}
dvproRequireCsrf();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// ===== BLOCK IP =====
function is_blocked_ip(string $ip): bool {
    return dvproIsBlockedIp('tiktok', $ip);
}



function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * ===== LẤY IP =====
 */
function get_client_ip(): string {
    if (function_exists('dvproClientIp')) {
        return dvproClientIp();
    }
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

/**
 * ===== LOG =====
 */
function log_request(string $username, string $ip): void {
    $username = str_replace(["\n","\r"], '', $username);
    dvproLog('tiktok', 'info', 'Username checked', ['username' => $username]);
}

/**
 * ===== RATE LIMIT =====
 */
function rate_limit_or_die(string $key, int $limit, int $windowSec): void {
    if (!dvproRateLimit('tiktok:' . $key, $limit, $windowSec)) {
        respond(429, [
            "status" => "RATE_LIMIT",
            "message" => "Bạn thao tác quá nhanh",
            "retry_after" => $windowSec
        ]);
    }
}

/**
 * ===== DIE STREAK (ANTI SPAM) =====
 */
function get_die_streak(string $ip): int {
    $data = dvproCacheGet('tiktok:die_streak:' . hash('sha256', $ip));
    return is_array($data) ? (int)($data['count'] ?? 0) : 0;
}

function set_die_streak(string $ip, int $count): void {
    dvproCacheSet('tiktok:die_streak:' . hash('sha256', $ip), [
        "count" => $count,
        "time" => time()
    ], (int)dvproEnv('TIKTOK_DIE_STREAK_TTL', '60'));
}

function ua_spam_check(string $ip, string $ua): void {
    if (!preg_match('/Chrome\/([\d\.]+)/', $ua, $m)) {
        return;
    }

    $ttl = max(1, (int)dvproEnv('TIKTOK_UA_FINGERPRINT_TTL', '60'));
    $bucket = (int)floor(time() / $ttl);
    // Track IP changes within this session; Chrome versions are shared by unrelated visitors.
    $cacheKey = 'tiktok:ua:' . hash('sha256', 'session:' . session_id() . ':' . $bucket);
    $data = dvproCacheGet($cacheKey);
    $ips = is_array($data) && is_array($data['ips'] ?? null) ? $data['ips'] : [];
    $ips[$ip] = true;
    dvproCacheSet($cacheKey, ['ips' => $ips], $ttl);

    if (count($ips) >= (int)dvproEnv('TIKTOK_UA_MAX_IPS', '5')) {
        respond(403, [
            "status" => "BLOCKED",
            "message" => "Phiên truy cập dùng quá nhiều IP. Vui lòng thử lại sau.",
            "chrome" => $m[1],
            "unique_ips" => count($ips),
            "retry_after" => $ttl - (time() % $ttl)
        ]);
    }
}

// ===== UA CHECK =====
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua === '' || strlen($ua) < 8) {
    respond(403, ["status" => "FORBIDDEN"]);
}


// ===== REFERER =====
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$host = parse_url($referer, PHP_URL_HOST);
if ($host && !dvproIsAllowedHost($host)) {
    respond(403, ["status" => "FORBIDDEN"]);
}


// ===== IP =====
$ip = get_client_ip();

ua_spam_check($ip, $ua);

// ===== RATE LIMIT =====
rate_limit_or_die("ip:" . $ip, (int)dvproEnv('TIKTOK_IP_RATE_LIMIT', '120'), (int)dvproEnv('TIKTOK_IP_RATE_WINDOW', '60'));
rate_limit_or_die("ip_endpoint:" . $ip, (int)dvproEnv('TIKTOK_ENDPOINT_RATE_LIMIT', '40'), (int)dvproEnv('TIKTOK_ENDPOINT_RATE_WINDOW', '10'));

// ===== DIE STREAK CHECK =====
$streak = get_die_streak($ip);
$MAX_DIE = (int)dvproEnv('TIKTOK_MAX_DIE_STREAK', '20');

// check block
if (is_blocked_ip($ip)) {
    respond(403, [
        "status" => "BLOCKED",
        "message" => "IP của bạn đã bị chặn"
    ]);
}


if ($streak >= $MAX_DIE) {
    respond(429, [
        "status" => "BLOCKED",
        "message" => "Tạm dừng do nhiều kết quả DIE liên tiếp. Vui lòng thử lại sau.",
        "retry_after" => (int)dvproEnv('TIKTOK_DIE_STREAK_TTL', '60')
    ]);
}

// ===== INPUT =====
$username = trim($_POST['username'] ?? '');
$username = preg_replace('/^@+/', '', $username);

$maxUsernameLength = max(2, min(64, (int)dvproEnv('TIKTOK_USERNAME_MAX_LENGTH', '24')));
if ($username === '' || !preg_match('/^[a-zA-Z0-9._]{2,' . $maxUsernameLength . '}$/', $username)) {
    respond(400, [
        "status" => "INVALID",
        "username" => $username,
        "message" => "Username không hợp lệ."
    ]);
}

rate_limit_or_die("ip_user:" . $ip . ":" . strtolower($username), (int)dvproEnv('TIKTOK_USER_RATE_LIMIT', '8'), (int)dvproEnv('TIKTOK_USER_RATE_WINDOW', '30'));

// ===== LOG =====
log_request($username, $ip);

require_once dirname(__DIR__) . '/check_video_tiktok/php/CacheMaintenance.php';
TiktokCacheMaintenance::run();

// ===== CACHE =====
$cacheKey = 'tiktok:result:' . hash('sha256', strtolower($username));
// Recheck bypasses result cache while keeping all existing rate limits.
$force = ($_POST['force'] ?? '') === '1';
$cached = $force ? null : dvproCacheGet($cacheKey);
if (is_array($cached) && in_array($cached['status'] ?? '', ['LIVE', 'DIE'], true)
    && in_array($cached['source'] ?? '', ['direct', 'api'], true)
    && empty($cached['blocked'])
    && ($cached['status'] !== 'DIE' || $cached['source'] !== 'api'
        || in_array($cached['tiktok_status_code'] ?? null, [10202, '10202'], true))) {
    echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ===== DELAY =====
$delayUs = max(0, (int)dvproEnv('TIKTOK_REQUEST_DELAY_US', '200000'));
if ($delayUs > 0) {
    usleep($delayUs);
}

// ===== MAIN =====
try {
    $model = new TiktokModel();
    $result = $model->checkUsername($username);

    $status = strtoupper((string)($result['status'] ?? ''));
    if (!in_array($status, ['LIVE', 'DIE'], true) || !empty($result['blocked'])) {
        throw new RuntimeException('Nguồn kiểm tra chưa trả kết quả LIVE/DIE hợp lệ.');
    }

    // ===== UPDATE STREAK =====
    if ($status === 'DIE') {
        set_die_streak($ip, $streak + 1);
    } else {
        set_die_streak($ip, 0);
    }

    $out = [
        "status" => $status,
        "username" => $result['username'] ?? $username,
        "checked_at" => date('Y-m-d H:i:s')
    ];

    foreach (["avatar","nickname","followers","following","videos","likes","private","check_time","create_time","source","message","tiktok_status_code"] as $k) {
        if (array_key_exists($k, $result)) $out[$k] = $result[$k];
    }

    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    dvproCacheSet($cacheKey, $out, max(1, (int)dvproEnv('TIKTOK_CACHE_TTL', '15')));

    echo $json;
    exit;

} catch (Throwable $e) {
    dvproLog('tiktok', 'error', 'Username lookup failed', [
        'username' => $username,
        'error' => $e->getMessage()
    ]);
    respond($e instanceof UnexpectedValueException ? 200 : 502, [
        "status" => "ERROR",
        "username" => $username,
        "message" => $e instanceof UnexpectedValueException
            ? $e->getMessage()
            : "Chưa lấy được kết quả từ TikTok/API. Vui lòng Recheck."
    ]);
}
