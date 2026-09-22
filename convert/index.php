<?php
require_once dirname(__DIR__) . '/env.php';

// ===== BẬT / TẮT BẢO TRÌ =====
$maintenance = dvproEnvBool('CONVERT_MAINTENANCE', false);

if ($maintenance) {
    http_response_code(503);
    header('Retry-After: 3600');

    $title = "Bảo trì hệ thống - DVPro.vn";
    $content = '
    <div class="page-wrap min-h-[70vh] flex items-center justify-center">
        <div class="tool-panel max-w-2xl w-full text-center" style="border-color:rgba(245,158,11,.35)">
            <h1 class="text-4xl font-extrabold text-yellow-400 mb-4">
                Hệ thống đang bảo trì
            </h1>
            <p class="text-gray-300 text-lg leading-8">
                Công cụ <strong class="text-white">Chuyển đổi JSON sang 9router</strong> đang được bảo trì tạm thời để nâng cấp và tối ưu hệ thống.
            </p>
            <p class="text-gray-400 mt-4">
                Vui lòng quay lại sau. Xin cảm ơn!
            </p>
        </div>
    </div>
    ';
    include '../layout.php';
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    secureSessionStart();
    dvproNoStoreHeaders();
    if (!dvproRateLimit('convert:' . dvproClientIp(), 40, 60)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Too many requests'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    dvproRequireCsrf();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    require_once __DIR__ . '/controllers/ConvertController.php';
    exit;
}

require_once dirname(__DIR__) . '/env.php';
secureSessionStart();

$title = "Chuyển đổi JSON sang 9router - DVPro.vn";
$content = '';

ob_start();
include __DIR__ . '/views/converter.html';
$content = ob_get_clean();

include '../layout.php';
?>
