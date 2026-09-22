<?php
require_once dirname(__DIR__, 2) . '/env.php';
require_once 'models/CheckLiveModel.php';

class CheckController {

    private function respond($code, $data) {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function getIp() {
        if (function_exists('dvproClientIp')) {
            return dvproClientIp();
        }

        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }


    private function logRequest($account) {
        dvproLog('instagram', 'info', 'Username checked', ['username' => $account]);
    }


    private function rateLimit($key,$limit,$window){
        if(!dvproRateLimit('instagram:'.$key, $limit, $window)){
            $this->respond(429,[
                "status"=>"RATE_LIMIT",
                "message"=>"Too many requests"
            ]);
        }
    }


    // ================= DIE STREAK =================

    private function getDieStreak($ip){
        $data = dvproCacheGet('instagram:die_streak:'.hash('sha256', $ip));
        return is_array($data) ? (int)($data['count'] ?? 0) : 0;
    }



    private function setDieStreak($ip,$count){
        dvproCacheSet(
            'instagram:die_streak:'.hash('sha256', $ip),
            ["count" => $count, "time" => time()],
            (int)dvproEnv('INSTAGRAM_DIE_STREAK_TTL', '600')
        );
    }



    // ================= UA SPAM CHECK =================

    private function ua_spam_check(string $ip,string $ua): void {

        if (!preg_match('/Chrome\/([\d\.]+)/',$ua,$m)) {
            return;
        }

        $chromeVersion=$m[1];

        $uaKey='chrome_'.$chromeVersion;

        $bucket=floor(time()/60);
        $cacheKey='instagram:ua:'.hash('sha256',$uaKey.':'.$bucket);
        $data=dvproCacheGet($cacheKey);
        $data=is_array($data)
            ? $data
            : [
                "ips"=>[],
                "count"=>0
            ];

        $data["count"]++;
        $data["ips"][$ip]=true;

        dvproCacheSet($cacheKey,$data,(int)dvproEnv('INSTAGRAM_UA_FINGERPRINT_TTL','60'));

        $uniqueIps=count($data["ips"]);

        if($uniqueIps >= (int)dvproEnv('INSTAGRAM_UA_MAX_IPS','5')
            || $data["count"] >= (int)dvproEnv('INSTAGRAM_UA_MAX_REQUESTS','20')){

            $this->respond(403,[
                "status"=>"BLOCKED",
                "message"=>"UA rotating IP blocked",
                "chrome"=>$chromeVersion,
                "unique_ips"=>$uniqueIps
            ]);
        }
    }



    public function form(){
        require 'views/form.php';
    }



    public function checkOne(){

        header('Content-Type: application/json');


        // ===== USER AGENT =====

        $ua=$_SERVER['HTTP_USER_AGENT'] ?? '';

        if($ua=='' || strlen($ua)<8){

            $this->respond(403,[
                "status"=>"FORBIDDEN"
            ]);
        }


        // ===== IP =====

        $ip=$this->getIp();


        // ===== UA SPAM CHECK =====

        $this->ua_spam_check($ip,$ua);



        // ===== REFERER =====

        $referer=$_SERVER['HTTP_REFERER'] ?? '';

        if(!$referer){

            $this->respond(403,[
                "status"=>"FORBIDDEN"
            ]);
        }

        $host=parse_url($referer,PHP_URL_HOST);

        if($host && !dvproIsAllowedHost($host)){

            $this->respond(403,[
                "status"=>"FORBIDDEN"
            ]);
        }



        // ===== RATE LIMIT =====

        $this->rateLimit("ip".$ip,(int)dvproEnv('INSTAGRAM_IP_RATE_LIMIT','60'),(int)dvproEnv('INSTAGRAM_IP_RATE_WINDOW','60'));
        $this->rateLimit("endpoint".$ip,(int)dvproEnv('INSTAGRAM_ENDPOINT_RATE_LIMIT','20'),(int)dvproEnv('INSTAGRAM_ENDPOINT_RATE_WINDOW','10'));



        // ===== DIE STREAK =====

        $streak=$this->getDieStreak($ip);

        if($streak >= (int)dvproEnv('INSTAGRAM_MAX_DIE_STREAK','20')){

            $this->respond(200,[

                "status"=>"die",
                "blocked"=>true,
                "message"=>"Spam die",
                "time"=>date("Y-m-d H:i:s")
            ]);
        }



        $data=json_decode(
            file_get_contents("php://input"),
            true
        );

        $account=trim(
            $data['account'] ?? ''
        );


        if($account==''){

            $this->respond(400,[
                "status"=>"invalid"
            ]);
        }


        $this->logRequest($account);



        // ===== VALIDATE =====

        if(!filter_var($account,FILTER_VALIDATE_URL)){

            if(strlen($account) > max(1, min(200, (int)dvproEnv('INSTAGRAM_USERNAME_MAX_LENGTH', '50')))){

                $this->respond(400,[
                    "status"=>"too_long"
                ]);
            }

            if(!preg_match('/^[a-zA-Z0-9._]+$/',$account)){

                $this->respond(400,[
                    "status"=>"invalid_format"
                ]);
            }
        }



        // ===== CACHE =====

        $cacheKey='instagram:result:'.hash('sha256',strtolower($account));
        $cached=dvproCacheGet($cacheKey);
        if(is_array($cached)){
            echo json_encode($cached,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }



        $delayUs = max(0, (int)dvproEnv('INSTAGRAM_REQUEST_DELAY_US', '200000'));
        if ($delayUs > 0) {
            usleep($delayUs);
        }



        $model=new CheckLiveModel();

        $result=
            $model->checkAccount($account);

        $status=
            $result['status'] ?? 'die';


        if($status==="die"){

            $this->setDieStreak(
                $ip,
                $streak+1
            );

        } else {

            $this->setDieStreak($ip,0);
        }


        $out=[

            "username"=>
                $result['username'] ?? $account,

            "status"=>$status,

            "time"=>
                date("Y-m-d H:i:s")
        ];


        $json=json_encode($out);

        dvproCacheSet($cacheKey,$out,(int)dvproEnv('INSTAGRAM_CACHE_TTL','30'));

        echo $json;
    }

}
