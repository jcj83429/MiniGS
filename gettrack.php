<?php
include 'common.php';

const BUFFER_SIZE = 2097152;

if(!isset($_GET["id"])){
	echo "invalid";
	exit;
}

$format_info = [
	"mp3" => [
		"suffix" => ".320k.mp3",
		"mime_type" => "audio/mpeg",
		"quality_params" => " -b:a 320k -vn "
	],
	"ogg" => [
		"suffix" => ".q8.ogg",
		"mime_type" => "audio/ogg",
		"quality_params" => " -aq 8 -vn "
	],
	"opus" => [
		"suffix" => ".256k.opus",
		"mime_type" => "audio/ogg",
		"quality_params" => " -b:a 256k -vn "
	],
	"flac" => [
		"suffix" => ".flac",
		"mime_type" => "audio/flac",
		"quality_params" => " -vn "
	]
];

// DTS is not always lossless, but it's higher quality than typical lossy
$lossless_formats = ["flac", "ape", "alac", "wv", "wav", "dts"];

if(isset($_GET["format"])){
	switch($_GET["format"]){
		case "mp3":
		case "ogg":
		case "opus":
			$lossy_format = $lossless_format = $_GET["format"];
			break;
		case "mp3,flac":
		case "ogg,flac":
		case "opus,flac":
			list($lossy_format, $lossless_format) = explode(",", $_GET["format"]);
			break;
		default:
			die("invalid");
	}
}else{
	$lossy_format = $lossless_format = "mp3";
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
	$src_file_ext = pathinfo($filepath, PATHINFO_EXTENSION);
	if(in_array($src_file_ext, $lossless_formats)){
		// source file is lossless. use target format for lossless source.
		$format = $lossless_format;
	}else{
		$format = $lossy_format;
	}
	if($format != $src_file_ext || $start != null || $end != null){
		// transcode needed

		// handle cue sheet image cutting
		$cut_params = '';
		if($start !== null){
			$outfile = COMPRESSED_CACHE . substr(md5(dirname($filepath)), 0, 8) . '_' . basename($filepath) . '.ss' . intval($start) . $format_info[$format]["suffix"];
			$cut_params = ' -ss ' . $start;
		}else{
			$outfile = COMPRESSED_CACHE . substr(md5(dirname($filepath)), 0, 8) . '_' . basename($filepath) . $format_info[$format]["suffix"];
		}
		if($end !== null){
			$cut_params = $cut_params . ' -to ' . $end;
		}

		// no high res or surround audio
		$static_params = ' -filter:a "aformat=sample_rates=48000|44100|32000|24000|22050|16000|11025|8000:channel_layouts=stereo|mono" ';

		// encode
		$lockfile = $outfile . '.compressing';

		if(!file_exists($outfile)){
			if(!isset($_GET["prepare"])){
				shell_exec('touch ' . escapeshellarg($lockfile) . ' && ffmpeg -i ' . escapeshellarg($filepath) . $static_params . $cut_params . $format_info[$format]["quality_params"] . escapeshellarg($outfile) . ' ; rm ' . escapeshellarg($lockfile));
			}else{
				pclose(popen('if true; then ' . 'touch ' . escapeshellarg($lockfile) . ' && ffmpeg -i ' . escapeshellarg($filepath) . $static_params . $cut_params . $format_info[$format]["quality_params"] . escapeshellarg($outfile) . ' ; rm ' . escapeshellarg($lockfile) . '; fi &', 'r'));
			}
		}else if(!isset($_GET["prepare"])){
			// encoding started in another request. block until encoding done
			while(file_exists($lockfile)){
				sleep(1);
			}
		}
		$filepath = $outfile;
	}

	if(!isset($_GET["prepare"])){
		$filesize = filesize($filepath);
		$range_start = 0;
		$range_end = $filesize - 1;
		$range_length = $filesize;
		if (isset($_SERVER['HTTP_RANGE'])) {
			preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);
			$range_start = intval($matches[1]);
			$range_end = (array_key_exists(2, $matches) ? intval($matches[2]) : $filesize - 1);
			$range_length = $range_end + 1 - $range_start;
			header('HTTP/1.1 206 Partial Content');
			header("Content-Range: bytes $range_start-$range_end/$filesize");
		}

		header('Content-type: ' . $format_info[$format]["mime_type"]);
		header('Content-length: ' . $range_length);
		header('Content-disposition: inline; filename="' . str_replace('"', '\\"', str_replace('\\', '\\\\', basename($filepath))) . '"');
		header('Accept-Ranges: bytes');
		header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 24 * 60 * 60))); //cache for 10 days

		$fp = fopen($filepath, 'r');
		fseek($fp, $range_start);
		while ($range_length >= BUFFER_SIZE){
			print(fread($fp, BUFFER_SIZE));
			$range_length -= BUFFER_SIZE;
		}
		if ($range_length) print(fread($fp, $range_length));
		fclose($fp);
	}else{
		header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (1 * 24 * 60 * 60))); //cache for 1 days
	}
}

$dbcon->close();

?>
