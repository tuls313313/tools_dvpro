<?php
require_once dirname(__DIR__) . '/env.php';
secureSessionStart();
require_once "controllers/ThreadsController.php";

$action = $_GET['action'] ?? "view";

$controller = new ThreadsController();

if ($action === "check") {
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
    $controller->check();
} else {
    $controller->view();
}