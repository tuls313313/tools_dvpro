<?php
require_once __DIR__ . '/../models/TiktokModel.php';

class TiktokController {
    private $model;

    public function __construct() {
        $this->model = new TiktokModel();
    }

    public function check() {
        $usernamesInput = isset($_POST['usernames']) ? trim($_POST['usernames']) : '';
        $lines = array_filter(array_map('trim', explode("\n", $usernamesInput)));

        $results = [];
        foreach ($lines as $username) {
            $results[] = $this->model->checkUsername($username);
        }

        require __DIR__ . '/../views/result.php';
    }
}
