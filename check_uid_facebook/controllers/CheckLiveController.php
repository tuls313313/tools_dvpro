<?php

require_once "models/CheckLiveModel.php";

class CheckLiveController {

    public function form() {
        require "views/checklive.php";
    }

    public function check(){
    
        header("Content-Type: application/json");
    
        $ids = $_GET["id"] ?? "";
    
        if(!$ids){
            echo json_encode([]);
            return;
        }
    
        $list = explode(",", $ids);
    
        $maxBatch = max(1, min(1000, (int)dvproEnv('FACEBOOK_MAX_BATCH', '100')));
        if(count($list) > $maxBatch){
            $list = array_slice($list,0,$maxBatch);
        }
    
        $ids = implode(",", $list);
    
        $model = new CheckLiveModel();
    
        $results = $model->checkBatch($ids);
    
        echo json_encode($results);
    }

}
