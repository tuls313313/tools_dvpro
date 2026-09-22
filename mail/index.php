<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Controllers/MailController.php';

$controller = new MailController();
$controller->index();