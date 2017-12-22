<?php 
	set_time_limit(300);
	ini_set('memory_limit', '1024M');

	require_once('./api/api.php');

	$api = new Api();

	$mode = $_GET['mode'];

	if($mode == 'run'){
		$api->importData();
		$api->printErrors();
		$api->printStats();
	}else if($mode == 'load'){
		$api->unzipSrcFiles();
	}else{
		echo 'Unknown method';
	}
	

?>