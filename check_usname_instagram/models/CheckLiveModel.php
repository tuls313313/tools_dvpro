<?php

class CheckLiveModel {

    private $apiKey;

    public function __construct() {
        $this->apiKey = function_exists('dvproEnv')
            ? (string)dvproEnv('INSTAGRAM_CHECK_API_KEY', '')
            : '';
    }

    public function checkAccount($account) {

        if (filter_var($account, FILTER_VALIDATE_URL)) {

            if (strpos($account, 'instagram.com') === false) {
                return ["status" => "die"];
            }

            $parsedUrl = parse_url($account);
            $path = trim($parsedUrl['path'], '/');

            $account = $path;
        }

        $url = rtrim((string)dvproEnv('INSTAGRAM_API_URL','https://api.dvpro.vn/checklive'), '?') . "?account="
            . urlencode($account)
            . "&api_key=" . $this->apiKey;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => max(1, (int)dvproEnv('INSTAGRAM_HTTP_TIMEOUT','5')),
            CURLOPT_CONNECTTIMEOUT => max(1, (int)dvproEnv('INSTAGRAM_CONNECT_TIMEOUT','3')),
        ]);

        $caFile = (string)dvproEnv('CURL_CA_BUNDLE','');
        if (is_file($caFile)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }

        $response = curl_exec($ch);

        curl_close($ch);

        if (!$response) {
            return ["status" => "die"];
        }

        $data = json_decode($response, true);

        if (isset($data['data']['status'])) {

            return [
                "status" => $data['data']['status'],
                "username" => $data['data']['username'] ?? $account
            ];
        }

        return [
            "status" => "die",
            "username" => $account
        ];
    }
}
