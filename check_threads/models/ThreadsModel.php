<?php

class ThreadsModel
{

    private string $api;

    public function __construct()
    {
        $this->api = rtrim((string) dvproEnv('THREADS_API_URL', 'https://api.dvpro.vn/threads/checklive'), '?') . '?account=';
    }

    public function checkStream($usernames)
    {

        $multi = curl_multi_init();
        $channels = [];

        foreach ($usernames as $username) {

            $url = $this->api . urlencode($username);

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) dvproEnv('THREADS_HTTP_TIMEOUT', '15'),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);

            $caFile = (string) dvproEnv('CURL_CA_BUNDLE', '');
            if (is_file($caFile)) {
                curl_setopt($ch, CURLOPT_CAINFO, $caFile);
            }

            curl_multi_add_handle($multi, $ch);

            $channels[$username] = $ch;
        }

        do {

            curl_multi_exec($multi, $active);

            while ($info = curl_multi_info_read($multi)) {

                $ch = $info["handle"];
                $username = array_search($ch, $channels);

                $response = curl_multi_getcontent($ch);

                $data = json_decode($response, true);

                $result = [
                    "username" => $username,
                    "status" => $data["status"] ?? "UNKNOWN"
                ];

                $json = json_encode($result);

                // ===== SAVE CACHE =====
                if ($json) {
                    dvproCacheSet(
                        'threads:' . hash('sha256', strtolower($username)),
                        $result,
                        (int) dvproEnv('THREADS_CACHE_TTL', '30')
                    );
                }

                echo $json . "\n";

                ob_flush();
                flush();

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }

        } while ($active);

        curl_multi_close($multi);
    }
}
