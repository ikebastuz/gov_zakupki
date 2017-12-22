<?php
    header('Content-Type: application/json; charset=utf-8');
    header("Access-Control-Allow-Orgin: *");
    header("Access-Control-Allow-Methods: *");

    require_once('./api/api.php');
    $api = new Api();
    
    $type = $_GET['type'];
    $query = htmlspecialchars($_GET['query']);
    if($query == ""){
        echo json_encode('Input something...');
        die();
    }

    switch($type){
        case "search":
            $data = $api->searchProduct($query);
            echo json_encode($data, JSON_UNESCAPED_UNICODE );
            //print_r($data);
                        
            break;
        case "details":
            $data = $api->getProductDetails($query);
            echo json_encode($data, JSON_UNESCAPED_UNICODE );
            //print_r($data);
                
            break;
        case "catalog":
            $data = $api->getCatalogTree($query);
            //echo json_encode($data, JSON_UNESCAPED_UNICODE );
            print_r($data);
                
            break;
        default:
            echo json_encode('Unknown method');
            break;
    }
    
?>