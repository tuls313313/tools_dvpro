<?php
declare(strict_types=1);

/**
 * Load .env once and expose helpers for production config.
 */
function dvproLoadEnv(?string $path = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = $path ?: (__DIR__ . DIRECTORY_SEPARATOR . '.env');
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // Keep configuration request-local so multiple vhosts cannot leak values.
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function dvproEnv(string $key, ?string $default = null): ?string
{
    dvproLoadEnv();
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

function dvproEnvBool(string $key, bool $default = false): bool
{
    $value = strtolower((string) dvproEnv($key, $default ? '1' : '0'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Production security helpers for tools.dvpro.vn
 */

function dvproIsLocalHost(?string $host = null): bool
{
    $host = strtolower((string) ($host ?? ($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?: '';
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.test');
}

function dvproAllowedHosts(): array
{
    $raw = (string) dvproEnv('ALLOWED_HOSTS', 'tools.dvpro.vn,www.tools.dvpro.vn,localhost,127.0.0.1,::1');
    $hosts = array_values(array_filter(array_map(
        static fn($h) => strtolower(trim($h)),
        explode(',', $raw)
    )));
    return $hosts !== [] ? $hosts : ['tools.dvpro.vn', 'localhost', '127.0.0.1'];
}

function dvproCurrentHost(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return preg_replace('/:\d+$/', '', $host) ?: '';
}

function dvproIsAllowedHost(?string $host = null): bool
{
    $host = strtolower((string) ($host ?? dvproCurrentHost()));
    $host = preg_replace('/:\d+$/', '', $host) ?: '';
    if ($host === '') {
        return false;
    }
    if (dvproIsLocalHost($host)) {
        return true;
    }
    return in_array($host, dvproAllowedHosts(), true);
}

function dvproAppUrl(): string
{
    $configured = rtrim((string) dvproEnv('APP_URL', ''), '/');
    if ($configured !== '') {
        return $configured . '/';
    }

    if (dvproIsLocalHost()) {
        return 'http://localhost/tools.dvpro.vn/';
    }

    return 'https://tools.dvpro.vn/';
}

function dvproBrandUrl(): string
{
    $configured = rtrim((string) dvproEnv('APP_BRAND_URL', ''), '/');
    if ($configured !== '') {
        return $configured . '/';
    }
    return dvproIsLocalHost() ? 'http://localhost/dvpro.vn/' : 'https://dvpro.vn/';
}

function dvproIsDebug(): bool
{
    if (dvproEnv('APP_DEBUG') !== null) {
        return dvproEnvBool('APP_DEBUG', false);
    }
    return dvproIsLocalHost();
}

function dvproClientIp(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $candidates = [];

    // Production behind Cloudflare/reverse proxy should set TRUST_PROXY=1.
    // Cloudflare provides CF-Connecting-IP after validating the client.
    if (dvproEnvBool('TRUST_PROXY', false)) {
        $cfIp = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cfIp !== '') {
            $candidates[] = $cfIp;
        }

        // Fallback only when CF header is absent (other reverse proxies).
        if ($cfIp === '') {
            $candidates[] = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            $candidates[] = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        }
    }

    $candidates[] = $remote;

    foreach ($candidates as $candidate) {
        $candidate = trim(explode(',', (string) $candidate)[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return '0.0.0.0';
}

function dvproDatabase(bool $ensureSchema = true): PDO
{
    static $connection = null;
    static $schemaReady = false;

    if (!$connection instanceof PDO) {
        $driver = strtolower(trim((string) dvproEnv('DB_DRIVER', 'mysql')));
        if ($driver !== 'mysql') {
            throw new RuntimeException('Only MySQL is supported. Set DB_DRIVER=mysql in .env');
        }

        $host = trim((string) dvproEnv('DB_HOST', ''));
        $port = trim((string) dvproEnv('DB_PORT', '3306'));
        $database = trim((string) dvproEnv('DB_DATABASE', ''));
        $username = trim((string) dvproEnv('DB_USERNAME', ''));
        $password = (string) dvproEnv('DB_PASSWORD', '');

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('MySQL configuration is incomplete. Set DB_HOST, DB_DATABASE and DB_USERNAME in .env');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
        $connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        try {
            $dbTimezone = trim((string) dvproEnv('DB_TIMEZONE', '+07:00'));
            if (preg_match('/^[+-](?:0\d|1\d|2[0-3]):[0-5]\d$/', $dbTimezone)) {
                $connection->exec("SET time_zone = '{$dbTimezone}'");
            }
        } catch (Throwable $e) {
        }
    }

    if ($ensureSchema && !$schemaReady && dvproEnvBool('DB_AUTO_SCHEMA', false)) {
        dvproEnsureDatabaseSchema($connection);
        $schemaReady = true;
    }

    return $connection;
}

function dvproEnsureDatabaseSchema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS app_cache (
        cache_key VARCHAR(191) NOT NULL,
        cache_value LONGTEXT NOT NULL,
        expires_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (cache_key),
        KEY idx_app_cache_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS app_rate_limits (
        bucket_key CHAR(64) NOT NULL,
        window_start BIGINT NOT NULL,
        request_count INT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (bucket_key),
        KEY idx_app_rate_limits_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS app_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        channel VARCHAR(80) NOT NULL,
        level VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        context_json LONGTEXT NULL,
        ip VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_app_logs_channel_created (channel, created_at),
        KEY idx_app_logs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS app_webhook_events (
        event_id CHAR(32) NOT NULL,
        received_at DATETIME NOT NULL,
        method VARCHAR(12) NOT NULL,
        request_uri TEXT NOT NULL,
        ip VARCHAR(45) NULL,
        headers_json LONGTEXT NULL,
        query_json LONGTEXT NULL,
        form_json LONGTEXT NULL,
        files_json LONGTEXT NULL,
        body_json LONGTEXT NULL,
        raw_body LONGTEXT NULL,
        body_truncated TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (event_id),
        KEY idx_app_webhook_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS mail_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(320) NOT NULL,
        account_line TEXT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_mail_accounts_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS app_blocked_ips (
        scope VARCHAR(40) NOT NULL,
        ip_or_prefix VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (scope, ip_or_prefix)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function dvproCacheGet(string $key): mixed
{
    $db = dvproDatabase();
    $st = $db->prepare('SELECT cache_value, expires_at FROM app_cache WHERE cache_key = ? LIMIT 1');
    $st->execute([$key]);
    $row = $st->fetch();

    if (!$row) {
        return null;
    }

    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) {
        $db->prepare('DELETE FROM app_cache WHERE cache_key = ?')->execute([$key]);
        return null;
    }

    $value = json_decode((string) $row['cache_value'], true);
    return json_last_error() === JSON_ERROR_NONE ? $value : null;
}

function dvproCacheSet(string $key, mixed $value, int $ttl = 0): void
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    $expiresAt = $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null;
    $st = dvproDatabase()->prepare(
        'INSERT INTO app_cache (cache_key, cache_value, expires_at, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expires_at = VALUES(expires_at), updated_at = NOW()'
    );
    $st->execute([$key, $json, $expiresAt]);
}

function dvproCacheDelete(string $key): void
{
    dvproDatabase()->prepare('DELETE FROM app_cache WHERE cache_key = ?')->execute([$key]);
}

function dvproCachePrune(): void
{
    $db = dvproDatabase();
    $db->exec('DELETE FROM app_cache WHERE expires_at IS NOT NULL AND expires_at <= NOW()');
    $db->exec('DELETE FROM app_rate_limits WHERE expires_at <= NOW()');
    $logDays = max(1, (int) dvproEnv('DB_LOG_RETENTION_DAYS', '30'));
    $webhookDays = max(1, (int) dvproEnv('DB_WEBHOOK_RETENTION_DAYS', '30'));
    $db->exec('DELETE FROM app_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $logDays . ' DAY)');
    $db->exec('DELETE FROM app_webhook_events WHERE received_at < DATE_SUB(NOW(), INTERVAL ' . $webhookDays . ' DAY)');
}
function dvproLog(string $channel, string $level, string $message, array $context = []): void
{
    try {
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $st = dvproDatabase()->prepare(
            'INSERT INTO app_logs (channel, level, message, context_json, ip, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $st->execute([
            $channel,
            $level,
            $message,
            $contextJson === false ? null : $contextJson,
            function_exists('dvproClientIp') ? dvproClientIp() : ($_SERVER['REMOTE_ADDR'] ?? null),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Logging must not hide the primary response.
    }
}

function dvproIsBlockedIp(string $scope, string $ip): bool
{
    $st = dvproDatabase()->prepare('SELECT ip_or_prefix FROM app_blocked_ips WHERE scope = ?');
    $st->execute([$scope]);

    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $blocked) {
        $blocked = trim((string) $blocked);
        if ($blocked === $ip) {
            return true;
        }
        if (str_ends_with($blocked, '*') && str_starts_with($ip, rtrim($blocked, '*'))) {
            return true;
        }
    }

    return false;
}

function dvproCsrfToken(): string
{
    secureSessionStart();
    if (empty($_SESSION['dvpro_csrf']) || !is_string($_SESSION['dvpro_csrf'])) {
        $_SESSION['dvpro_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['dvpro_csrf'];
}

function dvproCsrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(dvproCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function dvproRequestCsrfToken(): string
{
    $token = trim((string) ($_POST['csrf_token'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $header = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($header !== '') {
        return $header;
    }

    return '';
}

function dvproRequireCsrf(?string $token = null): void
{
    secureSessionStart();
    $provided = trim((string) ($token ?? dvproRequestCsrfToken()));
    $expected = (string) ($_SESSION['dvpro_csrf'] ?? '');

    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            dvproNoStoreHeaders();
        }
        echo json_encode([
            'status' => false,
            'success' => false,
            'ok' => false,
            'message' => 'Invalid CSRF token',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function dvproRateLimit(string $bucket, int $limit = 0, int $windowSeconds = 0): bool
{
    $now = time();
    $limit = max(1, $limit > 0 ? $limit : (int) dvproEnv('RATE_LIMIT_DEFAULT', '60'));
    $windowSeconds = max(1, $windowSeconds > 0 ? $windowSeconds : (int) dvproEnv('RATE_LIMIT_WINDOW', '60'));
    $bucketKey = hash('sha256', $bucket);
    $db = dvproDatabase();
    try {
        $db->beginTransaction();
        $insert = $db->prepare('INSERT IGNORE INTO app_rate_limits (bucket_key, window_start, request_count, expires_at, updated_at) VALUES (?, ?, 0, ?, NOW())');
        $insert->execute([$bucketKey, $now, date('Y-m-d H:i:s', $now + $windowSeconds)]);
        $select = $db->prepare('SELECT window_start, request_count FROM app_rate_limits WHERE bucket_key = ? FOR UPDATE');
        $select->execute([$bucketKey]);
        $data = $select->fetch() ?: ['window_start' => $now, 'request_count' => 0];
        $start = (int) $data['window_start'];
        $count = (int) $data['request_count'];
        if ($now - $start >= $windowSeconds) {
            $start = $now;
            $count = 0;
        }
        $count++;
        $update = $db->prepare('UPDATE app_rate_limits SET window_start = ?, request_count = ?, expires_at = ?, updated_at = NOW() WHERE bucket_key = ?');
        $update->execute([$start, $count, date('Y-m-d H:i:s', $start + $windowSeconds), $bucketKey]);
        $db->commit();
        return $count <= $limit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return dvproEnvBool('DB_RATE_LIMIT_FAIL_OPEN', false);
    }
}

function dvproJsonError(string $message, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => false, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dvproNoStoreHeaders(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
}

function dvproBlockDirectPhpAccess(): void
{
    // Prevent helper/config files from being executed via web as content dumps if misconfigured.
    if (PHP_SAPI === 'cli') {
        return;
    }
    $script = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    $blocked = ['env.php'];
    if (in_array($script, $blocked, true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
}

function secureSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $secureMode = strtolower((string) dvproEnv('SESSION_SECURE', 'auto'));
    $secure = match ($secureMode) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => $https,
    };

    $sameSite = (string) dvproEnv('SESSION_SAMESITE', 'Lax');
    if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
        $sameSite = 'Lax';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);
    session_start();
}


function dvproCspNonce(): string
{
    static $nonce = null;
    if (is_string($nonce)) {
        return $nonce;
    }
    $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    return $nonce;
}

function dvproSendCspHeader(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    static $sent = false;
    if ($sent) {
        return;
    }
    $sent = true;

    $nonce = dvproCspNonce();
    $isProd = !dvproIsLocalHost();
    $imgSrc = $isProd ? "img-src 'self' data: https:" : "img-src 'self' data: https: http:";
    $upgrade = $isProd ? ' upgrade-insecure-requests' : '';

    // Best-effort hardened CSP:
    // - scripts require nonce (no unsafe-inline/unsafe-eval)
    // - styles keep unsafe-inline for compatibility with many inline style attributes
    $csp = "default-src 'self'; "
         . "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://code.jquery.com https://cdnjs.cloudflare.com https://chathub.dvpro.vn; "
         . "style-src 'self' 'unsafe-inline'; "
         . "font-src 'self' data: https:; "
         . "{$imgSrc}; "
         . "connect-src 'self' https://chathub.dvpro.vn wss://chathub.dvpro.vn; "
         . "frame-src 'self' https://chathub.dvpro.vn; "
         . "object-src 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         . "frame-ancestors 'self';"
         . $upgrade;

    header('Content-Security-Policy: ' . $csp);
}

function dvproNonceAttr(): string
{
    return 'nonce="' . htmlspecialchars(dvproCspNonce(), ENT_QUOTES, 'UTF-8') . '"';
}

dvproLoadEnv();
date_default_timezone_set((string) dvproEnv('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));
dvproBlockDirectPhpAccess();

