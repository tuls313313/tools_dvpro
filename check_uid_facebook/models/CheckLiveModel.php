<?php
require_once dirname(__DIR__, 2) . '/env.php';

class CheckLiveModel {

    public function checkBatch($ids){

        $baseUrl = rtrim((string)dvproEnv('FACEBOOK_API_URL', ''), '?&');
        if ($baseUrl === '') {
            return [];
        }
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        $url = $baseUrl . $separator . http_build_query(['id' => $ids]);

        $ch = curl_init();

        curl_setopt_array($ch,[
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, (int)dvproEnv('FACEBOOK_HTTP_TIMEOUT', '10')),
            CURLOPT_CONNECTTIMEOUT => max(1, (int)dvproEnv('FACEBOOK_CONNECT_TIMEOUT', '5')),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $caFile = (string)dvproEnv('CURL_CA_BUNDLE', '');
        if ($caFile !== '' && is_file($caFile) && is_readable($caFile)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }

        $res = curl_exec($ch);

        curl_close($ch);

        if(!$res) return [];

        $lines = explode("\n", trim($res));

        $results = [];

        foreach($lines as $line){

            $line = trim($line);

            if(!$line) continue;

            $data = json_decode($line,true);

            if($data){
                $results[] = $data;
            }
        }

        return $results;
    }

}
