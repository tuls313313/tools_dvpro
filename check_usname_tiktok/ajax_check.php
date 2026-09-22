<?php
require_once dirname(__DIR__) . '/env.php';
secureSessionStart();
date_default_timezone_set((string) dvproEnv('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));
require_once __DIR__ . '/models/TiktokModel.php';

header('Content-Type: application/json; charset=utf-8');
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

    // chỉ bắt Chrome
    if (!preg_match('/Chrome\/([\d\.]+)/', $ua, $m)) {
        return;
    }

    $chromeVersion = $m[1];

    // fingerprint theo version Chrome
    $uaKey = 'chrome_' . $chromeVersion;

    $bucket = floor(time() / (int)dvproEnv('TIKTOK_UA_FINGERPRINT_TTL', '60'));
    $cacheKey = 'tiktok:ua:' . hash('sha256', $uaKey . ':' . $bucket);
    $data = dvproCacheGet($cacheKey);
    $data = is_array($data)
        ? $data
        : [
            "ips" => [],
            "count" => 0
        ];

    $data["count"]++;
    $data["ips"][$ip] = true;

    dvproCacheSet($cacheKey, $data, (int)dvproEnv('TIKTOK_UA_FINGERPRINT_TTL', '60'));

    $uniqueIps = count($data["ips"]);

    // cùng Chrome mà đổi quá nhiều IP trong 1 phút => block UA đó
    if ($uniqueIps >= (int)dvproEnv('TIKTOK_UA_MAX_IPS', '5')
        || $data["count"] >= (int)dvproEnv('TIKTOK_UA_MAX_REQUESTS', '30')) {
        respond(403, [
            "status" => "BLOCKED",
            "message" => "UA rotating IP blocked",
            "chrome" => $chromeVersion,
            "unique_ips" => $uniqueIps
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
    respond(200, [
        "status" => "DIE",
        "blocked" => true,
        "message" => "Spam die",
        "checked_at" => date('Y-m-d H:i:s')
    ]);
}

// ===== INPUT =====
$username = trim($_POST['username'] ?? '');
$username = preg_replace('/^@+/', '', $username);

$maxUsernameLength = max(2, min(64, (int)dvproEnv('TIKTOK_USERNAME_MAX_LENGTH', '24')));
if ($username === '' || !preg_match('/^[a-zA-Z0-9._]{2,' . $maxUsernameLength . '}$/', $username)) {
    respond(400, [
        "status" => "INVALID",
        "username" => $username
    ]);
}

rate_limit_or_die("ip_user:" . $ip . ":" . strtolower($username), (int)dvproEnv('TIKTOK_USER_RATE_LIMIT', '8'), (int)dvproEnv('TIKTOK_USER_RATE_WINDOW', '30'));

// ===== LOG =====
log_request($username, $ip);

// ===== CACHE =====
$cacheKey = 'tiktok:result:' . hash('sha256', strtolower($username));
$cached = dvproCacheGet($cacheKey);
if (is_array($cached)) {
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

    $status = strtoupper($result['status'] ?? 'DIE');

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

    foreach (["avatar","nickname","followers","following","videos","likes","check_time","create_time"] as $k) {
        if (isset($result[$k])) $out[$k] = $result[$k];
    }

    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    dvproCacheSet($cacheKey, $out, (int)dvproEnv('TIKTOK_CACHE_TTL', '30'));

    echo $json;
    exit;

} catch (Throwable $e) {
    respond(502, [
        "status" => "DIE",
        "message" => "API lỗi"
    ]);
}
