<?php
include 'common.php';

if(!isset($_GET["id"])){
	echo "invalid";
	exit;
}

if(isset($_GET["format"])){
	switch($_GET["format"]){
		case "mp3":
			$suffix = ".320k.mp3";
			$mime_type = "audio/mpeg";
			$quality_params = " -b:a 320k ";
			break;
		case "ogg":
			$suffix = ".q8.ogg";
			$mime_type = "audio/ogg";
			$quality_params = " -aq 8 ";
			break;
		case "opus":
			$suffix = ".256k.opus";
			$mime_type = "audio/ogg";
			$quality_params = " -b:a 256k ";
			break;
		default:
			die("invalid");
	}
}else{
	$suffix = ".320k.mp3";
	$mime_type = "audio/mpeg";
	$quality_params = " -b:a 320k ";
}


if (!file_exists(COMPRESSED_CACHE)) {
    mkdir(COMPRESSED_CACHE, 0777, true);
}

$dbcon = new mysqli("localhost", MYSQL_ACCOUNT, MYSQL_PASSWORD, DB_NAME);
if($dbcon->connect_errno) die("db connection failed: " . $dbcon->connect_error);

$dbcon->set_charset("utf8");

if (!($stmt = $dbcon->prepare("SELECT filepath,start,end FROM tracks WHERE id=(?)"))) {
	die("Prepare failed: (" . $dbcon->errno . ") " . $dbcon->error);
}

$stmt->bind_param('i', $_GET["id"]);
$stmt->execute();
$stmt->bind_result($filepath,$start,$end);

if($stmt->fetch()){
	if(!str_ends_with($filepath,'.ogg')){
		$cut_params = '';
		if($start != null){
			$outfile = COMPRESSED_CACHE . substr(md5(dirname($filepath)), 0, 8) . '_' . basename($filepath) . '.ss' . intval($start) . $suffix;
			$cut_params = ' -ss ' . $start;
		}else{
			$outfile = COMPRESSED_CACHE . substr(md5(dirname($filepath)), 0, 8) . '_' . basename($filepath) . $suffix;
		}
		if($end != null){
			$cut_params = $cut_params . ' -to ' . $end;
		}

		$lockfile = $outfile . '.compressing';

		if(!file_exists($outfile)){
			if(!isset($_GET["prepare"])){
				echo shell_exec('touch ' . escapeshellarg($lockfile) . ' && ffmpeg -i ' . escapeshellarg($filepath) . $cut_params . $quality_params . escapeshellarg($outfile) . ' && rm ' . escapeshellarg($lockfile));
			}else{
				pclose(popen('touch ' . escapeshellarg($lockfile) . ' && ffmpeg -i ' . escapeshellarg($filepath) . $cut_params . $quality_params . escapeshellarg($outfile) . ' && rm ' . escapeshellarg($lockfile) . ' &', 'r'));
			}
		}else if(!isset($_GET["prepare"])){
			while(file_exists($lockfile)){
				sleep(1);
			}
		}
		$filepath = $outfile;
	}
	if(!isset($_GET["prepare"])){
		header('Content-type: ' . $mime_type);
		header('Content-length: ' . filesize($filepath));
		header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 24 * 60 * 60))); //cache for 10 days
		readfile($filepath);
	}else{
		header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (1 * 24 * 60 * 60))); //cache for 1 days
	}
}

$dbcon->close();

?>

