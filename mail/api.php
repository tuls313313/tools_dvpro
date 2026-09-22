<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Models/MailModel.php';
require_once dirname(__DIR__) . '/env.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
dvproNoStoreHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function mail_api_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mail_api_param(string $name, string $default = ''): string
{
    return trim((string)($_POST[$name] ?? $_GET[$name] ?? $default));
}

if (!dvproRateLimit('mail_api:' . dvproClientIp(), 60, 60)) {
    mail_api_response(['status' => false, 'message' => 'Too many requests'], 429);
}

$validKeys = array_filter(array_map('trim', explode(',', (string)dvproEnv('MAIL_API_KEYS', ''))));

if (empty($validKeys)) {
    mail_api_response([
        'status' => false,
        'message' => 'MAIL_API_KEYS chưa được cấu hình',
    ], 503);
}

$mail = mail_api_param('mail', mail_api_param('email', mail_api_param('account')));
$key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));

if ($key === '' || !in_array($key, $validKeys, true)) {
    mail_api_response([
        'status' => false,
        'message' => 'API key không hợp lệ',
    ], 401);
}

if ($mail === '') {
    mail_api_response([
        'status' => false,
        'message' => 'Thiếu mail hoặc account',
        'usage' => [
            'header' => 'X-API-Key: API_KEY',
            'endpoint' => '/mail/api.php?mail=user@example.com',
        ],
    ], 400);
}

$listMail = mail_api_param('list_mail', 'all');
$top = (int)mail_api_param('top', '10');

try {
    $model = new MailModel();
    $result = $model->sanitizePublicMailResult(
        $model->readMail($mail, $listMail, $top),
        $mail
    );

    mail_api_response([
        'status' => (bool)($result['status'] ?? !empty($result['messages'])),
        'email' => $result['email'] ?? $mail,
        'mailbox_mode' => $result['mailbox_mode'] ?? null,
        'mailbox_type' => $result['mailbox_type'] ?? null,
        'mailbox_type_label' => $result['mailbox_type_label'] ?? null,
        'expires_at' => $result['expires_at'] ?? null,
        'remaining_seconds' => $result['remaining_seconds'] ?? null,
        // Sanitized metadata only (no password/token/client_id).
        'account' => $result['account'] ?? null,
        'messages' => $result['messages'] ?? [],
        'error' => $result['error'] ?? null,
    ]);
} catch (Throwable $e) {
    mail_api_response([
        'status' => false,
        'message' => 'Không đọc được mail',
    ], 500);
}
