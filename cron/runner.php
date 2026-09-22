<?php

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Forbidden';
  exit;
}

require_once __DIR__ . '/security.php';

function cronOnlyTaskId()
{
  foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (preg_match('/^--task=(\d+)$/', (string)$arg, $m)) {
      return (int)$m[1];
    }
  }

  return 0;
}

function cronHasFlag(string $flag): bool
{
  return in_array($flag, $GLOBALS['argv'] ?? [], true);
}

function cronDaemonSeconds(): int
{
  foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (preg_match('/^--daemon=(\d+)$/', (string)$arg, $matches)) {
      return max(5, min(300, (int)$matches[1]));
    }
  }

  return 0;
}

function cronTaskLockPath($taskId)
{
  return __DIR__ . '/runner_task_' . (int)$taskId . '.lock';
}

function cronRunnerLockPath(): string
{
  return __DIR__ . '/runner_daemon.lock';
}

function cronFindCaFile(): ?string
{
  $path = trim((string)dvproEnv('CURL_CA_BUNDLE', ''));
  return $path !== '' && is_file($path) && is_readable($path) ? $path : null;
}

function cronTaskIsDue(PDO $db, array $task)
{
  if (array_key_exists('last_started_at', $task)) {
    $last = empty($task['last_started_at']) ? false : [
      'status' => $task['last_status'] ?? '',
      'started_at' => $task['last_started_at'],
      'finished_at' => $task['last_finished_at'] ?? null,
    ];
  } else {
    $lastLog = $db->prepare("SELECT status,started_at,finished_at FROM cron_logs WHERE task_id=? ORDER BY id DESC LIMIT 1");
    $lastLog->execute([$task['id']]);
    $last = $lastLog->fetch(PDO::FETCH_ASSOC);
  }

  if (!$last) {
    return true;
  }

  $interval = max(3, (int)($task['interval_seconds'] ?? 60));
  $lastStarted = strtotime($last['started_at'] ?? '') ?: 0;
  $runningGrace = max(1, (int)dvproEnv('CRON_RUNNING_GRACE_SECONDS', '30'));
  $stillRunning = $last['status'] === 'running'
    && empty($last['finished_at'])
    && ($lastStarted + $runningGrace > time());

  if ($stillRunning) {
    return false;
  }

  return $lastStarted <= 0 || $lastStarted + $interval <= time();
}

function cronRunTask(PDO $db, array $task, bool $force = false)
{
  $url = cronDecrypt($task['url_encrypted']);
  if (!$url) {
    return;
  }

  $target = cronValidateUrl($url);
  if ($target === false) {
    $log = $db->prepare("INSERT INTO cron_logs(task_id,status,http_code,response_body,error_msg,started_at,finished_at,duration_ms)VALUES(?,?,?,?,?,?,?,?)");
    $now = date('Y-m-d H:i:s');
    $log->execute([$task['id'], 'error', 0, null, 'Blocked unsafe cron URL', $now, $now, 0]);
    return;
  }

  $lockPath = cronTaskLockPath($task['id']);
  $lock = fopen($lockPath, 'c');
  $lockMode = LOCK_EX | ($force ? 0 : LOCK_NB);
  if (!$lock || !flock($lock, $lockMode)) {
    if ($lock) fclose($lock);
    return;
  }

  try {
    ftruncate($lock, 0);
    fwrite($lock, (string)getmypid() . '|' . time());

    if (!$force && !cronTaskIsDue($db, $task)) {
      return;
    }

    $start = microtime(true);
    $startedAt = date('Y-m-d H:i:s');
    $log = $db->prepare("INSERT INTO cron_logs(task_id,status,http_code,response_body,error_msg,started_at,finished_at,duration_ms)VALUES(?,?,?,?,?,?,?,?)");
    $log->execute([$task['id'], 'running', 0, null, null, $startedAt, null, 0]);
    $logId = (int)$db->lastInsertId();

    $resolvedIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
    $ch = curl_init();
    $curlOpts = [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => max(1, (int)dvproEnv('CRON_HTTP_TIMEOUT', '8')),
      CURLOPT_CONNECTTIMEOUT => max(1, (int)dvproEnv('CRON_CONNECT_TIMEOUT', '3')),
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $resolvedIp],
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_USERAGENT => 'DVPro-Cron/1.0',
    ];
    $caFile = cronFindCaFile();
    if ($caFile !== null) {
      $curlOpts[CURLOPT_CAINFO] = $caFile;
    }
    curl_setopt_array($ch, $curlOpts);
    $body = curl_exec($ch);
    $httpCode = curl_errno($ch) ? 0 : curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $duration = round((microtime(true) - $start) * 1000);
    $status = ($httpCode >= 200 && $httpCode < 400) ? 'success' : 'error';
    $bodyStored = mb_substr($body ?? '', 0, 500);
    $st = $db->prepare("UPDATE cron_logs SET status=?,http_code=?,response_body=?,error_msg=?,finished_at=?,duration_ms=? WHERE id=?");
    $st->execute([$status, $httpCode, $bodyStored, $error ?: null, date('Y-m-d H:i:s'), $duration, $logId]);
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function cronLoadActiveTasks(PDO $db): array
{
  return $db->query(
    "SELECT t.*,l.status AS last_status,l.started_at AS last_started_at,l.finished_at AS last_finished_at
     FROM cron_tasks t
     LEFT JOIN (
       SELECT cl.*
       FROM cron_logs cl
       INNER JOIN (SELECT task_id, MAX(id) AS last_id FROM cron_logs GROUP BY task_id) latest
         ON latest.task_id=cl.task_id AND latest.last_id=cl.id
     ) l ON l.task_id=t.id
     WHERE t.is_active=1
     ORDER BY t.id"
  )->fetchAll(PDO::FETCH_ASSOC);
}

function cronDispatchDueTasks(PDO $db): void
{
  foreach (cronLoadActiveTasks($db) as $task) {
    if (cronTaskIsDue($db, $task)) {
      cronLaunchTaskProcess((int)$task['id']);
    }
  }
}

function cronPruneLogs(PDO $db): void
{
  $maxRows = max(1, (int)dvproEnv('CRON_LOG_MAX_ROWS', '500'));
  $db->exec("DELETE l FROM cron_logs l LEFT JOIN (SELECT id FROM (SELECT id FROM cron_logs ORDER BY id DESC LIMIT {$maxRows}) keep_rows) keep ON keep.id=l.id WHERE keep.id IS NULL");
}

function cronRunDaemon(PDO $db, int $seconds): void
{
  $lock = fopen(cronRunnerLockPath(), 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    if ($lock) fclose($lock);
    return;
  }

  try {
    ftruncate($lock, 0);
    fwrite($lock, (string)getmypid() . '|' . time());
    $deadline = microtime(true) + $seconds;

    do {
      cronDispatchDueTasks($db);
      $remaining = $deadline - microtime(true);
      if ($remaining <= 0) {
        break;
      }
      $pollUs = max(10000, min(1000000, (int)dvproEnv('CRON_DAEMON_POLL_US', '500000')));
      usleep((int)min($pollUs, $remaining * 1000000));
    } while (true);

    cronPruneLogs($db);
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

$onlyTaskId = cronOnlyTaskId();

try {
  $db = cronDatabase($onlyTaskId === 0);
} catch (Exception $e) {
  die("DB Error");
}

$force = cronHasFlag('--force');
$daemonSeconds = cronDaemonSeconds();
if ($onlyTaskId > 0) {
  $st = $db->prepare("SELECT * FROM cron_tasks WHERE is_active=1 AND id=? LIMIT 1");
  $st->execute([$onlyTaskId]);
  $task = $st->fetch(PDO::FETCH_ASSOC);
  if ($task) {
    cronRunTask($db, $task, $force);
  }
} elseif ($daemonSeconds > 0) {
  cronRunDaemon($db, $daemonSeconds);
} else {
  foreach (cronLoadActiveTasks($db) as $task) {
    cronRunTask($db, $task);
  }
  cronPruneLogs($db);
}
