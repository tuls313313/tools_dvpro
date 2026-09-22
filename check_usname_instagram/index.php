<?php
require_once dirname(__DIR__) . '/env.php';
secureSessionStart();
require_once 'controllers/CheckLiveController.php';

$action = $_GET['action'] ?? 'form';
$controller = new CheckController();

if ($action === 'checkOne') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    dvproRequireCsrf();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $controller->checkOne();
} else {
    $controller->form();
}
