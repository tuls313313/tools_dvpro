<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/security.php';

function legacyPath(string $key, string $default): string
{
    $path = trim((string)dvproEnv($key, $default));
    if ($path === '') return '';
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path)) return $path;
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($path, '\\\\/');
}

function report(string $name, int $imported, int $skipped, int $invalid): void
{
    printf("%s: imported=%d skipped=%d invalid=%d\n", $name, $imported, $skipped, $invalid);
}

$db = dvproDatabase(true);
cronConfigureDatabase($db, true);

$cronPath = legacyPath('LEGACY_CRON_DB_PATH', 'cron/cron.db');
$imported = $skipped = $invalid = 0;
if ($cronPath !== '' && is_file($cronPath)) {
    $source = new PDO('sqlite:' . $cronPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->beginTransaction();
    try {
        $insertUser = $db->prepare('INSERT IGNORE INTO cron_users (id, code, code_hash, name, twofa_secret, twofa_enabled, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($source->query('SELECT id,code,code_hash,name,twofa_secret,twofa_enabled,created_at FROM cron_users ORDER BY id') as $row) {
            $insertUser->execute([(int)$row['id'], $row['code'], $row['code_hash'], $row['name'], $row['twofa_secret'], (int)$row['twofa_enabled'], $row['created_at']]);
            $insertUser->rowCount() > 0 ? $imported++ : $skipped++;
        }
        $insertTask = $db->prepare('INSERT IGNORE INTO cron_tasks (id, user_id, name, url_encrypted, interval_seconds, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($source->query('SELECT id,user_id,name,url_encrypted,interval_seconds,is_active,created_at FROM cron_tasks ORDER BY id') as $row) {
            $insertTask->execute([(int)$row['id'], (int)$row['user_id'], $row['name'], $row['url_encrypted'], (int)$row['interval_seconds'], (int)$row['is_active'], $row['created_at']]);
            $insertTask->rowCount() > 0 ? $imported++ : $skipped++;
        }
        $insertLog = $db->prepare('INSERT IGNORE INTO cron_logs (id, task_id, status, http_code, response_body, error_msg, started_at, finished_at, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($source->query('SELECT id,task_id,status,http_code,response_body,error_msg,started_at,finished_at,duration_ms FROM cron_logs ORDER BY id') as $row) {
            $insertLog->execute([(int)$row['id'], (int)$row['task_id'], $row['status'], (int)$row['http_code'], $row['response_body'], $row['error_msg'], $row['started_at'], $row['finished_at'], (int)$row['duration_ms']]);
            $insertLog->rowCount() > 0 ? $imported++ : $skipped++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
} else $invalid++;
report('cron_sqlite', $imported, $skipped, $invalid);

$path = legacyPath('LEGACY_BLOCKED_IPS_PATH', 'check_usname_tiktok/blocked_ips.txt');
$imported = $skipped = $invalid = 0;
if ($path !== '' && is_file($path) && is_readable($path)) {
    $insert = $db->prepare('INSERT IGNORE INTO app_blocked_ips (scope, ip_or_prefix, created_at) VALUES (?, ?, NOW())');
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $ip = trim($line);
        if ($ip === '' || !preg_match('/^[0-9A-Fa-f:.]+\*?$/', $ip)) { $invalid++; continue; }
        $insert->execute(['tiktok', $ip]);
        $insert->rowCount() > 0 ? $imported++ : $skipped++;
    }
} else $invalid++;
report('blocked_ips', $imported, $skipped, $invalid);

$path = legacyPath('LEGACY_MAIL_ACCOUNTS_PATH', 'storage/mail/mail_accounts.txt');
$imported = $skipped = $invalid = 0;
if ($path !== '' && is_file($path) && is_readable($path)) {
    $insert = $db->prepare('INSERT INTO mail_accounts (email, account_line, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE account_line=VALUES(account_line), is_active=1, updated_at=NOW()');
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $accountLine = trim($line);
        $email = strtolower(trim(explode('|', $accountLine, 2)[0] ?? ''));
        if ($accountLine === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($accountLine) > 2500) { $invalid++; continue; }
        $insert->execute([$email, $accountLine]);
        $insert->rowCount() > 0 ? $imported++ : $skipped++;
    }
} else $invalid++;
report('mail_accounts', $imported, $skipped, $invalid);

$path = legacyPath('LEGACY_WEBHOOK_EVENTS_PATH', 'storage/webhook-events.jsonl');
$imported = $skipped = $invalid = 0;
if ($path !== '' && is_file($path) && is_readable($path)) {
    $insert = $db->prepare('INSERT IGNORE INTO app_webhook_events (event_id, received_at, method, request_uri, ip, headers_json, query_json, form_json, files_json, body_json, raw_body, body_truncated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $event = json_decode($line, true);
        if (!is_array($event)) { $invalid++; continue; }
        $eventId = preg_replace('/[^a-f0-9]/i', '', (string)($event['id'] ?? '')) ?: bin2hex(random_bytes(8));
        $eventId = substr(str_pad($eventId, 16, '0'), 0, 32);
        $receivedAt = date('Y-m-d H:i:s', strtotime((string)($event['time'] ?? 'now')) ?: time());
        $json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
        $insert->execute([$eventId, $receivedAt, (string)($event['method'] ?? 'UNKNOWN'), (string)($event['uri'] ?? ''), (string)($event['ip'] ?? ''), $json($event['headers'] ?? []), $json($event['query'] ?? []), $json($event['form'] ?? []), $json($event['files'] ?? []), $json($event['json'] ?? null), (string)($event['raw_body'] ?? ''), !empty($event['body_truncated']) ? 1 : 0]);
        $insert->rowCount() > 0 ? $imported++ : $skipped++;
    }
} else $invalid++;
report('webhook_events', $imported, $skipped, $invalid);
echo "done\n";
