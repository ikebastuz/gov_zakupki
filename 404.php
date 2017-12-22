<?php
    header("HTTP/1.0 404 Not Found");
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode('page not found');
    die();
?>