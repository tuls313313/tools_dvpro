<?php
declare(strict_types=1);

// The legacy public mail API is retired; the website reads Microsoft directly.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
    'status' => false,
    'message' => 'API mail cũ đã ngừng. Vui lòng dùng trang /mail/index.php với tài khoản OAuth Microsoft.'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
