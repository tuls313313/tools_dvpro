<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/env.php';

function cronSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $secureMode = strtolower((string)dvproEnv('SESSION_SECURE', 'auto'));
    $secure = match ($secureMode) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => $https,
    };

    $sameSite = (string)dvproEnv('SESSION_SAMESITE', 'Lax');
    if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
        $sameSite = 'Lax';
    }

    ini_set('session.gc_maxlifetime', '3600');
    session_set_cookie_params([
        'lifetime' => 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);
    session_start();
}

function cronCsrfToken(): string
{
    cronSessionStart();
    if (empty($_SESSION['cron_csrf'])) {
        $_SESSION['cron_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['cron_csrf'];
}

function cronRequireCsrf(): void
{
    $provided = (string)($_POST['csrf_token'] ?? '');
    if ($provided === '' || !hash_equals(cronCsrfToken(), $provided)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

function cronLoadKey(): string
{
    static $key = null;
    if (is_string($key)) {
        return $key;
    }

    $env = '';
    if (function_exists('dvproEnv')) {
        $env = trim((string) dvproEnv('CRON_ENCRYPT_KEY', ''));
    }
    if ($env !== '') {
        if (preg_match('/^[a-f0-9]{64}$/i', $env)) {
            $decoded = hex2bin($env);
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $key = $decoded;
            }
        }
        $decoded = base64_decode($env, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $key = $decoded;
        }
        if (strlen($env) === 32) {
            return $key = $env;
        }
    }

    throw new RuntimeException('CRON_ENCRYPT_KEY is not configured in .env');
}

function cronEncrypt(string $plain): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', cronLoadKey(), OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext)) {
        throw new RuntimeException('Unable to encrypt cron URL');
    }

    return 'v2:' . base64_encode($nonce . $tag . $ciphertext);
}

function cronDecrypt(string $encrypted): string|false
{
    if (!str_starts_with($encrypted, 'v2:')) {
        return false;
    }

    $payload = base64_decode(substr($encrypted, 3), true);
    if (!is_string($payload) || strlen($payload) < 29) {
        return false;
    }

    $nonce = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);

    return openssl_decrypt($ciphertext, 'aes-256-gcm', cronLoadKey(), OPENSSL_RAW_DATA, $nonce, $tag);
}

function cronClientIp(): string
{
    if (function_exists('dvproClientIp')) {
        return dvproClientIp();
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP)) {
        return $remote;
    }
    return 'unknown';
}

function cronLoginAttemptFile(): string
{
    return 'cron:login:' . hash('sha256', cronClientIp());
}

function cronReadLoginAttempts(): array
{
    $data = dvproCacheGet(cronLoginAttemptFile());
    return is_array($data) ? array_values(array_filter($data, static fn($time) => (int)$time >= time() - 900)) : [];
}

function cronLoginBlocked(): bool
{
    return count(cronReadLoginAttempts()) >= 5;
}

function cronRecordLoginFailure(): void
{
    $attempts = cronReadLoginAttempts();
    $attempts[] = time();
    dvproCacheSet(cronLoginAttemptFile(), $attempts, 900);
}

function cronClearLoginFailures(): void
{
    dvproCacheDelete(cronLoginAttemptFile());
}

function cronIsPublicIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP)
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function cronResolvePublicHost(string $host): array|false
{
    $host = trim($host, "[] \t\n\r\0\x0B.");
    if ($host === '') {
        return false;
    }

    $lower = strtolower($host);
    if ($lower === 'localhost' || str_ends_with($lower, '.localhost') || str_ends_with($lower, '.local')) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return cronIsPublicIp($host) ? [$host] : false;
    }

    $ips = [];
    foreach (@dns_get_record($host, DNS_A) ?: [] as $record) {
        if (!empty($record['ip'])) $ips[] = $record['ip'];
    }
    foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
        if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
    }
    foreach (@gethostbynamel($host) ?: [] as $ip) {
        $ips[] = $ip;
    }

    $ips = array_values(array_unique($ips));
    if ($ips === []) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!cronIsPublicIp($ip)) {
            return false;
        }
    }

    return $ips;
}

function cronValidateUrl(string $url): array|false
{
    $parts = parse_url(trim($url));
    if (!is_array($parts) || strlen($url) > 2048) {
        return false;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $port < 1 || $port > 65535 || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    $ips = cronResolvePublicHost($host);
    if ($ips === false) {
        return false;
    }

    return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
}

function cronTwofaAttemptFile(int $uid): string
{
    return 'cron:2fa:' . $uid . ':' . hash('sha256', cronClientIp());
}

function cronReadTwofaAttempts(int $uid): array
{
    $data = dvproCacheGet(cronTwofaAttemptFile($uid));
    return is_array($data) ? array_values(array_filter($data, static fn($time) => (int)$time >= time() - 900)) : [];
}

function cronTwofaBlocked(int $uid): bool
{
    return count(cronReadTwofaAttempts($uid)) >= 8;
}

function cronRecordTwofaFailure(int $uid): void
{
    $attempts = cronReadTwofaAttempts($uid);
    $attempts[] = time();
    dvproCacheSet(cronTwofaAttemptFile($uid), $attempts, 900);
}

function cronClearTwofaFailures(int $uid): void
{
    dvproCacheDelete(cronTwofaAttemptFile($uid));
}

function cronNormalizeInterval(int $interval): int
{
    return max(3, min(86400, $interval));
}

function cronEnvValue(string $key, ?string $default = null): ?string
{
    $fallbackKey = preg_replace('/^CRON_/', '', $key) ?: $key;
    $value = function_exists('dvproEnv') ? dvproEnv($fallbackKey, null) : null;
    return ($value !== null && trim($value) !== '') ? trim($value) : $default;
}

function cronDatabase(bool $ensureSchema = true): PDO
{
    $db = dvproDatabase(false);
    if ($ensureSchema) {
        cronConfigureDatabase($db, true);
    }
    return $db;
}

function cronEnsureMysqlIndex(PDO $db, string $table, string $index, string $columns): void
{
    $st = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $st->execute([$table, $index]);
    if ((int)$st->fetchColumn() === 0) {
        $db->exec("CREATE INDEX {$index} ON {$table} ({$columns})");
    }
}

function cronConfigureDatabase(PDO $db, bool $ensureSchema = true): void
{
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Unsupported database driver');
    }
    if (!$ensureSchema || !dvproEnvBool('DB_AUTO_SCHEMA', false)) {
        return;
    }

    $db->exec("CREATE TABLE IF NOT EXISTS cron_users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(128) NOT NULL,
        code_hash VARCHAR(255) NULL,
        name VARCHAR(120) NULL,
        twofa_secret TEXT NULL,
        twofa_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cron_users_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS cron_tasks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        url_encrypted TEXT NOT NULL,
        interval_seconds INT UNSIGNED NOT NULL DEFAULT 60,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS cron_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        task_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL,
        http_code INT NOT NULL DEFAULT 0,
        response_body TEXT NULL,
        error_msg TEXT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        duration_ms INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    cronEnsureMysqlIndex($db, 'cron_tasks', 'idx_cron_tasks_user_id_id', 'user_id, id');
    cronEnsureMysqlIndex($db, 'cron_tasks', 'idx_cron_tasks_active_id', 'is_active, id');
    cronEnsureMysqlIndex($db, 'cron_logs', 'idx_cron_logs_task_id_id', 'task_id, id');
    cronEnsureMysqlIndex($db, 'cron_logs', 'idx_cron_logs_status_task_id', 'status, task_id');
}

function cronCliPhpBinary(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $candidate = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return PHP_BINARY;
}

function cronLaunchTaskProcess(int $taskId, bool $force = false): bool
{
    if ($taskId <= 0) {
        return false;
    }

    $taskArg = '--task=' . $taskId;
    $forceArg = $force ? ' --force' : '';

    if (PHP_OS_FAMILY === 'Windows') {
        $systemRoot = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), '\\/');
        $wscript = $systemRoot . '\\System32\\wscript.exe';
        $launcher = __DIR__ . '\\run_hidden.vbs';
        $command = escapeshellarg($wscript)
            . ' ' . escapeshellarg($launcher)
            . ' ' . escapeshellarg($taskArg)
            . $forceArg;
        $handle = @popen($command, 'r');
        if (!is_resource($handle)) {
            return false;
        }
        pclose($handle);
        return true;
    }

    $command = escapeshellarg(cronCliPhpBinary())
        . ' ' . escapeshellarg(__DIR__ . '/runner.php')
        . ' ' . escapeshellarg($taskArg)
        . $forceArg
        . ' > /dev/null 2>&1 &';
    @exec($command, $output, $status);
    return $status === 0;
}

function cronSafeTaskName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 120);
    }
    return substr($name, 0, 120);
}

function cronEncryptSecret(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') {
        return '';
    }
    return cronEncrypt($plain);
}

function cronDecryptSecret(string $encrypted): string
{
    $encrypted = trim($encrypted);
    if ($encrypted === '') {
        return '';
    }
    if (!str_starts_with($encrypted, 'v2:')) {
        return $encrypted; // legacy plaintext
    }
    $plain = cronDecrypt($encrypted);
    return is_string($plain) ? $plain : '';
}

function cronMigrateTwofaSecrets(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $rows = $db->query("SELECT id, twofa_secret FROM cron_users WHERE twofa_secret IS NOT NULL AND twofa_secret != '' AND twofa_secret NOT LIKE 'v2:%'")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $db->prepare('UPDATE cron_users SET twofa_secret=? WHERE id=?');
        foreach ($rows as $row) {
            $secret = trim((string)($row['twofa_secret'] ?? ''));
            if ($secret === '' || str_starts_with($secret, 'v2:')) {
                continue;
            }
            $upd->execute([cronEncryptSecret($secret), (int)$row['id']]);
        }
    } catch (Throwable $e) {
    }
}
