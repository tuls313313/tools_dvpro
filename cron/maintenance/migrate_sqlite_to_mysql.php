<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/security.php';

$sourcePath = (string)dvproEnv('LEGACY_CRON_DB_PATH', 'cron/cron.db');
if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $sourcePath)) {
    $sourcePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($sourcePath, '\\\\/');
}
if (!is_file($sourcePath)) {
    throw new RuntimeException('Legacy SQLite file not found: ' . $sourcePath);
}

$source = new PDO('sqlite:' . $sourcePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$mysql = cronDatabase(true);

$tables = ['cron_users', 'cron_tasks', 'cron_logs'];
foreach ($tables as $table) {
    $count = (int)$mysql->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    if ($count > 0) {
        throw new RuntimeException("Destination table {$table} is not empty");
    }
}

$counts = [];
foreach ($tables as $table) {
    $counts[$table] = (int)$source->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

$mysql->beginTransaction();
try {
    $insertUser = $mysql->prepare(
        'INSERT INTO cron_users (id,code,code_hash,name,twofa_secret,twofa_enabled,created_at) VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($source->query('SELECT id,code,code_hash,name,twofa_secret,twofa_enabled,created_at FROM cron_users ORDER BY id') as $row) {
        $insertUser->execute([
            (int)$row['id'],
            (string)$row['code'],
            $row['code_hash'],
            $row['name'],
            $row['twofa_secret'],
            (int)$row['twofa_enabled'],
            $row['created_at'],
        ]);
    }

    $insertTask = $mysql->prepare(
        'INSERT INTO cron_tasks (id,user_id,name,url_encrypted,interval_seconds,is_active,created_at) VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($source->query('SELECT id,user_id,name,url_encrypted,interval_seconds,is_active,created_at FROM cron_tasks ORDER BY id') as $row) {
        $insertTask->execute([
            (int)$row['id'],
            (int)$row['user_id'],
            (string)$row['name'],
            (string)$row['url_encrypted'],
            (int)$row['interval_seconds'],
            (int)$row['is_active'],
            $row['created_at'],
        ]);
    }

    $insertLog = $mysql->prepare(
        'INSERT INTO cron_logs (id,task_id,status,http_code,response_body,error_msg,started_at,finished_at,duration_ms) VALUES (?,?,?,?,?,?,?,?,?)'
    );
    foreach ($source->query('SELECT id,task_id,status,http_code,response_body,error_msg,started_at,finished_at,duration_ms FROM cron_logs ORDER BY id') as $row) {
        $insertLog->execute([
            (int)$row['id'],
            (int)$row['task_id'],
            (string)$row['status'],
            (int)$row['http_code'],
            $row['response_body'],
            $row['error_msg'],
            (string)$row['started_at'],
            $row['finished_at'],
            (int)$row['duration_ms'],
        ]);
    }

    foreach ($tables as $table) {
        $actual = (int)$mysql->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        if ($actual !== $counts[$table]) {
            throw new RuntimeException("Count mismatch for {$table}");
        }
    }

    $mysql->commit();
} catch (Throwable $e) {
    if ($mysql->inTransaction()) {
        $mysql->rollBack();
    }
    throw $e;
}

foreach ($tables as $table) {
    $row = $mysql->query("SELECT MIN(id) AS min_id, MAX(id) AS max_id, COUNT(*) AS count FROM {$table}")->fetch();
    printf(
        "%s: source=%d destination=%d min=%s max=%s\n",
        $table,
        $counts[$table],
        (int)$row['count'],
        (string)($row['min_id'] ?? ''),
        (string)($row['max_id'] ?? '')
    );
}
