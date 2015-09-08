<?php
include 'settings.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);
setlocale(LC_ALL, 'C.UTF-8'); //for php
putenv('LC_ALL=C.UTF-8'); //for shell_exec


function str_ends_with($haystack, $needle){
	// search forward starting from end minus needle length characters
	return (($temp = strlen($haystack) - strlen($needle)) >= 0 && stripos($haystack, $needle, $temp) !== FALSE);
}
?>
