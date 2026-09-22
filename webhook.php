<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function webhookHeaders(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_')) {
            $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$header] = $value;
        }
    }

    return $headers;
}

function webhookFiles(array $files): array
{
    $result = [];
    foreach ($files as $name => $file) {
        $result[$name] = [
            'name' => $file['name'] ?? null,
            'type' => $file['type'] ?? null,
            'size' => $file['size'] ?? null,
            'error' => $file['error'] ?? null,
        ];
    }

    return $result;
}

function webhookJson(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function webhookReadEvents(): array
{
    $events = [];
    $limit = max(1, min(500, (int)dvproEnv('WEBHOOK_MAX_EVENTS', '100')));
    $rows = dvproDatabase()->query('SELECT * FROM app_webhook_events ORDER BY received_at DESC LIMIT ' . $limit)->fetchAll();
    foreach ($rows as $row) {
        $events[] = [
            'id' => $row['event_id'],
            'time' => $row['received_at'],
            'method' => $row['method'],
            'uri' => $row['request_uri'],
            'ip' => $row['ip'],
            'headers' => json_decode((string)$row['headers_json'], true) ?: [],
            'query' => json_decode((string)$row['query_json'], true) ?: [],
            'form' => json_decode((string)$row['form_json'], true) ?: [],
            'files' => json_decode((string)$row['files_json'], true) ?: [],
            'json' => json_decode((string)$row['body_json'], true),
            'raw_body' => $row['raw_body'],
            'body_truncated' => (bool)$row['body_truncated'],
        ];
    }
    return $events;
}

function webhookRenderViewer(array $events): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $count = count($events);
    ?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="10">
    <title>Webhook Viewer</title>
    <style>
        :root { color-scheme: light; --ink: #17211b; --paper: #f3f0e8; --card: #fffdf7; --accent: #d94f2b; --line: #d8d1c2; }
        * { box-sizing: border-box; }
        body { margin: 0; background: radial-gradient(circle at top right, #f6c96d55, transparent 32rem), var(--paper); color: var(--ink); font: 15px/1.55 Georgia, serif; }
        main { width: min(1100px, calc(100% - 28px)); margin: 42px auto; }
        header { display: flex; justify-content: space-between; gap: 20px; align-items: end; margin-bottom: 24px; }
        h1 { margin: 0; font-size: clamp(32px, 7vw, 70px); line-height: .9; letter-spacing: -.05em; }
        .status { color: #526057; text-align: right; }
        article { margin: 16px 0; padding: 20px; border: 1px solid var(--line); border-radius: 16px; background: var(--card); box-shadow: 0 8px 30px #3325110d; }
        .meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; font-family: Consolas, monospace; }
        .tag { padding: 3px 9px; border-radius: 999px; background: #e8e4d9; }
        .method { background: var(--accent); color: white; font-weight: 700; }
        pre { overflow: auto; max-height: 520px; margin: 0; padding: 15px; border-radius: 10px; background: #19221d; color: #eaf2e8; font: 13px/1.55 Consolas, monospace; white-space: pre-wrap; word-break: break-word; }
        .empty { padding: 60px 20px; text-align: center; border: 1px dashed var(--line); border-radius: 16px; }
        @media (max-width: 640px) { header { display: block; } .status { margin-top: 12px; text-align: left; } article { padding: 12px; } }
    </style>
</head>
<body>
<main>
    <header>
        <div><h1>Webhook<br>Viewer</h1></div>
        <div class="status"><?= $count ?> request gần nhất<br>Tự tải lại sau 10 giây</div>
    </header>
    <?php if ($events === []): ?>
        <div class="empty">Chưa nhận được dữ liệu webhook.</div>
    <?php endif; ?>
    <?php foreach ($events as $event): ?>
        <article>
            <div class="meta">
                <span class="tag method"><?= htmlspecialchars((string) ($event['method'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="tag"><?= htmlspecialchars((string) ($event['time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="tag"><?= htmlspecialchars((string) ($event['ip'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="tag"><?= htmlspecialchars((string) ($event['uri'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <pre><?= htmlspecialchars((string) json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8') ?></pre>
        </article>
    <?php endforeach; ?>
</main>
</body>
</html>
    <?php
    exit;
}

$viewKey = (string) ($_GET['key'] ?? '');
$configuredViewKey = (string)dvproEnv('WEBHOOK_VIEW_KEY', '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && $configuredViewKey !== ''
    && hash_equals($configuredViewKey, $viewKey)) {
    $events = webhookReadEvents();
    if (($_GET['format'] ?? '') === 'json') {
        webhookJson($events);
    }
    webhookRenderViewer($events);
}

$rawBody = file_get_contents('php://input');
$rawBody = $rawBody === false ? '' : $rawBody;
$maxBodyBytes = max(1024, (int)dvproEnv('WEBHOOK_MAX_BODY_BYTES', '1048576'));
$bodyTruncated = strlen($rawBody) > $maxBodyBytes;
if ($bodyTruncated) {
    $rawBody = substr($rawBody, 0, $maxBodyBytes);
}

$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
$decodedBody = null;
if (str_contains(strtolower($contentType), 'application/json') && $rawBody !== '') {
    $decodedBody = json_decode($rawBody, true);
}

$event = [
    'id' => bin2hex(random_bytes(8)),
    'time' => date('c'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'headers' => webhookHeaders(),
    'query' => $_GET,
    'form' => $_POST,
    'files' => webhookFiles($_FILES),
    'json' => $decodedBody,
    'raw_body' => $rawBody,
    'body_truncated' => $bodyTruncated,
];

try {
    $st = dvproDatabase()->prepare(
        'INSERT INTO app_webhook_events
         (event_id, received_at, method, request_uri, ip, headers_json, query_json, form_json, files_json, body_json, raw_body, body_truncated)
         VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $event['id'],
        $event['method'],
        $event['uri'],
        $event['ip'],
        json_encode($event['headers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($event['query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($event['form'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($event['files'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($event['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $event['raw_body'],
        $event['body_truncated'] ? 1 : 0,
    ]);
} catch (Throwable $e) {
    webhookJson(['ok' => false, 'error' => 'Cannot write webhook event'], 500);
}

webhookJson([
    'ok' => true,
    'message' => 'Webhook received',
    'id' => $event['id'],
    'received_at' => $event['time'],
]);
