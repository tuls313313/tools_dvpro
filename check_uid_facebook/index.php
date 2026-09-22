<?php

require_once dirname(__DIR__) . '/env.php';
secureSessionStart();
require_once 'controllers/CheckLiveController.php';

$action = $_GET['action'] ?? 'form';

$controller = new CheckLiveController();

if ($action === 'form') {
    $controller->form();
}

if ($action === 'check') {
    $controller->check();
}