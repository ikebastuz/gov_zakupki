<?php 
	set_time_limit(0);
	ini_set('memory_limit', '2048M');

	require_once('./api/api.php');

	$api = new Api();

	$mode = $_GET['mode'];

	if($mode == 'run'){
		$api->importData();
		$api->printErrors();
		$api->printStats();
		
	}else if($mode == 'load'){
		$api->prepareFiles();
	}else{
		echo 'Unknown method';
	}

	

?>