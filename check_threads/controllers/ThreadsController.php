<?php

require_once dirname(__DIR__, 2) . '/env.php';
require_once "models/ThreadsModel.php";

class ThreadsController
{

    public function view()
    {
        require "views/threads_view.php";
    }

    private function respond($code, $data)
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function getIp()
    {
        if (function_exists('dvproClientIp')) {
            return dvproClientIp();
        }

        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    private function logRequest($username)
    {
        $username = str_replace(["\n", "\r"], '', $username);
        dvproLog('threads', 'info', 'Username checked', ['username' => $username]);
    }

    private function rateLimit($key, $limit, $window)
    {
        if (!dvproRateLimit('threads:' . $key, $limit, $window)) {
            $this->respond(429, [
                "status" => "RATE_LIMIT",
                "message" => "Too many requests"
            ]);
        }
    }

    public function check()
    {
        header("Content-Type: application/x-ndjson");
        header("Cache-Control: no-cache");
        header("X-Accel-Buffering: no");

        // ===== UA =====
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua === '' || strlen($ua) < 8) {
            $this->respond(403, ["status" => "FORBIDDEN"]);
        }

        // ===== REFERER =====
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = parse_url($referer, PHP_URL_HOST);

        if ($host && !dvproIsAllowedHost($host)) {
            $this->respond(403, ["status" => "FORBIDDEN"]);
        }

        $ip = $this->getIp();

        // ===== RATE LIMIT =====
        $this->rateLimit("ip_" . $ip, (int)dvproEnv('THREADS_IP_RATE_LIMIT', '60'), (int)dvproEnv('THREADS_IP_RATE_WINDOW', '60'));
        $this->rateLimit("threads_" . $ip, (int)dvproEnv('THREADS_ENDPOINT_RATE_LIMIT', '20'), (int)dvproEnv('THREADS_ENDPOINT_RATE_WINDOW', '10'));

        // ===== INPUT =====
        $input = $_POST['username'] ?? "";
        $usernames = array_filter(array_map("trim", explode("\n", $input)));

        $maxUsernames = max(1, min(1000, (int)dvproEnv('THREADS_MAX_USERNAMES', '200')));
        if (count($usernames) > $maxUsernames) {
            $this->respond(400, ["status" => "TOO_MANY_USERNAMES", "max" => $maxUsernames]);
        }

        if (empty($usernames)) {
            $this->respond(400, ["status" => "INVALID"]);
        }

        // ===== VALIDATE + LIMIT USER =====
        foreach ($usernames as $u) {

            if (!preg_match('/^[a-zA-Z0-9._]{1,30}$/', $u)) {
                $this->respond(400, [
                    "status" => "INVALID_USERNAME",
                    "username" => $u
                ]);
            }

            $this->rateLimit("ip_user_" . $ip . "_" . strtolower($u), (int)dvproEnv('THREADS_USER_RATE_LIMIT', '8'), (int)dvproEnv('THREADS_USER_RATE_WINDOW', '30'));

            $this->logRequest($u);
        }

        // ===== CACHE =====
        $cacheTtl = max(1, (int) dvproEnv('THREADS_CACHE_TTL', '30'));
        if (random_int(1, max(1, (int) dvproEnv('DB_CACHE_PRUNE_PROBABILITY', '50'))) === 1) {
            dvproCachePrune();
        }

        $needCheck = [];

        foreach ($usernames as $u) {

            $key = 'threads:' . hash('sha256', strtolower($u));
            $cached = dvproCacheGet($key);
            if (is_array($cached)) {
                echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                ob_flush();
                flush();

            } else {
                $needCheck[] = $u;
            }
        }

        // ===== DELAY =====
        $delayUs = max(0, (int)dvproEnv('THREADS_REQUEST_DELAY_US', '50000'));
        if ($delayUs > 0) {
            usleep($delayUs);
        }

        // ===== STREAM =====
        if (!empty($needCheck)) {
            $model = new ThreadsModel();
            $model->checkStream($needCheck);
        }
    }
}
